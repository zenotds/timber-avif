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
		$known = self::known($dir);
		$foreign = self::foreign_converter();

		foreach ($names as $name) {
			if ($name[0] === '.' || isset($known['files'][$name]) || isset($known['owned'][$name])) continue;
			$kind = self::kind($name, $present, $foreign, $known);
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

	/**
	 * 'orphans', 'timber', or null for a file that is not ours to judge.
	 *
	 * A copy counts only when the attachment it was made for is known and done with — its
	 * index, final, no longer lists it — or when what it was made from is gone. One made
	 * from a file no attachment here owns is left: the database may be behind the folder (a
	 * copy restored from elsewhere, an import not run yet), and with the database it belongs
	 * to, that copy is served.
	 */
	private static function kind(string $name, array $present, bool $foreign, array $known): ?string {
		if (preg_match('/\.(avif|webp)\.lock$/i', $name)) return 'orphans';
		if (preg_match('/\.tavif-[A-Za-z0-9]{6}\.(avif|webp|jpe?g|png|gif)$/i', $name)) return 'orphans';

		// photo-640x427.jpg.avif: a modern copy of ours.
		if (preg_match('/^(.+\.(jpe?g|png|gif|webp))\.(avif|webp)$/i', $name, $m)) {
			$source = $m[1];
			if (preg_match('/^(.+)-\d+x\d+-tavif\.[a-z]+$/i', $source, $c)) return self::judge($c[1], $known, $present, 'orphans');
			if (!isset($present[$source])) return 'orphans';
			if ($foreign) return null;
			return self::judge(pathinfo($source, PATHINFO_FILENAME), $known, $present, 'orphans', $source);
		}
		// photo-scaled-640x160-tavif.jpg: a crop or a width of ours.
		if (preg_match('/^(.+)-\d+x\d+-tavif\.(jpe?g|png|gif|webp)$/i', $name, $m)) return self::judge($m[1], $known, $present, 'orphans');
		// photo-640x0-c-default.jpg: Timber's resize, or the older version's copy of one.
		if (preg_match('/^(.+)-\d+x\d+-c-[a-z]+\.(jpe?g|png|gif|webp|avif)$/i', $name, $m)) {
			if ($foreign && in_array(strtolower($m[2]), ['avif', 'webp'], true)) return null;
			return self::judge($m[1], $known, $present, 'timber');
		}
		// photo.webp next to photo.jpg: Timber's |towebp, or the older version's copy.
		if (!$foreign && preg_match('/^(.+)\.(avif|webp)$/i', $name, $m)) {
			$sources = strtolower($m[2]) === 'avif' ? ['jpg', 'jpeg', 'png', 'gif', 'webp'] : ['jpg', 'jpeg', 'png', 'gif'];
			foreach ($sources as $ext) {
				foreach (["{$m[1]}.$ext", $m[1] . '.' . strtoupper($ext)] as $source) {
					if (isset($present[$source])) return self::judge($m[1], $known, $present, 'timber', $source);
				}
			}
		}
		return null;
	}

	/**
	 * A file made from $stem (a file name without its extension), or from $source exactly:
	 * $kind if the attachment that owns it is known here and finished, or if nothing in the
	 * folder bears that name any more; null if it is someone else's, or not settled yet.
	 */
	private static function judge(string $stem, array $known, array $present, string $kind, ?string $source = null): ?string {
		$owners = $source !== null ? ($known['files'][$source] ?? null) : ($known['stems'][$stem] ?? null);
		if ($owners) {
			// Pending: the next pass may claim it, or make it again.
			foreach ($owners as $id) if (!empty($known['pending'][$id])) return null;
			return $kind;
		}
		// No attachment owns it. Gone from the folder too: an orphan. Still there: not ours.
		if ($source !== null) return null;
		foreach (array_keys($present) as $other) {
			if (str_starts_with((string) $other, $stem . '.')) return null;
		}
		return $kind;
	}

	/**
	 * What attachments own in $dir — the attached file, the original behind a `-scaled` one,
	 * the sub-sizes, the sizes an edit keeps for undo — with the attachments owning each
	 * name; the names their indexes list; each attachment's file names without extension,
	 * which its crops start with; and which attachments are still pending.
	 *
	 * @return array{files: array<string, int[]>, owned: array<string, true>, stems: array<string, int[]>, pending: array<int, bool>}
	 */
	private static function known(string $dir): array {
		global $wpdb;
		$base = trailingslashit(wp_get_upload_dir()['basedir']);
		$rel = ltrim(substr(wp_normalize_path($dir), strlen(wp_normalize_path($base))), '/');
		// Attached files in this folder, not in its subfolders.
		$like = $rel === '' ? '%' : $wpdb->esc_like($rel . '/') . '%';

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT a.post_id, a.meta_value AS file, m.meta_value AS meta, b.meta_value AS backup, i.meta_value AS idx, s.meta_value AS stamp
			FROM {$wpdb->postmeta} a
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.post_id AND m.meta_key = '_wp_attachment_metadata'
			LEFT JOIN {$wpdb->postmeta} b ON b.post_id = a.post_id AND b.meta_key = '_wp_attachment_backup_sizes'
			LEFT JOIN {$wpdb->postmeta} i ON i.post_id = a.post_id AND i.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = a.post_id AND s.meta_key = %s
			WHERE a.meta_key = '_wp_attached_file' AND a.meta_value LIKE %s",
			Index::META,
			Index::STAMP,
			$like
		));

		$known = ['files' => [], 'owned' => [], 'stems' => [], 'pending' => []];
		$fingerprint = \TimberAVIF\Config::fingerprint();
		foreach ($rows as $row) {
			$file = (string) $row->file;
			if (dirname($file) !== ($rel === '' ? '.' : $rel)) continue;
			$id = (int) $row->post_id;
			$known['pending'][$id] = (string) $row->stamp !== $fingerprint;
			$names = [wp_basename($file)];
			$meta = maybe_unserialize((string) $row->meta);
			if (is_array($meta)) {
				if (!empty($meta['file'])) $names[] = wp_basename((string) $meta['file']);
				if (!empty($meta['original_image'])) $names[] = (string) $meta['original_image'];
				foreach ((array) ($meta['sizes'] ?? []) as $size) if (!empty($size['file'])) $names[] = (string) $size['file'];
			}
			foreach ((array) maybe_unserialize((string) $row->backup) as $size) {
				if (is_array($size) && !empty($size['file'])) $names[] = (string) $size['file'];
			}
			foreach (array_unique($names) as $name) {
				$known['files'][$name][] = $id;
				$known['stems'][pathinfo($name, PATHINFO_FILENAME)][] = $id;
			}
			$index = maybe_unserialize((string) $row->idx);
			if (is_array($index)) foreach (Index::owned_names($index) as $name) $known['owned'][$name] = true;
		}
		return $known;
	}
}
