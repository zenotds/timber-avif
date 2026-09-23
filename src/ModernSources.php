<?php

namespace TimberAVIF;

/**
 * {{ image.avif }}, {{ image.webp }}, {{ image.best }} on a Timber image class: the full
 * size in that format when a copy exists, the original otherwise.
 */
trait ModernSources {
	public function avif(): string {
		return Renderer::url($this, null, null, 'avif');
	}

	public function webp(): string {
		return Renderer::url($this, null, null, 'webp');
	}

	public function best(): string {
		return Renderer::url($this);
	}
}
