<?php

namespace TimberAVIF;

/**
 * How wide a template shows an image, read from the `sizes` it gives it: the widest slot
 * over viewports from 320 to 2560 CSS px, at the density the files are made for.
 *
 * Evaluated the way a browser does it, at sampled viewport widths: the first entry whose
 * media condition matches, then its length. No interval arithmetic is needed, and a slot
 * that is widest on a tablet — `(min-width: 64rem) 50vw, calc(100vw - 3rem)` is 975 px just
 * below 1024 — is found where it is.
 *
 * Only the syntax themes write is read: `min-width` and `max-width` and their range forms,
 * in px, em and rem, joined by `and`; lengths in px, em, rem and vw, with calc(), min(),
 * max() and clamp(). Anything else makes the whole value unknown, and an unknown width is
 * the full width: a `sizes` misread keeps more files, never fewer.
 */
final class Need {
	// The files are made for screens up to this density; a 3x phone gets the 2x file, slightly upscaled.
	const DENSITY = 2;
	const MIN_VIEWPORT = 320;
	const MAX_VIEWPORT = 2560;
	const STEP = 8;
	// em and rem in `sizes` and in media conditions are the initial font size, whatever the page sets.
	const FONT = 16;
	// Pixels that stand for "every width": wider than anything generated.
	const WHOLE = 99999;

	/** @var array<string, ?float> */
	private static array $slots = [];

	/**
	 * Pixels the image must have for this call, or null when they cannot be known — which
	 * means all of them. With `max` the candidates stop at the first one past it, so the
	 * call never needs more, whatever its `sizes`.
	 */
	public static function pixels(?string $sizes, ?int $max = null): ?int {
		$slot = $sizes === null ? null : self::slot($sizes);
		$pixels = $slot === null ? null : (int) ceil($slot * self::DENSITY);
		if ($max && $max > 0) return $pixels === null ? $max : min($pixels, $max);
		return $pixels;
	}

	/**
	 * The configured width that covers $pixels: the smallest one at least as wide, below the
	 * image's own width. Null when none does, and the image is needed whole.
	 *
	 * @param int[] $widths Ascending.
	 */
	public static function covering(int $pixels, array $widths, int $full): ?int {
		foreach ($widths as $w) {
			if ($w >= $full) break;
			if ($w >= $pixels) return (int) $w;
		}
		return null;
	}

	/** CSS px of the widest slot, or null when the value cannot be read. */
	public static function slot(string $sizes): ?float {
		$sizes = trim($sizes);
		if (array_key_exists($sizes, self::$slots)) return self::$slots[$sizes];
		if (count(self::$slots) > 200) self::$slots = [];
		return self::$slots[$sizes] = self::evaluate($sizes);
	}

	private static function evaluate(string $sizes): ?float {
		$entries = [];
		$bounds = [];
		foreach (self::split($sizes, ',') as $entry) {
			$entry = trim($entry);
			if ($entry === '') continue;
			// `auto` lets the browser measure the image; the list after it is for those that cannot.
			if (strtolower($entry) === 'auto') continue;
			$parsed = self::entry($entry);
			if ($parsed === null) return null;
			$entries[] = $parsed;
			foreach ((array) $parsed[0] as [, $px]) array_push($bounds, $px - 0.5, $px, $px + 0.5);
		}
		if (!$entries) return null;

		$viewports = array_merge(range(self::MIN_VIEWPORT, self::MAX_VIEWPORT, self::STEP), [self::MAX_VIEWPORT], $bounds);
		$widest = 0.0;
		foreach ($viewports as $vw) {
			if ($vw < self::MIN_VIEWPORT || $vw > self::MAX_VIEWPORT) continue;
			// No entry matching is the same as 100vw.
			$slot = (float) $vw;
			foreach ($entries as [$condition, $length]) {
				if ($condition !== null && !self::matches($condition, $vw)) continue;
				$slot = $length($vw);
				break;
			}
			$widest = max($widest, $slot);
		}
		return $widest;
	}

	/**
	 * One entry: [conditions or null, length as fn(viewport): px], or null if unreadable.
	 * The length is the last thing in the entry, so it is read from the end.
	 */
	private static function entry(string $entry): ?array {
		if (preg_match('/^(.*?)\s*((?:calc|min|max|clamp)\s*\(.*\))$/is', $entry, $m)
			|| preg_match('/^(.*?)\s*([-+]?(?:\d+\.?\d*|\.\d+)(?:px|vw|em|rem)?)$/is', $entry, $m)) {
			$length = self::length($m[2]);
			if ($length === null) return null;
			$condition = trim($m[1]);
			if ($condition === '') return [null, $length];
			$parsed = self::condition($condition);
			return $parsed === null ? null : [$parsed, $length];
		}
		return null;
	}

	/**
	 * `(min-width: 64rem) and (max-width: 80rem)`, `(width >= 40rem)`, `screen and (…)` →
	 * a list of [operator, px] that must all hold.
	 *
	 * @return array<int, array{0: string, 1: float}>|null
	 */
	private static function condition(string $condition): ?array {
		$condition = strtolower(trim($condition));
		$condition = preg_replace('/^(only\s+)?(screen|all)(\s+and\s+|$)/', '', $condition);
		if ($condition === '') return [];

		$tests = [];
		foreach (preg_split('/\s+and\s+/', $condition) as $part) {
			$part = trim($part);
			$n = '(\d+\.?\d*|\.\d+)(px|em|rem)';
			if (preg_match("/^\(\s*(min|max)-width\s*:\s*$n\s*\)$/", $part, $m)) {
				$tests[] = [$m[1] === 'min' ? '>=' : '<=', self::px((float) $m[2], $m[3])];
			} elseif (preg_match("/^\(\s*width\s*(>=|<=|>|<)\s*$n\s*\)$/", $part, $m)) {
				$tests[] = [$m[1], self::px((float) $m[2], $m[3])];
			} elseif (preg_match("/^\(\s*$n\s*(>=|<=|>|<)\s*width\s*\)$/", $part, $m)) {
				// 40rem <= width reads as width >= 40rem.
				$tests[] = [strtr($m[3], ['<' => '>', '>' => '<']), self::px((float) $m[1], $m[2])];
			} elseif (preg_match("/^\(\s*$n\s*(<=|<)\s*width\s*(<=|<)\s*$n\s*\)$/", $part, $m)) {
				$tests[] = [$m[3] === '<' ? '>' : '>=', self::px((float) $m[1], $m[2])];
				$tests[] = [$m[4], self::px((float) $m[5], $m[6])];
			} else {
				return null;
			}
		}
		return $tests;
	}

	private static function matches(array $tests, float $vw): bool {
		foreach ($tests as [$op, $px]) {
			$ok = match ($op) {
				'>=' => $vw >= $px,
				'<=' => $vw <= $px,
				'>'  => $vw > $px,
				'<'  => $vw < $px,
			};
			if (!$ok) return false;
		}
		return true;
	}

	/**
	 * A length as a function of the viewport width, from a small recursive-descent parser:
	 * sums, products, parentheses, and calc(), min(), max(), clamp().
	 */
	private static function length(string $length): ?\Closure {
		$tokens = self::tokens($length);
		if ($tokens === null) return null;
		$pos = 0;
		$fn = self::sum($tokens, $pos);
		return ($fn && $pos === count($tokens)) ? $fn : null;
	}

	/** @return array<int, array{0: string, 1: mixed}>|null */
	private static function tokens(string $s): ?array {
		$tokens = [];
		$s = strtolower($s);
		$i = 0;
		$len = strlen($s);
		while ($i < $len) {
			$c = $s[$i];
			if (ctype_space($c)) { $i++; continue; }
			if (preg_match('/\G(\d+\.?\d*|\.\d+)(px|vw|rem|em)?/A', $s, $m, 0, $i)) {
				$tokens[] = ['num', [(float) $m[1], $m[2] ?? '']];
				$i += strlen($m[0]);
			} elseif (preg_match('/\G(calc|min|max|clamp)\s*\(/A', $s, $m, 0, $i)) {
				$tokens[] = ['fn', $m[1]];
				$i += strlen($m[0]);
			} elseif (str_contains('+-*/(),', $c)) {
				$tokens[] = [$c, null];
				$i++;
			} else {
				return null;
			}
		}
		return $tokens;
	}

	private static function sum(array $t, int &$pos): ?\Closure {
		$left = self::product($t, $pos);
		while ($left && isset($t[$pos]) && ($t[$pos][0] === '+' || $t[$pos][0] === '-')) {
			$op = $t[$pos++][0];
			$right = self::product($t, $pos);
			if (!$right) return null;
			$prev = $left;
			$left = $op === '+' ? fn($vw) => $prev($vw) + $right($vw) : fn($vw) => $prev($vw) - $right($vw);
		}
		return $left;
	}

	private static function product(array $t, int &$pos): ?\Closure {
		$left = self::factor($t, $pos);
		while ($left && isset($t[$pos]) && ($t[$pos][0] === '*' || $t[$pos][0] === '/')) {
			$op = $t[$pos++][0];
			$right = self::factor($t, $pos);
			if (!$right) return null;
			$prev = $left;
			$left = $op === '*' ? fn($vw) => $prev($vw) * $right($vw) : fn($vw) => ($d = $right($vw)) == 0 ? INF : $prev($vw) / $d;
		}
		return $left;
	}

	private static function factor(array $t, int &$pos): ?\Closure {
		$tok = $t[$pos] ?? null;
		if (!$tok) return null;

		if ($tok[0] === '-') {
			$pos++;
			$inner = self::factor($t, $pos);
			return $inner ? fn($vw) => -$inner($vw) : null;
		}
		if ($tok[0] === 'num') {
			$pos++;
			[$value, $unit] = $tok[1];
			if ($unit === 'vw') return fn($vw) => $value * $vw / 100;
			$px = $unit === '' ? $value : self::px($value, $unit);
			return fn($vw) => $px;
		}
		if ($tok[0] === '(') {
			$pos++;
			$inner = self::sum($t, $pos);
			if (!$inner || ($t[$pos][0] ?? '') !== ')') return null;
			$pos++;
			return $inner;
		}
		if ($tok[0] === 'fn') {
			$pos++;
			$args = [];
			do {
				$arg = self::sum($t, $pos);
				if (!$arg) return null;
				$args[] = $arg;
			} while (($t[$pos][0] ?? '') === ',' && ++$pos);
			if (($t[$pos][0] ?? '') !== ')') return null;
			$pos++;
			return match ($tok[1]) {
				'calc'  => count($args) === 1 ? $args[0] : null,
				'min'   => fn($vw) => min(array_map(fn($a) => $a($vw), $args)),
				'max'   => fn($vw) => max(array_map(fn($a) => $a($vw), $args)),
				'clamp' => count($args) === 3 ? fn($vw) => max($args[0]($vw), min($args[1]($vw), $args[2]($vw))) : null,
			};
		}
		return null;
	}

	private static function px(float $value, string $unit): float {
		return $unit === 'px' ? $value : $value * self::FONT;
	}

	/** Split on $sep outside parentheses. */
	private static function split(string $s, string $sep): array {
		$parts = [];
		$depth = 0;
		$start = 0;
		for ($i = 0, $n = strlen($s); $i < $n; $i++) {
			if ($s[$i] === '(') $depth++;
			elseif ($s[$i] === ')') $depth--;
			elseif ($s[$i] === $sep && $depth === 0) {
				$parts[] = substr($s, $start, $i - $start);
				$start = $i + 1;
			}
		}
		$parts[] = substr($s, $start);
		return $parts;
	}
}
