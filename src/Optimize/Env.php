<?php

namespace TimberAVIF\Optimize;

/**
 * The variables a template sees while Templates follows it, scoped as Twig scopes them: one
 * frame per `for` loop, whose own variables go when the loop ends, while a variable of the
 * template set inside the loop keeps its new value after it.
 *
 * A `set` replaces a value only where it surely runs — outside any `if` or loop — and never
 * one this template set before; otherwise it adds to what the name could be. So a value set
 * in one branch keeps the other, and one set under `{% if x is not defined %}` keeps the
 * value the including template passed.
 */
final class Env {
	/** @var array<int, array<string, Value>> */
	private array $frames = [[]];
	/** @var array<string, true> Names this template has set. */
	private array $local = [];
	// How many `if` and `for` blocks the template is inside.
	private int $conditional = 0;

	public function get(string $name): ?Value {
		for ($i = count($this->frames) - 1; $i >= 0; $i--) {
			if (isset($this->frames[$i][$name])) return $this->frames[$i][$name];
		}
		return null;
	}

	public function set(string $name, Value $value): void {
		$this->frames[count($this->frames) - 1][$name] = $value;
	}

	/** `{% set name = value %}`. */
	public function add(string $name, Value $value): void {
		// Written where the name lives: a loop's frame only if it was new there.
		$frame = count($this->frames) - 1;
		for ($i = $frame; $i >= 0; $i--) {
			if (isset($this->frames[$i][$name])) { $frame = $i; break; }
		}
		$current = $this->frames[$frame][$name] ?? null;
		$keep = $current && ($this->conditional > 0 || isset($this->local[$name]));
		$this->frames[$frame][$name] = $keep ? $current->union($value) : $value;
		$this->local[$name] = true;
	}

	public function enter(): void {
		$this->conditional++;
	}

	public function leave(): void {
		if ($this->conditional > 0) $this->conditional--;
	}

	public function push(): void {
		$this->frames[] = [];
	}

	public function pop(): void {
		if (count($this->frames) > 1) array_pop($this->frames);
	}

	/** Everything visible now, in one frame: what an include without `only` passes on. */
	public function copy(): self {
		$env = new self();
		foreach ($this->frames as $frame) foreach ($frame as $name => $value) $env->frames[0][$name] = $value;
		return $env;
	}

	public function hash(): string {
		$flat = [];
		foreach ($this->frames as $frame) foreach ($frame as $name => $value) $flat[$name] = $value->signature();
		ksort($flat);
		return md5(serialize($flat));
	}
}
