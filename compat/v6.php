<?php
/**
 * v6's static API, backed by v7, for themes that call it from PHP — a wp_content_img_tag
 * filter, a shortcode. Declared by Plugin::boot() once v6 is known not to be loaded, and
 * with v7's version, so it is never mistaken for v6 itself.
 */

if (!class_exists('TimberAVIF', false)) {
	class TimberAVIF {
		const VERSION = \TimberAVIF\Plugin::VERSION;

		public static function image_sources($src, array $opts = []): array {
			return \TimberAVIF\Renderer::sources($src, $opts);
		}

		public static function filter_toavif($src): string {
			return \TimberAVIF\Renderer::url($src, null, null, 'avif');
		}

		public static function filter_avif_src($src, $width = null, $height = null): string {
			return \TimberAVIF\Renderer::url($src, $width, $height, 'avif');
		}

		public static function filter_webp_src($src, $width = null, $height = null): string {
			return \TimberAVIF\Renderer::url($src, $width, $height, 'webp');
		}

		public static function filter_best_src($src, $width = null, $height = null): string {
			return \TimberAVIF\Renderer::url($src, $width, $height);
		}

		public static function modern_format(): ?string {
			return \TimberAVIF\Config::format();
		}
	}
}
