<?php

namespace TimberAVIF\Optimize;

/**
 * Twig expressions, parsed into nested arrays for Templates: a Pratt parser with Twig's
 * operator precedences, for what templates write — literals, names, `.attr`, `[key]`,
 * calls, filters, `~`, `? :`, `?:`, `??`, arrays and hashes. What it cannot read comes out
 * as null, and Templates takes that as unknown.
 *
 * Nodes: ['str', s] ['num', n] ['const'] ['name', n] ['array', [..]] ['hash', [[k, v], ..]]
 * ['attr', obj, name] ['index', obj, key] ['call', callee, args] ['filter', value, name, args]
 * ['cond', test, then, else] ['bin', op, left, right] ['unary', op, value] ['named', name, value]
 */
final class Expr {
	private const BINARY = [
		'or' => 10, 'and' => 15, 'b-or' => 16, 'b-xor' => 17, 'b-and' => 18,
		'==' => 20, '!=' => 20, '<=>' => 20, '<' => 20, '>' => 20, '>=' => 20, '<=' => 20,
		'in' => 20, 'not in' => 20, 'matches' => 20, 'starts with' => 20, 'ends with' => 20, 'has some' => 20, 'has every' => 20,
		'..' => 25, '+' => 30, '-' => 30, '~' => 40, '*' => 60, '/' => 60, '//' => 60, '%' => 60,
		'is' => 100, 'is not' => 100, '**' => 200, '??' => 300,
	];

	private array $t;
	private int $p = 0;

	public static function parse(string $source): ?array {
		$tokens = self::tokens($source);
		if (!$tokens) return null;
		$parser = new self($tokens);
		try {
			$ast = $parser->expression();
			return $parser->p === count($tokens) ? $ast : $ast;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function __construct(array $tokens) {
		$this->t = $tokens;
	}

	/* ── Tokens: [type, value] with type str, num, name, op, punct ── */

	private static function tokens(string $s): array {
		$tokens = [];
		$i = 0;
		$n = strlen($s);
		$ops = ['<=>', '...', '//', '**', '==', '!=', '<=', '>=', '??', '?:', '=>', '..', '<', '>', '+', '-', '*', '/', '%', '~', '=', '|', '?', ':', ',', '.', '(', ')', '[', ']', '{', '}'];
		while ($i < $n) {
			$c = $s[$i];
			if (ctype_space($c)) { $i++; continue; }
			if ($c === '"' || $c === "'") {
				$j = $i + 1;
				$str = '';
				while ($j < $n && $s[$j] !== $c) {
					if ($s[$j] === '\\' && $j + 1 < $n) { $str .= $s[$j + 1]; $j += 2; continue; }
					$str .= $s[$j++];
				}
				// "#{…}" interpolation is only known at render.
				$tokens[] = ($c === '"' && str_contains($str, '#{')) ? ['name', '__interpolated'] : ['str', $str];
				$i = $j + 1;
				continue;
			}
			if (ctype_digit($c)) {
				preg_match('/\G\d+(\.\d+)?/', $s, $m, 0, $i);
				$tokens[] = ['num', (float) $m[0]];
				$i += strlen($m[0]);
				continue;
			}
			if (ctype_alpha($c) || $c === '_') {
				preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $s, $m, 0, $i);
				$tokens[] = ['name', $m[0]];
				$i += strlen($m[0]);
				continue;
			}
			$matched = false;
			foreach ($ops as $op) {
				if (substr($s, $i, strlen($op)) === $op) {
					$tokens[] = ['op', $op];
					$i += strlen($op);
					$matched = true;
					break;
				}
			}
			if (!$matched) $i++;
		}
		return $tokens;
	}

	private function peek(int $ahead = 0): ?array {
		return $this->t[$this->p + $ahead] ?? null;
	}

	private function is_op(string $op, int $ahead = 0): bool {
		$t = $this->peek($ahead);
		return $t && $t[0] === 'op' && $t[1] === $op;
	}

	private function is_word(string $word, int $ahead = 0): bool {
		$t = $this->peek($ahead);
		return $t && $t[0] === 'name' && $t[1] === $word;
	}

	private function expect(string $op): void {
		if (!$this->is_op($op)) throw new \RuntimeException("expected $op");
		$this->p++;
	}

	/* ── Grammar ── */

	private function expression(int $min = 0): array {
		$left = $this->unary();
		while (true) {
			[$op, $width] = $this->binary_op();
			if ($op === null || self::BINARY[$op] < $min) break;
			$this->p += $width;
			if ($op === 'is' || $op === 'is not') {
				// A test: `x is defined`, `x is divisible by(3)`. Its result is a boolean.
				$this->test();
				$left = ['unary', 'test', $left];
				continue;
			}
			$right = $this->expression(self::BINARY[$op] + ($op === '**' ? 0 : 1));
			$left = ['bin', $op, $left, $right];
		}

		if ($min === 0) {
			if ($this->is_op('?:')) {
				$this->p++;
				return ['bin', '?:', $left, $this->expression()];
			}
			if ($this->is_op('?')) {
				$this->p++;
				if ($this->is_op(':')) {
					$this->p++;
					return ['bin', '?:', $left, $this->expression()];
				}
				$then = $this->expression();
				if ($this->is_op(':')) {
					$this->p++;
					$else = $this->expression();
				} else {
					$else = ['const'];
				}
				return ['cond', $left, $then, $else];
			}
		}
		return $left;
	}

	/** @return array{0: ?string, 1: int} */
	private function binary_op(): array {
		$t = $this->peek();
		if (!$t) return [null, 0];
		if ($t[0] === 'op' && isset(self::BINARY[$t[1]])) return [$t[1], 1];
		if ($t[0] !== 'name') return [null, 0];
		$word = $t[1];
		$next = $this->peek(1);
		$two = $next && $next[0] === 'name' ? "$word {$next[1]}" : null;
		if ($two && isset(self::BINARY[$two])) return [$two, 2];
		if (isset(self::BINARY[$word])) return [$word, 1];
		return [null, 0];
	}

	private function test(): void {
		$this->p++;
		// Two-word tests: `same as`, `divisible by`.
		if ($this->is_word('as') || $this->is_word('by')) $this->p++;
		if ($this->is_op('(')) $this->arguments();
	}

	private function unary(): array {
		if ($this->is_word('not')) {
			$this->p++;
			return ['unary', 'not', $this->expression(50)];
		}
		if ($this->is_op('-') || $this->is_op('+')) {
			$op = $this->t[$this->p++][1];
			return ['unary', $op, $this->expression(500)];
		}
		return $this->postfix($this->primary());
	}

	private function primary(): array {
		$t = $this->peek();
		if (!$t) throw new \RuntimeException('end');
		$this->p++;

		if ($t[0] === 'str') return ['str', $t[1]];
		if ($t[0] === 'num') return ['num', $t[1]];
		if ($t[0] === 'name') {
			if (in_array(strtolower($t[1]), ['true', 'false', 'null', 'none'], true)) return ['const'];
			// An arrow function, `p => p.x`: its value is not followed.
			if ($this->is_op('=>')) {
				$this->p++;
				$this->expression();
				return ['const'];
			}
			return ['name', $t[1]];
		}
		if ($t[0] === 'op' && $t[1] === '(') {
			// `(a, b) => …` is an arrow function too.
			$inner = $this->expression();
			if ($this->is_op(',')) {
				while (!$this->is_op(')') && $this->peek()) $this->p++;
			}
			$this->expect(')');
			if ($this->is_op('=>')) {
				$this->p++;
				$this->expression();
				return ['const'];
			}
			return $inner;
		}
		if ($t[0] === 'op' && $t[1] === '[') {
			$items = [];
			while (!$this->is_op(']')) {
				if ($this->is_op('...')) $this->p++;
				$items[] = $this->expression();
				if ($this->is_op(',')) $this->p++;
				elseif (!$this->is_op(']')) throw new \RuntimeException('array');
			}
			$this->p++;
			return ['array', $items];
		}
		if ($t[0] === 'op' && $t[1] === '{') {
			$pairs = [];
			while (!$this->is_op('}')) {
				if ($this->is_op('...')) {
					$this->p++;
					$this->expression();
				} else {
					$key = $this->peek();
					if ($key && ($key[0] === 'name' || $key[0] === 'str' || $key[0] === 'num')) {
						$this->p++;
						$key_ast = $key[0] === 'num' ? ['str', (string) (int) $key[1]] : [$key[0] === 'name' ? 'name' : 'str', $key[1]];
					} elseif ($this->is_op('(')) {
						$this->p++;
						$key_ast = $this->expression();
						$this->expect(')');
					} else {
						throw new \RuntimeException('hash key');
					}
					if ($this->is_op(':')) {
						$this->p++;
						$pairs[] = [$key_ast, $this->expression()];
					} else {
						// {name} is short for {name: name}.
						$pairs[] = [$key_ast, ['name', (string) $key_ast[1]]];
					}
				}
				if ($this->is_op(',')) $this->p++;
				elseif (!$this->is_op('}')) throw new \RuntimeException('hash');
			}
			$this->p++;
			return ['hash', $pairs];
		}
		throw new \RuntimeException('primary');
	}

	private function postfix(array $node): array {
		while (true) {
			if ($this->is_op('.')) {
				$this->p++;
				$name = $this->peek();
				if (!$name || ($name[0] !== 'name' && $name[0] !== 'num')) throw new \RuntimeException('attr');
				$this->p++;
				$node = ['attr', $node, $name[0] === 'num' ? (string) (int) $name[1] : $name[1]];
				continue;
			}
			if ($this->is_op('[')) {
				$this->p++;
				// A slice, [1:2], keeps the list.
				if ($this->is_op(':')) { $this->p++; if (!$this->is_op(']')) $this->expression(); $this->expect(']'); continue; }
				$key = $this->expression();
				if ($this->is_op(':')) { $this->p++; if (!$this->is_op(']')) $this->expression(); $this->expect(']'); continue; }
				$this->expect(']');
				$node = ['index', $node, $key];
				continue;
			}
			if ($this->is_op('(')) {
				$node = ['call', $node, $this->arguments()];
				continue;
			}
			if ($this->is_op('|')) {
				$this->p++;
				$name = $this->peek();
				if (!$name || $name[0] !== 'name') throw new \RuntimeException('filter');
				$this->p++;
				$args = $this->is_op('(') ? $this->arguments() : [];
				$node = ['filter', $node, $name[1], $args];
				continue;
			}
			return $node;
		}
	}

	private function arguments(): array {
		$this->expect('(');
		$args = [];
		while (!$this->is_op(')')) {
			if ($this->is_op('...')) $this->p++;
			$t = $this->peek();
			if ($t && $t[0] === 'name' && ($this->is_op('=', 1) || $this->is_op(':', 1))) {
				$this->p += 2;
				$args[] = ['named', $t[1], $this->expression()];
			} else {
				$args[] = $this->expression();
			}
			if ($this->is_op(',')) $this->p++;
			elseif (!$this->is_op(')')) throw new \RuntimeException('arguments');
		}
		$this->p++;
		return $args;
	}
}
