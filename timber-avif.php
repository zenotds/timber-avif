<?php
/**
 * Timber AVIF
 *
 * @version 7.0.4
 * @author Francesco Zeno Selva
 * @link https://github.com/zenotds/timber-avif
 *
 * Responsive images for Timber 2.x, with AVIF/WebP generated in the background and
 * served from a <picture> whose markup depends only on the attachment's metadata.
 *
 * Drop-in entry point: copy the folder into the theme and require this file from
 * functions.php. Installed with Composer, the classes are autoloaded and functions.php
 * calls TimberAVIF\Plugin::load() instead.
 */

if (!class_exists(\TimberAVIF\Plugin::class)) {
	spl_autoload_register(static function (string $class): void {
		if (!str_starts_with($class, 'TimberAVIF\\')) return;
		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
		if (is_file($file)) require $file;
	});
}

if (!defined('TIMBER_AVIF_DIR')) define('TIMBER_AVIF_DIR', __DIR__);

\TimberAVIF\Plugin::load();
