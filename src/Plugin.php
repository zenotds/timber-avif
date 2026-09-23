<?php

namespace TimberAVIF;

/**
 * Wiring.
 *
 * Two modes. Normally v7 does everything. With v6's avif.php still loaded it *prepares*:
 * the worker converts the library in the background while v6 keeps rendering the site,
 * so that removing avif.php switches to a library that is already converted. Taking over
 * straight away would serve every image as JPEG until the worker had been through it —
 * on a real catalogue, pages three to fifteen times heavier for an hour or more.
 */
final class Plugin {
	const VERSION = '7.0.0-dev';
	const VERSION_OPTION = 'timber_avif_version';

	// v6 hooks and storage, retired when v7 takes over.
	const V6_HOOKS   = ['timber_avif_process_queue', 'timber_avif_process_queue_now', 'timber_avif_cleanup_stale_locks'];
	const V6_OPTIONS = ['timber_avif_queue', 'timber_avif_log', 'timber_avif_fail_gen'];

	private static bool $loaded = false;
	private static bool $preparing = false;

	/**
	 * Start Timber AVIF. From functions.php, after vendor/autoload.php — like Timber::init().
	 *
	 * Called explicitly rather than from Composer's autoloader, which also runs where
	 * WordPress is not loaded (a PHPUnit bootstrap, a build script): there add_action()
	 * does not exist, and an autoloaded file calling it is a fatal error.
	 */
	public static function load(): void {
		if (self::$loaded || !function_exists('add_action')) return;
		self::$loaded = true;

		if (!defined('TIMBER_AVIF_DIR')) define('TIMBER_AVIF_DIR', dirname(__DIR__));

		// Booted once the theme has loaded, so a v6 avif.php required after this call is seen too.
		add_action('after_setup_theme', [self::class, 'boot'], 1);
	}

	public static function preparing(): bool {
		return self::$preparing;
	}

	public static function boot(): void {
		self::$preparing = self::v6_loaded();

		foreach (['add_option_', 'update_option_', 'delete_option_'] as $hook) add_action($hook . Config::OPTION, [Config::class, 'forget']);

		// Both modes: the files v7 makes, and the worker that makes them.
		Sizes::register();
		add_filter('intermediate_image_sizes_advanced', [Sizes::class, 'skip_redundant'], 10, 2);

		// Quality for every encode WordPress runs, not only ours: sub-sizes and the -scaled
		// original too. After v6's filters, so that while preparing the files v7 makes are
		// encoded the way they will be served.
		add_filter('wp_editor_set_quality', [self::class, 'quality'], 20, 2);
		add_filter('jpeg_quality', fn() => (int) Config::get('jpeg_quality'), 20);
		// Ceiling on the uploaded original: past this width WordPress scales down and keeps the -scaled file.
		add_filter('big_image_size_threshold', fn() => (int) Config::get('max_upload_dimension'), 20);

		add_filter('wp_update_attachment_metadata', [self::class, 'on_metadata'], 10, 2);
		add_action('delete_attachment', [Index::class, 'delete_files']);

		add_action(Worker::HOOK, [Worker::class, 'on_cron']);
		add_action(Worker::HEARTBEAT, [Worker::class, 'heartbeat']);
		add_action('init', [self::class, 'init']);
		add_action('switch_theme', [self::class, 'unschedule']);

		if (is_admin()) {
			add_action('init', [self::class, 'load_textdomain'], 0);
			add_action('shutdown', [Worker::class, 'on_admin_shutdown'], 99);
		}

		if (self::$preparing) {
			// v6 owns the markup, the admin page and its CLI commands; v7 adds a notice and `wp timber-avif prepare`.
			add_action('admin_notices', [self::class, 'prepare_notice']);
			if (defined('WP_CLI') && WP_CLI) Cli::register_prepare();
			return;
		}

		add_filter('timber/twig', [Twig::class, 'register']);
		add_filter('timber/locations', [Twig::class, 'locations']);
		add_filter('timber/post/classmap', [Twig::class, 'classmap'], 20);
		// Before a theme's own filter at 10, which then finds the <picture> already there.
		add_filter('wp_content_img_tag', [Content::class, 'img_tag'], 9, 3);

		// Themes written against v6 call its static API; the class exists again, backed by v7.
		require_once TIMBER_AVIF_DIR . '/compat/v6.php';

		if (is_admin()) Admin::boot();
		if (defined('WP_CLI') && WP_CLI) Cli::register();
	}

	/**
	 * v6's avif.php defines a global TimberAVIF class. So does v7's compatibility layer, but
	 * only after this check, and with v7's version.
	 */
	private static function v6_loaded(): bool {
		return class_exists('TimberAVIF', false)
			&& defined('TimberAVIF::VERSION')
			&& version_compare((string) \TimberAVIF::VERSION, '7', '<');
	}

	public static function init(): void {
		if (!self::$preparing && get_option(self::VERSION_OPTION) !== self::VERSION) self::migrate();

		// The heartbeat only wakes the worker if something is pending, for the rare case where every other trigger was missed.
		if (!wp_next_scheduled(Worker::HEARTBEAT)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', Worker::HEARTBEAT);
	}

	/**
	 * Once per version, when v7 is the one rendering. From v6: settings stored the way v7
	 * reads them, its queue, log, crons and failure transients dropped. Nothing is queued
	 * explicitly: an attachment without a v7 stamp is pending by definition, and one the
	 * worker prepared while v6 ran is already done.
	 */
	private static function migrate(): void {
		global $wpdb;

		Config::migrate();

		foreach (self::V6_HOOKS as $hook) wp_clear_scheduled_hook($hook);
		foreach (self::V6_OPTIONS as $option) delete_option($option);
		delete_transient('timber_avif_statistics_v2');
		// v6 kept a discarded conversion's verdict for a year, one transient per file.
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tavif\\_fail\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tavif\\_fail\\_%'");

		update_option(self::VERSION_OPTION, self::VERSION, true);
		Worker::hint();
		Worker::wake();
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook(Worker::HOOK);
		wp_clear_scheduled_hook(Worker::HEARTBEAT);
	}

	public static function quality($quality, $mime) {
		return match ($mime) {
			'image/jpeg' => (int) Config::get('jpeg_quality'),
			'image/avif' => Config::quality('avif'),
			'image/webp' => Config::quality('webp'),
			default      => $quality,
		};
	}

	/**
	 * Any change to an image's metadata — upload, regenerate, an edit in the media
	 * modal — may have changed its files: the next pass re-checks it. Half a minute later,
	 * so the pass does not race the uploader, which saves the metadata once per sub-size.
	 */
	public static function on_metadata($data, $id) {
		if (in_array(get_post_mime_type((int) $id), Config::SOURCE_MIMES, true)) Worker::stale((int) $id, 30);
		return $data;
	}

	/**
	 * While v6 renders: how far the preparation has got, and what to do when it is done.
	 */
	public static function prepare_notice(): void {
		if (!current_user_can('manage_options')) return;
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && !in_array($screen->id, ['dashboard', 'upload', 'settings_page_timber-avif-settings', 'plugins', 'themes'], true)) return;

		$total = Worker::count_sources();
		$pending = min($total, Worker::count_pending());
		$class = $pending ? 'notice-info' : 'notice-success';

		echo '<div class="notice ' . $class . '"><p><strong>Timber AVIF v7</strong> — ';
		if ($pending) {
			printf(
				/* translators: 1: images ready, 2: images in the library */
				esc_html__('preparing the library while v6 serves the site: %1$s of %2$s images ready. They are converted in the background; `wp timber-avif prepare --all` does it in one go.', 'timber-avif'),
				esc_html(number_format_i18n($total - $pending)),
				esc_html(number_format_i18n($total))
			);
		} else {
			esc_html_e('the library is ready. Remove the require of avif.php from functions.php: v7 takes over with nothing left to convert.', 'timber-avif');
		}
		echo '</p></div>';
	}

	/**
	 * Admin strings ship in English and a .mo translates them. Looked up in the child
	 * theme, the parent theme, then next to this package.
	 */
	public static function load_textdomain(): void {
		$file = 'timber-avif-' . determine_locale() . '.mo';
		foreach ([get_stylesheet_directory(), get_template_directory(), TIMBER_AVIF_DIR] as $dir) {
			if (is_readable("$dir/languages/$file")) {
				load_textdomain('timber-avif', "$dir/languages/$file");
				return;
			}
		}
	}
}
