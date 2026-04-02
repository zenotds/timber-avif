<?php
/**
 * Timber AVIF Converter
 *
 * @version 5.3.0
 * @author Francesco Zeno Selva
 * @link https://github.com/zenotds/timber-avif
 *
 * Performance-first image optimization for Timber 2.x.
 * Leverages Timber's native |resize and |towebp. Adds AVIF support.
 *
 * Architecture:
 *  - Fast file_exists check with per-request static cache
 *  - If AVIF/WebP sibling exists → serve it instantly (zero overhead)
 *  - If not → convert inline up to MAX_INLINE_CONVERSIONS per request
 *  - Overflow → background queue (shutdown + wp-cron)
 *  - Failed conversions are remembered for 24h (no wasteful retries)
 *  - WebP falls back to Timber's native |towebp (already fast/cached)
 *
 * Twig filters:
 *   |toavif            — convert/lookup AVIF for this image
 *   |towebp            — (native Timber, untouched)
 *   |avif_src(w, h)    — resize + AVIF lookup/conversion
 *   |webp_src(w, h)    — resize + WebP lookup (Timber fallback)
 *   |best_src(w, h)    — returns best available: AVIF > WebP > original
 *
 * Timber Image properties (via AVIFImage):
 *   image.avif         — AVIF URL or original
 *   image.webp         — WebP URL or original
 *   image.best         — best available format URL
 *
 * Admin:
 *   Settings → Timber AVIF (settings, tools, statistics)
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
    const VERSION     = '5.3.0';
    const OPTION_KEY  = 'timber_avif_settings';
    const QUEUE_KEY   = 'timber_avif_queue';
    const LOG_KEY     = 'timber_avif_log';
    const MAX_LOG_ENTRIES = 200;
    const CRON_HOOK   = 'timber_avif_process_queue';
    const CRON_CLEANUP_HOOK = 'timber_avif_cleanup_stale_locks';
    const STALE_LOCK_TIMEOUT = 300;

    // Defaults
    const DEFAULT_AVIF_QUALITY = 80;
    const DEFAULT_WEBP_QUALITY = 82;
    const MAX_IMAGE_DIMENSION  = 4096;
    const MAX_FILE_SIZE_MB     = 50;

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

        add_filter('timber/twig', [__CLASS__, 'add_twig_filters']);
        add_filter('timber/image/new_class', function () {
            return class_exists('AVIFImage') ? 'AVIFImage' : 'Timber\\Image';
        });
        add_action('wp_generate_attachment_metadata', [__CLASS__, 'on_upload'], 20, 2);

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

    private static function load_settings(): void {
        $defaults = [
            'generate_avif_uploads'   => true,
            'generate_webp_uploads'   => true,
            'avif_quality'            => self::DEFAULT_AVIF_QUALITY,
            'webp_quality'            => self::DEFAULT_WEBP_QUALITY,
            'only_if_smaller'         => true,
            'max_dimension'           => self::MAX_IMAGE_DIMENSION,
            'max_file_size'           => self::MAX_FILE_SIZE_MB,
            'max_inline_conversions'  => self::MAX_INLINE_CONVERSIONS,
            'pregenerate_breakpoints' => false,
            'breakpoint_widths'       => '640,768,1024,1280,1600,1920,2560',
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
            self::convert_file($job['path'], $job['format']);
            unset($queue[$key]);
            $done++;
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

        $cache_key = 'timber_avif_cap_' . $format;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
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
    private static function remember_failure(string $dest_path, string $format): void {
        set_transient('tavif_fail_' . md5($format . '|' . $dest_path), 1, self::FAILURE_TTL);
    }

    private static function is_failed(string $dest_path, string $format): bool {
        return (bool) get_transient('tavif_fail_' . md5($format . '|' . $dest_path));
    }

    private static function clear_failure(string $dest_path, string $format): void {
        delete_transient('tavif_fail_' . md5($format . '|' . $dest_path));
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

        $do_avif = self::setting('generate_avif_uploads');
        $do_webp = self::setting('generate_webp_uploads');
        if (!$do_avif && !$do_webp) return $metadata;

        $paths = [$file];
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = dirname($file);
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file'])) $paths[] = $dir . '/' . $size['file'];
            }
        }

        // Convert directly on upload (runs in admin context, acceptable overhead)
        foreach ($paths as $p) {
            if (!file_exists($p)) continue;
            if ($do_avif) self::convert_file($p, 'avif');
            if ($do_webp) self::convert_file($p, 'webp');
        }

        return $metadata;
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
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Timber AVIF:</strong> No AVIF support detected. Images will fall back to WebP/original.</p></div>';
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) return;

        $settings = self::$settings;
        $tab = sanitize_key($_GET['tab'] ?? 'settings');
        $base_url = admin_url('options-general.php?page=timber-avif-settings');
        $avif_method = self::detect_capabilities('avif');
        $webp_method = self::detect_capabilities('webp');
        $method_labels = ['gd' => 'GD Library', 'imagick' => 'ImageMagick', 'exec' => 'CLI (magick)', 'none' => 'Not available'];
        $queue_count = count(get_option(self::QUEUE_KEY, []));
        ?>
        <style>
            .tavif-wrap{max-width:860px}.tavif-header{display:flex;align-items:center;gap:12px;margin-bottom:4px}.tavif-header h1{margin:0;padding:0;line-height:1.2}.tavif-version{font-size:11px;color:#646970;background:#f0f0f1;padding:2px 8px;border-radius:10px;font-weight:400}.tavif-wrap .nav-tab-wrapper{margin-bottom:0;border-bottom:1px solid #c3c4c7}.tavif-card{background:#fff;border:1px solid #c3c4c7;border-top:0;padding:24px 28px;margin-bottom:20px}.tavif-status{display:grid;gap:12px;margin:16px 0 0}.tavif-status--3col{grid-template-columns:repeat(3,1fr)}.tavif-status+.tavif-status{margin-top:12px}.tavif-status:last-of-type{margin-bottom:20px}.tavif-status-item{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px}.tavif-status-item .label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:6px}.tavif-status-item .value{font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px}.tavif-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;line-height:1}.tavif-badge--ok{background:#d1fae5;color:#065f46}.tavif-badge--warn{background:#fef3c7;color:#92400e}.tavif-badge--off{background:#f3f4f6;color:#6b7280}.tavif-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}.tavif-dot--ok{background:#10b981}.tavif-dot--warn{background:#f59e0b}.tavif-dot--off{background:#9ca3af}.tavif-toggle{position:relative;display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none}.tavif-toggle input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0}.tavif-toggle .slider{width:40px;height:22px;background:#d1d5db;border-radius:11px;position:relative;transition:background .2s;flex-shrink:0}.tavif-toggle .slider::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.15)}.tavif-toggle input:checked+.slider{background:#2271b1}.tavif-toggle input:checked+.slider::after{transform:translateX(18px)}.tavif-toggle .toggle-label{font-size:13px}.tavif-range-group{margin-bottom:16px}.tavif-range-group label{display:flex;align-items:center;gap:12px;font-weight:500;font-size:13px}.tavif-range-group input[type="range"]{flex:1;max-width:280px;accent-color:#2271b1;height:6px}.tavif-range-group .range-val{display:inline-block;min-width:36px;text-align:center;font-weight:600;font-size:13px;background:#f0f0f1;padding:3px 10px;border-radius:4px;font-variant-numeric:tabular-nums}.tavif-range-group .range-label{min-width:42px}.tavif-field-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:12px}.tavif-field-row label{display:flex;align-items:center;gap:6px;font-size:13px}.tavif-field-row input[type="number"]{width:90px}.tavif-field-row input[type="text"].regular-text{max-width:320px}.tavif-section{margin-bottom:28px}.tavif-section:last-child{margin-bottom:0}.tavif-section h3{font-size:13px;font-weight:600;color:#1d2327;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #e5e7eb;text-transform:uppercase;letter-spacing:.3px}.tavif-tools-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.tavif-tool-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:22px;display:flex;flex-direction:column}.tavif-tool-card h3{margin:0 0 8px;font-size:14px;color:#1d2327}.tavif-tool-card p{color:#6b7280;font-size:13px;margin:0 0 18px;line-height:1.5;flex:1}.tavif-tool-card .button{align-self:flex-start}.tavif-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px}.tavif-stat-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:18px;text-align:center}.tavif-stat-card .stat-value{font-size:28px;font-weight:700;color:#1d2327;line-height:1.2;font-variant-numeric:tabular-nums}.tavif-stat-card .stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-top:4px}.tavif-stat-card .stat-sub{font-size:12px;color:#9ca3af;margin-top:2px}.tavif-stat-card--highlight{background:#eff6ff;border-color:#bfdbfe}.tavif-stat-card--highlight .stat-value{color:#1d4ed8}.tavif-stat-card--green{background:#ecfdf5;border-color:#a7f3d0}.tavif-stat-card--green .stat-value{color:#065f46}.tavif-progress{margin-bottom:24px}.tavif-progress h3{font-size:13px;font-weight:600;margin:0 0 12px;color:#1d2327}.tavif-progress-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;font-size:13px}.tavif-progress-row .bar-label{min-width:48px;font-weight:500}.tavif-progress-row .bar-wrap{flex:1;height:24px;background:#f3f4f6;border-radius:4px;overflow:hidden;position:relative}.tavif-progress-row .bar-fill{height:100%;border-radius:4px;transition:width .3s;min-width:2px}.tavif-progress-row .bar-fill--avif{background:linear-gradient(90deg,#6366f1,#818cf8)}.tavif-progress-row .bar-fill--webp{background:linear-gradient(90deg,#2563eb,#60a5fa)}.tavif-progress-row .bar-text{font-size:12px;color:#6b7280;min-width:80px;text-align:right}
        </style>

        <div class="wrap tavif-wrap">
            <div class="tavif-header">
                <h1>Timber AVIF</h1>
                <span class="tavif-version">v<?php echo self::VERSION; ?></span>
            </div>

            <div class="tavif-status tavif-status--3col">
                <div class="tavif-status-item">
                    <div class="label">AVIF Engine</div>
                    <div class="value">
                        <span class="tavif-dot tavif-dot--<?php echo $avif_method !== 'none' ? 'ok' : 'warn'; ?>"></span>
                        <?php echo esc_html($method_labels[$avif_method] ?? 'Unknown'); ?>
                    </div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">WebP Engine</div>
                    <div class="value">
                        <span class="tavif-dot tavif-dot--<?php echo $webp_method !== 'none' ? 'ok' : 'warn'; ?>"></span>
                        <?php echo esc_html($method_labels[$webp_method] ?? 'Unknown'); ?>
                    </div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">Auto-convert</div>
                    <div class="value">
                        <?php
                        $fmts = [];
                        if ($settings['generate_avif_uploads'] && $avif_method !== 'none') $fmts[] = 'AVIF';
                        if ($settings['generate_webp_uploads'] && $webp_method !== 'none') $fmts[] = 'WebP';
                        echo $fmts ? '<span class="tavif-badge tavif-badge--ok">' . esc_html(implode(' + ', $fmts)) . '</span>' : '<span class="tavif-badge tavif-badge--off">Off</span>';
                        ?>
                    </div>
                </div>
            </div>
            <div class="tavif-status tavif-status--3col">
                <div class="tavif-status-item">
                    <div class="label">Quality</div>
                    <div class="value">AVIF <?php echo esc_html($settings['avif_quality']); ?> &middot; WebP <?php echo esc_html($settings['webp_quality']); ?></div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">Inline budget</div>
                    <div class="value"><?php echo esc_html($settings['max_inline_conversions'] ?? self::MAX_INLINE_CONVERSIONS); ?> per request</div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">Queue</div>
                    <div class="value">
                        <?php if ($queue_count > 0): ?>
                            <span class="tavif-badge tavif-badge--warn"><?php echo esc_html($queue_count); ?> pending</span>
                        <?php else: ?>
                            <span class="tavif-badge tavif-badge--ok">Empty</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php $log_count = count(get_option(self::LOG_KEY, [])); ?>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'tools', $base_url)); ?>" class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>">Tools</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'statistics', $base_url)); ?>" class="nav-tab <?php echo $tab === 'statistics' ? 'nav-tab-active' : ''; ?>">Statistics</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'logs', $base_url)); ?>" class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>">Logs<?php if ($log_count > 0) echo ' <span class="count">(' . esc_html($log_count) . ')</span>'; ?></a>
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
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('timber_avif_settings'); ?>
            <input type="hidden" name="action" value="timber_avif_tools" />
            <input type="hidden" name="subaction" value="save_settings" />
            <input type="hidden" name="tab" value="settings" />

            <div class="tavif-section">
                <h3>Generation</h3>
                <div class="tavif-field-row">
                    <label class="tavif-toggle">
                        <input type="hidden" name="generate_avif_uploads" value="0" />
                        <input type="checkbox" name="generate_avif_uploads" value="1" <?php checked($s['generate_avif_uploads']); ?> />
                        <span class="slider"></span>
                        <span class="toggle-label">Generate AVIF on upload <?php if ($avif_method === 'none') echo '<span class="tavif-badge tavif-badge--warn" style="margin-left:6px;">No engine</span>'; ?></span>
                    </label>
                </div>
                <div class="tavif-field-row">
                    <label class="tavif-toggle">
                        <input type="hidden" name="generate_webp_uploads" value="0" />
                        <input type="checkbox" name="generate_webp_uploads" value="1" <?php checked($s['generate_webp_uploads']); ?> />
                        <span class="slider"></span>
                        <span class="toggle-label">Generate WebP on upload <?php if ($webp_method === 'none') echo '<span class="tavif-badge tavif-badge--warn" style="margin-left:6px;">No engine</span>'; ?></span>
                    </label>
                </div>
            </div>

            <div class="tavif-section">
                <h3>Quality</h3>
                <div class="tavif-range-group">
                    <label><span class="range-label">AVIF</span><input type="range" name="avif_quality" min="1" max="100" value="<?php echo esc_attr($s['avif_quality']); ?>" oninput="this.closest('label').querySelector('.range-val').textContent=this.value" /><span class="range-val"><?php echo esc_html($s['avif_quality']); ?></span></label>
                </div>
                <div class="tavif-range-group">
                    <label><span class="range-label">WebP</span><input type="range" name="webp_quality" min="1" max="100" value="<?php echo esc_attr($s['webp_quality']); ?>" oninput="this.closest('label').querySelector('.range-val').textContent=this.value" /><span class="range-val"><?php echo esc_html($s['webp_quality']); ?></span></label>
                </div>
            </div>

            <div class="tavif-section">
                <h3>Performance</h3>
                <div class="tavif-field-row">
                    <label>Max inline conversions per request <input type="number" name="max_inline_conversions" value="<?php echo esc_attr($s['max_inline_conversions'] ?? self::MAX_INLINE_CONVERSIONS); ?>" min="0" max="50" step="1" /></label>
                </div>
                <p class="description" style="margin-top:-8px;">Images beyond this limit are converted in the background. Set to 0 to disable inline conversion (background-only). Higher = faster warm-up, slower first loads.</p>
            </div>

            <div class="tavif-section">
                <h3>Size Limits</h3>
                <div class="tavif-field-row">
                    <label>Max dimension (px) <input type="number" name="max_dimension" value="<?php echo esc_attr($s['max_dimension']); ?>" min="512" step="1" /></label>
                    <label>Max file size (MB) <input type="number" name="max_file_size" value="<?php echo esc_attr($s['max_file_size']); ?>" min="1" step="1" /></label>
                </div>
                <div class="tavif-field-row">
                    <label class="tavif-toggle">
                        <input type="checkbox" name="only_if_smaller" value="1" <?php checked($s['only_if_smaller']); ?> />
                        <span class="slider"></span>
                        <span class="toggle-label">Only keep converted file if smaller than original</span>
                    </label>
                </div>
            </div>

            <div class="tavif-section">
                <h3>Responsive Breakpoints</h3>
                <div class="tavif-field-row">
                    <label class="tavif-toggle">
                        <input type="checkbox" name="pregenerate_breakpoints" value="1" <?php checked($s['pregenerate_breakpoints']); ?> />
                        <span class="slider"></span>
                        <span class="toggle-label">Pre-generate breakpoint variants on upload</span>
                    </label>
                </div>
                <div class="tavif-field-row" style="margin-top:4px;">
                    <div>
                        <input type="text" name="breakpoint_widths" value="<?php echo esc_attr($s['breakpoint_widths']); ?>" class="regular-text" />
                        <p class="description">Comma-separated widths (px).</p>
                    </div>
                </div>
            </div>

            <?php submit_button('Save settings'); ?>
        </form>
        <?php
    }

    private static function render_tools_tab(): void {
        $queue_count = count(get_option(self::QUEUE_KEY, []));
        ?>
        <div class="tavif-tools-grid">
            <div class="tavif-tool-card">
                <h3>Bulk Convert</h3>
                <p>Process all images in the media library. Already-converted images are skipped.</p>
                <button type="button" id="tavif-bulk-start" class="button button-primary">Convert all media</button>
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
                <h3>Purge Conversions</h3>
                <p>Delete all generated AVIF and WebP files. Originals are never touched.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('timber_avif_tools'); ?>
                    <input type="hidden" name="action" value="timber_avif_tools" />
                    <input type="hidden" name="subaction" value="purge_conversions" />
                    <input type="hidden" name="tab" value="tools" />
                    <button type="submit" class="button" style="color:#b91c1c;" onclick="return confirm('Delete all generated AVIF and WebP files?');">Purge all conversions</button>
                </form>
            </div>
            <div class="tavif-tool-card">
                <h3>Clear Caches</h3>
                <p>Flush capability cache and re-detect conversion engines.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('timber_avif_tools'); ?>
                    <input type="hidden" name="action" value="timber_avif_tools" />
                    <input type="hidden" name="subaction" value="clear_cache" />
                    <input type="hidden" name="tab" value="tools" />
                    <button type="submit" class="button">Flush &amp; re-detect</button>
                </form>
            </div>
            <div class="tavif-tool-card">
                <h3>Process Queue</h3>
                <p><span id="tavif-queue-remaining"><?php echo esc_html($queue_count); ?></span> pending background conversions.</p>
                <button type="button" id="tavif-queue-start" class="button"<?php echo $queue_count === 0 ? ' disabled' : ''; ?>>Process now</button>
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
                $labels = ['all' => 'All', 'skipped' => 'Skipped', 'failed' => 'Failed', 'error' => 'Error'];
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
                <button type="submit" class="button" style="color:#b91c1c;" onclick="return confirm('Clear all logs?');">Clear logs</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($filtered)): ?>
            <p class="description">No log entries<?php echo $filter !== 'all' ? ' matching this filter' : ''; ?>.</p>
        <?php else: ?>
            <table class="tavif-log-table">
                <thead>
                    <tr>
                        <th style="width:145px;">Time</th>
                        <th>File</th>
                        <th style="width:60px;">Format</th>
                        <th style="width:80px;">Status</th>
                        <th>Reason</th>
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
            <p class="description" style="margin-top:12px;">Showing <?php echo count($filtered); ?> of <?php echo count($logs); ?> entries (max <?php echo self::MAX_LOG_ENTRIES; ?> kept). Oldest entries are auto-pruned.</p>
        <?php endif;
    }

    private static function render_statistics_tab(): void {
        $stats = self::get_statistics();
        ?>
        <div class="tavif-stats-grid">
            <div class="tavif-stat-card">
                <div class="stat-value"><?php echo esc_html($stats['total']); ?></div>
                <div class="stat-label">Total images</div>
            </div>
            <div class="tavif-stat-card tavif-stat-card--highlight">
                <div class="stat-value"><?php echo esc_html($stats['avif']); ?></div>
                <div class="stat-label">AVIF converted</div>
                <div class="stat-sub"><?php echo $stats['total'] > 0 ? round($stats['avif'] / $stats['total'] * 100) : 0; ?>%</div>
            </div>
            <div class="tavif-stat-card tavif-stat-card--highlight">
                <div class="stat-value"><?php echo esc_html($stats['webp']); ?></div>
                <div class="stat-label">WebP converted</div>
                <div class="stat-sub"><?php echo $stats['total'] > 0 ? round($stats['webp'] / $stats['total'] * 100) : 0; ?>%</div>
            </div>
        </div>
        <div class="tavif-progress">
            <h3>Conversion progress</h3>
            <?php $ap = $stats['total'] > 0 ? round($stats['avif'] / $stats['total'] * 100) : 0; $wp_ = $stats['total'] > 0 ? round($stats['webp'] / $stats['total'] * 100) : 0; ?>
            <div class="tavif-progress-row"><span class="bar-label">AVIF</span><div class="bar-wrap"><div class="bar-fill bar-fill--avif" style="width:<?php echo $ap; ?>%"></div></div><span class="bar-text"><?php echo $stats['avif']; ?>/<?php echo $stats['total']; ?></span></div>
            <div class="tavif-progress-row"><span class="bar-label">WebP</span><div class="bar-wrap"><div class="bar-fill bar-fill--webp" style="width:<?php echo $wp_; ?>%"></div></div><span class="bar-text"><?php echo $stats['webp']; ?>/<?php echo $stats['total']; ?></span></div>
        </div>
        <?php if ($stats['orig_size'] > 0): ?>
        <div class="tavif-stats-grid">
            <div class="tavif-stat-card"><div class="stat-value"><?php echo self::format_bytes($stats['orig_size']); ?></div><div class="stat-label">Original size</div></div>
            <?php if ($stats['avif_size'] > 0): ?><div class="tavif-stat-card tavif-stat-card--green"><div class="stat-value"><?php echo self::format_bytes($stats['orig_size'] - $stats['avif_size']); ?></div><div class="stat-label">Saved with AVIF</div><div class="stat-sub"><?php echo round(($stats['orig_size'] - $stats['avif_size']) / $stats['orig_size'] * 100); ?>%</div></div><?php endif; ?>
            <?php if ($stats['webp_size'] > 0): ?><div class="tavif-stat-card tavif-stat-card--green"><div class="stat-value"><?php echo self::format_bytes($stats['orig_size'] - $stats['webp_size']); ?></div><div class="stat-label">Saved with WebP</div><div class="stat-sub"><?php echo round(($stats['orig_size'] - $stats['webp_size']) / $stats['orig_size'] * 100); ?>%</div></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <p class="description">Statistics are cached for 5 minutes.</p>
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
        if (!empty($_GET['updated']))        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        if (!empty($_GET['converted']))      echo '<div class="notice notice-success is-dismissible"><p>Bulk conversion complete.</p></div>';
        if (!empty($_GET['cleared']))        echo '<div class="notice notice-success is-dismissible"><p>Caches cleared.</p></div>';
        if (isset($_GET['purged']))          echo '<div class="notice notice-success is-dismissible"><p>' . intval($_GET['purged']) . ' files deleted.</p></div>';
        if (!empty($_GET['queue_processed'])) echo '<div class="notice notice-success is-dismissible"><p>Queue processed.</p></div>';
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
            self::$settings['generate_avif_uploads']  = !empty($_POST['generate_avif_uploads']) && intval($_POST['generate_avif_uploads']) === 1;
            self::$settings['generate_webp_uploads']  = !empty($_POST['generate_webp_uploads']) && intval($_POST['generate_webp_uploads']) === 1;
            self::$settings['avif_quality']            = max(1, min(100, intval($_POST['avif_quality'] ?? self::DEFAULT_AVIF_QUALITY)));
            self::$settings['webp_quality']            = max(1, min(100, intval($_POST['webp_quality'] ?? self::DEFAULT_WEBP_QUALITY)));
            self::$settings['only_if_smaller']         = !empty($_POST['only_if_smaller']);
            self::$settings['max_dimension']           = max(512, intval($_POST['max_dimension'] ?? self::MAX_IMAGE_DIMENSION));
            self::$settings['max_file_size']           = max(1, intval($_POST['max_file_size'] ?? self::MAX_FILE_SIZE_MB));
            self::$settings['max_inline_conversions']  = max(0, min(50, intval($_POST['max_inline_conversions'] ?? self::MAX_INLINE_CONVERSIONS)));
            self::$settings['pregenerate_breakpoints'] = !empty($_POST['pregenerate_breakpoints']);
            self::$settings['breakpoint_widths']       = sanitize_text_field($_POST['breakpoint_widths'] ?? '');
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

        $do_avif = self::setting('generate_avif_uploads');
        $do_webp = self::setting('generate_webp_uploads');

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
            if ($do_avif) self::convert_file($p, 'avif', true);
            if ($do_webp) self::convert_file($p, 'webp', true);
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
                        if(running){cancelled=true;btn.prop('disabled',true).text('Stopping\u2026');return;}
                        running=true;cancelled=false;
                        btn.text('Cancel').removeClass('button-primary').addClass('button-secondary');
                        wrap.show();bar.css('width','0%');count.text('0 / \u2026');status.text('Starting\u2026');
                        run(0);
                    });
                    function run(offset){
                        if(cancelled){done('Cancelled at '+offset);return;}
                        $.post(url,{action:'timber_avif_bulk_batch',nonce:'" . esc_js($bulk_nonce) . "',offset:offset,batch_size:5},function(r){
                            if(!r.success){done('Error: '+(r.data||'unknown'));return;}
                            var d=r.data,pct=d.total>0?Math.round(d.processed/d.total*100):0;
                            bar.css('width',pct+'%');count.text(d.processed+' / '+d.total);status.text(pct+'%');
                            if(d.done)done('Done! '+d.processed+' images processed.');else run(d.processed);
                        }).fail(function(){done('Request failed.');});
                    }
                    function done(msg){running=false;cancelled=false;btn.prop('disabled',false).text('Convert all media').removeClass('button-secondary').addClass('button-primary');status.text(msg);bar.css('width','100%');}
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
                        btn.prop('disabled',true).text('Processing\u2026');
                        wrap.show();bar.css('width','0%');countEl.text('0');status.text('Starting\u2026');
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
                                if(d.done){done('Done! '+processed+' conversions processed.');}else{run();}
                            }).fail(function(){done('Request failed.');});
                        }
                    });
                    function done(msg){running=false;btn.prop('disabled',false).text('Process now');status.text(msg);bar.css('width','100%');remaining.text('0');}
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
        delete_transient('timber_avif_cap_avif');
        delete_transient('timber_avif_cap_webp');
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
