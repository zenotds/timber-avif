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
	 * original, every sub-size and every Timber resize — and its .lock files.
	 *
	 * v7's own files keep the source extension (photo.jpg.avif), and uploaded AVIF/WebP
	 * originals with their sub-sizes are protected, so neither is touched.
	 */
	public static function purge_v6(): int {
		$base = wp_get_upload_dir()['basedir'];
		if (!is_dir($base)) return 0;

		$protected = self::uploaded_modern_files();
		$deleted = 0;

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if (!$file->isFile()) continue;
			$name = $file->getFilename();
			$path = $file->getPathname();

			if (preg_match('/\.(avif|webp)\.lock$/i', $name)) {
				if (@unlink($path)) $deleted++;
				continue;
			}

			if (!preg_match('/^(.+)\.(avif|webp)$/i', $name, $m)) continue;
			[$stem, $ext] = [$m[1], strtolower($m[2])];
			if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $stem)) continue;
			if (isset($protected[wp_normalize_path($path)])) continue;

			$sources = $ext === 'avif' ? ['jpg', 'jpeg', 'png', 'gif', 'webp'] : ['jpg', 'jpeg', 'png', 'gif'];
			foreach ($sources as $source) {
				if (is_file("{$file->getPath()}/$stem.$source") || is_file("{$file->getPath()}/$stem." . strtoupper($source))) {
					if (@unlink($path)) $deleted++;
					break;
				}
			}
		}

		return $deleted;
	}

	/** @return array<string, true> Normalized paths of uploaded AVIF/WebP originals and their sub-sizes. */
	private static function uploaded_modern_files(): array {
		$protected = [];
		$ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => ['image/avif', 'image/webp'], 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
		foreach ($ids as $id) {
			$file = get_attached_file($id);
			if (!$file) continue;
			$dir = dirname($file);
			$protected[wp_normalize_path($file)] = true;
			$meta = wp_get_attachment_metadata($id);
			if (!empty($meta['original_image'])) $protected[wp_normalize_path("$dir/{$meta['original_image']}")] = true;
			foreach ((array) ($meta['sizes'] ?? []) as $size) {
				if (!empty($size['file'])) $protected[wp_normalize_path("$dir/{$size['file']}")] = true;
			}
		}
		return $protected;
	}
}
