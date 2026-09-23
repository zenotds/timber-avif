<?php

namespace TimberAVIF;

/**
 * Twig functions and filters, the @timber-avif template namespace, and the image class.
 */
final class Twig {

	public static function register($twig) {
		$twig->addFunction(new \Twig\TwigFunction('image_sources', [Renderer::class, 'sources']));

		$single = [
			'avif_src' => 'avif',
			'webp_src' => 'webp',
			'best_src' => null,
		];
		foreach ($single as $name => $format) {
			$callback = static fn($src, $w = null, $h = null) => Renderer::url($src, $w, $h, $format);
			$twig->addFilter(new \Twig\TwigFilter($name, $callback));
			$twig->addFunction(new \Twig\TwigFunction($name, $callback));
		}
		$twig->addFilter(new \Twig\TwigFilter('toavif', static fn($src) => Renderer::url($src, null, null, 'avif')));

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

	/**
	 * `timber/post/classmap`: images get image.avif, image.webp and image.best.
	 *
	 * v6 used `timber/image/new_class`, which Timber 2 no longer has: the class was never
	 * swapped and those three properties printed empty. A theme with its own image class
	 * keeps it, and can `use \TimberAVIF\ModernSources;` in it for the same properties.
	 */
	public static function classmap(array $map): array {
		$previous = $map['attachment'] ?? null;
		$map['attachment'] = static function (\WP_Post $post) use ($previous) {
			$class = is_callable($previous) ? $previous($post) : ($previous ?: \Timber\Attachment::class);
			return $class === \Timber\Image::class ? Image::class : $class;
		};
		return $map;
	}
}
