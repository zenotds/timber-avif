<?php

namespace TimberAVIF;

/**
 * The destructive tools, shared by the admin and WP-CLI.
 */
final class Tools {

	/**
	 * Delete every file Timber AVIF made and every stamp, so the whole library is pending.
	 */
	public static function purge(): int {
		global $wpdb;
		$deleted = 0;

		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Index::META)));
		foreach (array_chunk($ids, 200) as $chunk) {
			update_meta_cache('post', $chunk);
			foreach ($chunk as $id) $deleted += Index::forget($id);
		}

		// Stamps can exist without an index (animated or already-modern sources): those go too.
		foreach ([Index::STAMP, Index::RETRY, Index::ATTEMPTS, Index::ISSUE] as $key) {
			delete_metadata('post', 0, $key, '', true);
		}

		return $deleted;
	}

	/**
	 * Delete what v6 left in uploads: photo.avif / photo.webp next to photo.jpg — for the
	 * original, every sub-size and every Timber resize — and its .lock files. With
	 * $timber_resizes, Timber's resized JPEGs too (photo-640x0-c-default.jpg): v6 made them
	 * for its srcsets, v7 never reads them, and on one real site they were 1 GB.
	 *
	 * v7's own files keep the source extension (photo.jpg.avif), and every file WordPress
	 * lists for an attachment — uploaded AVIF/WebP originals, their sub-sizes, the sizes an
	 * edit keeps for undo — is protected, so none of those is touched.
	 */
	public static function purge_v6(bool $timber_resizes = false): int {
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
