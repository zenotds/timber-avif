<?php

namespace TimberAVIF\Optimize;

use TimberAVIF\Index;

/**
 * Files in the uploads folders that no attachment owns and no index lists, recognised by the
 * names this package writes — 'orphans', deleted by Optimize:
 *
 *     photo-640x427.jpg.avif               a modern copy whose attachment is gone
 *     photo-640x160-tavif.jpg              a crop or width built on request, the same
 *     photo-640x427.jpg.tavif-Ab12Cd.avif  an encode a crash left half-written
 *     photo-640x427.avif.lock              a lock the older version left
 *
 * and by the names Timber writes next to the originals — 'timber', deleted only when asked,
 * since a template may still ask for them, and Timber then makes them again on the first
 * view of the page:
 *
 *     photo-640x0-c-default.jpg            a resize (|resize)
 *     photo.webp, photo-640x427.avif       a conversion (|towebp), or the older version's copy
 *
 * Nothing else is touched, whatever it is: a file uploaded by FTP and linked by URL is not
 * an attachment either. Where another plugin writes WebP or AVIF copies next to the
 * originals (EWWW, ShortPixel, Imagify…), under the same names, a copy counts only once its
 * original is gone.
 */
final class Orphans {
	// Plugins that write photo.webp or photo.jpg.webp next to photo.jpg, as constants they define.
	const CONVERTERS = ['EWWW_IMAGE_OPTIMIZER_VERSION', 'WEBPEXPRESS_PLUGIN', 'IMAGIFY_VERSION', 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION', 'WP_SMUSH_VERSION', 'WEBPC_VERSION', 'LSCWP_V'];

	/** @return string[] The folders uploads go to: year/month, and the uploads folder itself. */
	public static function dirs(): array {
		$base = wp_get_upload_dir()['basedir'];
		if (!is_dir($base)) return [];
		$dirs = [$base];
		foreach (glob("$base/[0-9][0-9][0-9][0-9]/[0-1][0-9]", GLOB_ONLYDIR) ?: [] as $dir) $dirs[] = $dir;
		return $dirs;
	}

	/**
	 * @return array{orphans: array{files: string[], bytes: int}, timber: array{files: string[], bytes: int}}
	 */
	public static function scan(string $dir): array {
		$out = ['orphans' => ['files' => [], 'bytes' => 0], 'timber' => ['files' => [], 'bytes' => 0]];
		$names = @scandir($dir) ?: [];
		if (!$names) return $out;
		$present = array_flip($names);
		[$protected, $owned] = self::known($dir);
		$foreign = self::foreign_converter();

		foreach ($names as $name) {
			if ($name[0] === '.' || isset($protected[$name]) || isset($owned[$name])) continue;
			$kind = self::kind($name, $present, $foreign);
			if ($kind === null) continue;
			$path = "$dir/$name";
			if (!is_file($path)) continue;
			$out[$kind]['files'][] = $path;
			$out[$kind]['bytes'] += (int) filesize($path);
		}
		return $out;
	}

	public static function foreign_converter(): bool {
		foreach (self::CONVERTERS as $constant) if (defined($constant)) return true;
		return false;
	}

	/** 'orphans', 'timber', or null for a file that is not ours to judge. */
	private static function kind(string $name, array $present, bool $foreign): ?string {
		if (preg_match('/\.(avif|webp)\.lock$/i', $name)) return 'orphans';
		if (preg_match('/\.tavif-[A-Za-z0-9]{6}\.(avif|webp|jpe?g|png|gif)$/i', $name)) return 'orphans';
		if (preg_match('/^(.+\.(jpe?g|png|gif|webp))\.(avif|webp)$/i', $name, $m)) {
			return ($foreign && isset($present[$m[1]])) ? null : 'orphans';
		}
		if (preg_match('/-\d+x\d+-tavif\.(jpe?g|png|gif|webp)$/i', $name)) return 'orphans';
		if (preg_match('/-\d+x\d+-c-[a-z]+\.(jpe?g|png|gif|webp|avif)$/i', $name, $m)) {
			return ($foreign && in_array(strtolower($m[1]), ['avif', 'webp'], true)) ? null : 'timber';
		}
		if (!$foreign && preg_match('/^(.+)\.(avif|webp)$/i', $name, $m)) {
			$sources = strtolower($m[2]) === 'avif' ? ['jpg', 'jpeg', 'png', 'gif', 'webp'] : ['jpg', 'jpeg', 'png', 'gif'];
			foreach ($sources as $ext) {
				if (isset($present["{$m[1]}.$ext"]) || isset($present[$m[1] . '.' . strtoupper($ext)])) return 'timber';
			}
		}
		return null;
	}

	/**
	 * The names in $dir that attachments own — the attached file, the original behind a
	 * `-scaled` one, the sub-sizes, the sizes an edit keeps for undo — and those the indexes
	 * of those attachments list.
	 *
	 * @return array{0: array<string, true>, 1: array<string, true>}
	 */
	private static function known(string $dir): array {
		global $wpdb;
		$base = trailingslashit(wp_get_upload_dir()['basedir']);
		$rel = ltrim(substr(wp_normalize_path($dir), strlen(wp_normalize_path($base))), '/');
		// Attached files in this folder, not in its subfolders.
		$like = $rel === '' ? '%' : $wpdb->esc_like($rel . '/') . '%';

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT a.post_id, a.meta_value AS file, m.meta_value AS meta, b.meta_value AS backup, i.meta_value AS idx
			FROM {$wpdb->postmeta} a
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.post_id AND m.meta_key = '_wp_attachment_metadata'
			LEFT JOIN {$wpdb->postmeta} b ON b.post_id = a.post_id AND b.meta_key = '_wp_attachment_backup_sizes'
			LEFT JOIN {$wpdb->postmeta} i ON i.post_id = a.post_id AND i.meta_key = %s
			WHERE a.meta_key = '_wp_attached_file' AND a.meta_value LIKE %s",
			Index::META,
			$like
		));

		$protected = [];
		$owned = [];
		foreach ($rows as $row) {
			$file = (string) $row->file;
			if (dirname($file) !== ($rel === '' ? '.' : $rel)) continue;
			$protected[wp_basename($file)] = true;
			$meta = maybe_unserialize((string) $row->meta);
			if (is_array($meta)) {
				if (!empty($meta['original_image'])) $protected[(string) $meta['original_image']] = true;
				foreach ((array) ($meta['sizes'] ?? []) as $size) if (!empty($size['file'])) $protected[(string) $size['file']] = true;
			}
			foreach ((array) maybe_unserialize((string) $row->backup) as $size) {
				if (is_array($size) && !empty($size['file'])) $protected[(string) $size['file']] = true;
			}
			$index = maybe_unserialize((string) $row->idx);
			if (is_array($index)) foreach (Index::owned_names($index) as $name) $owned[$name] = true;
		}
		return [$protected, $owned];
	}
}
