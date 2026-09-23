<?php

namespace TimberAVIF;

/**
 * Background conversion. The unit of work is the attachment, and the queue is derived.
 *
 * An attachment is pending when its stamp (Index::STAMP) is missing or differs from
 * Config::fingerprint(). Nothing keeps a list: an upload has no stamp yet, a settings
 * change alters the fingerprint, a template asking for a new crop deletes the stamp.
 * v6 kept a 500-entry array in one option, rewritten by every request that queued a
 * job — concurrent requests lost each other's jobs — drained by a cron guard that
 * never fired (6.1.3). None of that is left to go wrong.
 *
 * Nothing here runs while a page renders. Work happens in WP-Cron, after an admin
 * response has been sent, in the admin's "Process now", or in `wp timber-avif work`,
 * one worker at a time for the whole site.
 */
final class Worker {
	const HOOK      = 'timber_avif_work';
	const HEARTBEAT = 'timber_avif_heartbeat';
	// Autoloaded "there may be work" flag, so an idle admin request costs no query.
	const HINT      = 'timber_avif_pending';

	const CRON_BUDGET  = 20.0;
	const ADMIN_BUDGET = 8.0;
	// Passes that start and never finish mean the process died on this image (memory,
	// time limit). Past this the attachment is set aside instead of blocking the queue.
	const MAX_ATTEMPTS = 3;
	// A failed encode — not a discard — is retried after a day, this many times.
	const MAX_TRIES   = 3;
	const RETRY_AFTER = DAY_IN_SECONDS;

	/* ─────────────────────────────────────────────
	 * Scheduling
	 * ───────────────────────────────────────────── */

	public static function stale(int $id): void {
		delete_post_meta($id, Index::STAMP);
		self::hint();
		self::wake();
	}

	public static function hint(): void {
		update_option(self::HINT, 1, true);
	}

	public static function wake(int $delay = 0): void {
		if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + $delay, self::HOOK);
	}

	public static function on_cron(): void {
		self::run(self::CRON_BUDGET);
	}

	/**
	 * The fallback for sites where cron misses beats — no traffic, a full-page cache,
	 * DISABLE_WP_CRON without a system cron. Only where the response can be sent first,
	 * so no editor waits for it.
	 */
	public static function on_admin_shutdown(): void {
		if (!get_option(self::HINT)) return;
		if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;
		if (wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'timber_avif_work') return;

		if (function_exists('fastcgi_finish_request'))       fastcgi_finish_request();
		elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
		else return;

		self::run(self::ADMIN_BUDGET);
	}

	public static function heartbeat(): void {
		if (self::pending(1)) {
			self::hint();
			self::wake();
		}
	}

	/* ─────────────────────────────────────────────
	 * Queue
	 * ───────────────────────────────────────────── */

	/** @return int[] Newest first: a fresh upload matters more than the back catalogue. */
	public static function pending(int $limit): array {
		if (!Config::format()) return [];
		$query = new \WP_Query(self::pending_query(['posts_per_page' => $limit, 'no_found_rows' => true]));
		return array_map('intval', $query->posts);
	}

	public static function count_pending(): int {
		if (!Config::format()) return 0;
		$query = new \WP_Query(self::pending_query(['posts_per_page' => 1]));
		return (int) $query->found_posts;
	}

	private static function pending_query(array $args): array {
		return $args + [
			'post_type'              => 'attachment',
			'post_status'            => 'any',
			'post_mime_type'         => Config::SOURCE_MIMES,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [
				'relation' => 'OR',
				['key' => Index::STAMP, 'compare' => 'NOT EXISTS'],
				['key' => Index::STAMP, 'value' => Config::fingerprint(), 'compare' => '!='],
				['key' => Index::RETRY, 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC'],
			],
		];
	}

	/**
	 * Work through pending attachments for about $budget seconds.
	 *
	 * @return array{processed: int, finished: int, remaining: int, busy: bool, error: ?string}
	 */
	public static function run(float $budget): array {
		$result = ['processed' => 0, 'finished' => 0, 'remaining' => 0, 'busy' => false, 'error' => null];

		$format = Config::format();
		if (!$format) {
			update_option(self::HINT, 0, true);
			return $result;
		}
		if (!Engine::can($format)) {
			$result['error'] = sprintf(
				/* translators: 1: PHP SAPI name, 2: image format */
				__('This PHP process (%1$s) cannot encode %2$s. Run the queue from the web server, or enable Imagick for this PHP.', 'timber-avif'),
				PHP_SAPI,
				strtoupper($format)
			);
			return $result;
		}
		if (!Lock::acquire('worker', (int) ceil($budget) + 120)) {
			$result['busy'] = true;
			return $result;
		}

		if (function_exists('set_time_limit')) @set_time_limit((int) ceil($budget) + 60);
		wp_raise_memory_limit('image');

		$deadline = microtime(true) + $budget;
		$seen = [];
		try {
			while (microtime(true) < $deadline) {
				$ids = array_diff(self::pending(10), $seen);
				if (!$ids) break;
				foreach ($ids as $id) {
					if (microtime(true) >= $deadline) break 2;
					$seen[] = $id;
					$result['processed']++;
					if (self::process($id, $deadline)) $result['finished']++;
				}
			}
		} finally {
			Lock::release('worker');
		}

		$result['remaining'] = self::count_pending();
		if ($result['remaining']) self::wake();
		else update_option(self::HINT, 0, true);

		return $result;
	}

	/* ─────────────────────────────────────────────
	 * One attachment
	 * ───────────────────────────────────────────── */

	/**
	 * Bring one attachment up to date. Returns false when the deadline cut it short: what
	 * was done is saved, and the next pass carries on from there.
	 */
	public static function process(int $id, float $deadline): bool {
		$index = Index::get($id);

		// Counted per fingerprint: new settings (lower limits, another format) deserve a fresh try.
		$fingerprint = Config::fingerprint();
		$attempts = get_post_meta($id, Index::ATTEMPTS, true);
		$attempts = (is_array($attempts) && ($attempts['fp'] ?? '') === $fingerprint) ? (int) $attempts['n'] : 0;
		if ($attempts >= self::MAX_ATTEMPTS) {
			return self::finish($id, $index, sprintf(
				/* translators: %d: number of attempts */
				__('Set aside after %d attempts: converting this image stops the PHP process (memory or time limit).', 'timber-avif'),
				$attempts
			));
		}
		update_post_meta($id, Index::ATTEMPTS, ['fp' => $fingerprint, 'n' => $attempts + 1]);

		$format   = (string) Config::format();
		$attached = get_attached_file($id);
		$mime     = (string) get_post_mime_type($id);
		$meta     = wp_get_attachment_metadata($id);

		if (!$attached || !is_file($attached) || !is_array($meta) || empty($meta['width'])) {
			return self::finish($id, $index, __('Source file not found', 'timber-avif'));
		}

		// Already in the format being served: WordPress's own sub-sizes are the modern set.
		if (!in_array($mime, Config::SOURCE_MIMES, true) || Engine::mime($format) === $mime) {
			return self::finish($id, $index);
		}

		if (Engine::is_animated($attached, $mime)) {
			$index['anim'] = true;
			return self::finish($id, $index);
		}
		unset($index['anim']);

		$meta = Sizes::ensure($id, $meta);
		$dir = dirname($attached);
		$key = Config::encoding_key();
		$targets = self::targets($id, $meta, $mime);

		$entries = (array) ($index[$format] ?? []);
		$source = null;
		$editor = null;
		$done = 0;
		$finished = true;

		foreach ($targets as $t) {
			$entry = $entries[$t['file']] ?? null;
			if (self::up_to_date($entry, $key, $dir)) continue;

			// At least one encode per pass, so an image slower than the budget still advances.
			if ($done > 0 && microtime(true) >= $deadline) {
				$finished = false;
				break;
			}

			$source ??= self::source($id, $attached);
			if (!$source) {
				$entries[$t['file']] = ['skip' => 'too-large', 'key' => $key, 'at' => time()];
				continue;
			}

			if ($editor === null) {
				$editor = Engine::open($source, Engine::mime($format));
				// The original upload keeps its EXIF orientation; WordPress rotates before resizing, and so do we.
				if (!is_wp_error($editor)) $editor->maybe_exif_rotate();
			}
			if (is_wp_error($editor)) {
				$entries[$t['file']] = self::failure($entry, $key, $editor->get_error_message());
				continue;
			}

			if ($t['crop'] && !is_file("$dir/{$t['file']}")) {
				$made = $editor->tavif_save($t['w'], $t['h'], true, "$dir/{$t['file']}", $mime);
				if (is_wp_error($made)) {
					$entries[$t['file']] = self::failure($entry, $key, $made->get_error_message());
					continue;
				}
			}

			$entries[$t['file']] = self::encode($editor, $t, $dir, $format, $key, $entry);
			$done++;
		}

		if ($finished) {
			// Entries for files no longer served — a width removed, a sub-size regenerated
			// under another name — are deleted with their files.
			$live = array_flip(array_column($targets, 'file'));
			foreach (array_diff_key($entries, $live) as $file => $entry) {
				if (!empty($entry['file'])) @unlink("$dir/{$entry['file']}");
				unset($entries[$file]);
			}
		}

		$index[$format] = $entries;
		$index['v'] = 1;
		if ($crops = self::crops_built($targets, $dir)) $index['crops'] = $crops;

		if (!$finished) {
			Index::put($id, $index);
			delete_post_meta($id, Index::ATTEMPTS);
			return false;
		}

		return self::finish($id, $index, self::describe_issues($entries), self::retry_due($entries));
	}

	/**
	 * Stamp an attachment as done for the current settings.
	 */
	private static function finish(int $id, array $index, string $issue = '', bool $retry = false): bool {
		$index['v'] = 1;
		Index::put($id, $index);
		update_post_meta($id, Index::STAMP, Config::fingerprint());
		delete_post_meta($id, Index::ATTEMPTS);

		if ($retry) update_post_meta($id, Index::RETRY, time() + self::RETRY_AFTER);
		else delete_post_meta($id, Index::RETRY);

		if ($issue) update_post_meta($id, Index::ISSUE, ['text' => $issue, 'at' => time()]);
		else delete_post_meta($id, Index::ISSUE);

		return true;
	}

	/**
	 * Files to keep a modern copy of: the same candidates the renderer picks from, and
	 * the crops templates asked for.
	 *
	 * @return array<int, array{file: string, w: int, h: int, crop: bool, ratio?: string}>
	 */
	private static function targets(int $id, array $meta, string $mime): array {
		$fw = (int) $meta['width'];
		$fh = (int) $meta['height'];
		$targets = [];

		foreach (Sizes::candidates($meta, Config::widths()) as $c) {
			$full = $c['w'] === $fw;
			// Width-only, like the registered size that made the fallback: same dimensions, same rounding.
			$targets[] = ['file' => $c['file'], 'w' => $c['w'], 'h' => $full ? $fh : 0, 'crop' => false];
		}

		$stem = pathinfo((string) ($meta['original_image'] ?? basename((string) $meta['file'])), PATHINFO_FILENAME);
		$ext  = pathinfo((string) $meta['file'], PATHINFO_EXTENSION) ?: wp_get_default_extension_for_mime_type($mime);

		foreach (Index::ratios($id) as $key) {
			$ratio = Renderer::parse_ratio($key);
			if (!$ratio) continue;
			foreach (Sizes::crop_targets($fw, $fh, $ratio['value'], Config::widths()) as $t) {
				$targets[] = ['file' => "{$stem}-{$t['w']}x{$t['h']}-tavif.{$ext}", 'w' => $t['w'], 'h' => $t['h'], 'crop' => true, 'ratio' => $ratio['key']];
			}
		}

		return $targets;
	}

	/**
	 * Crops whose source file now exists, grouped by ratio, for the renderer.
	 */
	private static function crops_built(array $targets, string $dir): array {
		$crops = [];
		foreach ($targets as $t) {
			if (!$t['crop'] || !is_file("$dir/{$t['file']}")) continue;
			$crops[$t['ratio']][] = ['w' => $t['w'], 'h' => $t['h'], 'file' => $t['file']];
		}
		return $crops;
	}

	private static function up_to_date(?array $entry, string $key, string $dir): bool {
		if (!$entry || ($entry['key'] ?? '') !== $key) return false;
		if (!empty($entry['file'])) return is_file("$dir/{$entry['file']}");
		if (($entry['skip'] ?? '') === 'failed') return ($entry['tries'] ?? 0) >= self::MAX_TRIES;
		return isset($entry['skip']);
	}

	/**
	 * The file to encode from: the original upload when it is within the limits, since
	 * every sub-size is made from it; otherwise the scaled file WordPress serves as full.
	 */
	private static function source(int $id, string $attached): ?string {
		$max_bytes = (int) Config::get('max_file_size') * MB_IN_BYTES;
		$max_dim   = (int) Config::get('max_dimension');

		foreach (array_unique(array_filter([wp_get_original_image_path($id), $attached])) as $path) {
			if (!is_file($path) || filesize($path) > $max_bytes) continue;
			$size = wp_getimagesize($path);
			if (!$size || $size[0] > $max_dim || $size[1] > $max_dim) continue;
			return $path;
		}
		return null;
	}

	/**
	 * Write one modern copy, and decide whether it is worth keeping.
	 */
	private static function encode($editor, array $t, string $dir, string $format, string $key, ?array $previous): array {
		$dest = "$dir/{$t['file']}.$format";
		// Written aside and renamed into place: the file may be live, and a visitor must never get half of it.
		$tmp = "$dir/{$t['file']}.tavif-" . wp_generate_password(6, false) . ".$format";

		$saved = $editor->tavif_save($t['w'], $t['h'], $t['crop'], $tmp, Engine::mime($format));
		if (is_wp_error($saved)) {
			@unlink($tmp);
			return self::failure($previous, $key, $saved->get_error_message());
		}
		$tmp = $saved['path'] ?? $tmp;

		if (!Engine::is_valid($tmp, $format)) {
			@unlink($tmp);
			return self::failure($previous, $key, __('Output file invalid (corrupt header)', 'timber-avif'));
		}

		$bytes = (int) filesize($tmp);
		$baseline = is_file("$dir/{$t['file']}") ? (int) filesize("$dir/{$t['file']}") : 0;

		if (Config::get('only_if_smaller') && $baseline && Engine::exceeds_tolerance($baseline, $bytes)) {
			@unlink($tmp);
			@unlink($dest);
			return ['skip' => 'larger', 'key' => $key, 'at' => time(), 'bytes' => $bytes, 'src_bytes' => $baseline];
		}

		if (!@rename($tmp, $dest)) {
			@unlink($tmp);
			return self::failure($previous, $key, __('Could not move the converted file into place', 'timber-avif'));
		}

		return ['file' => basename($dest), 'bytes' => $bytes, 'src_bytes' => $baseline, 'key' => $key];
	}

	private static function failure(?array $previous, string $key, string $why): array {
		$tries = ($previous && ($previous['key'] ?? '') === $key && ($previous['skip'] ?? '') === 'failed') ? (int) ($previous['tries'] ?? 0) : 0;
		return ['skip' => 'failed', 'why' => $why, 'key' => $key, 'at' => time(), 'tries' => $tries + 1];
	}

	private static function retry_due(array $entries): bool {
		foreach ($entries as $e) {
			if (($e['skip'] ?? '') === 'failed' && ($e['tries'] ?? 0) < self::MAX_TRIES) return true;
		}
		return false;
	}

	/**
	 * One line for the admin's list of images that need a look. Discarding a conversion
	 * heavier than its source is the tolerance doing its job, not a problem.
	 */
	private static function describe_issues(array $entries): string {
		$failed = array_filter($entries, fn($e) => ($e['skip'] ?? '') === 'failed');
		$large  = array_filter($entries, fn($e) => ($e['skip'] ?? '') === 'too-large');

		if ($failed) {
			$why = implode('; ', array_unique(array_map(fn($e) => (string) ($e['why'] ?? ''), $failed)));
			/* translators: 1: number of sizes, 2: error message */
			return sprintf(_n('%1$d size failed: %2$s', '%1$d sizes failed: %2$s', count($failed), 'timber-avif'), count($failed), $why);
		}
		if ($large) {
			return __('Left as it is: the image exceeds the conversion limits (Settings → Limits).', 'timber-avif');
		}
		return '';
	}
}
