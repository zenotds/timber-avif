<?php

namespace TimberAVIF;

use TimberAVIF\Optimize\Orphans;
use TimberAVIF\Optimize\Templates;
use TimberAVIF\Optimize\Usage;

/**
 * Optimize: remove, on request, the files no template serves.
 *
 * The upload and the worker make every configured width of every image, and that stays.
 * Afterwards, this reads where each image is placed (Usage) and how the templates that show
 * those places size it (Templates), and works out the widest width each image is needed at.
 * Past that — rounded up to a configured width, never below FLOOR — its modern copies, its
 * `tavif-*` JPEGs and its crops go, and the width is kept as the image's cap
 * (Index::caps()) so that nothing makes them again. A template that later shows it wider
 * raises the cap from the render, and the worker builds what is missing.
 *
 * An image is only capped when every reference to it is understood and every one of them is
 * matched by a template call: a place no call shows, a reference in someone else's meta, an
 * image in post content, keep all of its files. One that nothing references at all keeps
 * FLOOR and below. Files that no attachment owns, left by an older version, a crash or
 * Timber, go too (Orphans).
 *
 * It runs in steps, from Tools (one request each, like Process now) or WP-CLI, and records
 * what it would do before doing anything: the analysis is a dry run, and Optimize applies
 * the plan it made. The cache is purged at the end, since a cached page may point at a
 * deleted file.
 */
final class Optimize {
	const JOB    = 'timber_avif_optimize';
	// Never capped below the configured width that covers this: an image the reading missed
	// is still served sharp enough in a card, until the render raises its cap.
	const FLOOR  = 1024;
	const BATCH  = 100;
	const PHASES = ['templates', 'posts', 'terms', 'options', 'images', 'orphans', 'done'];

	/* ─────────────────────────────────────────────
	 * The job
	 * ───────────────────────────────────────────── */

	/** The analysis or its report, or null. */
	public static function job(): ?array {
		$job = get_option(self::JOB);
		return is_array($job) ? $job : null;
	}

	/** Start an analysis from scratch. $timber: also count Timber's resizes, to delete them. */
	public static function start(bool $timber = false): array {
		$job = [
			'phase'   => 'templates',
			'cursor'  => 0,
			'started' => time(),
			'timber'  => $timber,
			'widths'  => Config::widths(),
			'refs'    => [],
			'plan'    => [],
			'dirs'    => [],
			'totals'  => self::empty_totals(),
			'images'  => ['total' => 0, 'capped' => 0, 'unused' => 0, 'kept' => []],
			'unmatched' => [],
		];
		self::save($job);
		return $job;
	}

	/**
	 * Advance the analysis for about $budget seconds. Returns the job, with 'phase' => 'done'
	 * once the report is ready.
	 */
	public static function analyse(float $budget): array {
		$job = self::job() ?? self::start();
		if (!empty($job['applied']) || $job['phase'] === 'done') return $job;
		if (function_exists('set_time_limit')) @set_time_limit((int) ceil($budget) + 60);
		wp_raise_memory_limit('admin');

		$deadline = microtime(true) + $budget;
		$images = null;
		do {
			switch ($job['phase']) {
				case 'templates':
					$read = Templates::read(Usage::locations(), Usage::option_vars());
					$job['uses'] = $read['uses'];
					$job['untraced'] = $read['untraced'];
					$job['templates'] = $read['files'];
					self::next($job);
					break;

				case 'posts':
					$images ??= Usage::images();
					$last = Usage::scan_posts((int) $job['cursor'], $job['refs'], $images);
					if ($last) $job['cursor'] = $last;
					else self::next($job);
					break;

				case 'terms':
					$images ??= Usage::images();
					Usage::scan_terms($job['refs'], $images);
					Usage::scan_users($job['refs'], $images);
					self::next($job);
					break;

				case 'options':
					$images ??= Usage::images();
					Usage::scan_options($job['refs'], $images);
					$job['matches'] = self::match_places($job);
					self::next($job);
					break;

				case 'images':
					$last = self::plan_batch($job);
					if ($last) $job['cursor'] = $last;
					else self::next($job);
					break;

				case 'orphans':
					if (!$job['dirs'] && !$job['cursor']) $job['dirs'] = Orphans::dirs();
					$dir = $job['dirs'][(int) $job['cursor']] ?? null;
					if ($dir === null) {
						self::next($job);
						break;
					}
					$found = Orphans::scan($dir);
					foreach (['orphans', 'timber'] as $kind) {
						$job['totals'][$kind]['files'] += count($found[$kind]['files']);
						$job['totals'][$kind]['bytes'] += $found[$kind]['bytes'];
					}
					$job['cursor']++;
					break;
			}
		} while ($job['phase'] !== 'done' && microtime(true) < $deadline);

		if ($job['phase'] === 'done') {
			$job['finished'] = time();
			// What the plan needs is kept; what it was made from is not.
			unset($job['refs'], $job['matches'], $job['uses']);
		}
		self::save($job);
		return $job;
	}

	/** Carry out the plan the analysis made, for about $budget seconds. */
	public static function apply(float $budget): array {
		$job = self::job();
		if (!$job || $job['phase'] !== 'done' || !empty($job['applied'])) return $job ?? [];
		// Widths changed since: the plan speaks of other files.
		if (($job['widths'] ?? []) !== Config::widths()) return ['error' => 'widths'] + $job;
		// The worker's lock: a pass encoding one of these images meanwhile would write back
		// what is being deleted. The relay and WP-Cron stand back, as for Process now, or a
		// chain of passes would take the lock again each time it is let go.
		set_transient(Worker::DRIVEN, 1, (int) ceil($budget) + 60);
		if (!Lock::acquire('worker', (int) ceil($budget) + 120)) return ['busy' => true] + $job;
		try {
			$job = self::apply_locked($job, $budget);
		} finally {
			Lock::release('worker');
		}
		if (!empty($job['applied'])) {
			delete_transient(Worker::DRIVEN);
			// Raised caps left images to build.
			if (Worker::count_pending()) Worker::wake();
		}
		return $job;
	}

	private static function apply_locked(array $job, float $budget): array {
		if (function_exists('set_time_limit')) @set_time_limit((int) ceil($budget) + 60);

		$job['apply'] ??= ['cursor' => 0, 'dir' => 0, 'deleted' => 0, 'bytes' => 0];
		$deadline = microtime(true) + $budget;
		$groups = array_keys($job['plan']);

		// Our own hook would put every image back in the queue for a metadata change that
		// only drops sizes.
		remove_filter('wp_update_attachment_metadata', [Plugin::class, 'on_metadata'], 10);
		$changed = [];
		try {
			while ($job['apply']['cursor'] < count($groups) && microtime(true) < $deadline) {
				$plan = $job['plan'][$groups[$job['apply']['cursor']]];
				[$files, $bytes] = self::apply_group($plan['ids'], $plan['caps']);
				$job['apply']['deleted'] += $files;
				$job['apply']['bytes'] += $bytes;
				$job['apply']['cursor']++;
				array_push($changed, ...$plan['ids']);
			}
		} finally {
			add_filter('wp_update_attachment_metadata', [Plugin::class, 'on_metadata'], 10, 2);
			// At the end of every step, not only of the last: a cached page pointing at a deleted
			// file shows a broken image, and the step after may never come.
			if ($changed) {
				self::save($job);
				do_action('timber_avif/changed', $changed);
				Cache::purge_all(true);
			}
		}

		$dirs = Orphans::dirs();
		while ($job['apply']['cursor'] >= count($groups) && $job['apply']['dir'] < count($dirs) && microtime(true) < $deadline) {
			$found = Orphans::scan($dirs[$job['apply']['dir']]);
			$kinds = !empty($job['timber']) ? ['orphans', 'timber'] : ['orphans'];
			foreach ($kinds as $kind) {
				foreach ($found[$kind]['files'] as $path) {
					$size = (int) @filesize($path);
					if (@unlink($path)) {
						$job['apply']['deleted']++;
						$job['apply']['bytes'] += $size;
					}
				}
			}
			$job['apply']['dir']++;
		}

		if ($job['apply']['cursor'] >= count($groups) && $job['apply']['dir'] >= count($dirs)) {
			$job['applied'] = time();
			// After the files, not before: a page cached in between would point at deleted ones.
			Cache::purge_all(true);
		}
		self::save($job);
		return $job;
	}

	/** Forget the report. The caps already applied stay. */
	public static function discard(): void {
		delete_option(self::JOB);
	}

	/**
	 * Take every cap off: the worker makes every width of every image again, in the
	 * background. Returns how many images had one.
	 */
	public static function reset(): int {
		global $wpdb;
		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Index::CAP)));
		delete_metadata('post', 0, Index::CAP, '', true);
		delete_metadata('post', 0, Index::NEED, '', true);
		foreach ($ids as $id) delete_post_meta($id, Index::STAMP);
		if ($ids) {
			Worker::hint();
			Worker::wake();
		}
		self::discard();
		return count($ids);
	}

	/** How many images have a cap. */
	public static function capped(): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Index::CAP));
	}

	/**
	 * What Optimize would delete, per row of the report: [label, files, bytes, deleted?].
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: bool}>
	 */
	public static function rows(array $job): array {
		$t = $job['totals'] ?? self::empty_totals();
		return [
			'beyond'  => [__('Widths wider than the templates show the image', 'timber-avif'), $t['beyond']['files'], $t['beyond']['bytes'], true],
			/* translators: %d: width in pixels */
			'unused'  => [sprintf(__('Images no content uses: kept up to %d px', 'timber-avif'), self::FLOOR), $t['unused']['files'], $t['unused']['bytes'], true],
			'orphans' => [__('Files no attachment owns: copies of deleted images, interrupted encodes', 'timber-avif'), $t['orphans']['files'], $t['orphans']['bytes'], true],
			'timber'  => [__('Resizes and conversions Timber made next to the originals, and an older version\'s copies: Timber makes again any a page still asks for', 'timber-avif'), $t['timber']['files'], $t['timber']['bytes'], !empty($job['timber'])],
		];
	}

	/** @return array{files: int, bytes: int} */
	public static function to_delete(array $job): array {
		$out = ['files' => 0, 'bytes' => 0];
		foreach (self::rows($job) as [, $files, $bytes, $deleted]) {
			if (!$deleted) continue;
			$out['files'] += $files;
			$out['bytes'] += $bytes;
		}
		return $out;
	}

	/**
	 * Why images keep all their files, as text.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return [
			'other'     => __('referenced where ACF does not say how (another plugin, SEO, the site icon)', 'timber-avif'),
			'content'   => __('in post content or a WYSIWYG field, laid out by WordPress', 'timber-avif'),
			'unmatched' => __('in a field no template call was traced to', 'timber-avif'),
			'shown'     => __('placed nowhere, yet shown: a template asked for a crop or a width', 'timber-avif'),
			'whole'     => __('shown at full width somewhere', 'timber-avif'),
			'animated'  => __('animated', 'timber-avif'),
			'none'      => __('no dimensions in its metadata', 'timber-avif'),
		];
	}

	/** The report as lines, for WP-CLI. */
	public static function report(array $job): array {
		$lines = [''];
		$images = $job['images'];
		$lines[] = sprintf('%d images: %d limited to the widths they are shown at, %d used nowhere, %d keep every file.', $images['total'], $images['capped'], $images['unused'], array_sum($images['kept']));
		foreach ($images['kept'] as $reason => $n) $lines[] = sprintf('  %6d  %s', $n, self::reasons()[$reason] ?? $reason);
		$lines[] = '';
		foreach (self::rows($job) as [$label, $files, $bytes, $deleted]) {
			$lines[] = sprintf('  %7d files  %10s  %s%s', $files, size_format($bytes, 1) ?: '0 B', $label, $deleted ? '' : ' (kept: --timber-resizes deletes them)');
		}
		if (!empty($job['unmatched'])) {
			arsort($job['unmatched']);
			$lines[] = '';
			$lines[] = 'Fields holding images that no template call was traced to (they keep every file):';
			foreach (array_slice($job['unmatched'], 0, 12, true) as $place => $n) $lines[] = sprintf('  %6d  %s', $n, explode(Usage::ALTERNATIVE, $place)[0]);
		}
		if (!empty($job['untraced'])) {
			$lines[] = '';
			$lines[] = 'Template calls whose image could not be followed (an image shown only there gets its widths back on first view):';
			foreach (array_slice($job['untraced'], 0, 12) as $at) $lines[] = '  ' . $at;
		}
		$lines[] = '';
		return $lines;
	}

	/** How far the analysis, or the deletion once it has started, is: 0–1, for the admin's bar. */
	public static function progress(array $job): float {
		if (isset($job['apply'])) {
			$steps = count($job['plan']) + count(Orphans::dirs());
			return $steps ? min(1.0, ($job['apply']['cursor'] + $job['apply']['dir']) / $steps) : 1.0;
		}
		$i = array_search($job['phase'], self::PHASES, true);
		return $i === false ? 1.0 : min(1.0, $i / (count(self::PHASES) - 1));
	}

	private static function next(array &$job): void {
		$i = array_search($job['phase'], self::PHASES, true);
		$job['phase'] = self::PHASES[min(count(self::PHASES) - 1, $i + 1)];
		$job['cursor'] = 0;
	}

	private static function save(array $job): void {
		update_option(self::JOB, $job, false);
	}

	private static function empty_totals(): array {
		$zero = ['files' => 0, 'bytes' => 0];
		return ['beyond' => $zero, 'unused' => $zero, 'orphans' => $zero, 'timber' => $zero];
	}

	/* ─────────────────────────────────────────────
	 * Matching places to template calls
	 * ───────────────────────────────────────────── */

	/**
	 * For every place an image is found at, the template calls that show it:
	 *
	 * - the same scope and path;
	 * - a call whose object is not known (`p.thumbnail` in a loop over a query) shows that
	 *   path of any post or term;
	 * - a call through something that holds other objects (`related.*.thumbnail`, a
	 *   relationship), whose own path no field has, shows the rest of the path of any post
	 *   or term.
	 *
	 * @return array<string, int[]> place → indexes into $job['uses']
	 */
	private static function match_places(array $job): array {
		$uses = $job['uses'] ?? [];
		$places = [];
		$names = [];
		foreach ($job['refs'] as $ref) {
			foreach (array_keys($ref['p'] ?? []) as $place) {
				$places[$place] = true;
				foreach (explode(Usage::ALTERNATIVE, $place) as $name) $names[$name] = true;
			}
		}

		$exact = [];
		$any = [];
		foreach ($uses as $i => $u) {
			$exact[$u['scope'] . '|' . $u['path']][] = $i;
			if ($u['scope'] === 'any') $any[$u['path']][] = $i;
		}
		foreach ($uses as $i => $u) {
			if (!str_contains($u['path'], '*') || isset($names[$u['scope'] . '|' . $u['path']])) continue;
			$tail = substr($u['path'], strrpos($u['path'], '*') + 1);
			$tail = ltrim($tail, '.');
			if ($tail !== '') $any[$tail][] = $i;
		}

		$matches = [];
		foreach (array_keys($places) as $place) {
			$found = [];
			foreach (explode(Usage::ALTERNATIVE, $place) as $name) {
				[$scope, $path] = explode('|', $name, 2);
				$found = array_merge($found, $exact[$name] ?? []);
				if ($scope === 'post' || $scope === 'term') $found = array_merge($found, $any[$path] ?? []);
			}
			$matches[$place] = array_values(array_unique($found));
		}
		return $matches;
	}

	/* ─────────────────────────────────────────────
	 * The plan
	 * ───────────────────────────────────────────── */

	/** Plan the next batch of images. Returns the last ID looked at, or 0 when done. */
	private static function plan_batch(array &$job): int {
		global $wpdb;
		$mimes = "'" . implode("','", array_map('esc_sql', Config::SOURCE_MIMES)) . "'";
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT p.ID, f.meta_value AS file FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($mimes) AND p.ID > %d ORDER BY p.ID LIMIT %d",
			(int) $job['cursor'],
			self::BATCH
		));
		if (!$rows) return 0;

		// The attachments of each file: WPML and Polylang give every language one.
		$files = array_unique(array_map(fn($r) => (string) $r->file, $rows));
		$groups = [];
		foreach (array_chunk($files, 100) as $chunk) {
			$in = implode(',', array_map(fn($f) => $wpdb->prepare('%s', $f), $chunk));
			foreach ($wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($in)") as $r) {
				$groups[(string) $r->meta_value][] = (int) $r->post_id;
			}
		}
		update_meta_cache('post', array_map(fn($r) => (int) $r->ID, $rows));

		foreach ($rows as $row) {
			$ids = $groups[(string) $row->file] ?? [(int) $row->ID];
			sort($ids);
			// A file is planned once, from its first attachment.
			if ($ids[0] !== (int) $row->ID) continue;
			$job['images']['total']++;
			$plan = self::plan_group($ids, $job);
			// An earlier run's caps are replaced: raised or taken off where the templates now
			// need more, and the worker builds what is missing.
			$before = Index::caps($ids[0]);
			if ($plan['kept']) {
				$job['images']['kept'][$plan['kept']] = ($job['images']['kept'][$plan['kept']] ?? 0) + 1;
				if ($before) $job['plan'][$ids[0]] = ['ids' => $ids, 'caps' => []];
				continue;
			}
			$category = $plan['unused'] ? 'unused' : 'beyond';
			$job['images'][$plan['unused'] ? 'unused' : 'capped']++;
			$job['totals'][$category]['files'] += $plan['files'];
			$job['totals'][$category]['bytes'] += $plan['bytes'];
			if ($plan['files'] || $plan['caps'] !== $before) $job['plan'][$ids[0]] = ['ids' => $ids, 'caps' => $plan['caps']];
		}
		return (int) end($rows)->ID;
	}

	/**
	 * What to keep of one file: its caps, and how much would go. 'kept' says why nothing
	 * would: 'other', 'content', 'unmatched', 'shown', 'whole', 'animated', 'none'.
	 *
	 * @param int[] $ids The attachments of the file, the first one planned for.
	 * @return array{caps: array<string, int>, files: int, bytes: int, unused: bool, kept: string}
	 */
	private static function plan_group(array $ids, array &$job): array {
		$none = ['caps' => [], 'files' => 0, 'bytes' => 0, 'unused' => false, 'kept' => ''];
		$meta = wp_get_attachment_metadata($ids[0]);
		$index = Index::get($ids[0]);
		$fw = (int) ($meta['width'] ?? 0);
		$fh = (int) ($meta['height'] ?? 0);
		if (!$fw || !$fh) return ['kept' => 'none'] + $none;
		if (!empty($index['anim'])) return ['kept' => 'animated'] + $none;

		$places = [];
		$other = false;
		$content = false;
		foreach ($ids as $id) {
			$ref = $job['refs'][$id] ?? [];
			$places += $ref['p'] ?? [];
			$other = $other || !empty($ref['o']);
			$content = $content || !empty($ref['c']);
		}
		if ($other) return ['kept' => 'other'] + $none;
		if ($content) return ['kept' => 'content'] + $none;

		// Needs by ratio key; null is every width.
		$needs = [];
		$add = function (string $ratio, ?int $pixels) use (&$needs) {
			if (!array_key_exists($ratio, $needs)) $needs[$ratio] = $pixels;
			elseif ($needs[$ratio] !== null) $needs[$ratio] = $pixels === null ? null : max($needs[$ratio], $pixels);
		};
		$crop_keys = array_keys((array) ($index['crops'] ?? []));
		$use = function (array $u) use ($add, $fw, $fh, &$crop_keys) {
			$ratio = $u['ratio'];
			if ($ratio === '?') {
				// A ratio not known: it may be any crop, or the image's own proportions.
				$add('', $u['pixels']);
				foreach ($crop_keys as $key) $add($key, $u['pixels']);
				return;
			}
			if ($ratio !== '') {
				$parsed = Renderer::parse_ratio($ratio);
				if ($parsed && Sizes::matches_ratio($fw, $fh, $parsed['value'])) $ratio = '';
			}
			$add($ratio, $u['pixels']);
		};

		$uses = $job['uses'] ?? [];
		$matched = false;
		foreach ($uses as $u) {
			if (str_starts_with($u['scope'], 'id:') && in_array((int) substr($u['scope'], 3), $ids, true)) {
				$use($u);
				$matched = true;
			}
		}
		foreach (array_keys($places) as $place) {
			$found = $job['matches'][$place] ?? [];
			if (!$found) {
				$job['unmatched'][$place] = ($job['unmatched'][$place] ?? 0) + 1;
				return ['kept' => 'unmatched'] + $none;
			}
			foreach ($found as $i) $use($uses[$i]);
			$matched = true;
		}

		$unused = false;
		if (!$matched) {
			// Placed nowhere, yet a render asked for a crop or a width: something shows it.
			foreach ($ids as $id) {
				if (Index::wants($id) || !empty(Index::get($id)['crops'])) return ['kept' => 'shown'] + $none;
			}
			$unused = true;
		}

		$widths = $job['widths'];
		$caps = [];
		foreach ($needs + ['' => 0] as $ratio => $pixels) {
			if ($pixels === null) continue;
			$parsed = $ratio === '' ? null : Renderer::parse_ratio($ratio);
			if ($ratio !== '' && !$parsed) continue;
			$widest = $parsed ? (int) min($fw, floor($fh * $parsed['value']), Config::MAX_GENERATED_WIDTH) + 1 : $fw;
			$cap = Need::covering(max($pixels, self::FLOOR), $widths, $widest);
			if ($cap !== null) $caps[$ratio] = $cap;
		}
		if (!$caps) return ['kept' => 'whole'] + $none;

		[$files, $bytes] = self::excess($ids, $caps, false);
		return ['caps' => $caps, 'files' => count($files), 'bytes' => $bytes, 'unused' => $unused, 'kept' => ''];
	}

	/**
	 * The files of this file's attachments that the caps leave out: modern copies of the
	 * candidates past the cap and of the full file, the `tavif-*` JPEGs past it, crops past
	 * a crop's cap and their copies. WordPress's own sizes are never among them.
	 *
	 * @return array{0: string[], 1: int} Paths, bytes.
	 */
	private static function excess(array $ids, array $caps, bool $apply): array {
		$paths = [];
		$bytes = 0;
		$widths = Config::widths();
		foreach ($ids as $id) {
			$attached = get_attached_file($id);
			$meta = wp_get_attachment_metadata($id);
			if (!$attached || !is_array($meta) || empty($meta['width'])) continue;
			$dir = dirname($attached);
			$index = Index::get($id);
			$extra = (array) ($index['extra'] ?? []);

			// WordPress names a sub-size after its dimensions, so a `tavif-*` size and another of the
			// same width — a theme's add_image_size('hero', 1920), core's medium_large — are one
			// file. Its other names still point at it, and it stays.
			$shared = [wp_basename($attached) => true];
			if (!empty($meta['original_image'])) $shared[(string) $meta['original_image']] = true;
			foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
				if (!str_starts_with((string) $name, Sizes::PREFIX) && !empty($size['file'])) $shared[(string) $size['file']] = true;
			}

			$gone = [];
			if (isset($caps[''])) {
				$before = array_column(Sizes::candidates($meta, $widths, null, $extra), 'file');
				$after = array_column(Sizes::candidates($meta, $widths, null, $extra, $caps['']), 'file');
				$gone = array_diff($before, $after);
				foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
					if (str_starts_with((string) $name, Sizes::PREFIX) && (int) ($size['width'] ?? 0) > $caps[''] && !empty($size['file'])) {
						if (!isset($shared[(string) $size['file']])) $paths[] = "$dir/{$size['file']}";
						$gone[] = (string) $size['file'];
					}
				}
			}
			foreach ((array) ($index['crops'] ?? []) as $ratio => $crops) {
				if (!isset($caps[$ratio])) continue;
				foreach ((array) $crops as $crop) {
					if ((int) $crop['w'] > $caps[$ratio] && !empty($crop['file'])) {
						$paths[] = "$dir/{$crop['file']}";
						$gone[] = (string) $crop['file'];
					}
				}
			}
			$gone = array_flip(array_unique($gone));
			foreach (['avif', 'webp'] as $format) {
				foreach ((array) ($index[$format] ?? []) as $source => $entry) {
					if (isset($gone[$source]) && !empty($entry['file'])) $paths[] = "$dir/{$entry['file']}";
				}
			}

			if ($apply) self::trim($id, $meta, $index, $caps, $gone);
		}

		$paths = array_values(array_unique($paths));
		$paths = array_values(array_filter($paths, 'is_file'));
		foreach ($paths as $path) $bytes += (int) filesize($path);
		return [$paths, $bytes];
	}

	/**
	 * Drop from one attachment's metadata and index what the caps leave out, and record them.
	 *
	 * @param array<string, int> $gone Names of the files going, as keys.
	 */
	private static function trim(int $id, array $meta, array $index, array $caps, array $gone): void {
		Index::put_caps($id, $caps);
		delete_post_meta($id, Index::NEED);

		foreach (['avif', 'webp'] as $format) {
			if (isset($index[$format])) $index[$format] = array_diff_key((array) $index[$format], $gone);
		}
		foreach ((array) ($index['crops'] ?? []) as $ratio => $crops) {
			if (!isset($caps[$ratio])) continue;
			$index['crops'][$ratio] = array_values(array_filter((array) $crops, fn($c) => (int) $c['w'] <= $caps[$ratio]));
		}
		Index::put($id, $index);

		$sizes = (array) ($meta['sizes'] ?? []);
		$kept = array_filter($sizes, fn($size, $name) => !(str_starts_with((string) $name, Sizes::PREFIX) && isset($gone[(string) ($size['file'] ?? '')])), ARRAY_FILTER_USE_BOTH);
		if (count($kept) !== count($sizes)) {
			$meta['sizes'] = $kept;
			wp_update_attachment_metadata($id, $meta);
		}
	}

	/** @return array{0: int, 1: int} Files deleted, bytes. */
	private static function apply_group(array $ids, array $caps): array {
		// Each language of the file had its own caps: a render may have raised one and not the other.
		$before = [];
		foreach ($ids as $id) $before[$id] = Index::caps($id);

		[$paths, $bytes] = self::excess($ids, $caps, true);
		$deleted = 0;
		foreach ($paths as $path) if (@unlink($path)) $deleted++;

		$queued = false;
		foreach ($ids as $id) {
			if (!$caps) Index::put_caps($id, []);
			// Allowed more than before: the widths in between are built again, and listed in its metadata.
			if (self::allows_more($before[$id], $caps)) {
				delete_post_meta($id, Index::STAMP);
				$queued = true;
			}
		}
		if ($queued) Worker::hint();
		return [$deleted, $bytes];
	}

	/** Whether $after keeps some width $before left out. */
	private static function allows_more(array $before, array $after): bool {
		foreach ($before as $ratio => $cap) {
			if (!isset($after[$ratio]) || $after[$ratio] > $cap) return true;
		}
		return false;
	}
}
