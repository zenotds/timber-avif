<?php

namespace TimberAVIF;

/**
 * Wiring.
 */
final class Plugin {
	const VERSION = '7.0.0';
	const VERSION_OPTION = 'timber_avif_version';
	// Set when v7 takes over from v6 and cleared once v6's files are removed: until then
	// Tools and the CLI offer to remove them. Here, not in Migration\V6, so that reading
	// it never loads the migration code.
	const V6_LEFTOVERS = 'timber_avif_v6_leftovers';

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

	/** v6's avif.php is loaded too, and renders the site: v7 only converts. */
	public static function preparing(): bool {
		return self::$preparing;
	}

	public static function boot(): void {
		// v6 declares a global TimberAVIF class; v7 declares none.
		self::$preparing = class_exists('TimberAVIF', false);

		foreach (['add_option_', 'update_option_', 'delete_option_'] as $hook) add_action($hook . Config::OPTION, [Config::class, 'forget']);

		// Both modes: the files v7 makes, and the worker that makes them.
		Sizes::register();
		add_filter('intermediate_image_sizes_advanced', [Sizes::class, 'skip_redundant'], 10, 2);

		// Quality for every encode WordPress runs, not only ours: sub-sizes and the -scaled
		// original too. After v6's own filters, when it is loaded.
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
			Migration\V6::prepare();
			return;
		}

		add_filter('timber/twig', [Twig::class, 'register']);
		add_filter('timber/locations', [Twig::class, 'locations']);
		// Before a theme's own filter at 10, which then finds the <picture> already there.
		add_filter('wp_content_img_tag', [Content::class, 'img_tag'], 9, 3);
		Cache::boot();

		if (is_admin()) Admin::boot();
		if (defined('WP_CLI') && WP_CLI) Cli::register();
	}

	public static function init(): void {
		// Once per version, when v7 is the one rendering. An attachment without a v7 stamp is
		// pending by definition, so nothing is queued explicitly.
		if (!self::$preparing && get_option(self::VERSION_OPTION) !== self::VERSION) {
			Migration\V6::take_over();
			update_option(self::VERSION_OPTION, self::VERSION, true);
			Worker::hint();
			Worker::wake();
		}

		// The heartbeat only wakes the worker if something is pending, for the rare case where every other trigger was missed.
		if (!wp_next_scheduled(Worker::HEARTBEAT)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', Worker::HEARTBEAT);
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
	 * Any change to an image's metadata — upload, regenerate, an edit in the media modal,
	 * an import replacing the file — may have changed its files: the next pass re-checks
	 * it, half a minute later so it does not race the uploader, which saves the metadata
	 * once per sub-size. A file replaced in place stops being served in the modern format
	 * right away: its copies are of the old picture.
	 */
	public static function on_metadata($data, $id) {
		$id = (int) $id;
		if (!in_array(get_post_mime_type($id), Config::SOURCE_MIMES, true)) return $data;
		if (Index::drop_if_replaced($id)) Worker::changed($id);
		Worker::stale($id, 30);
		return $data;
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
