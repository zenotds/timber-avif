<?php
/**
 * Unit tests for the parts that need no WordPress: `php tests/unit.php`.
 */

use TimberAVIF\Config;
use TimberAVIF\Engine;
use TimberAVIF\Renderer;
use TimberAVIF\Sizes;

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MB_IN_BYTES', 1048576);

spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'TimberAVIF\\')) require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
});

function wp_basename(string $path): string { return basename($path); }

$failures = 0;
$count = 0;
function check(string $name, $actual, $expected): void {
	global $failures, $count;
	$count++;
	if ($actual === $expected) return;
	$failures++;
	echo "FAIL  $name\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

/** Metadata as WordPress writes it for a 4000×2667 upload scaled to 2560. */
function meta(array $widths, int $fw = 2560, int $fh = 1707, array $extra = []): array {
	$sizes = [];
	foreach ($widths as $w) {
		$h = (int) round($w * $fh / $fw);
		$sizes["tavif-$w"] = ['file' => "photo-{$w}x{$h}.jpg", 'width' => $w, 'height' => $h];
	}
	return ['width' => $fw, 'height' => $fh, 'file' => '2026/09/photo-scaled.jpg', 'sizes' => $sizes + $extra];
}

$widths = [320, 480, 640, 768, 1024, 1280, 1600, 1920, 2560];
$w = fn(array $candidates) => array_column($candidates, 'w');

/* ── Sizes::candidates ── */

$c = Sizes::candidates(meta([320, 480, 640, 768, 1024, 1280, 1600, 1920]), $widths);
check('canonical widths plus the full file, 1024 included', $w($c), [320, 480, 640, 768, 1024, 1280, 1600, 1920, 2560]);
check('the full file is the last candidate', end($c)['file'], 'photo-scaled.jpg');

$c = Sizes::candidates(meta([640, 1024]), $widths);
check('only sizes that exist are used', $w($c), [640, 1024, 2560]);
$c = Sizes::candidates(meta([320, 480, 640, 768, 1024, 1280, 1600, 1920], 2560, 1707, ['medium' => ['file' => 'photo-300x200.jpg', 'width' => 300, 'height' => 200]]), $widths);
check('once complete, only the configured widths', $w($c), [320, 480, 640, 768, 1024, 1280, 1600, 1920, 2560]);

$legacy = meta([], 2560, 1707, [
	'thumbnail'    => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150],
	'medium'       => ['file' => 'photo-300x200.jpg', 'width' => 300, 'height' => 200],
	'medium_large' => ['file' => 'photo-768x512.jpg', 'width' => 768, 'height' => 512],
	'large'        => ['file' => 'photo-1024x683.jpg', 'width' => 1024, 'height' => 683],
	'woo_crop'     => ['file' => 'photo-600x600.jpg', 'width' => 600, 'height' => 600],
]);
check('a pre-v7 image falls back to its proportional core sizes', $w(Sizes::candidates($legacy, $widths)), [300, 768, 1024, 2560]);

$tie = meta([768]) ;
$tie['sizes']['medium_large'] = ['file' => 'photo-768x512-core.jpg', 'width' => 768, 'height' => 512];
check('a canonical size wins a tie with a core size of the same width', Sizes::candidates($tie, $widths)[0]['file'], 'photo-768x512.jpg');

$huge = meta([640, 1024, 2560], 6000, 4000);
check('a full file past the ceiling is not a candidate', $w(Sizes::candidates($huge, $widths)), [640, 1024, 2560]);

$small = ['width' => 280, 'height' => 280, 'file' => 'icon.png', 'sizes' => ['thumbnail' => ['file' => 'icon-150x150.png', 'width' => 150, 'height' => 150]]];
check('an image below every configured width is served at its real size', $w(Sizes::candidates($small, $widths)), [280]);

check('max keeps one candidate past it, for DPR 2', $w(Sizes::candidates(meta([320, 480, 640, 768]), $widths, 400)), [320, 480]);
check('max on an exact width stops there', $w(Sizes::candidates(meta([320, 480, 640]), $widths, 480)), [320, 480]);
check('no metadata, no candidates', Sizes::candidates([], $widths), []);

/* ── Sizes::crop_targets / crop_candidates ── */

check('4/1 crops of a 2560×1707 source', Sizes::crop_targets(2560, 1707, 4.0, [640, 1280, 2560]), [
	['w' => 640, 'h' => 160], ['w' => 1280, 'h' => 320], ['w' => 2560, 'h' => 640],
]);
check('a tall crop is bounded by the source height', array_column(Sizes::crop_targets(2560, 1707, 0.5, [320, 640, 1024]), 'w'), [320, 640, 853]);
check('a square source already is 1/1', [Sizes::matches_ratio(1200, 1200, 1.0), Sizes::matches_ratio(1001, 1000, 1.0), Sizes::matches_ratio(1000, 750, 4 / 3)], [true, true, true]);
check('a 3:2 photo is not 16/9, nor 1/1', [Sizes::matches_ratio(1920, 1280, 16 / 9), Sizes::matches_ratio(1920, 1280, 1.0)], [false, false]);
check('no crops to build for the proportions the source has', Sizes::crop_targets(1200, 1200, 1.0, [320, 640, 1024]), []);

$crops = [['w' => 640, 'h' => 160, 'file' => 'a.jpg'], ['w' => 1280, 'h' => 320, 'file' => 'b.jpg'], ['w' => 2560, 'h' => 640, 'file' => 'c.jpg']];
check('crop candidates keep the widest crop', $w(Sizes::crop_candidates($crops, [640])), [640, 2560]);

/* ── Renderer::modern_srcset ── */

$cands = [['w' => 640, 'file' => 'a.jpg'], ['w' => 1280, 'file' => 'b.jpg'], ['w' => 2560, 'file' => 'c.jpg']];
$made = fn(string $f) => ['file' => "$f.avif"];
$base = 'https://x/u/';

check('complete set', Renderer::modern_srcset($cands, ['a.jpg' => $made('a.jpg'), 'b.jpg' => $made('b.jpg'), 'c.jpg' => $made('c.jpg')], $base),
	'https://x/u/a.jpg.avif 640w, https://x/u/b.jpg.avif 1280w, https://x/u/c.jpg.avif 2560w');
check('one width never processed: no <source> at all', Renderer::modern_srcset($cands, ['a.jpg' => $made('a.jpg'), 'c.jpg' => $made('c.jpg')], $base), null);
check('a discarded middle width is a gap', Renderer::modern_srcset($cands, ['a.jpg' => $made('a.jpg'), 'b.jpg' => ['skip' => 'larger'], 'c.jpg' => $made('c.jpg')], $base),
	'https://x/u/a.jpg.avif 640w, https://x/u/c.jpg.avif 2560w');
check('without the largest width: no <source>', Renderer::modern_srcset($cands, ['a.jpg' => $made('a.jpg'), 'b.jpg' => $made('b.jpg'), 'c.jpg' => ['skip' => 'larger']], $base), null);

/* ── Renderer::parse_ratio ── */

check('16/9', Renderer::parse_ratio('16/9')['key'], '16x9');
check('32/18 shares the 16x9 set', Renderer::parse_ratio('32/18')['key'], '16x9');
check('1280/720 shares it too', Renderer::parse_ratio('1280/720')['key'], '16x9');
check('4x1 round-trips', Renderer::parse_ratio('4x1')['value'], 4.0);
check('a float', Renderer::parse_ratio(1.5)['key'], '1.5');
check('junk', Renderer::parse_ratio('wide'), null);
check('zero', Renderer::parse_ratio('0/9'), null);

/* ── Engine::exceeds_tolerance ── */

$kb = 1024;
check('3 KB → 3 KB kept', Engine::exceeds_tolerance(3 * $kb, 3 * $kb), false);
check('64 KB → 69 KB kept (+8%)', Engine::exceeds_tolerance(64 * $kb, 69 * $kb), false);
check('3 KB → 12 KB discarded', Engine::exceeds_tolerance(3 * $kb, 12 * $kb), true);
check('85 KB → 97 KB discarded', Engine::exceeds_tolerance(85 * $kb, 97 * $kb), true);
check('5 MB → +100 KB discarded', Engine::exceeds_tolerance(5000 * $kb, 5100 * $kb), true);
check('smaller is always kept', Engine::exceeds_tolerance(100 * $kb, 40 * $kb), false);

/* ── Engine::gd_avif_quality ── */

check('GD at the default: the quality with Imagick\'s bytes', Engine::gd_avif_quality(75), 65);
check('between two measured points, in proportion', Engine::gd_avif_quality(72), 62);
check('from 90 libgd would switch to 4:4:4: the scale stops at 89', Engine::gd_avif_quality(97), 89);
check('lossless is passed as it is', Engine::gd_avif_quality(100), 100);
check('out of range is clamped', [Engine::gd_avif_quality(0), Engine::gd_avif_quality(120)], [1, 100]);
check('never inverted, never at 4:4:4 below 100', (function () {
	$prev = 0;
	for ($q = 1; $q < 100; $q++) {
		$gd = Engine::gd_avif_quality($q);
		if ($gd < $prev || $gd >= 90) return $q;
		$prev = $gd;
	}
	return true;
})(), true);

/* ── Sizes: extra widths and proportions ── */

$c = Sizes::candidates(meta([480, 640, 1024]), [480, 640, 1024], 200, [['w' => 200, 'h' => 133, 'file' => 'photo-scaled-200x133-tavif.jpg']]);
check('an extra width asked for by a template is a candidate', $w($c), [200]);
check('with no max it joins the set', $w(Sizes::candidates(meta([480, 640]), [480, 640], null, [['w' => 200, 'h' => 133, 'file' => 'x.jpg']])), [200, 480, 640, 2560]);
$m = meta([640], 2560, 1707, ['thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150]]);
check('the full file is proportional', Sizes::is_proportional($m, 'photo-scaled.jpg'), true);
check('an uncropped sub-size is proportional', Sizes::is_proportional($m, 'photo-640x427.jpg'), true);
check('a square thumbnail is not', Sizes::is_proportional($m, 'photo-150x150.jpg'), false);
check('an unknown file is not', Sizes::is_proportional($m, 'other.jpg'), false);

/* ── Config ── */

check('widths: sorted, unique, capped', Config::parse_widths('1024, 640,640 99999 8'), [640, 1024, 2560]);
$clean = Config::sanitize(['format_mode' => 'gif', 'avif_quality' => '140', 'jpeg_quality' => '10', 'only_if_smaller' => '0', 'breakpoint_widths' => '']);
check('an unknown format falls back to auto', $clean['format_mode'], 'auto');
check('quality clamped', [$clean['avif_quality'], $clean['jpeg_quality']], [100, 60]);
check('checkbox off', $clean['only_if_smaller'], false);
check('empty widths fall back to the defaults', $clean['breakpoint_widths'], Config::defaults()['breakpoint_widths']);

$normalize = new ReflectionMethod(Config::class, 'normalize');
$normalize->setAccessible(true);
check('every stored value is a choice', $normalize->invoke(null, ['_v' => 7, 'jpeg_quality' => 95]), ['jpeg_quality' => 95]);
check('values equal to today\'s defaults are dropped', $normalize->invoke(null, ['_v' => 7, 'avif_quality' => 75, 'only_if_smaller' => '1']), []);
check('unknown keys are dropped', $normalize->invoke(null, ['_v' => 7, 'pregenerate_widths' => '640', 'webp_quality' => 85]), ['webp_quality' => 85]);

/* ── Bootstrap outside WordPress ── */

// What vendor/autoload.php plus a PHPUnit bootstrap does before WordPress is loaded.
require dirname(__DIR__) . '/timber-avif.php';
check('loading the package without WordPress is not a fatal error', class_exists(\TimberAVIF\Plugin::class), true);

echo $failures ? "\n$failures of $count checks failed.\n" : "$count checks passed.\n";
exit($failures ? 1 : 0);
