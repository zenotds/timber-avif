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
 *     'crops' => [ '4x1' => [ ['w' => 640, 'h' => 160, 'file' => 'photo-scaled-640x160-tavif.jpg'], … ] ],
 *     'extra' => [ ['w' => 160, 'h' => 107, 'file' => 'photo-scaled-160x107-tavif.jpg'] ],  // widths templates asked for
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
	const WANT     = '_tavif_want';     // one row per crop or width a template asked for

	public static function get(int $id): array {
		$index = get_post_meta($id, self::META, true);
		return is_array($index) ? $index : [];
	}

	public static function put(int $id, array $index): void {
		update_post_meta($id, self::META, $index);
	}

	/**
	 * Was the file every copy is encoded from replaced since? An import or a sync that writes
	 * a new picture over the old file keeps its name and URL, so without this its copies
	 * would go on showing the old picture. An index from before the record existed counts
	 * as unchanged.
	 */
	public static function source_changed(int $id, array $index): bool {
		if (empty($index['source'])) return false;
		$attached = get_attached_file($id);
		if (!$attached || !is_file($attached)) return false;
		return self::source_record($attached) !== $index['source'];
	}

	/**
	 * Name, size and a hash of the first 256 KB. Size alone is not enough: two flat images
	 * of the same dimensions can weigh the same to the byte. The hash is read by the worker
	 * and on a metadata update, never while a page renders.
	 */
	public static function source_record(string $path): array {
		clearstatcache(true, $path);
		$head = (string) @file_get_contents($path, false, null, 0, 262144);
		return ['file' => wp_basename($path), 'bytes' => (int) filesize($path), 'hash' => substr(md5($head), 0, 16)];
	}

	/**
	 * Stop serving everything made from a replaced file: modern copies, crops, extra widths.
	 * The renderer falls back to the new JPEG, uncropped, until the worker has made them
	 * again. The files stay for now, listed as 'old' — a cached page may still point at
	 * them — and the worker deletes those it did not write over once it has finished.
	 */
	public static function forget_source(array $index): array {
		$index['old'] = array_values(array_unique(self::owned_names($index)));
		unset($index['avif'], $index['webp'], $index['crops'], $index['extra'], $index['source']);
		$index['remake'] = true;
		return $index;
	}

	/**
	 * Names of every file the index owns, in the attachment's folder: modern copies, crops,
	 * extra widths, and what was set aside as 'old'.
	 *
	 * @return string[]
	 */
	public static function owned_names(array $index): array {
		$names = (array) ($index['old'] ?? []);
		foreach (['avif', 'webp'] as $format) {
			foreach ((array) ($index[$format] ?? []) as $entry) {
				if (!empty($entry['file'])) $names[] = (string) $entry['file'];
			}
		}
		foreach ((array) ($index['crops'] ?? []) as $crops) {
			foreach ((array) $crops as $crop) {
				if (!empty($crop['file'])) $names[] = (string) $crop['file'];
			}
		}
		foreach ((array) ($index['extra'] ?? []) as $extra) {
			if (!empty($extra['file'])) $names[] = (string) $extra['file'];
		}
		return $names;
	}

	public static function drop_if_replaced(int $id): bool {
		$index = self::get($id);
		if (!self::source_changed($id, $index)) return false;
		self::put($id, self::forget_source($index));
		return true;
	}

	/**
	 * What templates asked for beyond the configured widths: a crop ('4x1'), a width for an
	 * image displayed small ('@320'), or both ('1x1@320').
	 *
	 * @return array<int, array{ratio: string, width: int}>
	 */
	public static function wants(int $id): array {
		$wants = [];
		foreach (array_unique(array_map('strval', (array) get_post_meta($id, self::WANT, false))) as $spec) {
			[$ratio, $width] = array_pad(explode('@', $spec, 2), 2, '');
			$wants[] = ['ratio' => $ratio, 'width' => (int) $width];
		}
		return $wants;
	}

	/**
	 * A template asked for something the worker has never built. One row per request, so
	 * this never races the worker writing the index.
	 */
	public static function want(int $id, string $ratio, int $width = 0): void {
		$spec = $ratio . ($width ? '@' . $width : '');
		if ($spec === '' || in_array($spec, array_map('strval', (array) get_post_meta($id, self::WANT, false)), true)) return;
		add_post_meta($id, self::WANT, $spec);
		Worker::stale($id);
	}

	/**
	 * Absolute paths of every file the index owns (owned_names()).
	 */
	public static function files(int $id, array $index): array {
		$attached = get_attached_file($id);
		if (!$attached) return [];
		$dir = dirname($attached) . '/';
		return array_map(fn($name) => $dir . $name, array_values(array_unique(self::owned_names($index))));
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
	 * Drop every file and every trace, leaving the attachment pending. What templates asked
	 * for is kept: it is still wanted.
	 */
	public static function forget(int $id): int {
		$deleted = self::delete_files($id);
		foreach ([self::META, self::STAMP, self::RETRY, self::ATTEMPTS, self::ISSUE] as $key) delete_post_meta($id, $key);
		return $deleted;
	}
}
