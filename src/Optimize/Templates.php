<?php

namespace TimberAVIF\Optimize;

use TimberAVIF\Need;
use TimberAVIF\Renderer;

/**
 * Where the theme's Twig shows images, and how wide: every call to a macro named `image`,
 * to image_sources() and to |best_src, with the field its image comes from and the pixels
 * its `sizes` and `max` need.
 *
 * Read without rendering, by following each template the way Twig would run it — `set`,
 * `for`, `include … with`, macros and their arguments — with values tracked abstractly: a
 * string, a field path (`post` → `blocks.*.cover`), a hash, or unknown. So a card macro
 * that receives `sizes` from its caller, or a block template that reaches its image through
 * `{% set cover = get_image(item.cover) %}` inside `{% for item in content.blocks %}`, is
 * traced to the ACF field it shows. A value that depends on a condition keeps every branch:
 * `count >= 4 ? '310px' : '630px'` needs what the wider one does.
 *
 * Scopes of a path: `post` (a field of the post being rendered, or of posts in a list),
 * `term`, `option` (ACF options, through the context variable the theme fills with
 * get_fields('options')), `layout:NAME` (a row of an ACF flexible content layout, from
 * `{% include "block-" ~ row.acf_fc_layout ~ ".twig" %}`), `id:N` (a literal attachment
 * ID), and `any` (a value whose origin is not known, like the posts of a query).
 */
final class Templates {
	const MAX_ALTERNATIVES = 24;
	const MAX_DEPTH = 14;

	// Properties that give another form of the same image: still that image.
	const SAME_IMAGE = ['src', 'url', 'id', 'ID', 'alt', 'width', 'height', 'path', 'file', 'caption', 'title', 'sizes', 'srcset'];
	// Functions that take something standing for an image or a post and return it as an object.
	const WRAPPERS = ['get_image', 'Image', 'get_attachment', 'timber_image'];
	const POST_WRAPPERS = ['get_post', 'Post', 'get_posts', 'PostQuery', 'get_term', 'Term', 'get_terms'];

	/** @var array<string, array{file: string, rel: string, chunks: array}> by relative path */
	private array $files = [];
	/** @var array<string, array<string, array{params: array, defaults: array, chunks: array}>> file → macro → definition */
	private array $macros = [];
	private array $option_vars;
	private array $uses = [];
	private array $untraced = [];
	private array $visited = [];
	/** @var string[] Where the macros being run were called from, outermost first. */
	private array $callers = [];
	private int $depth = 0;
	private string $at = '';

	/**
	 * @param array<string, string[]> $locations   Timber's locations: namespace ('' for the main one) → directories.
	 * @param string[]                $option_vars Context variables holding ACF options.
	 * @return array{uses: array, untraced: array, files: int}
	 */
	public static function read(array $locations, array $option_vars = ['options']): array {
		$reader = new self($option_vars);
		foreach ($locations as $namespace => $dirs) $reader->load((string) $namespace, (array) $dirs);
		$reader->run();
		return $reader->result();
	}

	/** Templates given as `name => source`, for tests. */
	public static function read_sources(array $sources, array $option_vars = ['options']): array {
		$reader = new self($option_vars);
		foreach ($sources as $rel => $source) $reader->add($rel, $rel, $source);
		$reader->run();
		return $reader->result();
	}

	private function __construct(array $option_vars) {
		$this->option_vars = $option_vars;
	}

	private function result(): array {
		return ['uses' => array_values($this->uses), 'untraced' => array_values(array_unique($this->untraced)), 'files' => count($this->files)];
	}

	/* ─────────────────────────────────────────────
	 * Files
	 * ───────────────────────────────────────────── */

	private function load(string $namespace, array $roots): void {
		foreach (array_unique($roots) as $root) {
			if (!is_dir($root)) continue;
			$root = rtrim($root, '/');
			$prefix = $namespace === '' || $namespace === '__main__' ? '' : "@$namespace/";
			$it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				fn($f) => !($f->isDir() && in_array($f->getFilename(), ['vendor', 'node_modules', '.git', 'dist', 'build'], true))
			));
			foreach ($it as $file) {
				if (!$file->isFile() || strtolower($file->getExtension()) !== 'twig') continue;
				$rel = $prefix . substr($file->getPathname(), strlen($root) + 1);
				// The first location that has a name is the one Twig uses.
				if (isset($this->files[$rel])) continue;
				$this->add($rel, $file->getPathname(), (string) file_get_contents($file->getPathname()));
			}
		}
	}

	private function add(string $rel, string $path, string $source): void {
		$chunks = self::lex($source);
		$this->files[$rel] = ['file' => $path, 'rel' => $rel, 'chunks' => $chunks];
		$this->collect_macros($rel, $chunks);
	}

	/**
	 * A template name as Twig gets it — `components/block-x.twig`, `/page/header.twig`,
	 * `@blocks/hero.twig` — to the file it is, relative to a location. A name with `*` is a
	 * pattern, and can be several.
	 *
	 * @return string[]
	 */
	private function resolve(string $name): array {
		$name = ltrim($name, '/');
		if ($name === '') return [];
		if (!str_contains($name, '*')) return isset($this->files[$name]) ? [$name] : [];
		return array_values(array_filter(array_keys($this->files), fn($rel) => fnmatch($name, $rel)));
	}

	/* ─────────────────────────────────────────────
	 * Lexing: text, {{ }}, {% %}, comments dropped
	 * ───────────────────────────────────────────── */

	/** @return array<int, array{0: string, 1: string, 2: int}> [kind: 'print'|'tag', body, line] */
	private static function lex(string $src): array {
		$chunks = [];
		$len = strlen($src);
		$i = 0;
		$verbatim = false;
		while ($i < $len) {
			$open = strpos($src, '{', $i);
			if ($open === false || $open + 1 >= $len) break;
			$next = $src[$open + 1];
			if ($next !== '{' && $next !== '%' && $next !== '#') { $i = $open + 1; continue; }
			$line = substr_count($src, "\n", 0, $open) + 1;
			$close = $next === '{' ? '}}' : ($next === '%' ? '%}' : '#}');
			$end = self::find_close($src, $open + 2, $close, $next !== '#');
			if ($end === null) break;
			$body = trim(substr($src, $open + 2, $end - $open - 2), " \t\n\r-~");
			$i = $end + 2;
			if ($next === '#') continue;
			if ($next === '%') {
				$word = strtolower((string) strtok($body, " \t\n("));
				if ($verbatim) {
					if ($word === 'endverbatim' || $word === 'endraw') $verbatim = false;
					continue;
				}
				if ($word === 'verbatim' || $word === 'raw') { $verbatim = true; continue; }
				$chunks[] = ['tag', $body, $line];
			} elseif (!$verbatim) {
				$chunks[] = ['print', $body, $line];
			}
		}
		return $chunks;
	}

	private static function find_close(string $src, int $from, string $close, bool $strings): ?int {
		$len = strlen($src);
		for ($i = $from; $i < $len - 1; $i++) {
			$c = $src[$i];
			if ($strings && ($c === '"' || $c === "'")) {
				$j = $i + 1;
				while ($j < $len && $src[$j] !== $c) $j += $src[$j] === '\\' ? 2 : 1;
				$i = $j;
				continue;
			}
			if ($c === $close[0] && $src[$i + 1] === $close[1]) return $i;
		}
		return null;
	}

	/* ─────────────────────────────────────────────
	 * Structure
	 * ───────────────────────────────────────────── */

	private function collect_macros(string $rel, array $chunks): void {
		$current = null;
		$depth = 0;
		foreach ($chunks as $chunk) {
			[$kind, $body] = $chunk;
			if ($kind === 'tag' && preg_match('/^macro\s+(\w+)\s*\((.*)\)\s*$/s', $body, $m)) {
				if ($current === null) {
					[$params, $defaults] = $this->signature($m[2]);
					$current = $m[1];
					$this->macros[$rel][$current] = ['params' => $params, 'defaults' => $defaults, 'chunks' => []];
					continue;
				}
			}
			if ($current === null) continue;
			if ($kind === 'tag' && preg_match('/^endmacro\b/', $body)) {
				$current = null;
				continue;
			}
			$this->macros[$rel][$current]['chunks'][] = $chunk;
		}
	}

	/** `opts, sizes = '100vw'` → [['opts', 'sizes'], ['sizes' => ast]] */
	private function signature(string $params): array {
		$names = [];
		$defaults = [];
		foreach (self::split_top($params, ',') as $param) {
			$param = trim($param);
			if ($param === '') continue;
			if (preg_match('/^(\w+)\s*=\s*(.+)$/s', $param, $m)) {
				$names[] = $m[1];
				$defaults[$m[1]] = Expr::parse($m[2]);
			} else {
				$names[] = preg_replace('/\W.*$/s', '', $param);
			}
		}
		return [$names, $defaults];
	}

	/** Templates included, embedded or imported by others are run from there, not on their own. */
	private function entries(): array {
		$reached = [];
		foreach ($this->files as $rel => $file) {
			foreach ($file['chunks'] as [$kind, $body]) {
				if ($kind !== 'tag' || !preg_match('/^(include|embed|import|from)\s+(.+)$/s', $body, $m)) continue;
				foreach ($this->static_names($m[2]) as $name) foreach ($this->resolve($name) as $target) $reached[$target] = true;
			}
			foreach ($file['chunks'] as [$kind, $body]) {
				if ($kind === 'print' && preg_match_all('/\binclude\s*\(\s*([\'"][^\'"]+[\'"])/', $body, $mm)) {
					foreach ($mm[1] as $q) foreach ($this->resolve(trim($q, '\'"')) as $target) $reached[$target] = true;
				}
			}
		}
		return array_values(array_diff(array_keys($this->files), array_keys($reached)));
	}

	/** Names a tag refers to, with `*` for the parts only known at render. */
	private function static_names(string $rest): array {
		$rest = preg_replace('/\s+(with|only|ignore\s+missing|as|import)\b.*$/s', '', $rest);
		$ast = Expr::parse($rest);
		$names = [];
		foreach (self::name_patterns($ast) as [$pattern]) $names[] = $pattern;
		return $names;
	}

	/**
	 * A template-name expression as patterns: 'block-' ~ row.acf_fc_layout ~ '.twig' is
	 * ['block-*.twig', 'row'], the variable whose layout decides the file.
	 *
	 * @return array<int, array{0: string, 1: ?string}>
	 */
	private static function name_patterns(?array $ast): array {
		if (!$ast) return [];
		if ($ast[0] === 'array') {
			$out = [];
			foreach ($ast[1] as $item) $out = array_merge($out, self::name_patterns($item));
			return $out;
		}
		if ($ast[0] === 'cond') return array_merge(self::name_patterns($ast[2]), self::name_patterns($ast[3]));
		$parts = [];
		self::concat_parts($ast, $parts);
		$pattern = '';
		$layout = null;
		foreach ($parts as $part) {
			if ($part[0] === 'str') {
				$pattern .= $part[1];
				continue;
			}
			$pattern .= '*';
			if ($part[0] === 'attr' && $part[2] === 'acf_fc_layout' && $part[1][0] === 'name') $layout = $part[1][1];
		}
		return $pattern === '*' ? [] : [[$pattern, $layout]];
	}

	private static function concat_parts(array $ast, array &$parts): void {
		if ($ast[0] === 'bin' && $ast[1] === '~') {
			self::concat_parts($ast[2], $parts);
			self::concat_parts($ast[3], $parts);
			return;
		}
		$parts[] = $ast;
	}

	/* ─────────────────────────────────────────────
	 * Running
	 * ───────────────────────────────────────────── */

	private function run(): void {
		foreach ($this->entries() as $rel) {
			$this->run_chunks($rel, $this->files[$rel]['chunks'], new Env());
		}
	}

	private function run_chunks(string $rel, array $chunks, Env $env): void {
		if ($this->depth > self::MAX_DEPTH) return;
		$this->depth++;
		$macro_depth = 0;
		$loops = [];
		foreach ($chunks as [$kind, $body, $line]) {
			$this->at = "$rel:$line";
			// Macro bodies run when called, with their arguments.
			if ($kind === 'tag' && preg_match('/^macro\b/', $body)) { $macro_depth++; continue; }
			if ($kind === 'tag' && preg_match('/^endmacro\b/', $body)) { $macro_depth--; continue; }
			if ($macro_depth > 0) continue;
			if ($kind === 'print') $this->eval_text($body, $env, $rel);
			else $this->run_tag($rel, $body, $env, $loops);
		}
		$this->depth--;
	}

	private function run_tag(string $rel, string $body, Env $env, array &$loops): void {
		$word = strtolower((string) strtok($body, " \t\n("));
		$rest = trim(substr($body, strlen($word)));

		switch ($word) {
			case 'set':
				// {% set a, b = x, y %} is rare; {% set x %}…{% endset %} captures markup: unknown.
				if (preg_match('/^(\w+)\s*=\s*(.+)$/s', $rest, $m)) {
					$env->add($m[1], $this->eval_text($m[2], $env, $rel));
				} elseif (preg_match('/^(\w+)\s*$/', $rest, $m)) {
					$env->add($m[1], Value::unknown());
				}
				return;

			case 'for':
				if (preg_match('/^(?:(\w+)\s*,\s*)?(\w+)\s+in\s+(.+?)(?:\s+if\s+.+)?$/s', $rest, $m)) {
					$items = $this->eval_text($m[3], $env, $rel);
					$env->push();
					$env->enter();
					$env->set($m[2], $items->element());
					if ($m[1] !== '') $env->set($m[1], Value::unknown());
					$loops[] = true;
				}
				return;

			case 'endfor':
				if ($loops) { array_pop($loops); $env->pop(); $env->leave(); }
				return;

			case 'endif':
				$env->leave();
				return;

			case 'with':
				// {% with {a: b} %}: the names are added, as a set would.
				if ($rest !== '' && !str_starts_with($rest, 'only')) {
					$hash = $this->eval_text(preg_replace('/\s+only\s*$/', '', $rest), $env, $rel);
					foreach ($hash->keys() as $key) $env->add($key, $hash->get($key));
				}
				return;

			case 'if':
				$env->enter();
				$this->eval_text($rest, $env, $rel);
				return;

			case 'elseif':
			case 'do':
				$this->eval_text($rest, $env, $rel);
				return;

			case 'import':
				if (preg_match('/^(.+?)\s+as\s+(\w+)\s*$/s', $rest, $m)) $env->add($m[2], $this->namespace_of($m[1], $rel));
				return;

			case 'from':
				if (preg_match('/^(.+?)\s+import\s+(.+)$/s', $rest, $m)) {
					$ns = $this->namespace_of($m[1], $rel);
					foreach (self::split_top($m[2], ',') as $import) {
						if (!preg_match('/^\s*(\w+)(?:\s+as\s+(\w+))?\s*$/', $import, $im)) continue;
						$env->add($im[2] ?? $im[1], Value::of(['macro', $ns, $im[1]]));
					}
				}
				return;

			case 'include':
			case 'embed':
				$this->include($rel, $rest, $env);
				return;
		}
	}

	/** What `{% import X as m %}` makes `m`: the files it can be, or the calling file for _self. */
	private function namespace_of(string $expr, string $rel): Value {
		$expr = trim($expr);
		if ($expr === '_self') return Value::of(['ns', [$rel]]);
		$files = [];
		foreach ($this->static_names($expr) as $name) $files = array_merge($files, $this->resolve($name));
		// A namespace outside the theme (@timber-avif) still holds the image macro.
		return Value::of(['ns', $files]);
	}

	private function include(string $rel, string $rest, Env $env): void {
		$only = (bool) preg_match('/\sonly\s*$/', $rest);
		$rest = preg_replace('/\s+only\s*$/', '', $rest);
		$with = null;
		if (preg_match('/^(.+?)\s+with\s+(.+)$/s', $rest, $m)) {
			$rest = $m[1];
			$with = $this->eval_text($m[2], $env, $rel);
		}
		$rest = preg_replace('/\s+ignore\s+missing\s*$/', '', $rest);

		foreach (self::name_patterns(Expr::parse($rest)) as [$pattern, $layout_var]) {
			foreach ($this->resolve($pattern) as $target) {
				$inner = $only ? new Env() : $env->copy();
				if ($with) foreach ($with->keys() as $key) $inner->set($key, $with->get($key));
				// The file picked by a row's layout gets that row, as a row of that layout.
				if ($layout_var !== null && str_contains($pattern, '*')) {
					$layout = $this->wildcard($pattern, $target);
					if ($layout !== null) $inner->set($layout_var, Value::of(['path', "layout:$layout", []]));
				}
				$this->run_file($target, $inner);
			}
		}
	}

	/** What the `*` of a pattern stands for in a file that matched it. */
	private function wildcard(string $pattern, string $rel): ?string {
		$regex = '#(?:^|/)' . str_replace('\*', '([^/]+)', preg_quote(ltrim($pattern, '/'), '#')) . '$#';
		return preg_match($regex, $rel, $m) ? $m[1] : null;
	}

	private function run_file(string $rel, Env $env): void {
		$key = $rel . '|' . $env->hash();
		if (isset($this->visited[$key]) || !isset($this->files[$rel])) return;
		$this->visited[$key] = true;
		$at = $this->at;
		$this->run_chunks($rel, $this->files[$rel]['chunks'], $env);
		$this->at = $at;
	}

	/* ─────────────────────────────────────────────
	 * Expressions
	 * ───────────────────────────────────────────── */

	private function eval_text(string $text, Env $env, string $rel): Value {
		$ast = Expr::parse($text);
		return $ast ? $this->ev($ast, $env, $rel) : Value::unknown();
	}

	private function ev(array $n, Env $env, string $rel): Value {
		switch ($n[0]) {
			case 'str':  return Value::of(['str', $n[1]]);
			case 'num':  return Value::of(['num', $n[1]]);
			case 'const': return Value::of(['const']);

			case 'name':
				$bound = $env->get($n[1]);
				if ($bound !== null) return $bound;
				if ($n[1] === 'post') return Value::of(['path', 'post', []]);
				if ($n[1] === 'term' || $n[1] === 'category') return Value::of(['path', 'term', []]);
				if (in_array($n[1], $this->option_vars, true)) return Value::of(['path', 'option', []]);
				if ($n[1] === '_self') return Value::of(['ns', [$rel]]);
				// Set by PHP, a plugin, or nobody: not known, and a call given it is listed as untraced.
				return Value::of(['path', 'any', []]);

			case 'array':
				$items = Value::none();
				foreach ($n[1] as $item) $items = $items->union($this->ev($item, $env, $rel));
				return Value::of(['list', $items]);

			case 'hash':
				$hash = [];
				foreach ($n[1] as [$key, $value]) {
					$k = $key[0] === 'name' || $key[0] === 'str' ? (string) $key[1] : null;
					$v = $this->ev($value, $env, $rel);
					if ($k !== null) $hash[$k] = $v;
				}
				return Value::of(['hash', $hash]);

			case 'attr':
				return $this->ev($n[1], $env, $rel)->attr($n[2]);

			case 'index':
				$base = $this->ev($n[1], $env, $rel);
				$key = $this->ev($n[2], $env, $rel);
				$str = $key->single_string();
				return $str !== null && !ctype_digit($str) ? $base->attr($str) : $base->element();

			case 'call':
				return $this->call($n, $env, $rel);

			case 'filter':
				return $this->filter($n, $env, $rel);

			case 'cond':
				$this->ev($n[1], $env, $rel);
				return $this->ev($n[2], $env, $rel)->union($this->ev($n[3], $env, $rel));

			case 'bin':
				$l = $this->ev($n[2], $env, $rel);
				$r = $this->ev($n[3], $env, $rel);
				if ($n[1] === '~') return $l->concat($r);
				if ($n[1] === '??' || $n[1] === '?:') return $l->union($r);
				return Value::unknown();

			case 'unary':
				$this->ev($n[2], $env, $rel);
				return Value::unknown();
		}
		return Value::unknown();
	}

	/** Arguments, positional and named: [[0 => Value, …], ['name' => Value]]. */
	private function args(array $args, Env $env, string $rel): array {
		$pos = [];
		$named = [];
		foreach ($args as $arg) {
			if ($arg[0] === 'named') $named[$arg[1]] = $this->ev($arg[2], $env, $rel);
			else $pos[] = $this->ev($arg, $env, $rel);
		}
		return [$pos, $named];
	}

	private function call(array $n, Env $env, string $rel): Value {
		$callee = $n[1];
		[$pos, $named] = $this->args($n[2], $env, $rel);

		// A method: post.meta('x'), m.card(…), Timber.get_image(…)
		if ($callee[0] === 'attr') {
			$object = $this->ev($callee[1], $env, $rel);
			$method = $callee[2];
			if ($object->is_namespace()) {
				$this->macro_call($object->namespaces(), $method, $pos, $named);
				return Value::unknown();
			}
			if (in_array($method, ['meta', 'get_field', 'field', 'raw_meta'], true)) {
				$field = ($pos[0] ?? Value::unknown())->single_string();
				return $field !== null ? $object->attr($field) : Value::unknown();
			}
			if (in_array($method, self::WRAPPERS, true)) return ($pos[0] ?? Value::unknown())->as_image();
			if (in_array($method, self::POST_WRAPPERS, true)) return Value::of(['path', 'any', []]);
			return $object->attr($method);
		}

		if ($callee[0] !== 'name') return Value::unknown();
		$fn = $callee[1];

		$bound = $env->get($fn);
		if ($bound !== null && $bound->is_macro()) {
			foreach ($bound->macros() as [$files, $macro]) $this->macro_call($files, $macro, $pos, $named);
			return Value::unknown();
		}

		if ($fn === 'image_sources') {
			$this->use_image($pos[0] ?? Value::unknown(), $pos[1] ?? Value::of(['hash', []]), null, false);
			return Value::unknown();
		}
		if (in_array($fn, self::WRAPPERS, true)) return ($pos[0] ?? Value::unknown())->as_image();
		if (in_array($fn, self::POST_WRAPPERS, true)) return Value::of(['path', 'any', []]);
		// get_field('logo', 'option'), fn('get_field', 'logo', 'option')
		if ($fn === 'fn' || $fn === 'function') {
			$real = ($pos[0] ?? Value::unknown())->single_string();
			array_shift($pos);
			$fn = (string) $real;
		}
		if ($fn === 'get_field' || $fn === 'get_sub_field') {
			$field = ($pos[0] ?? Value::unknown())->single_string();
			if ($field === null) return Value::unknown();
			$where = ($pos[1] ?? null)?->single_string();
			$scope = in_array($where, ['option', 'options'], true) ? 'option' : 'post';
			return Value::of(['path', $scope, [$field]]);
		}
		if ($fn === 'attribute') {
			$key = ($pos[1] ?? Value::unknown())->single_string();
			return $key !== null ? ($pos[0] ?? Value::unknown())->attr($key) : Value::unknown();
		}
		if ($fn === 'include') {
			$name = $n[2][0] ?? null;
			$with = $pos[1] ?? null;
			if ($name) {
				foreach (self::name_patterns($name) as [$pattern]) {
					foreach ($this->resolve($pattern) as $target) {
						$inner = $env->copy();
						if ($with) foreach ($with->keys() as $key) $inner->set($key, $with->get($key));
						$this->run_file($target, $inner);
					}
				}
			}
			return Value::unknown();
		}
		return Value::unknown();
	}

	/**
	 * A macro call: run in the theme's file that defines it, with its arguments — a theme's
	 * own `image` wrapper too, which may change the options on the way. A macro named `image`
	 * that no theme file defines is the package's (@timber-avif/macros.twig): the call itself
	 * shows the image.
	 */
	private function macro_call(array $files, string $macro, array $pos, array $named): void {
		$defined = false;
		foreach ($files as $file) {
			if (!isset($this->macros[$file][$macro])) continue;
			$defined = true;
			$this->call_macro($file, $macro, $pos, $named);
		}
		if (!$defined && $macro === 'image') {
			$this->use_image($pos[0] ?? $named['image'] ?? Value::unknown(), $pos[1] ?? $named['options'] ?? Value::of(['hash', []]), null);
		}
	}

	private function call_macro(string $file, string $macro, array $pos, array $named): void {
		$def = $this->macros[$file][$macro] ?? null;
		if (!$def) return;
		$env = new Env();
		foreach ($def['params'] as $i => $param) {
			$value = $pos[$i] ?? $named[$param] ?? null;
			if ($value === null && isset($def['defaults'][$param])) $value = $this->ev($def['defaults'][$param], $env, $file);
			$env->set($param, $value ?? Value::unknown());
		}
		$key = "$file#$macro|" . $env->hash();
		if (isset($this->visited[$key])) return;
		$this->visited[$key] = true;
		$at = $this->at;
		// Uses inside a macro are reported where the template called it.
		$this->callers[] = $at;
		$this->run_chunks($file, $def['chunks'], $env);
		array_pop($this->callers);
		$this->at = $at;
	}

	private function filter(array $n, Env $env, string $rel): Value {
		$value = $this->ev($n[1], $env, $rel);
		$name = $n[2];
		[$pos] = $this->args($n[3], $env, $rel);

		switch ($name) {
			case 'first':
			case 'last':
				return $value->element();
			case 'default':
				// Null takes the default: what else the value can be stays.
				return $value->without_constants()->union($pos[0] ?? Value::unknown());
			case 'merge':
				return $value->merge($pos[0] ?? Value::unknown());
			case 'slice': case 'sort': case 'reverse': case 'filter': case 'shuffle': case 'batch': case 'column':
				return $value;
			case 'best_src':
				$w = ($pos[0] ?? null)?->single_number();
				$h = ($pos[1] ?? null)?->single_number();
				$ratio = ($w && $h) ? Renderer::parse_ratio("$w/$h") : null;
				$this->use_image($value, null, ['pixels' => $w ? (int) $w : null, 'ratio' => $ratio ? $ratio['key'] : '']);
				return Value::unknown();
			case 'raw': case 'e': case 'escape': case 'trim':
				return $value;
		}
		return Value::unknown();
	}

	/* ─────────────────────────────────────────────
	 * Uses
	 * ───────────────────────────────────────────── */

	/**
	 * One call that shows an image: every place its image can come from, with the pixels
	 * the widest of its option alternatives needs. Unknown `sizes` means all of them.
	 */
	private function use_image(Value $image, ?Value $options, ?array $fixed, bool $macro = true): void {
		if ($fixed !== null) {
			$needs = [[$fixed['pixels'], $fixed['ratio']]];
		} else {
			$needs = [];
			foreach ($options->alternatives() as $alt) {
				if ($alt[0] !== 'hash') {
					$needs[] = [null, '?'];
					continue;
				}
				// The macro's default is 100vw; image_sources() alone does not say.
				$sizes = $alt[1]['sizes'] ?? ($macro ? Value::of(['str', '100vw']) : Value::unknown());
				$max = isset($alt[1]['max']) ? $alt[1]['max'] : null;
				$ratios = isset($alt[1]['ratio']) ? $alt[1]['ratio'] : Value::of(['const']);
				$pixels = $this->pixels($sizes, $max);
				foreach ($ratios->alternatives() as $r) {
					if ($r[0] === 'const') $needs[] = [$pixels, ''];
					elseif ($r[0] === 'str' || $r[0] === 'num') $needs[] = [$pixels, Renderer::parse_ratio($r[1])['key'] ?? '?'];
					else $needs[] = [$pixels, '?'];
				}
			}
		}

		// A constant is null or a missing key: the call shows nothing. What is not known at all,
		// or is the root of a scope rather than a field, is a call this reading cannot follow.
		$places = [];
		$lost = false;
		foreach ($image->alternatives() as $alt) {
			if ($alt[0] === 'path' && ($alt[2] || str_starts_with($alt[1], 'id:'))) $places[] = [$alt[1], implode('.', $alt[2])];
			elseif ($alt[0] !== 'const' && $alt[0] !== 'str' && $alt[0] !== 'num') $lost = true;
		}
		$at = $this->callers[0] ?? $this->at;
		if ($lost) $this->untraced[] = $at;
		if (!$places) return;

		foreach ($places as [$scope, $path]) {
			foreach ($needs as [$pixels, $ratio]) {
				$key = "$scope|$path|$ratio";
				$prev = $this->uses[$key] ?? null;
				$merged = ($prev === null) ? $pixels : (($prev['pixels'] === null || $pixels === null) ? null : max($prev['pixels'], $pixels));
				$this->uses[$key] = ['scope' => $scope, 'path' => $path, 'ratio' => $ratio, 'pixels' => $merged, 'at' => array_values(array_unique(array_merge($prev['at'] ?? [], [$at])))];
			}
		}
	}

	/** The widest need among the alternatives of `sizes` × `max`; null when any is unknown. */
	private function pixels(Value $sizes, ?Value $max): ?int {
		$widest = 0;
		$maxes = $max ? $max->alternatives() : [['const']];
		foreach ($sizes->alternatives() as $s) {
			if ($s[0] !== 'str') return null;
			foreach ($maxes as $m) {
				// A max that is not a number, or not known, limits nothing.
				$p = Need::pixels($s[1], $m[0] === 'num' ? (int) $m[1] : null);
				if ($p === null) return null;
				$widest = max($widest, $p);
			}
		}
		return $widest ?: null;
	}

	/** Split on $sep outside brackets and strings. */
	public static function split_top(string $s, string $sep): array {
		$parts = [];
		$depth = 0;
		$start = 0;
		$quote = null;
		for ($i = 0, $n = strlen($s); $i < $n; $i++) {
			$c = $s[$i];
			if ($quote) {
				if ($c === '\\') $i++;
				elseif ($c === $quote) $quote = null;
				continue;
			}
			if ($c === '"' || $c === "'") $quote = $c;
			elseif (str_contains('([{', $c)) $depth++;
			elseif (str_contains(')]}', $c)) $depth--;
			elseif ($c === $sep && $depth === 0) {
				$parts[] = substr($s, $start, $i - $start);
				$start = $i + 1;
			}
		}
		$parts[] = substr($s, $start);
		return $parts;
	}
}
