<?php

namespace TimberAVIF;

/**
 * Markup data, from the attachment's metadata and index alone.
 *
 * No file_exists(), no resize, no encode: what a page renders depends only on what is
 * recorded, so a page cache holds the same markup a fresh render would produce. Markup
 * that depended on how far a conversion had got would let a cache keep the degraded
 * version long after the files existed.
 */
final class Renderer {

	/**
	 * Everything the image() macro needs for a responsive <picture>.
	 *
	 * @param mixed $image Timber\Image, attachment ID, WP_Post, ACF image array, or a URL.
	 * @param array $opts  widths (int[]), max (int), ratio ('16/9'|float), disclosure (string|false),
	 *                     atf (bool: the macro gives this image fetchpriority="high"),
	 *                     sizes (string: how wide it is shown, for an image Optimize capped)
	 */
	public static function sources($image, array $opts = []): array {
		$empty = ['ok' => false, 'src' => '', 'srcset' => '', 'width' => null, 'height' => null, 'modern' => null, 'disclosure' => ''];

		// WordPress gives fetchpriority="high" to one image per page, the first large one that
		// goes through its own functions, and keeps count with this flag. The macro's hero goes
		// through Twig, so WordPress never saw it and marked the first large image of the
		// content too: two hints competing for bandwidth. Now it knows the place is taken.
		// Pages without an atf image keep WordPress's own choice.
		if (!empty($opts['atf']) && function_exists('wp_high_priority_element_flag')) wp_high_priority_element_flag(false);

		$id = self::attachment_id($image);
		if (!$id) {
			// Not an attachment: served as it is. v7 converts library images only.
			$url = self::string_url($image);
			return $url ? array_merge($empty, ['ok' => true, 'src' => $url]) : $empty;
		}

		$full_url = wp_get_attachment_url($id);
		if (!$full_url) return $empty;

		$disclosure = self::disclosure($id, $opts['disclosure'] ?? '');
		$meta = wp_get_attachment_metadata($id);
		$index = Index::get($id);

		$fw = (int) ($meta['width'] ?? 0);
		$fh = (int) ($meta['height'] ?? 0);
		if (!$fw || !$fh || !empty($index['anim'])) {
			return array_merge($empty, ['ok' => true, 'src' => $full_url, 'width' => $fw ?: null, 'height' => $fh ?: null, 'disclosure' => $disclosure]);
		}

		$widths = self::widths($opts['widths'] ?? []);
		$max = !empty($opts['max']) ? (int) $opts['max'] : null;
		$ratio = self::parse_ratio($opts['ratio'] ?? null);
		// A square photo asked for as 1/1 is its own crop: served uncropped, nothing to build.
		if ($ratio && Sizes::matches_ratio($fw, $fh, $ratio['value'])) $ratio = null;

		$candidates = self::candidates($id, $meta, $index, $widths, $max, $ratio);
		// A call that does not say how wide it shows the image — no `sizes`, no `max` — may show
		// it at any width, as Optimize reads it too.
		self::check_cap($id, $ratio['key'] ?? '', Need::pixels(isset($opts['sizes']) ? (string) $opts['sizes'] : null, $max) ?? Need::WHOLE);
		if (!$candidates) {
			return array_merge($empty, ['ok' => true, 'src' => $full_url, 'width' => $fw, 'height' => $fh, 'disclosure' => $disclosure]);
		}

		$base = trailingslashit(dirname($full_url));
		$format = Config::format();
		$modern = $format ? self::modern_srcset($candidates, (array) ($index[$format] ?? []), $base) : null;

		// Fallback src: the candidate closest to 1024, where most viewports land.
		$pick = $candidates[0];
		foreach ($candidates as $c) {
			if (abs($c['w'] - 1024) < abs($pick['w'] - 1024)) $pick = $c;
		}

		// A crop not built yet is served uncropped, at the requested proportions: object-cover
		// does the cropping meanwhile, and width/height keep the box the template asked for.
		$height = $ratio ? (int) round($pick['w'] / $ratio['value']) : $pick['h'];

		return [
			'ok'         => true,
			'src'        => $base . $pick['file'],
			'srcset'     => implode(', ', array_map(fn($c) => $base . $c['file'] . ' ' . $c['w'] . 'w', $candidates)),
			'width'      => $pick['w'],
			'height'     => $height,
			'modern'     => $modern ? ['type' => Engine::mime($format), 'srcset' => $modern] : null,
			'disclosure' => $disclosure,
		];
	}

	/**
	 * A single URL, for |best_src: what cannot be a <picture> — a video poster, a CSS
	 * background, a blurred placeholder.
	 *
	 * The smallest candidate at least $width wide (the largest one without $width), in the
	 * served format when a copy exists, otherwise the fallback file. With both $width and
	 * $height it is a crop at that ratio. A width below every candidate — a 96px placeholder
	 * — is asked of the worker, as `max` is for the macro; until it is built the smallest
	 * candidate is served. Nothing is resized or converted inline: it picks from what exists,
	 * so the render never waits.
	 */
	public static function url($image, $width = null, $height = null): string {
		$id = self::attachment_id($image);
		if (!$id) return self::string_url($image);

		$full_url = wp_get_attachment_url($id);
		$meta = wp_get_attachment_metadata($id);
		if (!$full_url || empty($meta['width'])) return (string) $full_url;

		$index = Index::get($id);
		if (!empty($index['anim'])) return $full_url;

		$width  = $width !== null ? (int) round((float) $width) : null;
		$height = $height !== null ? (int) round((float) $height) : null;
		// As "1280/720", so it reduces to the same "16x9" crop set a template's ratio: '16/9' uses.
		$ratio  = ($width && $height) ? self::parse_ratio("$width/$height") : null;
		if ($ratio && Sizes::matches_ratio((int) $meta['width'], (int) ($meta['height'] ?? 0), $ratio['value'])) $ratio = null;

		$candidates = self::candidates($id, $meta, $index, Config::widths(), $width, $ratio);
		self::check_cap($id, $ratio['key'] ?? '', $width ?: Need::WHOLE);
		if (!$candidates) return $full_url;

		$pick = end($candidates);
		if ($width) {
			foreach ($candidates as $c) {
				if ($c['w'] >= $width) { $pick = $c; break; }
			}
		}

		$base = trailingslashit(dirname($full_url));
		$format = Config::format();
		$entry = $format ? ($index[$format][$pick['file']] ?? null) : null;

		return $base . (!empty($entry['file']) ? $entry['file'] : $pick['file']);
	}

	/**
	 * The modern srcset, or null when it must not be emitted.
	 *
	 * A candidate never processed makes the whole set unusable: a browser that supports
	 * the format picks only from this <source>, so a partial set — the smallest widths
	 * converted, the rest still queued — would have it upscale a 480w file on a desktop. A width
	 * discarded for coming out heavier is a permanent gap and is fine, as long as the
	 * largest candidate is there: without it big screens would get the next one down.
	 *
	 * @param array<int, array{w: int, file: string}> $candidates Ascending by width.
	 */
	public static function modern_srcset(array $candidates, array $entries, string $base): ?string {
		if (!$candidates) return null;

		$set = [];
		foreach ($candidates as $c) {
			$entry = $entries[$c['file']] ?? null;
			if ($entry === null) return null;
			if (!empty($entry['file'])) $set[] = $base . $entry['file'] . ' ' . $c['w'] . 'w';
		}

		$top = end($candidates);
		if (empty($entries[$top['file']]['file'])) return null;

		return implode(', ', $set);
	}

	/**
	 * '16/9', '4x1', 1.7778 → ['key' => '16x9', 'value' => 1.7778]. The key names the
	 * crop set in the index, so '16/9' and '32/18' share one.
	 */
	public static function parse_ratio($ratio): ?array {
		if (is_string($ratio) && preg_match('#^\s*(\d+(?:\.\d+)?)\s*[/x:]\s*(\d+(?:\.\d+)?)\s*$#', $ratio, $m)) {
			[$w, $h] = [(float) $m[1], (float) $m[2]];
			if ($w <= 0 || $h <= 0) return null;
			if (floor($w) == $w && floor($h) == $h) {
				$g = self::gcd((int) $w, (int) $h);
				return ['key' => ((int) $w / $g) . 'x' . ((int) $h / $g), 'value' => $w / $h];
			}
			$ratio = $w / $h;
		}
		if (is_numeric($ratio) && (float) $ratio > 0) {
			$value = (float) $ratio;
			return ['key' => rtrim(rtrim(sprintf('%.4f', $value), '0'), '.'), 'value' => $value];
		}
		return null;
	}

	/**
	 * Accepts what templates pass around: Timber images, IDs, posts, ACF image arrays, and
	 * — through attachment_url_to_postid(), one query each, remembered per request — the
	 * URL of an original upload.
	 */
	public static function attachment_id($image): int {
		if ($image instanceof \Timber\Post) return (int) $image->ID;
		if ($image instanceof \WP_Post) return $image->post_type === 'attachment' ? (int) $image->ID : 0;
		if (is_int($image) || (is_string($image) && ctype_digit($image))) return (int) $image;
		if (is_array($image) && isset($image['ID'])) return (int) $image['ID'];
		if (is_array($image) && isset($image['id'])) return (int) $image['id'];

		$url = self::string_url($image);
		if ($url === '') return 0;

		static $by_url = [];
		return $by_url[$url] ??= (int) attachment_url_to_postid($url);
	}

	private static function candidates(int $id, $meta, array $index, array $widths, ?int $max, ?array $ratio): array {
		$caps = Index::caps($id);
		if ($ratio) {
			$crops = (array) ($index['crops'][$ratio['key']] ?? []);
			if ($crops) return self::with_small($id, $ratio['key'], Sizes::crop_candidates($crops, $widths, $max, $caps[$ratio['key']] ?? null), $max);
			// First time a template asks for this crop: one meta row, then the worker builds it.
			Index::want($id, $ratio['key']);
			if ($max && $max < min(Config::widths() ?: [PHP_INT_MAX])) Index::want($id, $ratio['key'], $max);
			// Meanwhile the uncropped files stand in, as they are: asking for a small uncropped
			// width here would build a file nobody serves once the crop exists.
			return Sizes::candidates((array) $meta, $widths, $max, (array) ($index['extra'] ?? []), $caps[''] ?? null);
		}
		return self::with_small($id, '', Sizes::candidates((array) $meta, $widths, $max, (array) ($index['extra'] ?? []), $caps[''] ?? null), $max);
	}

	/**
	 * An image Optimize capped, shown here wider than the cap — reused in a hero after a card,
	 * or a template redesigned: the need is recorded once, and the worker builds the widths
	 * up to it. Meanwhile the capped set is served, slightly upscaled, still in the modern
	 * format. For an image with no cap this costs one array lookup.
	 */
	private static function check_cap(int $id, string $ratio, int $pixels): void {
		$caps = Index::caps($id);
		if (isset($caps[$ratio]) && $pixels > $caps[$ratio]) Index::need($id, $ratio, $pixels);
	}

	/**
	 * `max` well below every candidate — a thumbnail on a site whose smallest width is 480 —
	 * asks the worker for that width. Until the worker has built it, the smallest candidate
	 * is served. Within a quarter of it, the smallest candidate will do.
	 */
	private static function with_small(int $id, string $ratio, array $candidates, ?int $max): array {
		if ($max && $candidates && $candidates[0]['w'] > $max * 1.25) Index::want($id, $ratio, $max);
		return $candidates;
	}

	/**
	 * Per-call widths pick from the configured ones: files exist only for those.
	 */
	private static function widths($custom): array {
		$configured = Config::widths();
		$custom = array_filter(array_map('intval', (array) $custom));
		if (!$custom) return $configured;

		$kept = array_values(array_intersect($configured, $custom));
		if (count($kept) < count($custom) && defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[TimberAVIF] widths not in Settings → Widths are ignored: ' . implode(', ', array_diff($custom, $configured)));
		}
		return $kept ?: $configured;
	}

	private static function string_url($image): string {
		if (is_string($image)) return $image;
		if (is_array($image) && isset($image['url'])) return (string) $image['url'];
		if (is_object($image) && method_exists($image, '__toString')) return (string) $image;
		return '';
	}

	/**
	 * AI Act art. 50(4) disclosure markup for this image, or '' when it needs none.
	 *
	 * Answered by whoever implements the filter — the ai-disclosure module in Bizen
	 * Toolkit — so this file carries no dependency on it: with nothing listening the
	 * string stays empty and the macro skips the wrapper entirely.
	 *
	 * $position is the corner (bottom-right by default). Which corner is a property of
	 * the composition rather than of the file, so the template decides. 'none', or false,
	 * leaves the label off this one placement; the image keeps it everywhere else.
	 */
	private static function disclosure(int $id, $position): string {
		// `disclosure: false` reads as "not here". Cast to a string it would become '',
		// which the plugin reads as "wherever the default is" — the opposite.
		if (false === $position) $position = 'none';
		return (string) apply_filters('bizen_ai_disclosure_badge', '', $id, (string) $position);
	}

	private static function gcd(int $a, int $b): int {
		return $b === 0 ? max(1, $a) : self::gcd($b, $a % $b);
	}
}
