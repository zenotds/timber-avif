<?php
/**
 * GD's AVIF path, against a real WordPress:
 *
 *     wp eval-file tests/gd.php
 *
 * Where this PHP's GD cannot write AVIF, imageavif() is stood in for, recording what it is
 * called with: that is what the package decides. Where it can, the file is written for real.
 */

use TimberAVIF\Editor\Gd;
use TimberAVIF\Engine;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

$GLOBALS['tavif_gd'] = ['failures' => 0, 'count' => 0, 'call' => null];
function gcheck(string $name, $ok, string $detail = ''): void {
	$GLOBALS['tavif_gd']['count']++;
	if ($ok) { echo "  ok    $name\n"; return; }
	$GLOBALS['tavif_gd']['failures']++;
	echo "  FAIL  $name" . ($detail !== '' ? "\n        $detail" : '') . "\n";
}

$real = function_exists('imageavif');
if (!$real) {
	function imageavif($image, $file = null, int $quality = -1, int $speed = -1): bool {
		$GLOBALS['tavif_gd']['call'] = [$quality, $speed];
		return (bool) file_put_contents($file, 'stand-in');
	}
}

$dir = get_temp_dir() . 'tavif-gd-' . wp_generate_password(6, false);
wp_mkdir_p($dir);
$src = "$dir/photo.jpg";
$im = imagecreatetruecolor(800, 533);
imagefilledrectangle($im, 0, 0, 799, 532, imagecolorallocate($im, 200, 60, 40));
imagejpeg($im, $src, 90);

// The worker's editor, with make_image() within reach.
$editor = new class($src) extends Gd {
	public function write(string $dest, string $callback, int $quality): bool {
		return (bool) $this->make_image($dest, $callback, [$this->image, $dest, $quality]);
	}
};
$editor->load();

echo "\nGD, AVIF" . ($real ? '' : ' (imageavif() stood in for)') . "\n";
try {
	gcheck('written', $editor->write("$dir/photo.jpg.avif", 'imageavif', 75) && is_file("$dir/photo.jpg.avif"));
	if (!$real) {
		gcheck('with the quality Imagick\'s bytes correspond to, and speed 8', $GLOBALS['tavif_gd']['call'] === [Engine::gd_avif_quality(75), Engine::GD_AVIF_SPEED], wp_json_encode($GLOBALS['tavif_gd']['call']));
	} else {
		gcheck('a real AVIF', Engine::is_valid("$dir/photo.jpg.avif", 'avif'));
	}
	$GLOBALS['tavif_gd']['call'] = null;
	gcheck('other formats are left to WordPress', $editor->write("$dir/photo.webp", 'imagewebp', 75) && is_file("$dir/photo.webp") && $GLOBALS['tavif_gd']['call'] === null);
} catch (Throwable $e) {
	$GLOBALS['tavif_gd']['failures']++;
	echo "\n  ERROR " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
	array_map('unlink', glob("$dir/*") ?: []);
	@rmdir($dir);
}

['failures' => $failures, 'count' => $count] = $GLOBALS['tavif_gd'];
echo $failures ? "\n$failures of $count checks failed.\n" : "\n$count checks passed.\n";
if ($failures) exit(1);
