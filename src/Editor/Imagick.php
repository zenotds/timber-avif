<?php

namespace TimberAVIF\Editor;

/**
 * WordPress's Imagick editor, plus a way to save a resized copy under a name and in a
 * format of our choosing without disturbing the loaded image. make_subsize() does the
 * same, but picks the file name itself and keeps the source format.
 */
class Imagick extends \WP_Image_Editor_Imagick {

	/**
	 * Imagick built from source (PECL, MAMP, some hosts) reports its version as the
	 * literal "@PACKAGE_VERSION@". WordPress reads that as older than 2.2 and refuses the
	 * extension, leaving AVIF to GD — which on those builds usually lacks imageavif().
	 * The placeholder only ever comes from a recent build, so the version check is skipped
	 * and every other requirement is checked exactly as WordPress checks it.
	 */
	public static function test($args = []) {
		if (parent::test($args)) return true;
		if (preg_match('/\d/', (string) phpversion('imagick'))) return false;

		if (!extension_loaded('imagick') || !class_exists('Imagick', false) || !class_exists('ImagickPixel', false)) return false;
		if (!defined('imagick::COMPRESSION_JPEG')) return false;

		$required = ['clear', 'destroy', 'valid', 'getimage', 'writeimage', 'getimageblob', 'getimagegeometry', 'getimageformat', 'setimageformat', 'setimagecompression', 'setimagecompressionquality', 'setimagepage', 'setoption', 'scaleimage', 'cropimage', 'rotateimage', 'flipimage', 'flopimage', 'readimage', 'readimageblob'];
		return !array_diff($required, array_map('strtolower', get_class_methods('Imagick')));
	}

	/**
	 * @param int  $width  Target width.
	 * @param int  $height Target height, or 0 to follow the aspect ratio.
	 * @param bool $crop   Centre-crop to exactly $width × $height.
	 * @return array|\WP_Error What _save() returns: path, file, width, height, mime-type, filesize.
	 */
	public function tavif_save(int $width, int $height, bool $crop, string $dest, string $mime) {
		$orig_size  = $this->size;
		$orig_image = $this->image->getImage();

		$saved = null;
		if ($crop || $width < $this->size['width']) {
			$resized = $this->resize($width, $height, $crop);
			if (is_wp_error($resized)) $saved = $resized;
		}
		if ($saved === null && ($mime === 'image/avif' || $mime === 'image/webp')) $this->keep_colour_profile_only();
		$saved ??= $this->_save($this->image, $dest, $mime);

		$this->image->clear();
		$this->image->destroy();
		$this->size  = $orig_size;
		$this->image = $orig_image;

		return $saved;
	}

	/**
	 * WordPress keeps EXIF, XMP and IPTC in what it writes, which suits the JPEG sub-sizes.
	 * In the modern copies they are about 2 KB per file — 8% of an AVIF up to 480px wide on
	 * a real site — for data no browser reads. The colour profile stays: without it a
	 * Display P3 photo changes colour. Provenance is read from the original, not from these.
	 */
	private function keep_colour_profile_only(): void {
		try {
			foreach (array_keys($this->image->getImageProfiles('*', true)) as $name) {
				if ($name !== 'icc' && $name !== 'icm') $this->image->removeImageProfile($name);
			}
		} catch (\Exception $e) {
			// Written with the metadata, then: heavier, not wrong.
		}
	}

	/**
	 * The parent switches on the *source* mime type, so a JPEG written as AVIF gets the
	 * JPEG branch: quality is applied, but not the encoder speed WordPress picked for AVIF.
	 */
	public function set_quality($quality = null, $dims = []) {
		$result = parent::set_quality($quality, $dims);

		if (true === $result && $this->image && $this->output_mime_type === 'image/avif' && $this->mime_type !== 'image/avif') {
			try {
				$this->image->setOption('heic:speed', '7');
			} catch (\Exception $e) {
				// Older ImageMagick without the define: the default speed is only slower.
			}
		}

		return $result;
	}
}
