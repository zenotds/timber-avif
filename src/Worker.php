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

	// Attachments whose markup this worker changed, announced once per run (Cache purges on it).
	private static array $changed = [];

	/* ─────────────────────────────────────────────
	 * Scheduling
	 * ───────────────────────────────────────────── */

	public static function stale(int $id, int $delay = 0): void {
		delete_post_meta($id, Index::STAMP);
		self::hint();
		self::wake($delay);
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
		global $wpdb;
		return array_map('intval', $wpdb->get_col(self::pending_sql('DISTINCT p.ID') . ' ORDER BY p.ID DESC LIMIT ' . max(1, $limit)));
	}

	public static function count_pending(): int {
		if (!Config::format()) return 0;
		global $wpdb;
		return (int) $wpdb->get_var(self::pending_sql('COUNT(DISTINCT p.ID)'));
	}

	/** Images the worker would convert, pending or not. */
	public static function count_sources(): int {
		global $wpdb;
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . self::sources_where());
	}

	/**
	 * Written by hand rather than as a WP_Query meta_query. WordPress joins each clause of
	 * an OR meta_query on post_id alone, so every attachment is multiplied by all its meta
	 * rows once per clause: 49 ms for 1,480 images on a real site, growing with both the
	 * library and the rows this package adds. With the key in each join it is 0.6 ms.
	 */
	private static function pending_sql(string $select): string {
		global $wpdb;
		return $wpdb->prepare(
			"SELECT $select FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
			WHERE " . self::sources_where() . "
			AND (s.meta_id IS NULL OR s.meta_value <> %s OR CAST(r.meta_value AS SIGNED) <= %d)",
			Index::STAMP,
			Index::RETRY,
			Config::fingerprint(),
			time()
		);
	}

	private static function sources_where(): string {
		$mimes = implode(',', array_map(fn($m) => "'" . esc_sql($m) . "'", Config::SOURCE_MIMES));
		return "p.post_type = 'attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.post_mime_type IN ($mimes)";
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

		self::announce();
		if (!$result['remaining']) do_action('timber_avif/idle');

		return $result;
	}

	/**
	 * `timber_avif/changed`, with the attachments whose markup the passes since the last call
	 * changed: a modern set that became complete, a crop or a width built, copies of a
	 * replaced file dropped. A page cache that holds a page showing one of them is stale.
	 */
	/**
	 * Note an attachment whose markup changed outside a worker pass — copies of a replaced
	 * file dropped. Announced once, at the end of the request: an import that replaces five
	 * hundred files makes one call, not five hundred.
	 */
	public static function changed(int $id): void {
		self::$changed[] = $id;
		if (!has_action('shutdown', [self::class, 'announce'])) add_action('shutdown', [self::class, 'announce'], 5);
	}

	public static function announce(): void {
		if (!self::$changed) return;
		$ids = array_values(array_unique(self::$changed));
		self::$changed = [];
		do_action('timber_avif/changed', $ids);
	}

	/* ─────────────────────────────────────────────
	 * One attachment
	 * ───────────────────────────────────────────── */

	/**
	 * Bring one attachment up to date. Returns false when the deadline cut it short: what
	 * was done is saved, and the next pass carries on from there.
	 */
	public static function process(int $id, float $deadline): bool {
		$format = (string) Config::format();
		$before = self::served($id, $format);
		$done = self::work_on($id, $deadline);
		if (self::served($id, $format) !== $before) self::$changed[] = $id;
		return $done;
	}

	/**
	 * What the markup of this attachment depends on, as a fingerprint: the modern set of its
	 * full candidates, the crops and widths built on request and whether they are served in
	 * the modern format. File names only, not bytes — a copy re-encoded in place, at a new
	 * quality, keeps its URL and changes no page.
	 */
	private static function served(int $id, string $format): string {
		$meta = (array) wp_get_attachment_metadata($id);
		$index = Index::get($id);
		$entries = (array) ($index[$format] ?? []);
		$extra = (array) ($index['extra'] ?? []);

		$sets = [Renderer::modern_srcset(Sizes::candidates($meta, Config::widths(), null, $extra), $entries, ''), array_column($extra, 'w')];
		foreach ((array) ($index['crops'] ?? []) as $ratio => $crops) {
			$sets[$ratio] = [array_column($crops, 'w'), Renderer::modern_srcset(Sizes::crop_candidates($crops, Config::widths()), $entries, '')];
		}
		return md5(serialize($sets + ['anim' => !empty($index['anim'])]));
	}

	private static function work_on(int $id, float $deadline): bool {
		$index = Index::get($id);
		// The file was replaced since its copies were made — in place, or by an edit in the
		// media modal: they show the old picture.
		if (Index::source_changed($id, $index)) $index = Index::forget_source($index);
		$owned_before = Index::owned_names($index);

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
		[$shared, $twin_entries] = self::twins($id, $format, $attached);

		$entries = (array) ($index[$format] ?? []);
		$source = null;
		$editor = null;
		$done = 0;
		$finished = true;

		foreach ($targets as $t) {
			$entry = $entries[$t['file']] ?? null;
			if (self::up_to_date($entry, $key, $dir)) continue;

			// Made already by a translation of the same file. A crop or width of ours needs its
			// fallback on disk too, and one made before the source was replaced is made again.
			$twin = $twin_entries[$t['file']] ?? null;
			if ($twin && self::up_to_date($twin, $key, $dir) && (!$t['make'] || (is_file("$dir/{$t['file']}") && empty($index['remake'])))) {
				$entries[$t['file']] = $twin;
				continue;
			}

			// At least one encode per pass, so an image slower than the budget still advances.
			if ($done > 0 && microtime(true) >= $deadline) {
				$finished = false;
				break;
			}

			$source ??= self::source($meta, $attached);
			if (!$source) {
				$entries[$t['file']] = ['skip' => 'too-large', 'key' => $key, 'at' => time()];
				continue;
			}

			if ($editor === null) {
				$editor = Engine::open($source, Engine::mime($format));
				// A file WordPress never rotated (uploaded before 5.3) still carries its EXIF orientation.
				if (!is_wp_error($editor)) $editor->maybe_exif_rotate();
			}
			if (is_wp_error($editor)) {
				$entries[$t['file']] = self::failure($entry, $key, $editor->get_error_message());
				continue;
			}

			// Crops and widths asked for by a template have no WordPress sub-size: the fallback
			// file is ours to make too — again when the source was replaced.
			if ($t['make'] && (!is_file("$dir/{$t['file']}") || !empty($index['remake']))) {
				$made = self::make($editor, $t, $dir, $mime);
				if (is_wp_error($made)) {
					$entries[$t['file']] = self::failure($entry, $key, $made->get_error_message());
					continue;
				}
			}

			$entries[$t['file']] = self::encode($editor, $t, $dir, $format, $key, $entry, $shared);
			$done++;
		}

		if ($finished) {
			// Entries for files no longer served — a width removed, a sub-size regenerated
			// under another name — go.
			$live = array_flip(array_column($targets, 'file'));
			$entries = array_intersect_key($entries, $live);
		}

		$index[$format] = $entries;
		$index['v'] = 1;
		[$crops, $extra] = self::made_built($targets, $dir);
		if ($crops) $index['crops'] = $crops; else unset($index['crops']);
		if ($extra) $index['extra'] = $extra; else unset($index['extra']);

		if ($finished) {
			// Every file this index owned before the pass and no longer does is deleted — a
			// replaced source's copies not written over, a crop of an edited image, a removed
			// width. Nothing else knows they exist. Those a twin serves stay.
			unset($index['old']);
			foreach (array_diff($owned_before, Index::owned_names($index), array_keys($shared)) as $name) @unlink("$dir/$name");
		}

		if (!$finished) {
			Index::put($id, $index);
			delete_post_meta($id, Index::ATTEMPTS);
			return false;
		}

		return self::finish($id, $index, self::describe_issues($entries), self::retry_due($entries));
	}

	/**
	 * Stamp an attachment as done for the current settings, recording the file its copies
	 * were made from (Index::source_changed()).
	 */
	private static function finish(int $id, array $index, string $issue = '', bool $retry = false): bool {
		$index['v'] = 1;
		unset($index['remake']);
		$attached = get_attached_file($id);
		if ($attached && is_file($attached)) $index['source'] = Index::source_record($attached);
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
	 * What the translations of this file have (Index::twins()): the names they own, which
	 * this pass never deletes, and their entries for copies made from the same picture,
	 * taken over instead of encoding the same files a second time. Twins that have not
	 * finished a pass since the file changed offer nothing.
	 *
	 * @return array{0: array<string, int>, 1: array<string, array>}
	 */
	private static function twins(int $id, string $format, string $attached): array {
		$twins = Index::twins($id);
		if (!$twins) return [[], []];

		$source = Index::source_record($attached);
		$names = [];
		$entries = [];
		foreach ($twins as $twin) {
			$index = Index::get($twin);
			$names = array_merge($names, Index::owned_names($index));
			if (($index['source'] ?? null) === $source && empty($index['remake'])) $entries += (array) ($index[$format] ?? []);
		}
		return [array_flip($names), $entries];
	}

	/**
	 * Files to keep a modern copy of: the same candidates the renderer picks from, plus the
	 * crops and widths templates asked for (Index::wants()).
	 *
	 * @return array<int, array{file: string, w: int, h: int, crop: bool, make: bool, ratio: string}>
	 */
	private static function targets(int $id, array $meta, string $mime): array {
		$fw = (int) $meta['width'];
		$fh = (int) $meta['height'];
		$targets = [];
		$widths = [];

		foreach (Sizes::candidates($meta, Config::widths()) as $c) {
			$full = $c['w'] === $fw;
			// Width-only, like the registered size that made the fallback: same dimensions, same rounding.
			$targets[] = ['file' => $c['file'], 'w' => $c['w'], 'h' => $full ? $fh : 0, 'crop' => false, 'make' => false, 'ratio' => ''];
			$widths[$c['w']] = true;
		}

		// Named after the current file, which after an edit in the media modal is the edited one.
		$stem = pathinfo(basename((string) $meta['file']), PATHINFO_FILENAME);
		$ext  = pathinfo((string) $meta['file'], PATHINFO_EXTENSION) ?: wp_get_default_extension_for_mime_type($mime);

		$ratios = [];
		foreach (Index::wants($id) as $want) {
			if ($want['ratio'] === '') {
				// An uncropped width below every configured one, for an image displayed small.
				$w = (int) $want['width'];
				if (!$w || isset($widths[$w]) || $w >= $fw || $w > Config::MAX_GENERATED_WIDTH) continue;
				$h = max(1, (int) round($w * $fh / $fw));
				$targets[] = ['file' => "{$stem}-{$w}x{$h}-tavif.{$ext}", 'w' => $w, 'h' => $h, 'crop' => false, 'make' => true, 'ratio' => ''];
				$widths[$w] = true;
				continue;
			}
			$ratios[$want['ratio']] ??= [];
			if ($want['width']) $ratios[$want['ratio']][] = (int) $want['width'];
		}

		foreach ($ratios as $key => $extra) {
			$ratio = Renderer::parse_ratio($key);
			if (!$ratio) continue;
			foreach (Sizes::crop_targets($fw, $fh, $ratio['value'], array_merge(Config::widths(), $extra)) as $t) {
				$targets[] = ['file' => "{$stem}-{$t['w']}x{$t['h']}-tavif.{$ext}", 'w' => $t['w'], 'h' => $t['h'], 'crop' => true, 'make' => true, 'ratio' => $ratio['key'], 'asked' => in_array($t['w'], $extra, true)];
			}
		}

		return $targets;
	}

	/**
	 * The files this package made whose fallback now exists, for the renderer: crops
	 * grouped by ratio, and uncropped extra widths.
	 */
	private static function made_built(array $targets, string $dir): array {
		$crops = [];
		$extra = [];
		foreach ($targets as $t) {
			if (!$t['make'] || !is_file("$dir/{$t['file']}")) continue;
			$row = ['w' => $t['w'], 'h' => $t['h'], 'file' => $t['file']];
			if (!empty($t['asked'])) $row['asked'] = true;
			if ($t['crop']) $crops[$t['ratio']][] = $row;
			else $extra[] = $row;
		}
		return [$crops, $extra];
	}

	private static function up_to_date(?array $entry, string $key, string $dir): bool {
		if (!$entry || ($entry['key'] ?? '') !== $key) return false;
		if (!empty($entry['file'])) return is_file("$dir/{$entry['file']}");
		if (($entry['skip'] ?? '') === 'failed') return ($entry['tries'] ?? 0) >= self::MAX_TRIES;
		return isset($entry['skip']);
	}

	/**
	 * The file to encode from: the attached file, which is what WordPress serves as full size.
	 *
	 * Never the original upload behind it. After an edit in the media modal the attached
	 * file is the edited one and `original_image` still names the untouched upload, so a
	 * rotated photo came out of AVIF unrotated. And where the attached file is the -scaled
	 * copy, starting from it costs 31% less time and 20% less memory for the same bytes:
	 * nothing is ever generated wider than it.
	 *
	 * Past the conversion limits — a large PNG, which WordPress does not scale — the widest
	 * proportional sub-size stands in, since no candidate is wider than 2560 anyway.
	 */
	private static function source(array $meta, string $attached): ?string {
		$max_bytes = (int) Config::get('max_file_size') * MB_IN_BYTES;
		$max_dim   = (int) Config::get('max_dimension');

		$paths = [$attached];
		$by_width = [];
		foreach (Sizes::candidates($meta, Config::widths()) as $c) $by_width[$c['w']] = dirname($attached) . '/' . $c['file'];
		krsort($by_width);
		foreach ($by_width as $path) $paths[] = $path;

		foreach (array_unique($paths) as $path) {
			if (!is_file($path) || filesize($path) > $max_bytes) continue;
			$size = wp_getimagesize($path);
			if (!$size || $size[0] > $max_dim || $size[1] > $max_dim) continue;
			return $path;
		}
		return null;
	}

	/**
	 * A fallback file of ours — a crop, an extra width — written aside and renamed into
	 * place, since it may be live on a page already.
	 *
	 * @return array|\WP_Error
	 */
	private static function make($editor, array $t, string $dir, string $mime) {
		$ext = pathinfo($t['file'], PATHINFO_EXTENSION);
		$tmp = "$dir/{$t['file']}.tavif-" . wp_generate_password(6, false) . ".$ext";
		$made = $editor->tavif_save($t['w'], $t['h'], $t['crop'], $tmp, $mime);
		if (is_wp_error($made)) {
			@unlink($tmp);
			return $made;
		}
		if (!@rename($made['path'] ?? $tmp, "$dir/{$t['file']}")) {
			@unlink($made['path'] ?? $tmp);
			return new \WP_Error('tavif_move', __('Could not move the converted file into place', 'timber-avif'));
		}
		return $made;
	}

	/**
	 * Write one modern copy, and decide whether it is worth keeping. A copy discarded as
	 * heavier stays on disk while a twin still lists it ($shared): its own pass drops it.
	 */
	private static function encode($editor, array $t, string $dir, string $format, string $key, ?array $previous, array $shared = []): array {
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
			if (!isset($shared[basename($dest)])) @unlink($dest);
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
