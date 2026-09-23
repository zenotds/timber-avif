<?php
/**
 * Timber AVIF
 *
 * @version 7.0.0-dev
 * @author Francesco Zeno Selva
 * @link https://github.com/zenotds/timber-avif
 *
 * Responsive images for Timber 2.x, with AVIF/WebP generated in the background and
 * served from a <picture> whose markup depends only on the attachment's metadata.
 *
 * Two ways in, one file:
 *  - Composer: `composer require zenotds/timber-avif`. Composer's autoloader includes
 *    this file, so requiring vendor/autoload.php is all it takes.
 *  - Drop-in: copy the folder into the theme and require this file from functions.php.
 */

if (defined('TIMBER_AVIF_FILE')) return;
define('TIMBER_AVIF_FILE', __FILE__);
define('TIMBER_AVIF_DIR', __DIR__);

// Under Composer the classes are already autoloadable; copied into a theme they are not.
if (!class_exists(\TimberAVIF\Plugin::class)) {
	spl_autoload_register(static function (string $class): void {
		if (!str_starts_with($class, 'TimberAVIF\\')) return;
		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
		if (is_file($file)) require $file;
	});
}

\TimberAVIF\Plugin::load();
