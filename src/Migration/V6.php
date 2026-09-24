<?php

namespace TimberAVIF\Migration;

use TimberAVIF\Cache;
use TimberAVIF\Cli;
use TimberAVIF\Config;
use TimberAVIF\Plugin;
use TimberAVIF\Worker;

/**
 * Everything that exists only to move a site from v6 (avif.php) to v7.
 *
 * Loaded while v6 is still loaded next to v7 (prepare mode), once when v7 first runs
 * where v6 left traces, and while v6's files are still on disk. A site that never ran v6
 * does not load it past that first check. To be deleted in v8, once every site has moved.
 */
final class V6 {
	const HOOKS   = ['timber_avif_process_queue', 'timber_avif_process_queue_now', 'timber_avif_cleanup_stale_locks'];
	const OPTIONS = ['timber_avif_queue', 'timber_avif_log', 'timber_avif_fail_gen'];

	// v6 wrote every default into the database on its first run, so its option cannot tell a
	// choice from a default nobody touched. These are the defaults v7 changed: a v6 option
	// holding one is read as v7's default. 65 is the AVIF quality 6.0–6.1.1 shipped with by mistake.
	const FROZEN = ['avif_quality' => [65], 'jpeg_quality' => [95]];

	/* ─────────────────────────────────────────────
	 * Prepare mode: v6 renders, v7 converts
	 * ───────────────────────────────────────────── */

	/**
	 * v6 owns the markup, the admin page and its CLI commands; v7 adds a notice and
	 * `wp timber-avif prepare`. Removing the require of avif.php switches to a library
	 * that is already converted: taking over straight away would serve every image as JPEG
	 * until the worker had been through it — on a real catalogue, pages three to fifteen
	 * times heavier for an hour or more.
	 */
	public static function prepare(): void {
		add_action('admin_notices', [self::class, 'notice']);
		if (defined('WP_CLI') && WP_CLI) \WP_CLI::add_command('timber-avif prepare', [self::class, 'cli_prepare']);
	}

	public static function notice(): void {
		if (!current_user_can('manage_options')) return;
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && !in_array($screen->id, ['dashboard', 'upload', 'settings_page_timber-avif-settings', 'plugins', 'themes'], true)) return;

		$total = Worker::count_sources();
		$pending = min($total, Worker::count_pending());

		echo '<div class="notice ' . ($pending ? 'notice-info' : 'notice-success') . '"><p><strong>Timber AVIF v7</strong> — ';
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
	 * Convert the library for v7 while v6 still serves the site.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Keep going until every image is ready.
	 *
	 * [--budget=<seconds>]
	 * : Seconds per pass. Default 60.
	 */
	public static function cli_prepare(array $args = [], array $assoc = []): void {
		\WP_CLI::log(sprintf('v7 is preparing alongside v6. %d of %d images ready.', Worker::count_sources() - Worker::count_pending(), Worker::count_sources()));
		Cli::work($args, $assoc);
		if (!Worker::count_pending()) \WP_CLI::success('Ready: remove the require of avif.php from functions.php and v7 takes over.');
	}

	/* ─────────────────────────────────────────────
	 * Settings v6 wrote
	 * ───────────────────────────────────────────── */

	/**
	 * A v6 option, without its frozen defaults. Config reads it through this both while v7
	 * prepares and when it takes over, so the settings in effect — and the fingerprint every
	 * prepared image is stamped with — are the same before and after the switch.
	 */
	public static function settings(array $saved): array {
		foreach (self::FROZEN as $key => $values) {
			if (isset($saved[$key]) && in_array((int) $saved[$key], $values, true)) unset($saved[$key]);
		}
		return $saved;
	}

	/* ─────────────────────────────────────────────
	 * The switch
	 * ───────────────────────────────────────────── */

	/**
	 * When a v7 version first runs: if v6 left traces, retire them. Settings are stored
	 * the way v7 reads them; v6's queue, log, crons and cached failures go; the page cache
	 * is purged, since every page cached while v6 rendered points at v6's files.
	 */
	public static function take_over(): void {
		$saved = get_option(Config::OPTION);
		$traces = (is_array($saved) && $saved && empty($saved[Config::SCHEMA])) || wp_next_scheduled(self::HOOKS[0]);
		if (!$traces) return;

		global $wpdb;
		Config::migrate();
		foreach (self::HOOKS as $hook) wp_clear_scheduled_hook($hook);
		foreach (self::OPTIONS as $option) delete_option($option);
		delete_transient('timber_avif_statistics_v2');
		// v6 kept a discarded conversion's verdict for a year, one transient per file.
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tavif\\_fail\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tavif\\_fail\\_%'");

		// Its files stay until they are removed from Tools; until then the tool is shown.
		update_option(Plugin::V6_LEFTOVERS, 1, true);
		Cache::purge_all(true);
	}

	/* ─────────────────────────────────────────────
	 * v6's files
	 * ───────────────────────────────────────────── */

	/** The Tools card, while v6's files may still be on disk. */
	public static function tool(): array {
		return [
			__('Remove v6 files', 'timber-avif'),
			__('Deletes the copies v6 wrote next to each file (photo.avif beside photo.jpg) and its leftover .lock files. v7 names its files differently and never reads those.', 'timber-avif'),
			__('Remove', 'timber-avif'),
			__('Delete every file left by v6?', 'timber-avif'),
			__('Also delete the JPEGs Timber resized for v6 (-640x0-c-default.jpg): v7 does not use them, and Timber rebuilds any a template still asks for with |resize.', 'timber-avif'),
		];
	}

	public static function register_cli(): void {
		\WP_CLI::add_command('timber-avif purge-v6', [self::class, 'cli_purge']);
	}

	/**
	 * Delete what v6 left behind.
	 *
	 * [--timber-resizes]
	 * : Also delete the JPEGs Timber resized for v6 (photo-640x0-c-default.jpg).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public static function cli_purge(array $args = [], array $assoc = []): void {
		\WP_CLI::confirm('Delete every file left by v6?', $assoc);
		\WP_CLI::success(sprintf('%d files deleted.', self::purge(!empty($assoc['timber-resizes']))));
	}

	/**
	 * Delete photo.avif / photo.webp next to photo.jpg — for the original, every sub-size and
	 * every Timber resize — and v6's .lock files. With $timber_resizes, Timber's resized
	 * JPEGs too (photo-640x0-c-default.jpg): v6 made them for its srcsets, v7 never reads
	 * them, and on one real site they were 1 GB.
	 *
	 * v7's own files keep the source extension (photo.jpg.avif), and every file WordPress
	 * lists for an attachment — uploaded AVIF/WebP originals, their sub-sizes, the sizes an
	 * edit keeps for undo — is protected, so none of those is touched.
	 */
	public static function purge(bool $timber_resizes = false): int {
		$base = wp_get_upload_dir()['basedir'];
		if (!is_dir($base)) return 0;

		$protected = self::attachment_files();
		$deleted = 0;
		$delete = static function (string $path) use (&$deleted, $protected): void {
			if (!isset($protected[wp_normalize_path($path)]) && @unlink($path)) $deleted++;
		};

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if (!$file->isFile()) continue;
			$name = $file->getFilename();
			$path = $file->getPathname();

			if (preg_match('/\.(avif|webp)\.lock$/i', $name)) {
				$delete($path);
				continue;
			}

			// A Timber resize, or v6's copy of one: the name alone says so, whatever order they are found in.
			if (preg_match('/^(.+)-\d+x\d+-c-[a-z]+\.(jpe?g|png|gif|webp|avif)$/i', $name, $m)) {
				if ($timber_resizes || in_array(strtolower($m[2]), ['avif', 'webp'], true)) $delete($path);
				continue;
			}

			if (!preg_match('/^(.+)\.(avif|webp)$/i', $name, $m)) continue;
			[$stem, $ext] = [$m[1], strtolower($m[2])];
			if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $stem)) continue;

			$sources = $ext === 'avif' ? ['jpg', 'jpeg', 'png', 'gif', 'webp'] : ['jpg', 'jpeg', 'png', 'gif'];
			foreach ($sources as $source) {
				if (is_file("{$file->getPath()}/$stem.$source") || is_file("{$file->getPath()}/$stem." . strtoupper($source))) {
					$delete($path);
					break;
				}
			}
		}

		delete_option(Plugin::V6_LEFTOVERS);
		// No page of v7's points at these files; a cache still holding pages from before the switch would.
		Cache::purge_all(true);
		return $deleted;
	}

	/**
	 * Normalized paths of every file WordPress lists for an attachment: the attached file,
	 * the original behind a -scaled one, the sub-sizes, and the sizes an edit keeps for undo.
	 *
	 * @return array<string, true>
	 */
	private static function attachment_files(): array {
		global $wpdb;
		$basedir = trailingslashit(wp_get_upload_dir()['basedir']);
		$protected = [];

		$rows = $wpdb->get_results(
			"SELECT a.meta_value AS file, m.meta_value AS meta, b.meta_value AS backup
			FROM {$wpdb->postmeta} a
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.post_id AND m.meta_key = '_wp_attachment_metadata'
			LEFT JOIN {$wpdb->postmeta} b ON b.post_id = a.post_id AND b.meta_key = '_wp_attachment_backup_sizes'
			WHERE a.meta_key = '_wp_attached_file'"
		);
		foreach ($rows as $row) {
			$file = (string) $row->file;
			if ($file === '') continue;
			$path = str_starts_with($file, '/') ? $file : $basedir . $file;
			$dir = dirname($path);
			$protected[wp_normalize_path($path)] = true;

			$meta = maybe_unserialize((string) $row->meta);
			if (is_array($meta)) {
				if (!empty($meta['original_image'])) $protected[wp_normalize_path("$dir/{$meta['original_image']}")] = true;
				foreach ((array) ($meta['sizes'] ?? []) as $size) {
					if (!empty($size['file'])) $protected[wp_normalize_path("$dir/{$size['file']}")] = true;
				}
			}
			foreach ((array) maybe_unserialize((string) $row->backup) as $size) {
				if (is_array($size) && !empty($size['file'])) $protected[wp_normalize_path("$dir/{$size['file']}")] = true;
			}
		}
		return $protected;
	}
}
