<?php

namespace TimberAVIF\Optimize;

/**
 * What a Twig expression can be, for Templates: the set of its alternatives, each one of
 *
 *     ['str', s]  ['num', n]  ['const']        a scalar; const is one not known
 *     ['path', scope, segments]                where the value comes from: 'post' → ['blocks', '*', 'cover']
 *     ['hash', [key => Value]]  ['list', Value]
 *     ['ns', files]  ['macro', Value, name]    an imported macro file, a macro imported by name
 *     ['unknown']
 *
 * A branch adds alternatives instead of choosing one, so every file a template can show is
 * kept. Past MAX alternatives the value is unknown.
 */
final class Value {
	const MAX = 24;

	/** @var array<int, array> */
	private array $alts;

	private function __construct(array $alts) {
		$this->alts = $alts;
	}

	public static function of(array $alt): self {
		return new self([$alt]);
	}

	public static function unknown(): self {
		return new self([['unknown']]);
	}

	public static function none(): self {
		return new self([]);
	}

	public function alternatives(): array {
		return $this->alts ?: [['unknown']];
	}

	public function union(self $other): self {
		$alts = $this->alts;
		$seen = array_map('serialize', $alts);
		foreach ($other->alts as $alt) {
			$s = serialize($alt);
			if (in_array($s, $seen, true)) continue;
			$seen[] = $s;
			$alts[] = $alt;
		}
		return count($alts) > self::MAX ? self::unknown() : new self($alts);
	}

	private static function join(array $values): self {
		$out = self::none();
		foreach ($values as $v) $out = $out->union($v);
		return $out->alts ? $out : self::unknown();
	}

	/** `value.name`. Another form of the same image (`.src`, `.id`) is still that image. */
	public function attr(string $name): self {
		$out = [];
		foreach ($this->alternatives() as $alt) {
			switch ($alt[0]) {
				case 'path':
					if ($name === 'acf_fc_layout') { $out[] = self::of(['const']); break; }
					if (in_array($name, Templates::SAME_IMAGE, true)) { $out[] = self::of($alt); break; }
					$out[] = self::of(['path', $alt[1], array_merge($alt[2], [$name])]);
					break;
				case 'hash':
					$out[] = $alt[1][$name] ?? self::of(['const']);
					break;
				case 'list':
					$out[] = ctype_digit($name) ? $alt[1] : self::unknown();
					break;
				case 'ns':
				case 'macro':
					$out[] = self::unknown();
					break;
				case 'unknown':
					// Something whose origin is not known — what a theme function returns — is
					// still an object with fields: `event.logo` is a `logo` field of some post.
					$out[] = self::of(['path', 'any', [$name]]);
					break;
				default:
					$out[] = self::of(['const']);
			}
		}
		return self::join($out);
	}

	/** One item of a list: the loop variable of `for x in value`, value|first, value[0]. */
	public function element(): self {
		$out = [];
		foreach ($this->alternatives() as $alt) {
			switch ($alt[0]) {
				case 'path': $out[] = self::of(['path', $alt[1], array_merge($alt[2], ['*'])]); break;
				case 'list': $out[] = $alt[1]; break;
				case 'hash': $out[] = self::join(array_values($alt[1]) ?: [self::of(['const'])]); break;
				case 'unknown': $out[] = self::of(['path', 'any', ['*']]); break;
				default: $out[] = self::of(['const']);
			}
		}
		return self::join($out);
	}

	/** get_image(x): an ID in a field is that image, a literal ID is that attachment. */
	public function as_image(): self {
		$out = [];
		foreach ($this->alternatives() as $alt) {
			if ($alt[0] === 'num') $out[] = self::of(['path', 'id:' . (int) $alt[1], []]);
			elseif ($alt[0] === 'str' && ctype_digit($alt[1])) $out[] = self::of(['path', 'id:' . (int) $alt[1], []]);
			else $out[] = self::of($alt);
		}
		return self::join($out);
	}

	/** `a ~ b`: every string it can make, or an unknown string. */
	public function concat(self $other): self {
		$out = [];
		foreach ($this->alternatives() as $a) {
			foreach ($other->alternatives() as $b) {
				$sa = self::scalar($a);
				$sb = self::scalar($b);
				$out[] = ($sa === null || $sb === null) ? self::of(['const']) : self::of(['str', $sa . $sb]);
			}
		}
		return self::join($out);
	}

	/** `a|merge(b)`: hashes merged, b's keys winning. Merged with something unknown, every key may be anything. */
	public function merge(self $other): self {
		$out = [];
		foreach ($this->alternatives() as $a) {
			foreach ($other->alternatives() as $b) {
				if ($a[0] === 'hash' && $b[0] === 'hash') {
					$out[] = self::of(['hash', array_merge($a[1], $b[1])]);
				} elseif ($a[0] === 'hash') {
					$out[] = self::of(['hash', array_map(fn($v) => $v->union(self::unknown()), $a[1])]);
				} elseif ($a[0] === 'list' && $b[0] === 'list') {
					$out[] = self::of(['list', $a[1]->union($b[1])]);
				} else {
					$out[] = self::unknown();
				}
			}
		}
		return self::join($out);
	}

	/**
	 * What |default keeps of a value: not a constant (null, a missing key), and not a bare
	 * name nothing in the templates sets — `x|default(…)` is written for when x is not passed.
	 */
	public function without_constants(): self {
		return new self(array_values(array_filter($this->alts, fn($a) => $a[0] !== 'const' && $a !== ['path', 'any', []])));
	}

	/** @return string[] */
	public function keys(): array {
		$keys = [];
		foreach ($this->alternatives() as $alt) if ($alt[0] === 'hash') $keys = array_merge($keys, array_keys($alt[1]));
		return array_values(array_unique(array_map('strval', $keys)));
	}

	public function get(string $key): self {
		$out = [];
		foreach ($this->alternatives() as $alt) {
			if ($alt[0] === 'hash') $out[] = $alt[1][$key] ?? self::of(['const']);
		}
		return self::join($out);
	}

	public function single_string(): ?string {
		return count($this->alts) === 1 ? self::scalar($this->alts[0]) : null;
	}

	public function single_number(): ?float {
		if (count($this->alts) !== 1) return null;
		$a = $this->alts[0];
		if ($a[0] === 'num') return (float) $a[1];
		if ($a[0] === 'str' && is_numeric($a[1])) return (float) $a[1];
		return null;
	}

	public function is_namespace(): bool {
		foreach ($this->alts as $alt) if ($alt[0] === 'ns') return true;
		return false;
	}

	/** @return string[] */
	public function namespaces(): array {
		$files = [];
		foreach ($this->alts as $alt) if ($alt[0] === 'ns') $files = array_merge($files, $alt[1]);
		return array_values(array_unique($files));
	}

	public function is_macro(): bool {
		foreach ($this->alts as $alt) if ($alt[0] === 'macro') return true;
		return false;
	}

	/** @return array<int, array{0: string[], 1: string}> */
	public function macros(): array {
		$out = [];
		foreach ($this->alts as $alt) if ($alt[0] === 'macro') $out[] = [$alt[1]->namespaces(), $alt[2]];
		return $out;
	}

	public function signature(): string {
		return md5(serialize($this->alts));
	}

	private static function scalar(array $alt): ?string {
		if ($alt[0] === 'str') return $alt[1];
		if ($alt[0] === 'num') return (string) (floor($alt[1]) == $alt[1] ? (int) $alt[1] : $alt[1]);
		return null;
	}
}
