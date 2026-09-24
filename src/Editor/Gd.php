<?php

namespace TimberAVIF\Editor;

/**
 * WordPress's GD editor with the same tavif_save() as the Imagick one. GD keeps no colour
 * profile, so it is the fallback: the admin says so when it is the engine in use.
 */
class Gd extends \WP_Image_Editor_GD {

	/** @return array|\WP_Error */
	public function tavif_save(int $width, int $height, bool $crop, string $dest, string $mime) {
		$orig_size = $this->size;

		$image = $this->image;
		if ($crop || $width < $this->size['width']) {
			$image = $this->_resize($width, $height, $crop);
			if (is_wp_error($image)) return $image;
		}

		$saved = $this->_save($image, $dest, $mime);
		$this->size = $orig_size;

		return $saved;
	}

	/**
	 * WordPress writes AVIF as imageavif($image, $file, $quality): the quality as it is and
	 * libavif's default speed. Here the quality Imagick's bytes correspond to, and a faster
	 * speed (Engine::gd_avif_quality(), Engine::GD_AVIF_SPEED). Only this editor, which only
	 * the worker opens: WordPress's own sub-sizes are left to WordPress.
	 */
	protected function make_image($filename, $callback, $arguments) {
		if ($callback === 'imageavif' && isset($arguments[2])) {
			$arguments[2] = \TimberAVIF\Engine::gd_avif_quality((int) $arguments[2]);
			$arguments[3] = \TimberAVIF\Engine::GD_AVIF_SPEED;
		}
		return parent::make_image($filename, $callback, $arguments);
	}
}
