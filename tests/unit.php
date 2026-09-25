<?php
/**
 * Unit tests for the parts that need no WordPress: `php tests/unit.php`.
 */

use TimberAVIF\Config;
use TimberAVIF\Engine;
use TimberAVIF\Need;
use TimberAVIF\Optimize\Templates;
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

/* ── Need: how wide a template shows an image ── */

$widths = [320, 480, 640, 768, 1024, 1280, 1600, 1920, 2560];
// Thinkwater's event cards: widest on a phone just below 640 px, 591.5 CSS px.
check('sizes: the widest slot is found where it is, on a phone', Need::pixels('(min-width: 64rem) 380px, (min-width: 40rem) 48vw, calc(100vw - 3rem)'), 1183);
check('covered by 1280', Need::covering(1183, $widths, 2560), 1280);
// Thinkwater's split blocks: widest on a tablet, 975.5 px below 1024.
check('a "small" image can need everything', Need::covering(Need::pixels('(min-width: 102.5rem) 784px, (min-width: 64rem) 50vw, calc(100vw - 3rem)'), $widths, 2560), null);
check('100vw is the full width', Need::pixels('100vw'), 5120);
check('a fixed width, at 2x', Need::pixels('96px'), 192);
check('entries after a max-width', Need::pixels('(max-width: 640px) 100vw, 50vw'), 2560);
check('auto is skipped', Need::pixels('auto, (min-width: 64rem) 300px, 50vw'), 1024);
check('clamp()', Need::pixels('clamp(200px, 50vw, 600px)'), 1200);
check('min()', Need::pixels('min(100vw, 1200px)'), 2400);
check('range syntax', Need::pixels('(width >= 64rem) 400px, 100vw'), 2047);
check('range syntax, reversed', Need::pixels('(64rem <= width) 400px, 100vw'), 2047);
check('screen and …', Need::pixels('screen and (min-width: 40rem) 300px, 100vw'), 1279);
check('em in a media condition is 16px', Need::pixels('(min-width: 40em) 300px, 100vw'), 1279);
check('a condition not read: unknown', Need::pixels('(orientation: portrait) 50vw, 100vw'), null);
check('a unit not read: unknown', Need::pixels('50vh'), null);
check('a percentage: unknown', Need::pixels('calc(100% - 2rem)'), null);
check('empty: unknown', Need::pixels(''), null);
check('max caps it', Need::pixels('100vw', 400), 400);
check('max is enough without sizes', Need::pixels(null, 400), 400);
check('nothing at all: unknown', Need::pixels(null), null);
check('covering: the smallest width at least as wide', Need::covering(100, $widths, 2560), 320);
check('covering: nothing below the image is wide enough', Need::covering(1183, $widths, 1200), null);

/* ── Templates: where the Twig shows images ── */

$uses = function (array $sources, array $options = ['options']): array {
	$read = Templates::read_sources($sources, $options);
	$out = [];
	foreach ($read['uses'] as $u) $out[$u['scope'] . '|' . $u['path'] . ($u['ratio'] !== '' ? '@' . $u['ratio'] : '')] = $u['pixels'];
	ksort($out);
	return $out + ($read['untraced'] ? ['untraced' => count($read['untraced'])] : []);
};
$macros = ['macros.twig' => "{% macro image(image, options = {}) %}{% import '@timber-avif/macros.twig' as tavif %}{{ tavif.image(image, options) }}{% endmacro %}"];

check('templates: a flexible layout row, a repeater, a condition in sizes', $uses($macros + [
	'page.twig' => "{% for content in post.meta('content') %}{% include 'components/block-' ~ content.acf_fc_layout ~ '.twig' %}{% endfor %}",
	'components/block-cards.twig' => "{% import 'macros.twig' as macros %}{% for item in content.cards %}{% set cover = get_image(item.cover) %}{{ macros.image(cover, { sizes: '(min-width: 64rem) ' ~ (count > 3 ? '310px' : '630px') ~ ', calc(100vw - 3rem)' }) }}{% endfor %}",
]), ['layout:cards|cards.*.cover' => 1951]);

check('templates: a macro of the theme, given the image and the sizes', $uses($macros + [
	'block.twig' => "{% import 'macros.twig' as macros %}{% macro card(project, sizes) %}{% import 'macros.twig' as macros %}{{ macros.image(get_image(project.after), { sizes: sizes }) }}{% endmacro %}{% for p in content.projects %}{{ _self.card(p, '(min-width: 64rem) 400px, 100vw') }}{% endfor %}",
	'page.twig' => "{% for content in post.meta('rows') %}{% include 'block.twig' %}{% endfor %}",
]), ['post|rows.*.projects.*.after' => 2047]);

check('templates: include with, a group field, the featured image', $uses($macros + [
	'page.twig' => "{% include 'header.twig' with { header: post.header, cover: post.thumbnail } %}",
	'header.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(cover, { sizes: '600px' }) }}{{ macros.image(get_image(header.image), { sizes: '300px', max: 400 }) }}",
]), ['post|header.image' => 400, 'post|thumbnail' => 1200]);

check('templates: options, |best_src, a literal ID', $uses($macros + [
	'footer.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(get_image(settings.logo), { sizes: '200px' }) }}<video poster=\"{{ get_image(content.poster)|best_src(1280, 720) }}\"></video>{{ macros.image(get_image(42), { sizes: '96px' }) }}",
], ['settings']), ['any|poster@16x9' => 1280, 'id:42|' => 192, 'option|logo' => 400]);

check('templates: sizes from a variable nothing sets is every width', $uses($macros + [
	'a.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(post.thumbnail, { sizes: mysizes }) }}",
]), ['post|thumbnail' => null]);

check('templates: a set in each branch keeps both', $uses($macros + [
	'a.twig' => "{% import 'macros.twig' as macros %}{% if wide %}{% set s = '100vw' %}{% else %}{% set s = '300px' %}{% endif %}{{ macros.image(post.thumbnail, { sizes: s }) }}",
]), ['post|thumbnail' => 5120]);

check('templates: a loop over a query, the posts of a relationship', $uses($macros + [
	'a.twig' => "{% import 'macros.twig' as macros %}{% for p in tw_events() %}{{ macros.image(p.thumbnail, { sizes: '400px' }) }}{% endfor %}{% for r in post.related %}{{ macros.image(r.thumbnail, { sizes: '200px' }) }}{% endfor %}",
]), ['any|*.thumbnail' => 800, 'post|related.*.thumbnail' => 400]);

check('templates: a variable nothing in Twig sets is listed, not guessed', $uses($macros + [
	'a.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(cover, { sizes: '100vw' }) }}",
]), ['untraced' => 1]);

check('templates: |default, when nothing passes the value', $uses($macros + [
	'list.twig' => "{% for post in posts %}{% include 'tease.twig' %}{% endfor %}{% for post in posts %}{% include 'tease.twig' with { img_sizes: '200px' } %}{% endfor %}",
	'tease.twig' => "{% import 'macros.twig' as macros %}{% set img_sizes = img_sizes|default('(min-width: 64rem) 25vw, 50vw') %}{{ macros.image(post.thumbnail, { sizes: img_sizes }) }}",
]), ['any|*.thumbnail' => 1280]);

check('templates: a set under a condition keeps what the including template passed', $uses($macros + [
	'page.twig' => "{% include 'card.twig' with { sizes: '100vw' } %}",
	'card.twig' => "{% import 'macros.twig' as macros %}{% if sizes is not defined %}{% set sizes = '300px' %}{% endif %}{{ macros.image(post.thumbnail, { sizes: sizes }) }}",
]), ['post|thumbnail' => 5120]);

check('templates: a variable of the template set in a loop keeps its value after it', $uses($macros + [
	'a.twig' => "{% import 'macros.twig' as macros %}{% set s = '300px' %}{% for x in post.items %}{% set s = '100vw' %}{% endfor %}{{ macros.image(post.thumbnail, { sizes: s }) }}",
]), ['post|thumbnail' => 5120]);

check('templates: a theme wrapper named image that changes the options is followed', $uses([
	'macros.twig' => "{% macro image(image, options = {}) %}{% import '@timber-avif/macros.twig' as tavif %}{{ tavif.image(image, options|merge({ sizes: '100vw', ratio: '16/9' })) }}{% endmacro %}",
	'a.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(post.thumbnail, { sizes: '300px' }) }}",
]), ['post|thumbnail@16x9' => 5120]);

check('templates: a ratio of the call', $uses($macros + [
	'a.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(post.thumbnail, { sizes: '80px', max: 320, ratio: '1/1' }) }}",
]), ['post|thumbnail@1x1' => 160]);

/* ── Bootstrap outside WordPress ── */

// What vendor/autoload.php plus a PHPUnit bootstrap does before WordPress is loaded.
require dirname(__DIR__) . '/timber-avif.php';
check('loading the package without WordPress is not a fatal error', class_exists(\TimberAVIF\Plugin::class), true);

echo $failures ? "\n$failures of $count checks failed.\n" : "$count checks passed.\n";
exit($failures ? 1 : 0);
