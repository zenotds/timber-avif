<?php

namespace TimberAVIF;

/**
 * What exists for an attachment, kept in its own post meta.
 *
 * v6 had no index: every render asked the disk (`file_exists`, `filesize`, and two
 * queries for the failure transient of each variant it had discarded). Post meta is
 * loaded in one query with everything else about the attachment, so reading this is
 * free, and deleting the attachment knows exactly which files to remove.
 *
 * Shape, keyed by the name of the file each entry stands in for:
 *
 *     'avif'  => [ 'photo-640x427.jpg' => ['file' => 'photo-640x427.jpg.avif', 'bytes' => …, 'src_bytes' => …, 'key' => …],
 *                  'photo-scaled.jpg'  => ['skip' => 'larger', 'key' => …, 'at' => …] ],
 *     'crops' => [ '4x1' => [ ['w' => 640, 'h' => 160, 'file' => 'photo-640x160-tavif.jpg'], … ] ],
 *     'anim'  => true    // animated source: served as it is
 *
 * Modern files are named after the file they replace plus the new extension. v6 swapped
 * the extension, so photo.jpg, photo.png and an uploaded photo.avif all claimed the same
 * path.
 */
final class Index {
	const META     = '_tavif';
	const STAMP    = '_tavif_fp';       // Config::fingerprint() of the last finished pass
	const RETRY    = '_tavif_retry';    // timestamp: retry transient failures after it
	const ATTEMPTS = '_tavif_attempts'; // passes started and not finished: a crash counter
	const ISSUE    = '_tavif_issue';    // last problem worth showing in the admin
	const RATIO    = '_tavif_ratio';    // one row per crop ratio a template asked for

	public static function get(int $id): array {
		$index = get_post_meta($id, self::META, true);
		return is_array($index) ? $index : [];
	}

	public static function put(int $id, array $index): void {
		update_post_meta($id, self::META, $index);
	}

	/** @return string[] */
	public static function ratios(int $id): array {
		return array_values(array_unique(array_map('strval', (array) get_post_meta($id, self::RATIO, false))));
	}

	/**
	 * A template asked for a crop the worker has never built. One row per ratio, so this
	 * never races the worker writing the index.
	 */
	public static function want_ratio(int $id, string $key): void {
		if (in_array($key, self::ratios($id), true)) return;
		add_post_meta($id, self::RATIO, $key);
		Worker::stale($id);
	}

	/**
	 * Absolute paths of every file the index owns: modern copies and crop sources.
	 */
	public static function files(int $id, array $index): array {
		$attached = get_attached_file($id);
		if (!$attached) return [];
		$dir = dirname($attached) . '/';

		$files = [];
		foreach (['avif', 'webp'] as $format) {
			foreach ((array) ($index[$format] ?? []) as $entry) {
				if (!empty($entry['file'])) $files[] = $dir . $entry['file'];
			}
		}
		foreach ((array) ($index['crops'] ?? []) as $crops) {
			foreach ((array) $crops as $crop) {
				if (!empty($crop['file'])) $files[] = $dir . $crop['file'];
			}
		}
		return $files;
	}

	/**
	 * `delete_attachment`. WordPress removes the sub-sizes it knows about and Timber the
	 * resizes it made; nothing removed v6's copies, which stayed on disk forever.
	 */
	public static function delete_files(int $id): int {
		$deleted = 0;
		foreach (self::files($id, self::get($id)) as $path) {
			if (is_file($path) && @unlink($path)) $deleted++;
		}
		return $deleted;
	}

	/**
	 * Drop every file and every trace, leaving the attachment pending. The crop ratios
	 * templates asked for are kept: they are still wanted.
	 */
	public static function forget(int $id): int {
		$deleted = self::delete_files($id);
		foreach ([self::META, self::STAMP, self::RETRY, self::ATTEMPTS, self::ISSUE] as $key) delete_post_meta($id, $key);
		return $deleted;
	}
}
