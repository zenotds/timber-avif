<?php
/**
 * Timber AVIF Converter
 *
 * @version 6.0.0
 * @author Francesco Zeno Selva
 * @link https://github.com/zenotds/timber-avif
 *
 * Responsive images for Timber 2.x, with AVIF/WebP generated next to the original.
 *
 * Architecture:
 *  - One shared set of canonical widths for the whole theme, so variants are reused
 *    across modules instead of multiplying per template
 *  - One modern format per request, resolved from what the server can encode
 *  - Fast file_exists check with per-request static cache
 *  - Missing variants: converted inline up to a budget, then after the response,
 *    then in a queue drained by cron or by admin requests
 *  - Failed conversions are remembered for 24h, keyed to quality and engine
 *  - Never upscales past the original width
 *
 * Twig:
 *   image_sources(image, opts)   — srcset data for a responsive <picture> (see macros.twig)
 *   |toavif                      — convert/lookup AVIF for this image
 *   |avif_src(w, h)              — resize + AVIF lookup/conversion
 *   |webp_src(w, h)              — resize + WebP lookup
 *   |best_src(w, h)              — best available: AVIF > WebP > original
 *
 * Timber Image properties (via AVIFImage):
 *   image.avif / image.webp / image.best
 *
 * Admin:
 *   Settings → Timber AVIF (settings, tools, statistics, logs)
 *
 * WP-CLI:
 *   wp timber-avif detect        — show available conversion engines
 *   wp timber-avif bulk          — convert all media library images
 *   wp timber-avif queue         — show/process background queue
 *   wp timber-avif clear-cache   — flush capability caches
 */

use Timber\Image;
use Timber\ImageHelper;

/* ─────────────────────────────────────────────
 * Extended Timber Image with .avif / .webp / .best properties
 * Usage in Twig: {{ image.avif }}, {{ image.webp }}, {{ image.best }}
 * ───────────────────────────────────────────── */
if (class_exists('Timber\\Image') && !class_exists('AVIFImage')) {
	class AVIFImage extends Image {
		public function __get($field) {
			if ($field === 'avif') return TimberAVIF::filter_toavif($this);
			if ($field === 'webp') return TimberAVIF::filter_webp_src($this);
			if ($field === 'best') return TimberAVIF::filter_best_src($this);
			return parent::__get($field);
		}
	}
}

class TimberAVIF {
	const VERSION     = '6.0.0';
	const OPTION_KEY  = 'timber_avif_settings';
	const QUEUE_KEY   = 'timber_avif_queue';
	const LOG_KEY     = 'timber_avif_log';
	const MAX_LOG_ENTRIES = 200;
	const CRON_HOOK   = 'timber_avif_process_queue';
	const CRON_CLEANUP_HOOK = 'timber_avif_cleanup_stale_locks';
	const STALE_LOCK_TIMEOUT = 300;

	// Defaults
	// Quality scales are not comparable across codecs: AVIF 65 already sits above JPEG 95 in perceived quality.
	const DEFAULT_AVIF_QUALITY = 65;
	const DEFAULT_WEBP_QUALITY = 90;
	const DEFAULT_JPEG_QUALITY = 95;
	const MAX_IMAGE_DIMENSION  = 4096;
	const MAX_FILE_SIZE_MB     = 50;

	// One shared set of widths for the whole theme, so variants are reused across modules instead of multiplying per recipe.
	const CANONICAL_WIDTHS = [320, 480, 640, 768, 1024, 1280, 1600, 1920, 2560];

	// Generation ceiling, matching WordPress big_image_size_threshold.
	const MAX_GENERATED_WIDTH = 2560;

	// Candidate cap per image: a single call site must not be able to flood the library.
	const MAX_CANDIDATES = 8;

	// Per-request inline conversion budget.
	// Once exhausted, remaining conversions go to background queue.
	const MAX_INLINE_CONVERSIONS = 10;

	// Failed conversions are remembered for this long to avoid retrying.
	const FAILURE_TTL = DAY_IN_SECONDS;

	// Runtime state
	private static array $settings = [];
	private static array $exists_cache = [];          // path => bool|'converting'
	private static array $conversion_methods = ['avif' => null, 'webp' => null];
	private static int   $inline_budget = self::MAX_INLINE_CONVERSIONS;
	private static array $bg_queue = [];              // jobs for shutdown
	private static bool  $shutdown_registered = false;
	private static ?array $upload_dir_cache = null;

	/* ─────────────────────────────────────────────
	 * Bootstrap
	 * ───────────────────────────────────────────── */

	public static function init(): void {
		self::load_settings();
		self::load_textdomain();

		add_filter('timber/twig', [__CLASS__, 'add_twig_filters']);
		add_filter('timber/image/new_class', function () {
			return class_exists('AVIFImage') ? 'AVIFImage' : 'Timber\\Image';
		});
		add_action('wp_generate_attachment_metadata', [__CLASS__, 'on_upload'], 20, 2);

		// Fallback-format quality. WordPress defaults to 82; forcing 100 doubles file size for no visible gain.
		add_filter('jpeg_quality', fn() => (int) self::setting('jpeg_quality', self::DEFAULT_JPEG_QUALITY));
		add_filter('wp_editor_set_quality', fn($q, $mime) => $mime === 'image/jpeg' ? (int) self::setting('jpeg_quality', self::DEFAULT_JPEG_QUALITY) : $q, 10, 2);

		// Ceiling on the uploaded original: past this width WordPress scales down and keeps the -scaled file.
		add_filter('big_image_size_threshold', fn() => (int) self::setting('max_upload_dimension', 2560));

		// Admin
		add_action('admin_menu', [__CLASS__, 'register_admin_page']);
		add_action('admin_post_timber_avif_tools', [__CLASS__, 'handle_admin_post']);
		add_action('wp_ajax_timber_avif_bulk_batch', [__CLASS__, 'handle_ajax_bulk_batch']);
		add_action('wp_ajax_timber_avif_queue_batch', [__CLASS__, 'handle_ajax_queue_batch']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
		add_action('admin_notices', [__CLASS__, 'admin_notice']);
		add_filter('manage_media_columns', [__CLASS__, 'add_media_column']);
		add_action('manage_media_custom_column', [__CLASS__, 'render_media_column'], 10, 2);
		add_action('admin_head', [__CLASS__, 'media_column_css']);

		// Cron
		add_action(self::CRON_HOOK, [__CLASS__, 'process_cron_queue']);

		// Con DISABLE_WP_CRON l'hook non scatta mai e la coda resta ferma: in admin la si smaltisce
		// a piccoli passi dopo la risposta, dove un rallentamento non si vede.
		if (is_admin() && (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
			add_action('shutdown', [__CLASS__, 'drain_queue_in_admin'], 99);
		}
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
		}
		add_action(self::CRON_CLEANUP_HOOK, [__CLASS__, 'cleanup_stale_locks']);
		if (!wp_next_scheduled(self::CRON_CLEANUP_HOOK)) {
			wp_schedule_event(time(), 'hourly', self::CRON_CLEANUP_HOOK);
		}
		add_action('switch_theme', [__CLASS__, 'deregister_crons']);

		// Detect capabilities once
		add_action('init', function () {
			self::detect_capabilities('avif');
			self::detect_capabilities('webp');
		});

		if (defined('WP_CLI') && WP_CLI) {
			self::register_cli();
		}
	}

	/**
	 * Admin strings ship in English and a .mo translates them.
	 * Looked up in the theme's languages/ folder first, then next to this file.
	 * Nothing to install: without a .mo the UI stays in English.
	 */
	private static function load_textdomain(): void {
		if (!is_admin()) return;

		$file = 'timber-avif-' . determine_locale() . '.mo';

		foreach ([get_stylesheet_directory(), get_template_directory(), __DIR__] as $dir) {
			$mofile = $dir . '/languages/' . $file;
			if (is_readable($mofile)) {
				load_textdomain('timber-avif', $mofile);
				return;
			}
		}
	}

	private static function load_settings(): void {
		$defaults = [
			'avif_quality'            => self::DEFAULT_AVIF_QUALITY,
			'webp_quality'            => self::DEFAULT_WEBP_QUALITY,
			'only_if_smaller'         => true,
			'max_dimension'           => self::MAX_IMAGE_DIMENSION,
			'max_file_size'           => self::MAX_FILE_SIZE_MB,
			'max_inline_conversions'  => self::MAX_INLINE_CONVERSIONS,
			'jpeg_quality'            => self::DEFAULT_JPEG_QUALITY,
			'format_mode'             => 'auto',
			'pregenerate_breakpoints' => true,
			'breakpoint_widths'       => implode(',', self::CANONICAL_WIDTHS),
			'pregenerate_widths'      => '640,1024,1600,1920',
			'max_upload_dimension'    => 2560,
		];
		$saved = get_option(self::OPTION_KEY, []);
		self::$settings = wp_parse_args($saved, $defaults);
		self::$inline_budget = (int) self::setting('max_inline_conversions', self::MAX_INLINE_CONVERSIONS);
		if (empty($saved)) {
			update_option(self::OPTION_KEY, self::$settings);
		}
	}

	private static function setting(string $key, $default = null) {
		return self::$settings[$key] ?? $default;
	}

	private static function upload_dir(): array {
		if (self::$upload_dir_cache === null) {
			self::$upload_dir_cache = wp_upload_dir();
		}
		return self::$upload_dir_cache;
	}

	/* ─────────────────────────────────────────────
	 * Twig Filters
	 * ───────────────────────────────────────────── */

	public static function add_twig_filters($twig) {
		$twig->addFilter(new \Twig\TwigFilter('toavif', [__CLASS__, 'filter_toavif']));
		$twig->addFilter(new \Twig\TwigFilter('avif_src', [__CLASS__, 'filter_avif_src']));
		$twig->addFilter(new \Twig\TwigFilter('webp_src', [__CLASS__, 'filter_webp_src']));
		$twig->addFilter(new \Twig\TwigFilter('best_src', [__CLASS__, 'filter_best_src']));
		$twig->addFunction(new \Twig\TwigFunction('avif_src', [__CLASS__, 'filter_avif_src']));
		$twig->addFunction(new \Twig\TwigFunction('webp_src', [__CLASS__, 'filter_webp_src']));
		$twig->addFunction(new \Twig\TwigFunction('best_src', [__CLASS__, 'filter_best_src']));
		$twig->addFunction(new \Twig\TwigFunction('image_sources', [__CLASS__, 'image_sources']));
		return $twig;
	}

	/**
	 * |toavif — Get or create AVIF version of this image.
	 */
	public static function filter_toavif($src): string {
		$url = self::extract_url($src);
		if (!$url) return '';
		return self::get_or_create_sibling($url, 'avif');
	}

	/**
	 * |avif_src(width, height) — Resize via Timber, then get/create AVIF.
	 * Accepts float from Twig math operations.
	 */
	public static function filter_avif_src($src, $width = null, $height = null): string {
		$url = self::extract_url($src);
		if (!$url) return '';

		$width  = $width  !== null ? (int) $width  : null;
		$height = $height !== null ? (int) $height : null;

		if ($width || $height) {
			$url = self::timber_resize($url, $width, $height);
		}

		return self::get_or_create_sibling($url, 'avif');
	}

	/**
	 * |webp_src(width, height) — Resize via Timber, then get/create WebP.
	 * Checks for sibling .webp first, falls back to Timber's native towebp.
	 */
	public static function filter_webp_src($src, $width = null, $height = null): string {
		$url = self::extract_url($src);
		if (!$url) return '';

		$width  = $width  !== null ? (int) $width  : null;
		$height = $height !== null ? (int) $height : null;

		if ($width || $height) {
			$url = self::timber_resize($url, $width, $height);
		}

		// Fast check: does .webp sibling already exist?
		$webp = self::check_sibling_exists($url, 'webp');
		if ($webp) return $webp;

		// Fallback: Timber's native WebP conversion (fast, has its own cache)
		try {
			if (class_exists('Timber\\ImageHelper')) {
				$result = ImageHelper::img_to_webp($url, (int) self::setting('webp_quality', self::DEFAULT_WEBP_QUALITY));
				if ($result && $result !== $url) return $result;
			}
		} catch (\Throwable $e) {
			self::log('Timber towebp failed: ' . $e->getMessage(), 'warning');
		}

		// Last resort: try our own conversion
		return self::get_or_create_sibling($url, 'webp');
	}

	/**
	 * |best_src(width, height) — Returns best available: AVIF > WebP > original.
	 */
	public static function filter_best_src($src, $width = null, $height = null): string {
		$url = self::extract_url($src);
		if (!$url) return '';

		$width  = $width  !== null ? (int) $width  : null;
		$height = $height !== null ? (int) $height : null;

		if ($width || $height) {
			$url = self::timber_resize($url, $width, $height);
		}

		// Try AVIF
		$avif = self::get_or_create_sibling($url, 'avif');
		if ($avif !== $url) return $avif;

		// Try WebP
		return self::filter_webp_src($url);
	}

	/* ─────────────────────────────────────────────
	 * Responsive sources
	 *
	 * Builds everything <picture> needs in one pass: fallback srcset, modern-format
	 * srcset, and width/height attributes. Widths come from one shared canonical set.
	 * ───────────────────────────────────────────── */

	/**
	 * Modern format to serve, resolved once per request from what this server can encode.
	 */
	public static function modern_format(): ?string {
		static $resolved = null;
		if ($resolved !== null) return $resolved ?: null;

		$mode = self::setting('format_mode', 'auto');
		if ($mode === 'off') return ($resolved = '') ? null : null;

		if ($mode === 'avif' || $mode === 'webp') {
			$resolved = (self::detect_capabilities($mode) !== 'none') ? $mode : '';
			return $resolved ?: null;
		}

		if (self::detect_capabilities('avif') !== 'none')      $resolved = 'avif';
		elseif (self::detect_capabilities('webp') !== 'none')  $resolved = 'webp';
		else                                                   $resolved = '';

		return $resolved ?: null;
	}

	/**
	 * Candidate widths for an image: canonical (or custom) set, never beyond the original.
	 */
	public static function candidate_widths(int $original_width, array $custom = [], ?int $max = null): array {
		$widths = $custom ?: self::canonical_widths();

		$ceiling = min($original_width, self::MAX_GENERATED_WIDTH);
		if ($max) $ceiling = min($ceiling, $max);

		$widths = array_values(array_unique(array_filter(array_map('intval', $widths), fn($w) => $w > 0 && $w <= $ceiling)));
		sort($widths);

		// An image smaller than every candidate is still served at its real size.
		if (!$widths) return [$ceiling];

		// The full-size candidate covers high-density screens.
		if (end($widths) < $ceiling) $widths[] = $ceiling;

		// Past the cap, thin out evenly while always keeping the smallest and largest.
		$count = count($widths);
		if ($count > self::MAX_CANDIDATES) {
			$keep = [];
			$step = ($count - 1) / (self::MAX_CANDIDATES - 1);
			for ($i = 0; $i < self::MAX_CANDIDATES; $i++) $keep[] = $widths[(int) round($i * $step)];
			$widths = array_values(array_unique($keep));
		}

		return $widths;
	}

	private static function canonical_widths(): array {
		$raw = (string) self::setting('breakpoint_widths', '');
		$widths = array_filter(array_map('intval', array_map('trim', explode(',', $raw))));
		return $widths ?: self::CANONICAL_WIDTHS;
	}

	/**
	 * Data for a responsive <picture>. See the image() macro in partial/macros.twig.
	 *
	 * $opts: widths (array), max (int), ratio (float|'16/9')
	 */
	public static function image_sources($src, array $opts = []): array {
		$empty = ['ok' => false, 'src' => '', 'srcset' => '', 'width' => null, 'height' => null, 'modern' => null];

		$url = self::extract_url($src);
		if (!$url) return $empty;

		[$ow, $oh] = self::source_dimensions($src, $url);
		if (!$ow) return array_merge($empty, ['ok' => true, 'src' => $url]);

		$ratio = self::parse_ratio($opts['ratio'] ?? null) ?: ($oh ? $ow / $oh : null);

		$widths = self::candidate_widths($ow, (array) ($opts['widths'] ?? []), isset($opts['max']) ? (int) $opts['max'] : null);
		$modern = self::modern_format();

		$fallback = [];
		$modern_set = [];
		foreach ($widths as $w) {
			$h = ($ratio && !empty($opts['ratio'])) ? (int) round($w / $ratio) : null;

			$resized = self::timber_resize($url, $w, $h);
			$fallback[] = $resized . ' ' . $w . 'w';

			if ($modern) {
				$sibling = self::get_or_create_sibling($resized, $modern);
				if ($sibling !== $resized) $modern_set[] = $sibling . ' ' . $w . 'w';
			}
		}

		// Fallback src: the candidate closest to 1024, where most viewports land.
		$base = $widths[0];
		foreach ($widths as $w) {
			if (abs($w - 1024) < abs($base - 1024)) $base = $w;
		}

		return [
			'ok'     => true,
			'src'    => self::timber_resize($url, $base, ($ratio && !empty($opts['ratio'])) ? (int) round($base / $ratio) : null),
			'srcset' => implode(', ', $fallback),
			'width'  => $base,
			'height' => $ratio ? (int) round($base / $ratio) : null,
			'modern' => $modern_set ? ['type' => 'image/' . $modern, 'srcset' => implode(', ', $modern_set)] : null,
		];
	}

	private static function source_dimensions($src, string $url): array {
		if (is_object($src) && method_exists($src, 'width')) {
			$w = (int) $src->width();
			$h = (int) $src->height();
			if ($w) return [$w, $h];
		}

		$path = self::url_to_path($url);
		if ($path && file_exists($path)) {
			$info = @getimagesize($path);
			if ($info) return [(int) $info[0], (int) $info[1]];
		}

		return [0, 0];
	}

	private static function parse_ratio($ratio): ?float {
		if (is_numeric($ratio)) return (float) $ratio ?: null;
		if (is_string($ratio) && str_contains($ratio, '/')) {
			[$w, $h] = array_map('trim', explode('/', $ratio, 2));
			return ($w > 0 && $h > 0) ? ((float) $w / (float) $h) : null;
		}
		return null;
	}

	/* ─────────────────────────────────────────────
	 * Core: Get or Create Sibling
	 *
	 * 1. Static cache check (free)
	 * 2. file_exists check (fast)
	 * 3. If missing & budget > 0: convert inline
	 * 4. If missing & budget exhausted: queue for background
	 * 5. Return sibling URL or original URL
	 * ───────────────────────────────────────────── */

	private static function get_or_create_sibling(string $url, string $format): string {
		$path = self::url_to_path($url);
		if (!$path) return $url;

		$sibling = self::sibling_path($path, $format);
		if (!$sibling) return $url;

		// 1. Static cache
		if (isset(self::$exists_cache[$sibling])) {
			return self::$exists_cache[$sibling] ? self::path_to_url($sibling) : $url;
		}

		// 2. File exists on disk?
		if (file_exists($sibling) && filesize($sibling) > 0) {
			self::$exists_cache[$sibling] = true;
			return self::path_to_url($sibling);
		}

		// 3. Source file must exist
		if (!file_exists($path)) {
			self::$exists_cache[$sibling] = false;
			return $url;
		}

		// 3b. Skip if this conversion previously failed (cached for 24h)
		if (self::is_failed($sibling, $format)) {
			self::$exists_cache[$sibling] = false;
			return $url;
		}

		// 4. Can we convert inline?
		if (self::$inline_budget > 0) {
			self::$inline_budget--;
			$success = self::convert_file($path, $format);
			self::$exists_cache[$sibling] = $success;
			if (!$success) {
				self::remember_failure($sibling, $format);
			}
			return $success ? self::path_to_url($sibling) : $url;
		}

		// 5. Budget exhausted → queue for background
		self::$exists_cache[$sibling] = false;
		self::queue_for_background($path, $format);
		return $url;
	}

	/**
	 * Quick existence check only (no conversion). Used for WebP before Timber fallback.
	 */
	private static function check_sibling_exists(string $url, string $format): ?string {
		$path = self::url_to_path($url);
		if (!$path) return null;

		$sibling = self::sibling_path($path, $format);
		if (!$sibling) return null;

		if (isset(self::$exists_cache[$sibling])) {
			return self::$exists_cache[$sibling] ? self::path_to_url($sibling) : null;
		}

		if (file_exists($sibling) && filesize($sibling) > 0) {
			self::$exists_cache[$sibling] = true;
			return self::path_to_url($sibling);
		}

		return null;
	}

	/**
	 * Derive sibling path: /path/to/image.jpg → /path/to/image.avif
	 */
	private static function sibling_path(string $path, string $format): ?string {
		$ext = ($format === 'webp') ? 'webp' : 'avif';
		$new = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '.' . $ext, $path);
		return ($new && $new !== $path) ? $new : null;
	}

	/* ─────────────────────────────────────────────
	 * URL/Path Resolution
	 * ───────────────────────────────────────────── */

	private static function extract_url($src): string {
		if ($src instanceof \Timber\Image) {
			return (string) ($src->src ?? $src->src() ?? '');
		}
		if (is_object($src) && method_exists($src, '__toString')) {
			return (string) $src;
		}
		if (is_string($src)) {
			return $src;
		}
		return '';
	}

	private static function url_to_path(string $url): ?string {
		$upload = self::upload_dir();
		if (str_starts_with($url, $upload['baseurl'])) {
			return str_replace($upload['baseurl'], $upload['basedir'], $url);
		}
		$theme_url = get_template_directory_uri();
		if (str_starts_with($url, $theme_url)) {
			return str_replace($theme_url, get_template_directory(), $url);
		}
		return null;
	}

	private static function path_to_url(string $path): string {
		$upload = self::upload_dir();
		if (str_starts_with($path, $upload['basedir'])) {
			return str_replace($upload['basedir'], $upload['baseurl'], $path);
		}
		$theme_dir = get_template_directory();
		if (str_starts_with($path, $theme_dir)) {
			return str_replace($theme_dir, get_template_directory_uri(), $path);
		}
		return $path;
	}

	private static function timber_resize(string $url, ?int $width, ?int $height): string {
		if (!$width && !$height) return $url;

		// Timber upscales silently: a candidate wider than the original would be heavier and blurrier than the original itself.
		$path = self::url_to_path($url);
		if ($path && file_exists($path)) {
			$info = @getimagesize($path);
			if ($info && $width && $width >= (int) $info[0]) return $url;
		}

		try {
			if (class_exists('Timber\\ImageHelper')) {
				$resized = ImageHelper::resize($url, $width, $height ?: 0);
				return $resized ?: $url;
			}
		} catch (\Throwable $e) {
			// ignore
		}
		return $url;
	}

	/* ─────────────────────────────────────────────
	 * Background Queue (overflow from inline budget)
	 * ───────────────────────────────────────────── */

	private static function queue_for_background(string $source_path, string $format): void {
		$key = $source_path . ':' . $format;

		// Add to shutdown queue (processed after response sent)
		self::$bg_queue[$key] = ['path' => $source_path, 'format' => $format];

		if (!self::$shutdown_registered) {
			self::$shutdown_registered = true;
			register_shutdown_function([__CLASS__, 'process_shutdown_queue']);
		}
	}

	public static function process_shutdown_queue(): void {
		if (empty(self::$bg_queue)) return;

		// Flush response first
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} elseif (function_exists('litespeed_finish_request')) {
			litespeed_finish_request();
		}

		$limit = 10; // max shutdown conversions
		$done = 0;
		foreach (self::$bg_queue as $job) {
			if ($done >= $limit) {
				// Overflow to persistent cron queue
				self::add_to_cron_queue($job['path'], $job['format']);
				continue;
			}
			self::convert_file($job['path'], $job['format']);
			$done++;
		}
		self::$bg_queue = [];
	}

	/**
	 * Smaltisce qualche job in coda a una richiesta admin. Serve dove wp-cron e disattivato.
	 */
	public static function drain_queue_in_admin(): void {
		if (empty(get_option(self::QUEUE_KEY, []))) return;

		if (function_exists('fastcgi_finish_request'))        fastcgi_finish_request();
		elseif (function_exists('litespeed_finish_request'))  litespeed_finish_request();

		self::process_cron_queue();
	}

	private static function add_to_cron_queue(string $path, string $format): void {
		$queue = get_option(self::QUEUE_KEY, []);
		$key = md5($path . ':' . $format);
		if (isset($queue[$key])) return;

		$queue[$key] = ['path' => $path, 'format' => $format, 'added' => time()];

		if (count($queue) > 500) {
			$queue = array_slice($queue, -500, null, true);
		}

		update_option(self::QUEUE_KEY, $queue, false);

		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time(), self::CRON_HOOK);
		}
	}

	public static function process_cron_queue(): void {
		$queue = get_option(self::QUEUE_KEY, []);
		if (empty($queue)) return;

		$batch = 20;
		$done = 0;
		foreach ($queue as $key => $job) {
			if ($done >= $batch) break;
			// Un sorgente sparito nel frattempo si scarta: ritentarlo a ogni giro e lavoro a vuoto.
			if (file_exists($job['path'])) {
				self::convert_file($job['path'], $job['format']);
				$done++;
			}
			unset($queue[$key]);
		}

		if (empty($queue)) {
			delete_option(self::QUEUE_KEY);
		} else {
			update_option(self::QUEUE_KEY, $queue, false);
			if (!wp_next_scheduled(self::CRON_HOOK)) {
				wp_schedule_single_event(time() + 30, self::CRON_HOOK);
			}
		}
	}

	/* ─────────────────────────────────────────────
	 * Actual Conversion
	 * ───────────────────────────────────────────── */

	private static function convert_file(string $source_path, string $format, bool $clear_failures = false): bool {
		if (!file_exists($source_path)) {
			self::add_log($source_path, $format, 'failed', 'Source file not found');
			return false;
		}

		$method = self::detect_capabilities($format);
		if ($method === 'none') {
			self::add_log($source_path, $format, 'failed', 'No ' . strtoupper($format) . ' engine available');
			return false;
		}

		$dest = self::sibling_path($source_path, $format);
		if (!$dest) return false;

		// Already done
		if (file_exists($dest) && filesize($dest) > 0) {
			return true;
		}

		// Clear failure cache when explicitly retrying (bulk convert, CLI)
		if ($clear_failures) {
			self::clear_failure($dest, $format);
		}

		// Size/dimension guards
		$size_mb = filesize($source_path) / 1024 / 1024;
		if ($size_mb > self::setting('max_file_size', self::MAX_FILE_SIZE_MB)) {
			self::add_log($source_path, $format, 'skipped', sprintf('File too large (%.1f MB > %d MB limit)', $size_mb, self::setting('max_file_size')));
			return false;
		}

		$info = @getimagesize($source_path);
		if (!$info) {
			self::add_log($source_path, $format, 'failed', 'Cannot read image dimensions (corrupt or unsupported)');
			return false;
		}

		// Converting an animated GIF drops the animation and keeps the first frame.
		if (($info[2] ?? 0) === IMAGETYPE_GIF && self::is_animated_gif($source_path)) {
			self::add_log($source_path, $format, 'skipped', 'Animated GIF');
			return false;
		}

		$max_dim = (int) self::setting('max_dimension', self::MAX_IMAGE_DIMENSION);
		if ($info[0] > $max_dim || $info[1] > $max_dim) {
			self::add_log($source_path, $format, 'skipped', sprintf('Dimensions too large (%dx%d > %dpx limit)', $info[0], $info[1], $max_dim));
			return false;
		}

		// Lock
		$lock_file = $dest . '.lock';
		if (file_exists($lock_file) && (time() - filemtime($lock_file)) < self::STALE_LOCK_TIMEOUT) {
			return false; // another process is working on it, no log needed
		}

		$lock = @fopen($lock_file, 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			if ($lock) fclose($lock);
			return false;
		}

		$quality = ($format === 'webp')
			? (int) self::setting('webp_quality', self::DEFAULT_WEBP_QUALITY)
			: (int) self::setting('avif_quality', self::DEFAULT_AVIF_QUALITY);

		$skipped_larger = false;
		try {
			$ok = self::perform_conversion($source_path, $dest, $quality, $format, $method);

			if ($ok) {
				if (!self::is_valid_file($dest, $format)) {
					@unlink($dest);
					$ok = false;
					self::add_log($source_path, $format, 'failed', 'Output file invalid (corrupt header)');
				} elseif (self::setting('only_if_smaller', true) && filesize($dest) >= filesize($source_path)) {
					$orig_kb = round(filesize($source_path) / 1024);
					$dest_kb = round(filesize($dest) / 1024);
					@unlink($dest);
					$ok = false;
					$skipped_larger = true;
					self::add_log($source_path, $format, 'skipped', sprintf('Converted file larger than original (%d KB → %d KB)', $orig_kb, $dest_kb));
				}
			} else {
				self::add_log($source_path, $format, 'failed', 'Engine returned false (' . $method . ')');
			}
		} catch (\Throwable $e) {
			self::log("Conversion error [{$format}]: " . $e->getMessage(), 'error');
			self::add_log($source_path, $format, 'error', $e->getMessage());
			$ok = false;
		}

		flock($lock, LOCK_UN);
		fclose($lock);
		@unlink($lock_file);

		if ($ok) {
			self::clear_failure($dest, $format);
		} elseif ($skipped_larger) {
			self::remember_failure($dest, $format);
		}

		return $ok;
	}

	/* ─────────────────────────────────────────────
	 * Conversion Engines
	 * ───────────────────────────────────────────── */

	public static function detect_capabilities(string $format = 'avif'): string {
		$format = ($format === 'webp') ? 'webp' : 'avif';

		if (self::$conversion_methods[$format] !== null) {
			return self::$conversion_methods[$format];
		}

		// Key includes the SAPI: wp-cli, php-fpm and cron can be different PHP builds with different extensions.
		// With a shared key, the CLI read "imagick" written by Apache and every conversion failed for no apparent reason.
		$cache_key = 'timber_avif_cap_' . $format . '_' . self::runtime_key();
		$cached = get_transient($cache_key);
		if ($cached !== false && self::method_available($cached, $format)) {
			self::$conversion_methods[$format] = $cached;
			return $cached;
		}

		$method = 'none';
		$fn_check = ($format === 'avif') ? 'imageavif' : 'imagewebp';

		if (function_exists($fn_check) && self::test_gd($format))              $method = 'gd';
		elseif (extension_loaded('imagick') && self::test_imagick($format))    $method = 'imagick';
		elseif (self::is_exec_available() && self::test_exec($format))         $method = 'exec';

		set_transient($cache_key, $method, WEEK_IN_SECONDS);
		self::$conversion_methods[$format] = $method;
		self::log("Detected {$format} engine: {$method}", 'info');
		return $method;
	}

	private static function runtime_key(): string {
		return php_sapi_name() . '_' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
	}

	/**
	 * A cached engine is only valid if the extension is still present in this process.
	 */
	private static function method_available(string $method, string $format): bool {
		return match ($method) {
			'gd'      => function_exists($format === 'avif' ? 'imageavif' : 'imagewebp'),
			'imagick' => extension_loaded('imagick'),
			'exec'    => self::is_exec_available(),
			'none'    => true,
			default   => false,
		};
	}

	private static function perform_conversion(string $src, string $dst, int $quality, string $format, string $method): bool {
		return match ($method) {
			'gd'      => self::convert_gd($src, $dst, $quality, $format),
			'imagick' => self::convert_imagick($src, $dst, $quality, $format),
			'exec'    => self::convert_exec($src, $dst, $quality, $format),
			default   => false,
		};
	}

	private static function convert_gd(string $src, string $dst, int $quality, string $format): bool {
		try {
			$type = @exif_imagetype($src);
			if (!$type) return false;

			// Memory guard
			$info = @getimagesize($src);
			if ($info) {
				$ch = ($type === IMAGETYPE_PNG) ? 4 : 3;
				$needed = (int) ceil($info[0] * $info[1] * $ch * 1.5) + 32 * 1024 * 1024;
				$limit = self::parse_memory_limit(ini_get('memory_limit'));
				if ($limit > 0 && $limit < $needed) {
					@ini_set('memory_limit', ceil($needed / 1024 / 1024) . 'M');
				}
			}

			$image = match ($type) {
				IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
				IMAGETYPE_PNG  => @imagecreatefrompng($src),
				IMAGETYPE_WEBP => @imagecreatefromwebp($src),
				IMAGETYPE_GIF  => @imagecreatefromgif($src),
				default        => null,
			};
			if (!$image) return false;

			if ($type === IMAGETYPE_PNG) {
				imagealphablending($image, false);
				imagesavealpha($image, true);
			}

			$ok = ($format === 'webp') ? @imagewebp($image, $dst, $quality) : @imageavif($image, $dst, $quality);
			imagedestroy($image);
			return (bool) $ok;
		} catch (\Throwable $e) {
			self::log("GD error: " . $e->getMessage(), 'error');
			return false;
		}
	}

	private static function convert_imagick(string $src, string $dst, int $quality, string $format): bool {
		try {
			$im = new \Imagick($src);
			$im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
			$im->setResourceLimit(\Imagick::RESOURCETYPE_TIME, 60);
			$im->setImageFormat($format);
			// setCompressionQuality writes to image_info->quality, which is what the coder reads.
			// setImageCompressionQuality writes to image->quality and AVIF ignores it: quality would stay at the libheif default.
			$im->setCompressionQuality($quality);
			$im->setImageCompressionQuality($quality);
			$im->stripImage();
			$ok = $im->writeImage($dst);
			$im->clear();
			$im->destroy();
			return (bool) $ok;
		} catch (\Throwable $e) {
			self::log("Imagick error: " . $e->getMessage(), 'error');
			return false;
		}
	}

	private static function convert_exec(string $src, string $dst, int $quality, string $format): bool {
		$s = escapeshellarg($src);
		$d = escapeshellarg($dst);
		$q = intval($quality);

		@exec("magick {$s} -quality {$q} {$d} 2>&1", $out, $ret);
		if ($ret === 0 && file_exists($dst) && filesize($dst) > 0) return true;

		@exec("convert {$s} -quality {$q} {$d} 2>&1", $out, $ret);
		return $ret === 0 && file_exists($dst) && filesize($dst) > 0;
	}

	/* ─────────────────────────────────────────────
	 * Capability Tests
	 * ───────────────────────────────────────────── */

	private static function test_gd(string $format): bool {
		try {
			$img = @imagecreatetruecolor(1, 1);
			if (!$img) return false;
			ob_start();
			$ok = ($format === 'webp') ? @imagewebp($img, null, 80) : @imageavif($img, null, 80);
			ob_end_clean();
			imagedestroy($img);
			return $ok !== false;
		} catch (\Throwable $e) { return false; }
	}

	private static function test_imagick(string $format): bool {
		try {
			if (empty(\Imagick::queryFormats(strtoupper($format)))) return false;
			$im = new \Imagick();
			$im->newImage(1, 1, 'white');
			$im->setImageFormat($format);
			$blob = $im->getImageBlob();
			$im->clear();
			return !empty($blob);
		} catch (\Throwable $e) { return false; }
	}

	private static function test_exec(string $format): bool {
		if (!self::is_exec_available()) return false;
		$src = tempnam(sys_get_temp_dir(), 'tavif_') . '.png';
		$dst = tempnam(sys_get_temp_dir(), 'tavif_') . '.' . $format;
		$img = @imagecreatetruecolor(1, 1);
		if (!$img) return false;
		@imagepng($img, $src);
		imagedestroy($img);

		$s = escapeshellarg($src);
		$d = escapeshellarg($dst);
		@exec("magick {$s} {$d} 2>&1", $out, $ret);
		if ($ret !== 0) @exec("convert {$s} {$d} 2>&1", $out, $ret);
		$ok = $ret === 0 && file_exists($dst) && filesize($dst) > 0;

		@unlink($src);
		@unlink($dst);
		return $ok;
	}

	private static function is_exec_available(): bool {
		if (!function_exists('exec')) return false;
		$disabled = array_map('trim', explode(',', ini_get('disable_functions')));
		return !in_array('exec', $disabled, true);
	}

	/* ─────────────────────────────────────────────
	 * Helpers
	 * ───────────────────────────────────────────── */

	private static function is_animated_gif(string $path): bool {
		$fh = @fopen($path, 'rb');
		if (!$fh) return false;
		$frames = 0;
		$buffer = '';
		while (!feof($fh) && $frames < 2) {
			$buffer = substr($buffer, -1) . fread($fh, 8192);
			$frames += preg_match_all('/\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)/s', $buffer);
		}
		fclose($fh);
		return $frames > 1;
	}

	private static function is_valid_file(string $path, string $format): bool {
		if (!file_exists($path) || filesize($path) < 50) return false;
		$h = file_get_contents($path, false, null, 0, 16);
		if ($format === 'avif') return str_contains($h, 'ftyp') && str_contains($h, 'avif');
		return str_contains($h, 'WEBP');
	}

	/**
	 * Remember a failed conversion so we don't retry for 24h.
	 * Stolen from Codex's solution — smart optimization.
	 */
	/**
	 * Quality and engine fingerprint, so changing settings retires stale failures.
	 */
	private static function failure_key(string $dest_path, string $format): string {
		$fingerprint = implode('|', [
			$format,
			self::setting($format . '_quality'),
			self::$conversion_methods[$format] ?? '',
			self::runtime_key(),
			$dest_path,
		]);
		return 'tavif_fail_' . md5($fingerprint);
	}

	private static function remember_failure(string $dest_path, string $format): void {
		set_transient(self::failure_key($dest_path, $format), 1, self::FAILURE_TTL);
	}

	private static function is_failed(string $dest_path, string $format): bool {
		return (bool) get_transient(self::failure_key($dest_path, $format));
	}

	private static function clear_failure(string $dest_path, string $format): void {
		delete_transient(self::failure_key($dest_path, $format));
	}

	private static function parse_memory_limit(string $limit): int {
		$limit = trim($limit);
		if ($limit === '-1') return -1;
		$last = strtolower($limit[strlen($limit) - 1]);
		$val = (int) $limit;
		return match ($last) {
			'g' => $val * 1024 * 1024 * 1024,
			'm' => $val * 1024 * 1024,
			'k' => $val * 1024,
			default => $val,
		};
	}

	private static function log(string $msg, string $level = 'debug'): void {
		if (!defined('WP_DEBUG') || !WP_DEBUG) return;
		error_log("[TimberAVIF][" . strtoupper($level) . "] {$msg}");
	}

	/**
	 * Structured log entry for the admin Logs tab.
	 * @param string $status  'converted' | 'skipped' | 'failed' | 'error' | 'queued'
	 */
	private static function add_log(string $file, string $format, string $status, string $reason = ''): void {
		$logs = get_option(self::LOG_KEY, []);
		if (!is_array($logs)) $logs = [];

		// Make path relative for readability
		$upload = self::upload_dir();
		$display = str_starts_with($file, $upload['basedir'])
			? str_replace($upload['basedir'] . '/', '', $file)
			: basename($file);

		array_unshift($logs, [
			'time'   => time(),
			'file'   => $display,
			'format' => $format,
			'status' => $status,
			'reason' => $reason,
		]);

		// Cap log size
		if (count($logs) > self::MAX_LOG_ENTRIES) {
			$logs = array_slice($logs, 0, self::MAX_LOG_ENTRIES);
		}

		update_option(self::LOG_KEY, $logs, false);
	}

	public static function clear_logs(): void {
		delete_option(self::LOG_KEY);
	}

	/* ─────────────────────────────────────────────
	 * Upload Hook
	 * ───────────────────────────────────────────── */

	public static function on_upload(array $metadata, int $attachment_id): array {
		$file = get_attached_file($attachment_id);
		if (!$file || !file_exists($file)) return $metadata;

		// Si genera il formato che il sito serve davvero, deciso una volta da format_mode.
		$modern = self::modern_format();
		if (!$modern) return $metadata;

		$paths = [$file];
		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			$dir = dirname($file);
			foreach ($metadata['sizes'] as $size) {
				if (!empty($size['file'])) $paths[] = $dir . '/' . $size['file'];
			}
		}

		// The most-used widths are built here, so the front end converts nothing on first load.
		if (self::setting('pregenerate_breakpoints', true)) {
			foreach (self::pregenerate_paths($file) as $p) $paths[] = $p;
		}

		// Convert directly on upload (runs in admin context, acceptable overhead)
		foreach ($paths as $p) {
			if (file_exists($p)) self::convert_file($p, $modern);
		}

		return $metadata;
	}

	/**
	 * Builds the resizes for the most-used widths and returns their paths.
	 * The rest stay with the front end, on the inline budget and the queue.
	 */
	private static function pregenerate_paths(string $file): array {
		$info = @getimagesize($file);
		if (!$info) return [];

		$raw = (string) self::setting('pregenerate_widths', '');
		$widths = array_filter(array_map('intval', array_map('trim', explode(',', $raw))));
		if (!$widths) return [];

		$url = self::path_to_url($file);
		$paths = [];
		foreach (self::candidate_widths((int) $info[0], $widths) as $w) {
			$resized = self::timber_resize($url, $w, null);
			if ($resized === $url) continue;
			$path = self::url_to_path($resized);
			if ($path && file_exists($path)) $paths[] = $path;
		}

		return $paths;
	}

	/* ─────────────────────────────────────────────
	 * Admin Page
	 * ───────────────────────────────────────────── */

	public static function register_admin_page(): void {
		add_options_page('Timber AVIF', 'Timber AVIF', 'manage_options', 'timber-avif-settings', [__CLASS__, 'render_admin_page']);
	}

	public static function admin_notice(): void {
		if (self::detect_capabilities('avif') !== 'none') return;
		if (!current_user_can('manage_options')) return;
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Timber AVIF:</strong> ' . esc_html__('this server cannot generate AVIF. Images are served as WebP or in their original format.', 'timber-avif') . '</p></div>';
	}

	public static function render_admin_page(): void {
		if (!current_user_can('manage_options')) return;

		$settings = self::$settings;
		$tab = sanitize_key($_GET['tab'] ?? 'settings');
		$base_url = admin_url('options-general.php?page=timber-avif-settings');
		$avif_method = self::detect_capabilities('avif');
		$webp_method = self::detect_capabilities('webp');
		$method_labels = ['gd' => 'GD', 'imagick' => 'ImageMagick', 'exec' => 'ImageMagick CLI', 'none' => __('Not available', 'timber-avif')];
		$queue_count = count(get_option(self::QUEUE_KEY, []));
		$log_count   = count(get_option(self::LOG_KEY, []));
		?>
		<style>
			.tavif-wrap{max-width:860px}.tavif-header{display:flex;align-items:center;gap:12px;margin-bottom:4px}.tavif-header h1{margin:0;padding:0;line-height:1.2}.tavif-version{font-size:11px;color:#646970;background:#f0f0f1;padding:2px 8px;border-radius:10px;font-weight:400}.tavif-wrap .nav-tab-wrapper{margin-bottom:0;border-bottom:1px solid #c3c4c7}.tavif-card{background:#fff;border:1px solid #c3c4c7;border-top:0;padding:24px 28px;margin-bottom:20px}.tavif-status+.tavif-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;line-height:1}.tavif-badge--ok{background:#d1fae5;color:#065f46}.tavif-badge--warn{background:#fef3c7;color:#92400e}.tavif-badge--off{background:#f3f4f6;color:#6b7280}.tavif-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}.tavif-dot--ok{background:#10b981}.tavif-dot--warn{background:#f59e0b}.tavif-dot--off{background:#9ca3af}.tavif-toggle{position:relative;display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none}.tavif-toggle input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0}.tavif-toggle .slider{width:40px;height:22px;background:#d1d5db;border-radius:11px;position:relative;transition:background .2s;flex-shrink:0}.tavif-toggle .slider::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.15)}.tavif-toggle input:checked+.slider{background:#2271b1}.tavif-toggle input:checked+.slider::after{transform:translateX(18px)}.tavif-toggle .toggle-label{font-size:13px}.tavif-range-group{margin-bottom:16px}.tavif-range-group label{display:flex;align-items:center;gap:12px;font-weight:500;font-size:13px}.tavif-range-group input[type="range"]{flex:1;max-width:280px;accent-color:#2271b1;height:6px}.tavif-range-group .range-val{display:inline-block;min-width:36px;text-align:center;font-weight:600;font-size:13px;background:#f0f0f1;padding:3px 10px;border-radius:4px;font-variant-numeric:tabular-nums}.tavif-range-group .range-label{min-width:42px}.tavif-field-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:12px}.tavif-field-row label{display:flex;align-items:center;gap:6px;font-size:13px}.tavif-field-row input[type="number"]{width:90px}.tavif-field-row input[type="text"].regular-text{max-width:320px}.tavif-section{margin-bottom:28px}.tavif-section:last-child{margin-bottom:0}.tavif-section h3{font-size:13px;font-weight:600;color:#1d2327;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #e5e7eb;text-transform:uppercase;letter-spacing:.3px}.tavif-tools-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.tavif-tool-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:22px;display:flex;flex-direction:column}.tavif-tool-card h3{margin:0 0 8px;font-size:14px;color:#1d2327}.tavif-tool-card p{color:#6b7280;font-size:13px;margin:0 0 18px;line-height:1.5;flex:1}.tavif-tool-card .button{align-self:flex-start}.tavif-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}.tavif-stat-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:18px;text-align:center}.tavif-stat-card .stat-value{font-size:28px;font-weight:700;color:#1d2327;line-height:1.2;font-variant-numeric:tabular-nums}.tavif-stat-card .stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-top:4px}.tavif-stat-card .stat-sub{font-size:12px;color:#9ca3af;margin-top:2px}.tavif-stat-card--highlight{background:#eff6ff;border-color:#bfdbfe}.tavif-stat-card--highlight .stat-value{color:#1d4ed8}.tavif-stat-card--green{background:#ecfdf5;border-color:#a7f3d0}.tavif-stat-card--green .stat-value{color:#065f46}
			.tavif-badge--fail{background:#fee2e2;color:#991b1b}
			/* Log filters */
			.tavif-log-filters{display:flex;gap:4px;flex-wrap:wrap}
			.tavif-log-filter{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:4px;font-size:13px;text-decoration:none;color:#6b7280;background:#f3f4f6;font-weight:500;transition:all .15s}
			.tavif-log-filter:hover{background:#e5e7eb;color:#374151}
			.tavif-log-filter--active{background:#1d2327;color:#fff}
			.tavif-log-filter--active:hover{background:#1d2327;color:#fff}
			.tavif-log-filter-count{font-size:11px;opacity:.7}
			/* Log table */
			.tavif-log-table{width:100%;border-collapse:collapse;font-size:13px}
			.tavif-log-table th{text-align:left;padding:8px 10px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap}
			.tavif-log-table td{padding:7px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top}
			.tavif-log-table tbody tr:hover{background:#f9fafb}
			.tavif-log-time{font-variant-numeric:tabular-nums;color:#9ca3af;font-size:12px;white-space:nowrap}
			.tavif-log-file{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
			.tavif-log-format{font-size:11px;font-weight:600;color:#6b7280}
			.tavif-log-reason{color:#6b7280;font-size:12px}
			.tavif-log-row--error td{background:#fef2f2}
			.tavif-log-row--failed td{background:#fff7ed}
			/* Card di stato */
			.tavif-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0 20px}
			.tavif-card-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}
			.tavif-card-stat .label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:6px}
			.tavif-card-stat .value{font-size:14px;font-weight:600;color:#1d2327;display:flex;align-items:center;gap:8px}
			.tavif-card-stat .value .dashicons{font-size:16px;width:16px;height:16px;color:#a7aaad;margin-left:-3px;cursor:help}
			.tavif-card-stat .value .dashicons:hover{color:#2271b1}
			.tavif-notice{margin:0 0 20px;padding:10px 14px;background:#fef3c7;border-radius:6px;font-size:13px;color:#92400e;line-height:1.5}
			/* Blocchi */
			.tavif-block{padding-bottom:24px;margin-bottom:24px;border-bottom:1px solid #f0f0f1}
			.tavif-block:last-of-type{border-bottom:0;margin-bottom:8px}
			.tavif-block h3{margin:0 0 4px;font-size:15px;font-weight:600;color:#1d2327;border:0;padding:0;text-transform:none;letter-spacing:0}
			.tavif-block-intro{margin:0 0 18px;font-size:13px;line-height:1.6;color:#646970;max-width:64ch}
			.tavif-block h3+.tavif-field{margin-top:18px}
			/* Campi */
			.tavif-field{display:grid;grid-template-columns:130px 1fr;gap:16px;align-items:start;margin-bottom:16px}
			.tavif-field:last-child{margin-bottom:0}
			.tavif-field.is-muted{opacity:.5}
			.tavif-field-label{font-size:13px;font-weight:600;color:#1d2327;padding-top:5px}
			.tavif-field-input input[type=number]{width:92px}
			.tavif-hint{margin:6px 0 0;font-size:12px;line-height:1.55;color:#787c82;max-width:60ch}
			.tavif-hint--block{margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f1;max-width:none}
			.tavif-field .tavif-toggle{margin-top:10px}
			.tavif-range{display:flex;align-items:center;gap:12px}
			.tavif-range input[type=range]{flex:1;max-width:280px;accent-color:#2271b1;height:6px}
			.tavif-range .range-val{min-width:38px;text-align:center;font-weight:600;font-size:13px;background:#f0f0f1;padding:3px 10px;border-radius:4px;font-variant-numeric:tabular-nums}
			/* Chip delle larghezze */
			.tavif-chips{display:flex;flex-wrap:wrap;gap:6px}
			.tavif-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid #dcdcde;border-radius:20px;font-size:13px;cursor:pointer;background:#fff;color:#646970;font-variant-numeric:tabular-nums;transition:all .12s;user-select:none}
			.tavif-chip:hover{border-color:#8c8f94}
			.tavif-chip input{margin:0;width:14px;height:14px}
			.tavif-chip.is-on{background:#f0f6fc;border-color:#2271b1;color:#1d2327;font-weight:600}
			@media (max-width:782px){.tavif-field{grid-template-columns:1fr;gap:6px}.tavif-field-label{padding-top:0}}
		</style>

		<div class="wrap tavif-wrap">
			<div class="tavif-header">
				<h1>Timber AVIF</h1>
				<span class="tavif-version">v<?php echo self::VERSION; ?></span>
			</div>

			<?php
			$modern   = self::modern_format();
			$widths   = self::canonical_widths();
			$pregen   = array_filter(array_map('intval', array_map('trim', explode(',', (string) ($settings['pregenerate_widths'] ?? '')))));
			$cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
			$cards = [
				[__('Served format', 'timber-avif'), $modern ? strtoupper($modern) : __('Original', 'timber-avif'), $modern ? 'ok' : 'off'],
				[__('Engine', 'timber-avif'), $modern ? self::engine_label(self::detect_capabilities($modern)) : '—', $modern ? 'ok' : 'off'],
				[__('Quality', 'timber-avif'), $modern ? $settings[$modern . '_quality'] . ' · JPEG ' . ($settings['jpeg_quality'] ?? '—') : 'JPEG ' . ($settings['jpeg_quality'] ?? '—'), 'ok'],
				[__('Widths', 'timber-avif'), count($widths) . (!empty($settings['pregenerate_breakpoints']) && $pregen ? ' / ' . count($pregen) : ''), 'ok',
					!empty($settings['pregenerate_breakpoints']) && $pregen
						? sprintf(__('%1$d defined, %2$d built on upload', 'timber-avif'), count($widths), count($pregen))
						: sprintf(__('%d defined, built on first request', 'timber-avif'), count($widths))],
				[__('Queue', 'timber-avif'), $queue_count ? sprintf(_n('%d pending', '%d pending', $queue_count, 'timber-avif'), $queue_count) : __('Empty', 'timber-avif'), $queue_count ? 'warn' : 'ok'],
			];
			?>
			<div class="tavif-cards">
				<?php foreach ($cards as $card) : list($label, $value, $state) = $card; $tip = $card[3] ?? ''; ?>
					<div class="tavif-card-stat">
						<div class="label"><?php echo esc_html($label); ?></div>
						<div class="value">
							<span class="tavif-dot tavif-dot--<?php echo esc_attr($state); ?>"></span><?php echo esc_html($value); ?>
							<?php if ($tip) : ?><span class="dashicons dashicons-info-outline" title="<?php echo esc_attr($tip); ?>"></span><?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ($queue_count && $cron_off) : ?>
				<p class="tavif-notice"><?php esc_html_e('Queued images are converted a few at a time while you work in the admin.', 'timber-avif'); ?></p>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Settings', 'timber-avif'); ?></a>
				<a href="<?php echo esc_url(add_query_arg('tab', 'tools', $base_url)); ?>" class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Tools', 'timber-avif'); ?></a>
				<a href="<?php echo esc_url(add_query_arg('tab', 'statistics', $base_url)); ?>" class="nav-tab <?php echo $tab === 'statistics' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Statistics', 'timber-avif'); ?></a>
				<a href="<?php echo esc_url(add_query_arg('tab', 'logs', $base_url)); ?>" class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Log', 'timber-avif'); ?><?php if ($log_count > 0) echo ' <span class="count">(' . esc_html($log_count) . ')</span>'; ?></a>
			</h2>

			<div class="tavif-card">
			<?php
			if ($tab === 'statistics')  self::render_statistics_tab();
			elseif ($tab === 'tools')   self::render_tools_tab();
			elseif ($tab === 'logs')    self::render_logs_tab();
			else                        self::render_settings_tab($avif_method, $webp_method);
			?>
			</div>

			<?php self::render_admin_notices(); ?>
			<p style="text-align:right;color:#9ca3af;font-size:11px;margin:12px 0 0;">Timber AVIF v<?php echo self::VERSION; ?> &mdash; <a href="https://github.com/zenotds/timber-avif" target="_blank" style="color:#9ca3af;">GitHub</a></p>
		</div>
		<?php
	}

	private static function render_settings_tab(string $avif_method, string $webp_method): void {
		$s = self::$settings;
		$modern = self::modern_format();
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tavif-card">
			<?php wp_nonce_field('timber_avif_settings'); ?>
			<input type="hidden" name="action" value="timber_avif_tools" />
			<input type="hidden" name="subaction" value="save_settings" />
			<input type="hidden" name="tab" value="settings" />

			<div class="tavif-block">
				<h3><?php esc_html_e('Format and quality', 'timber-avif'); ?></h3>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-format"><?php esc_html_e('Format', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<select name="format_mode" id="tavif-format">
							<?php foreach (['auto' => __('Auto', 'timber-avif'), 'avif' => 'AVIF', 'webp' => 'WebP', 'off' => __('None', 'timber-avif')] as $k => $label) : ?>
								<option value="<?php echo esc_attr($k); ?>" <?php selected($s['format_mode'] ?? 'auto', $k); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="tavif-hint"><?php esc_html_e('Served instead of the original, which stays as a fallback.', 'timber-avif'); ?></p>
					</div>
				</div>

				<?php
				$ranges = [
					'avif_quality' => ['AVIF', 1],
					'webp_quality' => ['WebP', 1],
					'jpeg_quality' => ['JPEG', 60],
				];
				foreach ($ranges as $key => [$label, $min]) :
					$val = $s[$key] ?? 80;
					$muted = $modern && $key !== 'jpeg_quality' && $key !== $modern . '_quality'; ?>
					<div class="tavif-field<?php echo $muted ? ' is-muted' : ''; ?>">
						<label class="tavif-field-label" for="tavif-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
						<div class="tavif-field-input">
							<div class="tavif-range">
								<input type="range" id="tavif-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" min="<?php echo (int) $min; ?>" max="100" value="<?php echo esc_attr($val); ?>"
									oninput="this.closest('.tavif-range').querySelector('.range-val').textContent=this.value" />
								<span class="range-val"><?php echo esc_html($val); ?></span>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				<p class="tavif-hint tavif-hint--block"><?php esc_html_e('Quality scales are not comparable across formats: AVIF 75 matches JPEG 95.', 'timber-avif'); ?></p>
			</div>

			<div class="tavif-block">
				<h3><?php esc_html_e('Widths', 'timber-avif'); ?></h3>
				<p class="tavif-block-intro"><?php esc_html_e('The sizes every image is generated in. The browser picks the one that fits the screen.', 'timber-avif'); ?></p>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-widths"><?php esc_html_e('Sizes', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="text" id="tavif-widths" name="breakpoint_widths" value="<?php echo esc_attr($s['breakpoint_widths']); ?>" class="regular-text" />
						<p class="tavif-hint"><?php esc_html_e('Comma-separated. Each size is one more file per image.', 'timber-avif'); ?></p>
					</div>
				</div>

				<div class="tavif-field">
					<label class="tavif-field-label"><?php esc_html_e('On upload', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="hidden" name="pregenerate_widths" id="tavif-pregen" value="<?php echo esc_attr($s['pregenerate_widths'] ?? ''); ?>" />
						<div class="tavif-chips" id="tavif-chips"></div>
						<p class="tavif-hint"><?php esc_html_e('Ticked sizes are built right away. The rest on the first visit to a page that uses them.', 'timber-avif'); ?></p>
						<label class="tavif-toggle">
							<input type="hidden" name="pregenerate_breakpoints" value="0" />
							<input type="checkbox" name="pregenerate_breakpoints" value="1" <?php checked($s['pregenerate_breakpoints']); ?> />
							<span class="slider"></span>
							<span class="toggle-label"><?php esc_html_e('Enabled', 'timber-avif'); ?></span>
						</label>
					</div>
				</div>
			</div>

			<div class="tavif-block">
				<h3><?php esc_html_e('Limits', 'timber-avif'); ?></h3>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-upload"><?php esc_html_e('Upload', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="number" id="tavif-upload" name="max_upload_dimension" value="<?php echo esc_attr($s['max_upload_dimension'] ?? 2560); ?>" min="1024" step="1" /> px
						<p class="tavif-hint"><?php esc_html_e('Wider images are scaled down to this size on arrival.', 'timber-avif'); ?></p>
					</div>
				</div>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-maxdim"><?php esc_html_e('Conversion', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="number" id="tavif-maxdim" name="max_dimension" value="<?php echo esc_attr($s['max_dimension']); ?>" min="512" step="1" /> px
						<input type="number" name="max_file_size" value="<?php echo esc_attr($s['max_file_size']); ?>" min="1" step="1" /> MB
						<p class="tavif-hint"><?php esc_html_e('Past either value the image is left as it is, to avoid exhausting server memory. This also covers files already in the library, which can exceed the upload limit.', 'timber-avif'); ?></p>
					</div>
				</div>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-budget"><?php esc_html_e('Per page', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="number" id="tavif-budget" name="max_inline_conversions" value="<?php echo esc_attr($s['max_inline_conversions'] ?? self::MAX_INLINE_CONVERSIONS); ?>" min="0" max="50" step="1" /> <?php esc_html_e('images', 'timber-avif'); ?>
						<p class="tavif-hint"><?php esc_html_e('How many to convert while the visitor waits. The rest continue in the background.', 'timber-avif'); ?></p>
					</div>
				</div>

				<div class="tavif-field">
					<label class="tavif-field-label"><?php esc_html_e('Discard', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<label class="tavif-toggle">
							<input type="hidden" name="only_if_smaller" value="0" />
							<input type="checkbox" name="only_if_smaller" value="1" <?php checked($s['only_if_smaller']); ?> />
							<span class="slider"></span>
							<span class="toggle-label"><?php esc_html_e('Keep the converted file only if it weighs less than the original', 'timber-avif'); ?></span>
						</label>
					</div>
				</div>
			</div>

			<?php submit_button(__('Save', 'timber-avif')); ?>
		</form>

		<script>
		(function () {
			var widths = document.getElementById('tavif-widths'),
			    chips  = document.getElementById('tavif-chips'),
			    store  = document.getElementById('tavif-pregen');
			if (!widths || !chips || !store) return;

			function selected() {
				return store.value.split(',').map(function (v) { return parseInt(v, 10); }).filter(Boolean);
			}

			// Le misure da pregenerare sono un sottoinsieme di quelle dichiarate sopra: qui si spuntano invece di riscriverle.
			function draw() {
				var on = selected();
				chips.innerHTML = '';
				widths.value.split(',').map(function (v) { return parseInt(v, 10); }).filter(Boolean).forEach(function (w) {
					var label = document.createElement('label');
					label.className = 'tavif-chip' + (on.indexOf(w) > -1 ? ' is-on' : '');
					var box = document.createElement('input');
					box.type = 'checkbox';
					box.checked = on.indexOf(w) > -1;
					box.addEventListener('change', function () {
						var next = selected().filter(function (v) { return v !== w; });
						if (box.checked) next.push(w);
						next.sort(function (a, b) { return a - b; });
						store.value = next.join(',');
						draw();
					});
					label.appendChild(box);
					label.appendChild(document.createTextNode(w));
					chips.appendChild(label);
				});
			}

			widths.addEventListener('input', draw);
			draw();
		})();
		</script>
		<?php
	}

	private static function engine_label(string $method): string {
		return ['gd' => 'GD', 'imagick' => 'ImageMagick', 'exec' => 'ImageMagick CLI', 'none' => __('Not available', 'timber-avif')][$method] ?? $method;
	}

	private static function render_tools_tab(): void {
		$queue_count = count(get_option(self::QUEUE_KEY, []));
		?>
		<div class="tavif-tools-grid">
			<div class="tavif-tool-card">
				<h3><?php esc_html_e('Convert everything', 'timber-avif'); ?></h3>
				<p><?php esc_html_e('Generate the modern format for every image in the library. Already converted ones are skipped.', 'timber-avif'); ?></p>
				<button type="button" id="tavif-bulk-start" class="button button-primary"><?php esc_html_e('Start', 'timber-avif'); ?></button>
				<div id="tavif-bulk-progress" style="display:none;margin-top:14px;">
					<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
						<div style="flex:1;height:22px;background:#f3f4f6;border-radius:4px;overflow:hidden;">
							<div id="tavif-bulk-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#6366f1,#818cf8);border-radius:4px;transition:width .3s;"></div>
						</div>
						<span id="tavif-bulk-count" style="font-size:13px;font-variant-numeric:tabular-nums;min-width:80px;text-align:right;">0 / 0</span>
					</div>
					<p id="tavif-bulk-status" class="description" style="margin:0;"></p>
				</div>
			</div>
			<div class="tavif-tool-card">
				<h3><?php esc_html_e('Delete conversions', 'timber-avif'); ?></h3>
				<p><?php esc_html_e('Deletes every generated AVIF and WebP file. Originals are untouched and files rebuild on first visit. Use it after a quality change, to realign the library.', 'timber-avif'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('timber_avif_tools'); ?>
					<input type="hidden" name="action" value="timber_avif_tools" />
					<input type="hidden" name="subaction" value="purge_conversions" />
					<input type="hidden" name="tab" value="tools" />
					<button type="submit" class="button" style="color:#b91c1c;" onclick="return confirm('<?php echo esc_js(__('Delete every generated AVIF and WebP file?', 'timber-avif')); ?>');"><?php esc_html_e('Delete', 'timber-avif'); ?></button>
				</form>
			</div>
			<div class="tavif-tool-card">
				<h3><?php esc_html_e('Clear cache', 'timber-avif'); ?></h3>
				<p><?php esc_html_e('Detect the conversion engines available on this server again, and clear the memory of failed attempts.', 'timber-avif'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('timber_avif_tools'); ?>
					<input type="hidden" name="action" value="timber_avif_tools" />
					<input type="hidden" name="subaction" value="clear_cache" />
					<input type="hidden" name="tab" value="tools" />
					<button type="submit" class="button"><?php esc_html_e('Clear', 'timber-avif'); ?></button>
				</form>
			</div>
			<div class="tavif-tool-card">
				<h3><?php esc_html_e('Queue', 'timber-avif'); ?></h3>
				<p><span id="tavif-queue-remaining"><?php echo esc_html($queue_count); ?></span> <?php esc_html_e('images waiting to be converted.', 'timber-avif'); ?></p>
				<button type="button" id="tavif-queue-start" class="button"<?php echo $queue_count === 0 ? ' disabled' : ''; ?>><?php esc_html_e('Process now', 'timber-avif'); ?></button>
				<div id="tavif-queue-progress" style="display:none;margin-top:14px;">
					<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
						<div style="flex:1;height:22px;background:#f3f4f6;border-radius:4px;overflow:hidden;">
							<div id="tavif-queue-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#10b981,#34d399);border-radius:4px;transition:width .3s;"></div>
						</div>
						<span id="tavif-queue-count" style="font-size:13px;font-variant-numeric:tabular-nums;min-width:60px;text-align:right;">0</span>
					</div>
					<p id="tavif-queue-status" class="description" style="margin:0;"></p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_logs_tab(): void {
		$logs = get_option(self::LOG_KEY, []);
		if (!is_array($logs)) $logs = [];

		$filter = sanitize_key($_GET['log_filter'] ?? 'all');
		$filtered = $logs;
		if ($filter !== 'all') {
			$filtered = array_filter($logs, fn($l) => ($l['status'] ?? '') === $filter);
		}

		$status_counts = ['all' => count($logs)];
		foreach ($logs as $l) {
			$s = $l['status'] ?? 'unknown';
			$status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
		}

		$base = admin_url('options-general.php?page=timber-avif-settings&tab=logs');
		?>
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
			<div class="tavif-log-filters">
				<?php
				$labels = ['all' => __('All', 'timber-avif'), 'skipped' => __('Skipped', 'timber-avif'), 'failed' => __('Failed', 'timber-avif'), 'error' => __('Errors', 'timber-avif')];
				foreach ($labels as $key => $label):
					$count = $status_counts[$key] ?? 0;
					$active = $filter === $key;
				?>
					<a href="<?php echo esc_url(add_query_arg('log_filter', $key, $base)); ?>" class="tavif-log-filter<?php echo $active ? ' tavif-log-filter--active' : ''; ?>"><?php echo esc_html($label); ?> <span class="tavif-log-filter-count"><?php echo esc_html($count); ?></span></a>
				<?php endforeach; ?>
			</div>
			<?php if (!empty($logs)): ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
				<?php wp_nonce_field('timber_avif_tools'); ?>
				<input type="hidden" name="action" value="timber_avif_tools" />
				<input type="hidden" name="subaction" value="clear_logs" />
				<input type="hidden" name="tab" value="logs" />
				<button type="submit" class="button" style="color:#b91c1c;" onclick="return confirm('<?php echo esc_js(__('Clear the log?', 'timber-avif')); ?>');"><?php esc_html_e('Clear log', 'timber-avif'); ?></button>
			</form>
			<?php endif; ?>
		</div>

		<?php if (empty($filtered)): ?>
			<p class="description"><?php esc_html_e('No entries', 'timber-avif'); ?><?php echo $filter !== 'all' ? ' matching this filter' : ''; ?>.</p>
		<?php else: ?>
			<table class="tavif-log-table">
				<thead>
					<tr>
						<th style="width:145px;"><?php esc_html_e('Time', 'timber-avif'); ?></th>
						<th>File</th>
						<th style="width:60px;"><?php esc_html_e('Format', 'timber-avif'); ?></th>
						<th style="width:80px;"><?php esc_html_e('Result', 'timber-avif'); ?></th>
						<th><?php esc_html_e('Reason', 'timber-avif'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($filtered as $entry): ?>
					<tr class="tavif-log-row tavif-log-row--<?php echo esc_attr($entry['status'] ?? 'unknown'); ?>">
						<td class="tavif-log-time"><?php echo esc_html(wp_date('Y-m-d H:i:s', $entry['time'] ?? 0)); ?></td>
						<td class="tavif-log-file" title="<?php echo esc_attr($entry['file'] ?? ''); ?>"><?php echo esc_html($entry['file'] ?? '—'); ?></td>
						<td><span class="tavif-log-format"><?php echo esc_html(strtoupper($entry['format'] ?? '')); ?></span></td>
						<td>
							<?php
							$badge_class = match ($entry['status'] ?? '') {
								'skipped' => 'tavif-badge--warn',
								'failed', 'error' => 'tavif-badge--fail',
								default => 'tavif-badge--off',
							};
							?>
							<span class="tavif-badge <?php echo $badge_class; ?>"><?php echo esc_html(ucfirst($entry['status'] ?? 'unknown')); ?></span>
						</td>
						<td class="tavif-log-reason"><?php echo esc_html($entry['reason'] ?? ''); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:12px;"><?php esc_html_e('Showing', 'timber-avif'); ?> ?php echo count($filtered); ?> of <?php echo count($logs); ?> entries (max <?php echo self::MAX_LOG_ENTRIES; ?> kept). Oldest entries are auto-pruned.</p>
		<?php endif;
	}

	private static function render_statistics_tab(): void {
		$stats  = self::get_statistics();
		$modern = self::modern_format() ?: 'avif';
		$other  = $modern === 'avif' ? 'webp' : 'avif';

		$done    = $stats[$modern];
		$total   = $stats['total'];
		$pct     = $total > 0 ? round($done / $total * 100) : 0;
		$size    = $stats[$modern . '_size'];
		$saved   = $stats['orig_size'] - $size;
		$savePct = $stats['orig_size'] > 0 ? round($saved / $stats['orig_size'] * 100) : 0;
		?>
		<div class="tavif-stats-grid">
			<div class="tavif-stat-card">
				<div class="stat-value"><?php echo esc_html($total); ?></div>
				<div class="stat-label"><?php esc_html_e('Images', 'timber-avif'); ?></div>
			</div>
			<div class="tavif-stat-card tavif-stat-card--highlight">
				<div class="stat-value"><?php echo esc_html($done); ?></div>
				<div class="stat-label"><?php printf(esc_html__('Converted to %s', 'timber-avif'), esc_html(strtoupper($modern))); ?></div>
				<div class="stat-sub"><?php printf(esc_html__('%d%% of the library', 'timber-avif'), (int) $pct); ?></div>
			</div>
			<?php if ($saved > 0) : ?>
				<div class="tavif-stat-card tavif-stat-card--green">
					<div class="stat-value"><?php echo self::format_bytes($saved); ?></div>
					<div class="stat-label"><?php esc_html_e('Saved', 'timber-avif'); ?></div>
					<div class="stat-sub"><?php printf(esc_html__('%1$d%% of %2$s', 'timber-avif'), (int) $savePct, self::format_bytes($stats['orig_size'])); ?></div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ($done < $total) : ?>
			<p class="description" style="margin-bottom:20px;">
				<?php printf(
					esc_html__('The %d images left out are those where the modern format would weigh more than the original: it happens on flat graphics and icons, and they are left as they were.', 'timber-avif'),
					(int) ($total - $done)
				); ?>
			</p>
		<?php endif; ?>

		<?php if ($stats[$other] > 0) : ?>
			<p class="description">
				<?php printf(
					esc_html__('The library also holds %1$d %2$s files (%3$s), generated when a different format was being served. Remove them from Tools &rarr; Delete conversions.', 'timber-avif'),
					(int) $stats[$other],
					esc_html(strtoupper($other)),
					self::format_bytes($stats[$other . '_size'])
				); ?>
			</p>
		<?php endif; ?>

		<p class="description"><?php esc_html_e('Updated every 5 minutes. The count covers original images, not the individual sizes generated from them.', 'timber-avif'); ?></p>
		<?php
	}

	private static function get_statistics(): array {
		$cached = get_transient('timber_avif_statistics');
		if ($cached !== false) return $cached;

		$ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'], 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
		$stats = ['total' => count($ids), 'avif' => 0, 'webp' => 0, 'orig_size' => 0, 'avif_size' => 0, 'webp_size' => 0];

		foreach ($ids as $id) {
			$file = get_attached_file($id);
			if (!$file || !file_exists($file)) continue;
			$stats['orig_size'] += filesize($file);
			$avif = preg_replace('/\.(jpe?g|png|gif)$/i', '.avif', $file);
			if (file_exists($avif)) { $stats['avif']++; $stats['avif_size'] += filesize($avif); }
			$webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $file);
			if (file_exists($webp)) { $stats['webp']++; $stats['webp_size'] += filesize($webp); }
		}

		set_transient('timber_avif_statistics', $stats, 5 * MINUTE_IN_SECONDS);
		return $stats;
	}

	private static function format_bytes(int $bytes): string {
		if ($bytes <= 0) return '0 B';
		$u = ['B', 'KB', 'MB', 'GB'];
		$f = floor(log($bytes, 1024));
		return round($bytes / pow(1024, $f), 1) . ' ' . $u[$f];
	}

	private static function render_admin_notices(): void {
		if (!empty($_GET['updated']))        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'timber-avif') . '</p></div>';
		if (!empty($_GET['converted']))      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Conversion complete.', 'timber-avif') . '</p></div>';
		if (!empty($_GET['cleared']))        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cache cleared.', 'timber-avif') . '</p></div>';
		if (isset($_GET['purged']))          echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('%d files deleted.', 'timber-avif'), intval($_GET['purged'])) . '</p></div>';
		if (!empty($_GET['queue_processed'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Queue processed.', 'timber-avif') . '</p></div>';
		if (!empty($_GET['logs_cleared']))   echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Log cleared.', 'timber-avif') . '</p></div>';
	}

	/* ─────────────────────────────────────────────
	 * Admin Handlers
	 * ───────────────────────────────────────────── */

	public static function handle_admin_post(): void {
		if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

		$sub = sanitize_text_field($_POST['subaction'] ?? '');
		$tab = sanitize_key($_POST['tab'] ?? 'settings');

		if ($sub === 'save_settings') {
			check_admin_referer('timber_avif_settings');
			self::$settings['avif_quality']            = max(1, min(100, intval($_POST['avif_quality'] ?? self::DEFAULT_AVIF_QUALITY)));
			self::$settings['webp_quality']            = max(1, min(100, intval($_POST['webp_quality'] ?? self::DEFAULT_WEBP_QUALITY)));
			self::$settings['jpeg_quality']            = max(60, min(100, intval($_POST['jpeg_quality'] ?? self::DEFAULT_JPEG_QUALITY)));
			self::$settings['max_upload_dimension']    = max(1024, intval($_POST['max_upload_dimension'] ?? 2560));
			$mode = sanitize_key($_POST['format_mode'] ?? 'auto');
			self::$settings['format_mode']             = in_array($mode, ['auto', 'avif', 'webp', 'off'], true) ? $mode : 'auto';
			self::$settings['only_if_smaller']         = !empty($_POST['only_if_smaller']);
			self::$settings['max_dimension']           = max(512, intval($_POST['max_dimension'] ?? self::MAX_IMAGE_DIMENSION));
			self::$settings['max_file_size']           = max(1, intval($_POST['max_file_size'] ?? self::MAX_FILE_SIZE_MB));
			self::$settings['max_inline_conversions']  = max(0, min(50, intval($_POST['max_inline_conversions'] ?? self::MAX_INLINE_CONVERSIONS)));
			self::$settings['pregenerate_breakpoints'] = !empty($_POST['pregenerate_breakpoints']);
			self::$settings['breakpoint_widths']       = sanitize_text_field($_POST['breakpoint_widths'] ?? '');
			self::$settings['pregenerate_widths']      = sanitize_text_field($_POST['pregenerate_widths'] ?? '');
			update_option(self::OPTION_KEY, self::$settings);
			wp_safe_redirect(add_query_arg(['updated' => 'true', 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
			exit;
		}

		check_admin_referer('timber_avif_tools');

		if ($sub === 'purge_conversions') {
			$deleted = self::purge_all_conversions();
			wp_safe_redirect(add_query_arg(['purged' => $deleted, 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
			exit;
		}
		if ($sub === 'clear_cache') {
			self::clear_all_caches();
			wp_safe_redirect(add_query_arg(['cleared' => 'true', 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
			exit;
		}
		if ($sub === 'clear_logs') {
			self::clear_logs();
			wp_safe_redirect(add_query_arg(['logs_cleared' => 'true', 'tab' => 'logs'], admin_url('options-general.php?page=timber-avif-settings')));
			exit;
		}
		wp_safe_redirect(admin_url('options-general.php?page=timber-avif-settings'));
		exit;
	}

	public static function handle_ajax_bulk_batch(): void {
		check_ajax_referer('timber_avif_bulk_batch', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions', 403);

		$offset = max(0, intval($_POST['offset'] ?? 0));
		$batch_size = max(1, min(10, intval($_POST['batch_size'] ?? 5)));

		$ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'], 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
		$total = count($ids);
		$slice = array_slice($ids, $offset, $batch_size);

		foreach ($slice as $id) {
			self::convert_single_attachment($id);
		}

		$new_offset = $offset + count($slice);
		$done = $new_offset >= $total;
		if ($done) delete_transient('timber_avif_statistics');

		wp_send_json_success(['processed' => $new_offset, 'total' => $total, 'done' => $done]);
	}

	public static function handle_ajax_queue_batch(): void {
		check_ajax_referer('timber_avif_queue_batch', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error('Insufficient permissions', 403);

		$queue = get_option(self::QUEUE_KEY, []);
		if (!is_array($queue)) $queue = [];
		$total = count($queue);

		if ($total === 0) {
			wp_send_json_success(['processed' => 0, 'remaining' => 0, 'done' => true]);
		}

		$batch = 10;
		$done_count = 0;
		foreach ($queue as $key => $job) {
			if ($done_count >= $batch) break;
			self::convert_file($job['path'], $job['format']);
			unset($queue[$key]);
			$done_count++;
		}

		if (empty($queue)) {
			delete_option(self::QUEUE_KEY);
		} else {
			update_option(self::QUEUE_KEY, $queue, false);
		}

		$remaining = count($queue);
		wp_send_json_success(['processed' => $done_count, 'remaining' => $remaining, 'done' => $remaining === 0]);
	}

	private static function convert_single_attachment(int $id): void {
		$file = get_attached_file($id);
		if (!$file || !file_exists($file)) return;

		$modern = self::modern_format();
		if (!$modern) return;

		$paths = [$file];
		$meta = wp_get_attachment_metadata($id);
		if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
			$dir = dirname($file);
			foreach ($meta['sizes'] as $size) {
				if (!empty($size['file'])) $paths[] = $dir . '/' . $size['file'];
			}
		}

		foreach ($paths as $p) {
			if (!file_exists($p)) continue;
			self::convert_file($p, $modern, true);
		}
	}

	public static function enqueue_admin_scripts(string $hook): void {
		if ($hook !== 'settings_page_timber-avif-settings') return;

		$bulk_nonce = wp_create_nonce('timber_avif_bulk_batch');
		$queue_nonce = wp_create_nonce('timber_avif_queue_batch');
		$ajax_url = admin_url('admin-ajax.php');

		wp_add_inline_script('jquery-core', "
			jQuery(function($){
				var url='" . esc_js($ajax_url) . "';

				/* ── Bulk Convert ── */
				(function(){
					var running=false,cancelled=false;
					var btn=$('#tavif-bulk-start'),wrap=$('#tavif-bulk-progress'),bar=$('#tavif-bulk-bar'),count=$('#tavif-bulk-count'),status=$('#tavif-bulk-status');
					if(!btn.length)return;
					btn.on('click',function(){
						if(running){cancelled=true;btn.prop('disabled',true).text('" . esc_js(__('Stopping…', 'timber-avif')) . "');return;}
						running=true;cancelled=false;
						btn.text('" . esc_js(__('Cancel', 'timber-avif')) . "').removeClass('button-primary').addClass('button-secondary');
						wrap.show();bar.css('width','0%');count.text('0 / \u2026');status.text('" . esc_js(__('Starting…', 'timber-avif')) . "');
						run(0);
					});
					function run(offset){
						if(cancelled){done('" . esc_js(__('Cancelled at', 'timber-avif')) . " '+offset);return;}
						$.post(url,{action:'timber_avif_bulk_batch',nonce:'" . esc_js($bulk_nonce) . "',offset:offset,batch_size:5},function(r){
							if(!r.success){done('Error: '+(r.data||'unknown'));return;}
							var d=r.data,pct=d.total>0?Math.round(d.processed/d.total*100):0;
							bar.css('width',pct+'%');count.text(d.processed+' / '+d.total);status.text(pct+'%');
							if(d.done)done(d.processed+' " . esc_js(__('images processed.', 'timber-avif')) . "');else run(d.processed);
						}).fail(function(){done('Request failed.');});
					}
					function done(msg){running=false;cancelled=false;btn.prop('disabled',false).text('" . esc_js(__('Start', 'timber-avif')) . "').removeClass('button-secondary').addClass('button-primary');status.text(msg);bar.css('width','100%');}
				})();

				/* ── Process Queue ── */
				(function(){
					var running=false;
					var btn=$('#tavif-queue-start'),wrap=$('#tavif-queue-progress'),bar=$('#tavif-queue-bar'),countEl=$('#tavif-queue-count'),status=$('#tavif-queue-status'),remaining=$('#tavif-queue-remaining');
					if(!btn.length)return;
					var total=parseInt(remaining.text())||0;
					btn.on('click',function(){
						if(running||total===0)return;
						running=true;
						btn.prop('disabled',true).text('" . esc_js(__('Processing…', 'timber-avif')) . "');
						wrap.show();bar.css('width','0%');countEl.text('0');status.text('Avvio\u2026');
						var processed=0;
						run();
						function run(){
							$.post(url,{action:'timber_avif_queue_batch',nonce:'" . esc_js($queue_nonce) . "'},function(r){
								if(!r.success){done('Error: '+(r.data||'unknown'));return;}
								var d=r.data;
								processed+=d.processed;
								var pct=total>0?Math.min(100,Math.round(processed/total*100)):100;
								bar.css('width',pct+'%');countEl.text(processed+' / '+total);remaining.text(d.remaining);
								status.text(d.remaining+' remaining\u2026');
								if(d.done){done(processed+' " . esc_js(__('conversions complete.', 'timber-avif')) . "');}else{run();}
							}).fail(function(){done('Request failed.');});
						}
					});
					function done(msg){running=false;btn.prop('disabled',false).text('" . esc_js(__('Process now', 'timber-avif')) . "');status.text(msg);bar.css('width','100%');remaining.text('0');}
				})();
			});
		");
	}

	/* ─────────────────────────────────────────────
	 * Purge & Cache
	 * ───────────────────────────────────────────── */

	public static function purge_all_conversions(): int {
		$base_dir = self::upload_dir()['basedir'];
		$deleted = 0;

		// Protect originally-uploaded AVIF/WebP files
		$protected = [];
		$originals = get_posts(['post_type' => 'attachment', 'post_mime_type' => ['image/avif', 'image/webp'], 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
		foreach ($originals as $id) {
			$p = get_attached_file($id);
			if ($p && ($r = realpath($p))) $protected[$r] = true;
		}

		$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
		foreach ($iter as $file) {
			if (!$file->isFile()) continue;
			$ext = strtolower($file->getExtension());
			if ($ext !== 'avif' && $ext !== 'webp') continue;
			$r = realpath($file->getPathname());
			if ($r && isset($protected[$r])) continue;
			if (@unlink($file->getPathname())) $deleted++;
		}

		delete_option(self::QUEUE_KEY);
		delete_transient('timber_avif_statistics');
		self::flush_failure_transients();
		self::$exists_cache = [];
		return $deleted;
	}

	public static function clear_all_caches(): void {
		self::flush_capability_transients();
		delete_transient('timber_avif_statistics');
		self::flush_failure_transients();
		self::$conversion_methods = ['avif' => null, 'webp' => null];
		self::$exists_cache = [];
		self::detect_capabilities('avif');
		self::detect_capabilities('webp');
	}

	/**
	 * Flush all tavif_fail_* transients so failed/skipped conversions can be retried.
	 */
	/**
	 * Flush the capability cache for every SAPI, not just this one.
	 *
	 * The key carries the SAPI (see detect_capabilities), so clearing only the
	 * current runtime would leave php-fpm's detection in place when this runs
	 * under wp-cli — exactly the mismatch the suffix exists to prevent.
	 */
	private static function flush_capability_transients(): void {
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timber_avif_cap_%' OR option_name LIKE '_transient_timeout_timber_avif_cap_%'");

		// With an external object cache transients never reach the options table,
		// so at least retire the keys this runtime owns.
		delete_transient('timber_avif_cap_avif_' . self::runtime_key());
		delete_transient('timber_avif_cap_webp_' . self::runtime_key());
	}

	private static function flush_failure_transients(): void {
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tavif_fail_%' OR option_name LIKE '_transient_timeout_tavif_fail_%'");
	}

	/* ─────────────────────────────────────────────
	 * Media Library Column
	 * ───────────────────────────────────────────── */

	public static function add_media_column(array $columns): array {
		$new = [];
		foreach ($columns as $k => $v) {
			$new[$k] = $v;
			if ($k === 'date') $new['tavif_optimized'] = 'Optimized';
		}
		return $new;
	}

	public static function render_media_column(string $column, int $post_id): void {
		if ($column !== 'tavif_optimized') return;
		$file = get_attached_file($post_id);
		if (!$file || !file_exists($file)) { echo '&mdash;'; return; }
		$avif = preg_replace('/\.(jpe?g|png|gif)$/i', '.avif', $file);
		$webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $file);
		$ha = $avif !== $file && file_exists($avif);
		$hw = $webp !== $file && file_exists($webp);
		if (!$ha && !$hw) { echo '<span style="color:#9ca3af;">&mdash;</span>'; return; }
		if ($ha) echo '<span class="tavif-col-badge tavif-col-badge--avif">AVIF</span> ';
		if ($hw) echo '<span class="tavif-col-badge tavif-col-badge--webp">WebP</span>';
	}

	public static function media_column_css(): void {
		$screen = get_current_screen();
		if (!$screen || $screen->id !== 'upload') return;
		echo '<style>.fixed .column-tavif_optimized{width:90px;text-align:center}.tavif-col-badge{display:inline-block;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;line-height:1.3}.tavif-col-badge--avif{background:#d1fae5;color:#065f46}.tavif-col-badge--webp{background:#dbeafe;color:#1e40af}</style>';
	}

	/* ─────────────────────────────────────────────
	 * Cron / Cleanup
	 * ───────────────────────────────────────────── */

	public static function cleanup_stale_locks(): void {
		$base = self::upload_dir()['basedir'];
		if (!is_dir($base)) return;
		$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
		$now = time();
		foreach ($iter as $f) {
			if (!$f->isFile() || substr($f->getFilename(), -5) !== '.lock') continue;
			if (($now - $f->getMTime()) > self::STALE_LOCK_TIMEOUT) @unlink($f->getPathname());
		}
	}

	public static function deregister_crons(): void {
		foreach ([self::CRON_HOOK, self::CRON_CLEANUP_HOOK] as $h) {
			$ts = wp_next_scheduled($h);
			if ($ts) wp_unschedule_event($ts, $h);
		}
	}

	/* ─────────────────────────────────────────────
	 * WP-CLI
	 * ───────────────────────────────────────────── */

	private static function register_cli(): void {
		\WP_CLI::add_command('timber-avif clear-cache', function () {
			self::clear_all_caches();
			\WP_CLI::success('Caches cleared.');
		});
		\WP_CLI::add_command('timber-avif detect', function () {
			\WP_CLI::log('AVIF: ' . self::detect_capabilities('avif'));
			\WP_CLI::log('WebP: ' . self::detect_capabilities('webp'));
		});
		\WP_CLI::add_command('timber-avif bulk', function () {
			$ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'], 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
			if (empty($ids)) { \WP_CLI::warning('No images.'); return; }
			$progress = \WP_CLI\Utils\make_progress_bar('Converting ' . count($ids) . ' images', count($ids));
			foreach ($ids as $id) { self::convert_single_attachment($id); $progress->tick(); }
			$progress->finish();
			\WP_CLI::success('Done.');
		});
		\WP_CLI::add_command('timber-avif queue', function () {
			$q = get_option(self::QUEUE_KEY, []);
			\WP_CLI::log(count($q) . ' pending.');
			if (!empty($q)) { self::process_cron_queue(); \WP_CLI::success('Processed.'); }
		});
	}
}

TimberAVIF::init();
