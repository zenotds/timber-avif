<?php

namespace TimberAVIF;

/**
 * Twig: the data function behind the image() macro, one filter for a single URL, and the
 * @timber-avif template namespace.
 */
final class Twig {

	public static function register($twig) {
		$twig->addFunction(new \Twig\TwigFunction('image_sources', [Renderer::class, 'sources']));

		// For what cannot be a <picture>: a video poster, a CSS background, a blurred placeholder.
		$twig->addFilter(new \Twig\TwigFilter('best_src', static fn($src, $w = null, $h = null) => Renderer::url($src, $w, $h)));

		return $twig;
	}

	/**
	 * `timber/locations`: the macro ships with the package, so a theme imports it instead
	 * of carrying a copy that drifts — `{% import '@timber-avif/macros.twig' as tavif %}`.
	 */
	public static function locations(array $paths): array {
		$paths['timber-avif'] = [TIMBER_AVIF_DIR . '/views'];
		return $paths;
	}
}
