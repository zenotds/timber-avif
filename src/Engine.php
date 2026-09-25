<?php

namespace TimberAVIF;

/**
 * Encoding, through WordPress's own image editors.
 *
 * WordPress's Imagick editor keeps ICC, EXIF orientation and XMP, and since 6.5 writes
 * AVIF with the quality actually applied. An Imagick engine of one's own tends to call
 * stripImage(), which drops the ICC profile too, and GD never has one: a Display P3 photo
 * then comes out of AVIF with different colours than its JPEG. The two subclasses in
 * Editor/ add the one thing missing: saving a resized copy to a path and format of our
 * choosing.
 */
final class Engine {
	const AUTO_OPTION    = 'timber_avif_auto_format';
	const CAPABILITY_TTL = WEEK_IN_SECONDS;

	private static array $detected = [];

	public static function mime(string $format): string {
		return $format === 'webp' ? 'image/webp' : 'image/avif';
	}

	/**
	 * Which editor can write this format in this PHP process: 'imagick', 'gd' or 'none'.
	 *
	 * Cached per SAPI: wp-cli, php-fpm and cron can be different PHP builds with different
	 * extensions, and a shared answer once had the CLI calling an engine it did not have.
	 */
	public static function detect(string $format): string {
		$format = $format === 'webp' ? 'webp' : 'avif';
		if (isset(self::$detected[$format])) return self::$detected[$format];

		$key = 'timber_avif_cap_' . $format . '_' . self::runtime();
		$cached = get_transient($key);
		if (is_string($cached) && self::still_available($cached, $format)) {
			return self::$detected[$format] = $cached;
		}

		$found = 'none';
		foreach (self::engines() as $engine) {
			if (self::probe($engine, $format)) { $found = $engine; break; }
		}

		set_transient($key, $found, self::CAPABILITY_TTL);
		return self::$detected[$format] = $found;
	}

	public static function can(string $format): bool {
		return self::detect($format) !== 'none';
	}

	/**
	 * What `format_mode: auto` resolves to. Decided by the web server's PHP, which is the
	 * one the queue normally runs in, and remembered in an autoloaded option so the render
	 * path reads it for free. A CLI process answers for itself without overwriting it.
	 */
	public static function auto_format(): ?string {
		$stored = get_option(self::AUTO_OPTION);
		if (is_array($stored) && array_key_exists('format', $stored)) return $stored['format'] ?: null;

		$format = self::can('avif') ? 'avif' : (self::can('webp') ? 'webp' : '');
		if (PHP_SAPI !== 'cli') update_option(self::AUTO_OPTION, ['format' => $format, 'at' => time()], true);
		return $format ?: null;
	}

	/**
	 * Forget every capability answer, for every SAPI, and the auto format with them.
	 */
	public static function flush(): void {
		global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timber\\_avif\\_cap\\_%' OR option_name LIKE '\\_transient\\_timeout\\_timber\\_avif\\_cap\\_%'");
		// Under an external object cache transients never reach the table: retire this runtime's at least.
		delete_transient('timber_avif_cap_avif_' . self::runtime());
		delete_transient('timber_avif_cap_webp_' . self::runtime());
		delete_option(self::AUTO_OPTION);
		self::$detected = [];
	}

	public static function runtime(): string {
		return PHP_SAPI . '_' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
	}

	/**
	 * An editor on $path able to write $mime, or the reason there is none.
	 *
	 * @return Editor\Imagick|Editor\Gd|\WP_Error
	 */
	public static function open(string $path, string $mime) {
		self::load_editors();
		$source_mime = wp_get_image_mime($path) ?: '';
		$error = new \WP_Error('tavif_no_editor', sprintf('No image editor can write %s here', $mime));

		foreach (self::engines() as $engine) {
			$class = self::editor_class($engine);
			if (!$class::test(['path' => $path, 'mime_type' => $source_mime])) continue;
			if (!$class::supports_mime_type($mime)) continue;
			if ($source_mime && !$class::supports_mime_type($source_mime)) continue;

			$editor = new $class($path);
			$loaded = $editor->load();
			if (!is_wp_error($loaded)) return $editor;
			$error = $loaded;
		}

		return $error;
	}

	/**
	 * Is the converted file heavy enough, next to the one it would replace, to be worth
	 * throwing away? The excess has to clear a relative *and* an absolute bar — unless it
	 * is big enough on its own to matter whatever the ratio.
	 *
	 *     3 KB   →   3 KB  (+0 KB)     keep
	 *    64 KB   →  69 KB  (+5 KB)     keep     — +8%, under the ratio
	 *     3 KB   →  12 KB  (+9 KB)     discard  — +300%: a flat icon AVIF handles badly
	 *    85 KB   →  97 KB  (+12 KB)    discard  — over the floor and over 10%
	 *     5 MB   → 5.1 MB  (+100 KB)   discard  — over the hard limit, at just +2%
	 */
	public static function exceeds_tolerance(int $baseline, int $bytes): bool {
		$excess = $bytes - $baseline;
		if ($excess <= 0) return false;
		if ($excess > Config::OVERSIZE_HARD_LIMIT) return true;

		return $excess > Config::OVERSIZE_MIN_BYTES
			&& $excess > $baseline * Config::OVERSIZE_MIN_RATIO;
	}

	/**
	 * libavif's speed for GD. WordPress calls imageavif() with the quality alone, which leaves
	 * libavif at 6; at 8 it encoded 2.6 times faster for 3.6% more bytes, measured on the
	 * photos below. 9 cost 18%.
	 */
	const GD_AVIF_SPEED = 8;

	/**
	 * Settings quality → the quality GD needs for the bytes Imagick writes at it.
	 *
	 * The same number means different things: libgd turns it into one fixed quantizer, libheif
	 * into a target the encoder spends bits against where they show. At 75 GD came out 23%
	 * heavier. Measured with libgd 2.3.3 (libavif 1.4.2, at GD_AVIF_SPEED) against the
	 * Imagick path (ImageMagick 7.1.2, libheif 1.23): 18 photos and renders from three sites,
	 * at 640 and 1280 px. Each point matches Imagick's bytes within 3% on average, within 15%
	 * for any one image. From 90 libgd switches to 4:4:4 chroma, 23% heavier at once, which
	 * Imagick never does: the scale stops at 89. 100, lossless, is passed as it is.
	 */
	const GD_AVIF_QUALITY = [1 => 1, 40 => 30, 50 => 39, 60 => 49, 70 => 60, 75 => 65, 80 => 73, 85 => 77, 90 => 84, 95 => 89, 99 => 89, 100 => 100];

	public static function gd_avif_quality(int $quality): int {
		$quality = max(1, min(100, $quality));
		$prev = null;
		foreach (self::GD_AVIF_QUALITY as $at => $gd) {
			if ($quality === $at) return $gd;
			if ($quality < $at && $prev) {
				[$prev_at, $prev_gd] = $prev;
				return (int) round($prev_gd + ($gd - $prev_gd) * ($quality - $prev_at) / ($at - $prev_at));
			}
			$prev = [$at, $gd];
		}
		return $quality;
	}

	public static function is_valid(string $path, string $format): bool {
		if (!is_file($path) || filesize($path) < 50) return false;
		$head = (string) file_get_contents($path, false, null, 0, 32);
		if ($format === 'webp') return substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
		return substr($head, 4, 4) === 'ftyp' && str_contains($head, 'avif');
	}

	/**
	 * Animated sources are left alone: every encoder here keeps the first frame only, and
	 * WordPress's own sub-sizes of them are static too.
	 */
	public static function is_animated(string $path, string $mime): bool {
		$fh = @fopen($path, 'rb');
		if (!$fh) return false;

		if ($mime === 'image/webp') {
			$head = (string) fread($fh, 32);
			fclose($fh);
			return substr($head, 12, 4) === 'VP8X' && (ord($head[20] ?? "\0") & 0x02);
		}

		if ($mime === 'image/png') {
			$head = (string) fread($fh, 4096);
			fclose($fh);
			$idat = strpos($head, 'IDAT');
			$actl = strpos($head, 'acTL');
			return $actl !== false && ($idat === false || $actl < $idat);
		}

		if ($mime === 'image/gif') {
			$frames = 0;
			$buffer = '';
			while (!feof($fh) && $frames < 2) {
				$buffer = substr($buffer, -20) . fread($fh, 8192);
				$frames = preg_match_all('/\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)/s', $buffer);
			}
			fclose($fh);
			return $frames > 1;
		}

		fclose($fh);
		return false;
	}

	/**
	 * Engines in the order WordPress would pick them, so a site that forces GD through
	 * `wp_image_editors` gets GD here too.
	 */
	private static function engines(): array {
		self::load_editors();
		$order = (array) apply_filters('wp_image_editors', ['WP_Image_Editor_Imagick', 'WP_Image_Editor_GD']);
		$engines = [];
		foreach ($order as $class) {
			if ($class === 'WP_Image_Editor_Imagick') $engines[] = 'imagick';
			if ($class === 'WP_Image_Editor_GD')      $engines[] = 'gd';
		}
		return $engines ?: ['imagick', 'gd'];
	}

	private static function editor_class(string $engine): string {
		return $engine === 'gd' ? Editor\Gd::class : Editor\Imagick::class;
	}

	private static function load_editors(): void {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
	}

	private static function still_available(string $engine, string $format): bool {
		return match ($engine) {
			'imagick' => extension_loaded('imagick'),
			'gd'      => function_exists($format === 'webp' ? 'imagewebp' : 'imageavif'),
			'none'    => true,
			default   => false,
		};
	}

	/**
	 * Encode a real file with the real editor: a format listed by the library can still
	 * fail to encode when the codec behind it is missing.
	 */
	private static function probe(string $engine, string $format): bool {
		$class = self::editor_class($engine);
		$mime = self::mime($format);
		if (!$class::test(['mime_type' => $mime]) || !$class::supports_mime_type($mime)) return false;

		// wp_tempnam() lives in wp-admin/includes, which the front end never loads.
		$src = get_temp_dir() . 'tavif-probe-' . wp_generate_password(8, false) . '.png';
		$dst = $src . '.' . $format;

		try {
			if (!self::write_probe_png($src)) return false;
			$editor = new $class($src);
			if (is_wp_error($editor->load())) return false;
			$saved = $editor->tavif_save(16, 16, false, $dst, $mime);
			return !is_wp_error($saved) && self::is_valid($dst, $format);
		} catch (\Throwable $e) {
			return false;
		} finally {
			@unlink($src);
			@unlink($dst);
		}
	}

	private static function write_probe_png(string $path): bool {
		if (function_exists('imagecreatetruecolor')) {
			$img = imagecreatetruecolor(16, 16);
			imagefilledrectangle($img, 0, 0, 7, 15, imagecolorallocate($img, 200, 40, 40));
			return imagepng($img, $path);
		}
		if (class_exists('Imagick')) {
			$img = new \Imagick();
			$img->newImage(16, 16, 'red', 'png');
			return $img->writeImage($path);
		}
		return false;
	}
}
