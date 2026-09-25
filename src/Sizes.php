<?php

namespace TimberAVIF;

/**
 * The canonical widths, as image sizes WordPress knows about.
 *
 * Made at upload like every other sub-size, never while a page renders: built with
 * Timber's resize on the render path, a category page of 28 products ran ~250 JPEG resizes
 * on its first view. As registered sizes, `wp media regenerate`, the `-scaled` original
 * and deletion all work as they do in core.
 */
final class Sizes {
	const PREFIX = 'tavif-';

	public static function register(): void {
		foreach (Config::widths() as $w) {
			add_image_size(self::PREFIX . $w, $w, 0, false);
		}
	}

	/**
	 * `intermediate_image_sizes_advanced`: a width at or above the full image would only
	 * write a copy of it under another name.
	 */
	public static function skip_redundant(array $sizes, array $meta): array {
		$full = (int) ($meta['width'] ?? 0);
		if (!$full) return $sizes;
		foreach ($sizes as $name => $data) {
			if (str_starts_with($name, self::PREFIX) && (int) ($data['width'] ?? 0) >= $full) unset($sizes[$name]);
		}
		return $sizes;
	}

	/**
	 * Create the canonical sizes an attachment is missing — it was uploaded before v7, or
	 * before a width was added — and only those: wp_update_image_subsizes() on its own
	 * would also build whatever other plugins registered.
	 */
	public static function ensure(int $id, array $meta): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$full = (int) ($meta['width'] ?? 0);
		$ours = static function ($missing) use ($full) {
			return array_filter((array) $missing, fn($data, $name) => str_starts_with($name, self::PREFIX) && (int) $data['width'] < $full, ARRAY_FILTER_USE_BOTH);
		};

		// wp_update_image_subsizes() runs `wp_generate_attachment_metadata` again, as if the
		// image had changed. Timber acts on that, wrongly here, since sizes are only added: it
		// deletes every resize it made for the image — on Mobilissimo all 6,685 of them, which
		// the next render of each page then rebuilt inline.
		$suspended = [];
		$callback = ['Timber\\ImageHelper', 'generate_attachment_metadata'];
		$priority = has_filter('wp_generate_attachment_metadata', $callback);
		if ($priority !== false) {
			remove_filter('wp_generate_attachment_metadata', $callback, $priority);
			$suspended[] = [$callback, $priority];
		}

		add_filter('wp_get_missing_image_subsizes', $ours, 99);
		try {
			if (!wp_get_missing_image_subsizes($id)) return $meta;
			$updated = wp_update_image_subsizes($id);
		} finally {
			remove_filter('wp_get_missing_image_subsizes', $ours, 99);
			foreach ($suspended as [$callback, $priority]) add_filter('wp_generate_attachment_metadata', $callback, $priority, 2);
		}

		return is_array($updated) ? $updated : $meta;
	}

	/**
	 * The srcset candidates of an uncropped image, from its metadata alone.
	 *
	 * Sub-sizes that keep the full image's aspect ratio are usable; once every configured
	 * width exists, only those are. An image uploaded before v7 has none of them yet, so
	 * until the worker builds them it is also served from the proportional sizes WordPress
	 * already made (medium, medium_large, large…) rather than from the full file alone.
	 *
	 * @param array $meta   Attachment metadata: width, height, file, sizes.
	 * @param int[] $widths Configured (or per-call) widths.
	 * @param ?int  $max    Cap for images displayed small.
	 * @param array $extra  Uncropped widths a template asked for (Index 'extra'): always candidates.
	 * @return array<int, array{w: int, h: int, file: string}> Ascending by width.
	 */
	public static function candidates(array $meta, array $widths, ?int $max = null, array $extra = []): array {
		$fw = (int) ($meta['width'] ?? 0);
		$fh = (int) ($meta['height'] ?? 0);
		$file = basename((string) ($meta['file'] ?? ''));
		if (!$fw || !$fh || !$file) return [];

		$proportional = [];
		foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
			$w = (int) ($size['width'] ?? 0);
			$h = (int) ($size['height'] ?? 0);
			if (!$w || !$h || empty($size['file']) || $w >= $fw) continue;
			// Proportional within rounding: WordPress rounds each dimension on its own.
			if (abs($h - $w * $fh / $fw) > 1.5) continue;
			// Ours win a tie: they are the files the worker keeps a modern copy of.
			if (!isset($proportional[$w]) || str_starts_with((string) $name, self::PREFIX)) {
				$proportional[$w] = ['w' => $w, 'h' => $h, 'file' => (string) $size['file']];
			}
		}

		$wanted = array_filter($widths, fn($w) => $w < $fw && $w <= Config::MAX_GENERATED_WIDTH);
		$chosen = array_intersect_key($proportional, array_flip($wanted));
		// Incomplete — uploaded before v7, or a width was just added: fill in with whatever
		// else WordPress made, until the worker builds the rest.
		if (count($chosen) < count($wanted)) $chosen += $proportional;

		foreach ($extra as $e) {
			$w = (int) ($e['w'] ?? 0);
			if ($w && $w < $fw && !empty($e['file'])) $chosen[$w] ??= ['w' => $w, 'h' => (int) ($e['h'] ?? 0), 'file' => (string) $e['file']];
		}

		$chosen = array_filter($chosen, fn($c) => $c['w'] <= Config::MAX_GENERATED_WIDTH);
		// The full file covers high-density screens, unless it is past what is ever generated.
		if ($fw <= Config::MAX_GENERATED_WIDTH) $chosen[$fw] = ['w' => $fw, 'h' => $fh, 'file' => $file];

		ksort($chosen);
		return self::limit(array_values($chosen), $max);
	}

	/**
	 * Is this file of the attachment shown at the full image's proportions — the full file,
	 * the original upload, or a sub-size that was not cropped?
	 */
	public static function is_proportional(array $meta, string $file): bool {
		$fw = (int) ($meta['width'] ?? 0);
		$fh = (int) ($meta['height'] ?? 0);
		if (!$fw || !$fh || $file === '') return false;
		if ($file === wp_basename((string) ($meta['file'] ?? '')) || $file === ($meta['original_image'] ?? null)) return true;

		foreach ((array) ($meta['sizes'] ?? []) as $size) {
			if (($size['file'] ?? '') !== $file) continue;
			$w = (int) ($size['width'] ?? 0);
			$h = (int) ($size['height'] ?? 0);
			return $w && $h && abs($h - $w * $fh / $fw) <= 1.5;
		}
		return false;
	}

	/**
	 * Candidates for a server-side crop, from the index the worker wrote.
	 *
	 * @param array<int, array{w: int, h: int, file: string}> $crops
	 */
	public static function crop_candidates(array $crops, array $widths, ?int $max = null): array {
		$by_width = [];
		$asked = [];
		foreach ($crops as $c) {
			if (empty($c['file']) || empty($c['w'])) continue;
			$by_width[(int) $c['w']] = ['w' => (int) $c['w'], 'h' => (int) $c['h'], 'file' => (string) $c['file']];
			// Built because a template displays this crop small: a candidate whatever the configured widths.
			if (!empty($c['asked'])) $asked[(int) $c['w']] = true;
		}
		ksort($by_width);
		// The largest crop is the "full" one: always kept, like the full file of an uncropped image.
		$top = $by_width ? end($by_width) : null;
		$chosen = array_intersect_key($by_width, array_flip($widths) + $asked);
		if ($top) $chosen[$top['w']] = $top;
		ksort($chosen);
		return self::limit(array_values($chosen), $max);
	}

	/**
	 * Does the source already have these proportions? Then cropping to them would only
	 * resize: the files the uncropped set has, written again under -tavif names. And the
	 * widest, which is the source itself, WordPress refuses to make at all — "Could not
	 * calculate resized image dimensions" — since it ignores a difference of a pixel. Such
	 * a ratio is served uncropped.
	 */
	public static function matches_ratio(int $w, int $h, float $ratio): bool {
		if ($w < 1 || $h < 1 || $ratio <= 0) return false;
		return $w - min($w, floor($h * $ratio)) <= 1 && $h - min($h, floor($w / $ratio)) <= 1;
	}

	/**
	 * The crops to build for one ratio: each configured width the source can cover, plus
	 * the widest crop it allows, capped at the generation ceiling. None when the source
	 * already has the ratio (matches_ratio()).
	 *
	 * @return array<int, array{w: int, h: int}>
	 */
	public static function crop_targets(int $src_w, int $src_h, float $ratio, array $widths): array {
		if ($src_w < 1 || $src_h < 1 || $ratio <= 0 || self::matches_ratio($src_w, $src_h, $ratio)) return [];

		$widest = (int) min($src_w, floor($src_h * $ratio), Config::MAX_GENERATED_WIDTH);
		$targets = [];
		foreach (array_merge($widths, [$widest]) as $w) {
			$w = (int) $w;
			if ($w < 1 || $w > $widest) continue;
			$targets[$w] = ['w' => $w, 'h' => max(1, (int) round($w / $ratio))];
		}
		ksort($targets);
		return array_values($targets);
	}

	/**
	 * Apply `max`. Above it one more candidate is kept, the smallest past it: a 200px logo
	 * capped at 400 still needs something sharp at DPR 2.
	 *
	 * Nothing else thins the set: every configured width exists, so it is what Settings →
	 * Widths says.
	 */
	private static function limit(array $candidates, ?int $max): array {
		if (!$max) return $candidates;

		$kept = [];
		foreach ($candidates as $c) {
			$kept[] = $c;
			if ($c['w'] >= $max) break;
		}
		return $kept;
	}
}
