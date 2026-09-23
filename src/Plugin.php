<?php

namespace TimberAVIF;

/**
 * Wiring.
 */
final class Plugin {
	const VERSION = '7.0.0-dev';
	const VERSION_OPTION = 'timber_avif_version';

	// v6 hooks and storage, retired on the first v7 request.
	const V6_HOOKS   = ['timber_avif_process_queue', 'timber_avif_process_queue_now', 'timber_avif_cleanup_stale_locks'];
	const V6_OPTIONS = ['timber_avif_queue', 'timber_avif_log', 'timber_avif_fail_gen'];

	public static function load(): void {
		// Booted once the theme has loaded, so a v6 avif.php required after this file is seen too.
		add_action('after_setup_theme', [self::class, 'boot'], 1);
	}

	public static function boot(): void {
		if (class_exists('TimberAVIF', false)) {
			add_action('admin_notices', [self::class, 'v6_notice']);
			return;
		}

		Sizes::register();
		add_filter('intermediate_image_sizes_advanced', [Sizes::class, 'skip_redundant'], 10, 2);

		// Quality for every encode WordPress runs, not only ours: sub-sizes and the -scaled original too.
		add_filter('wp_editor_set_quality', [self::class, 'quality'], 10, 2);
		add_filter('jpeg_quality', fn() => (int) Config::get('jpeg_quality'));
		// Ceiling on the uploaded original: past this width WordPress scales down and keeps the -scaled file.
		add_filter('big_image_size_threshold', fn() => (int) Config::get('max_upload_dimension'));

		add_filter('wp_update_attachment_metadata', [self::class, 'on_metadata'], 10, 2);
		add_action('delete_attachment', [Index::class, 'delete_files']);

		add_filter('timber/twig', [Twig::class, 'register']);
		add_filter('timber/locations', [Twig::class, 'locations']);
		add_filter('timber/post/classmap', [Twig::class, 'classmap'], 20);

		add_action(Worker::HOOK, [Worker::class, 'on_cron']);
		add_action(Worker::HEARTBEAT, [Worker::class, 'heartbeat']);
		add_action('init', [self::class, 'init']);
		add_action('switch_theme', [self::class, 'unschedule']);

		if (is_admin()) {
			add_action('init', [self::class, 'load_textdomain'], 0);
			Admin::boot();
			add_action('shutdown', [Worker::class, 'on_admin_shutdown'], 99);
		}

		if (defined('WP_CLI') && WP_CLI) Cli::register();
	}

	public static function init(): void {
		if (get_option(self::VERSION_OPTION) !== self::VERSION) self::migrate();

		// The heartbeat only wakes the worker if something is pending, for the rare case where every other trigger was missed.
		if (!wp_next_scheduled(Worker::HEARTBEAT)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', Worker::HEARTBEAT);
	}

	/**
	 * Once per version. From v6: settings pruned, its queue, log, crons and failure
	 * transients dropped. Nothing is queued explicitly: no attachment has a v7 stamp yet,
	 * so they are all pending by definition.
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
	 * modal — may have changed its files: the next pass re-checks it.
	 */
	public static function on_metadata($data, $id) {
		if (in_array(get_post_mime_type((int) $id), Config::SOURCE_MIMES, true)) Worker::stale((int) $id);
		return $data;
	}

	public static function v6_notice(): void {
		if (!current_user_can('manage_options')) return;
		echo '<div class="notice notice-error"><p><strong>Timber AVIF:</strong> ' . esc_html__('v6 (avif.php) is still loaded, so v7 stays off. Remove the require of avif.php from functions.php.', 'timber-avif') . '</p></div>';
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
