<?php
/**
 * Integration test, against a real WordPress with Timber and this package loaded:
 *
 *     wp eval-file tests/integration.php
 *
 * It uploads its own images, runs the worker and renders through Twig. Use a throwaway
 * site: it changes settings and deletes what it uploads, but not only that.
 */

use Timber\Timber;
use TimberAVIF\Config;
use TimberAVIF\Engine;
use TimberAVIF\Index;
use TimberAVIF\Lock;
use TimberAVIF\Renderer;
use TimberAVIF\Tools;
use TimberAVIF\Worker;

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// wp eval-file runs this inside a function: shared state has to live in $GLOBALS explicitly.
$GLOBALS['tavif_it'] = ['failures' => 0, 'count' => 0, 'uploaded' => []];
function check(string $name, $ok, string $detail = ''): void {
	$GLOBALS['tavif_it']['count']++;
	if ($ok) { echo "  ok    $name\n"; return; }
	$GLOBALS['tavif_it']['failures']++;
	echo "  FAIL  $name" . ($detail !== '' ? "\n        $detail" : '') . "\n";
}
function section(string $title): void { echo "\n$title\n"; }

$work = get_temp_dir() . 'tavif-it-' . wp_generate_password(6, false);
wp_mkdir_p($work);
function upload(string $path): int {
	$tmp = wp_tempnam(basename($path));
	copy($path, $tmp);
	$id = media_handle_sideload(['name' => basename($path), 'tmp_name' => $tmp], 0);
	if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
	return $GLOBALS['tavif_it']['uploaded'][] = $id;
}

function dir_of(int $id): string { return dirname(get_attached_file($id)); }

function drain(): array {
	$total = ['processed' => 0, 'finished' => 0];
	for ($i = 0; $i < 20; $i++) {
		$r = Worker::run(120);
		$total['processed'] += $r['processed'];
		$total['finished'] += $r['finished'];
		if ($r['error']) throw new RuntimeException($r['error']);
		if (!$r['remaining'] || !$r['processed']) break;
	}
	return $total;
}

function render(string $tpl, array $ctx = []): string {
	return Timber::compile_string('{% import "@timber-avif/macros.twig" as m %}' . $tpl, $ctx);
}

try {
	Config::reset();
	Engine::flush();

	section('Environment');
	check('Imagick encodes AVIF', Engine::detect('avif') === 'imagick', Engine::detect('avif'));
	check('auto resolves to AVIF', Config::format() === 'avif');

	/* ── Fixtures ── */
	$photo = "$work/photo.jpg";
	$im = new Imagick();
	$im->newPseudoImage(4000, 2667, 'plasma:fractal');
	$p3 = '/System/Library/ColorSync/Profiles/Display P3.icc';
	if (is_readable($p3)) $im->profileImage('icc', file_get_contents($p3));
	$im->setImageFormat('jpeg');
	$im->setImageCompressionQuality(92);
	$im->writeImage($photo);

	$logo = "$work/logo.png";
	$im = new Imagick();
	$im->newImage(800, 400, new ImagickPixel('transparent'), 'png');
	$draw = new ImagickDraw();
	$draw->setFillColor('#1d4ed8');
	$draw->rectangle(100, 100, 700, 300);
	$im->drawImage($draw);
	$im->writeImage($logo);

	$anim = "$work/anim.gif";
	$gif = new Imagick();
	foreach (['red', 'blue'] as $color) {
		$frame = new Imagick();
		$frame->newImage(300, 300, $color, 'gif');
		$frame->setImageDelay(20);
		$gif->addImage($frame);
	}
	$gif->setFormat('gif');
	$gif->writeImages($anim, true);

	section('Upload');
	$id = upload($photo);
	$logo_id = upload($logo);
	$anim_id = upload($anim);
	$meta = wp_get_attachment_metadata($id);

	check('the original is scaled to 2560', (int) $meta['width'] === 2560, (string) $meta['width']);
	$ours = array_filter(array_keys($meta['sizes']), fn($k) => str_starts_with($k, 'tavif-'));
	check('canonical sizes built at upload, none at or past full width', count($ours) === 8 && !isset($meta['sizes']['tavif-2560']), implode(',', $ours));
	check('nothing converted during the upload', !glob(dir_of($id) . '/*.avif'));
	check('all three are pending', Worker::count_pending() === 3, (string) Worker::count_pending());

	section('Before the worker');
	$img = Timber::get_image($id);
	$data = Renderer::sources($img, []);
	check('fallback srcset has every width', substr_count($data['srcset'], 'w,') === 8, $data['srcset']);
	check('no <source> while nothing is converted', $data['modern'] === null);
	check('src is the candidate closest to 1024', str_ends_with($data['src'], 'photo-1024x683.jpg'), $data['src']);

	section('Worker');
	$t = microtime(true);
	$r = drain();
	$elapsed = microtime(true) - $t;
	check('queue drained', Worker::count_pending() === 0, (string) Worker::count_pending());
	echo sprintf("        %d attachments in %.1fs\n", $r['finished'], $elapsed);

	$index = Index::get($id);
	$files = array_filter(array_column($index['avif'], 'file'));
	check('nine AVIF files, named after what they replace', count($files) === 9 && in_array('photo-640x427.jpg.avif', $files, true) && in_array('photo-scaled.jpg.avif', $files, true), implode(', ', $files));
	check('each one is a valid AVIF', !array_filter($files, fn($f) => !Engine::is_valid(dir_of($id) . "/$f", 'avif')));
	check('stamped with the fingerprint', get_post_meta($id, Index::STAMP, true) === Config::fingerprint());
	check('no temp files left behind', !glob(dir_of($id) . '/*.tavif-*'));

	$avif = new Imagick(dir_of($id) . '/photo-1024x683.jpg.avif');
	check('the Display P3 profile survives', !is_readable($p3) || isset($avif->getImageProfiles('icc', false)[0]), implode(',', $avif->getImageProfiles('*', false)));
	check('dimensions match the JPEG it replaces', $avif->getImageWidth() === 1024 && abs($avif->getImageHeight() - 683) <= 1, $avif->getImageWidth() . 'x' . $avif->getImageHeight());

	$bytes = array_sum(array_column($index['avif'], 'bytes'));
	$src = array_sum(array_column($index['avif'], 'src_bytes'));
	echo sprintf("        photo: %s of AVIF for %s of JPEG (-%d%%)\n", size_format($bytes), size_format($src), round(($src - $bytes) / $src * 100));

	check('animated GIF left alone', !empty(Index::get($anim_id)['anim']) && !glob(dir_of($anim_id) . '/anim*.avif'));
	$gif_data = Renderer::sources($anim_id, []);
	check('animated GIF served as the original, no srcset', $gif_data['srcset'] === '' && str_ends_with($gif_data['src'], 'anim.gif'), $gif_data['src']);

	section('Markup');
	$html = render('{{ m.image(img, { sizes: "(min-width: 64rem) 50vw, 100vw" }) }}', ['img' => $img]);
	check('<source type="image/avif">', str_contains($html, '<source type="image/avif"'));
	check('lazy images get sizes="auto, …"', str_contains($html, 'sizes="auto, (min-width: 64rem) 50vw, 100vw"'));
	check('the modern srcset lists every width', substr_count(Renderer::sources($img, [])['modern']['srcset'], '.avif ') === 9);
	$atf = render('{{ m.image(img, { sizes: "100vw", atf: true }) }}', ['img' => $img]);
	check('above the fold: no auto, fetchpriority', str_contains($atf, 'sizes="100vw"') && str_contains($atf, 'fetchpriority="high"') && !str_contains($atf, 'loading='));
	check('image.avif works on Timber 2', str_ends_with(render('{{ img.avif }}', ['img' => $img]), 'photo-scaled.jpg.avif'), render('{{ img.avif }}', ['img' => $img]));
	check('image.best works', str_ends_with(render('{{ img.best }}', ['img' => $img]), '.avif'));
	check('|best_src(1280, 720) takes the 16x9 path', str_contains(render('{{ img|best_src(1280, 720) }}', ['img' => $img]), 'photo'));
	check('a plain URL passes through', Renderer::sources('https://example.com/a.jpg', [])['src'] === 'https://example.com/a.jpg');

	$t = microtime(true);
	for ($i = 0; $i < 500; $i++) Renderer::sources($img, ['sizes' => '100vw']);
	echo sprintf("        %.3f ms per image_sources() call\n", (microtime(true) - $t) * 1000 / 500);

	section('Partial sets are never emitted');
	$broken = $index;
	unset($broken['avif']['photo-480x320.jpg']);
	Index::put($id, $broken);
	check('one missing entry, no <source>', Renderer::sources($id, [])['modern'] === null);
	Index::put($id, $index);

	section('Other sources');
	$logo_files = array_filter(array_column(Index::get($logo_id)['avif'] ?? [], 'file'));
	check('a PNG with transparency is converted', (bool) $logo_files, wp_json_encode(Index::get($logo_id)));
	if ($logo_files) {
		$alpha = new Imagick(dir_of($logo_id) . '/' . reset($logo_files));
		check('and keeps its alpha channel', (bool) $alpha->getImageAlphaChannel());
	}

	$webp = "$work/import.webp";
	$im = new Imagick($photo);
	$im->resizeImage(1600, 0, Imagick::FILTER_LANCZOS, 1);
	$im->setImageFormat('webp');
	$im->writeImage($webp);
	$webp_id = upload($webp);
	drain();
	$webp_files = array_filter(array_column(Index::get($webp_id)['avif'] ?? [], 'file'));
	check('a WebP original gets AVIF copies', in_array('import.webp.avif', $webp_files, true), implode(', ', $webp_files));

	section('Image uploaded before v7');
	$legacy_photo = "$work/legacy.jpg";
	$im = new Imagick($photo);
	$im->resizeImage(1600, 0, Imagick::FILTER_LANCZOS, 1);
	$im->writeImage($legacy_photo);
	$no_canonical = fn($sizes) => array_filter($sizes, fn($k) => !str_starts_with($k, 'tavif-'), ARRAY_FILTER_USE_KEY);
	add_filter('intermediate_image_sizes_advanced', $no_canonical, 99);
	$legacy_id = upload($legacy_photo);
	remove_filter('intermediate_image_sizes_advanced', $no_canonical, 99);

	$data = Renderer::sources($legacy_id, []);
	check('served from the core sizes meanwhile, medium included', str_contains($data['srcset'], ' 300w') && str_contains($data['srcset'], ' 1024w') && $data['modern'] === null, $data['srcset']);
	drain();
	$data = Renderer::sources($legacy_id, []);
	check('the worker builds the canonical sizes it lacked', str_contains($data['srcset'], '-1280x') && !str_contains($data['srcset'], ' 300w'), $data['srcset']);
	check('and then serves AVIF', $data['modern'] !== null);

	section('Edited in the media library');
	wp_update_attachment_metadata($legacy_id, wp_get_attachment_metadata($legacy_id));
	check('a metadata change puts the image back in the queue', get_post_meta($legacy_id, Index::STAMP, true) === '');
	check('its files keep being served', Renderer::sources($legacy_id, [])['modern'] !== null);
	drain();

	section('Settings change');
	$fp = Config::fingerprint();
	Config::save(['avif_quality' => 50] + Config::all());
	check('only the changed value is stored', get_option(Config::OPTION) === ['avif_quality' => 50], wp_json_encode(get_option(Config::OPTION)));
	check('the fingerprint moved', Config::fingerprint() !== $fp);
	check('the whole library is pending again', Worker::count_pending() === count($GLOBALS['tavif_it']['uploaded']), (string) Worker::count_pending());
	check('old files are still served meanwhile', Renderer::sources($id, [])['modern'] !== null);
	drain();
	$requality = array_sum(array_column(Index::get($id)['avif'], 'bytes'));
	check('re-encoded lighter at quality 50', $requality < $bytes, "$requality vs $bytes");

	section('Crop on request');
	$data = Renderer::sources($id, ['ratio' => '4/1']);
	check('first request registers the ratio', in_array('4x1', Index::ratios($id), true), implode(',', Index::ratios($id)));
	check('meanwhile: uncropped files, 4:1 box', (int) round($data['width'] / $data['height']) === 4 && !str_contains($data['src'], 'tavif'), $data['src'] . ' ' . $data['width'] . 'x' . $data['height']);
	check('the attachment is pending', Worker::count_pending() === 1);
	drain();
	$data = Renderer::sources($id, ['ratio' => '4/1']);
	check('crops built and served', str_contains($data['src'], '-tavif.jpg') && $data['modern'] !== null, $data['src']);
	check('the crop has the right shape', (function () use ($id) {
		$c = Index::get($id)['crops']['4x1'][0];
		$size = wp_getimagesize(dir_of($id) . '/' . $c['file']);
		return $size && abs($size[0] / $size[1] - 4) < 0.02;
	})());

	section('Deadline');
	Config::bump_generation();
	$partial = Worker::process($id, microtime(true) - 1);
	$left = array_filter(Index::get($id)['avif'], fn($e) => ($e['key'] ?? '') !== Config::encoding_key());
	check('an expired deadline still encodes one file, then stops', $partial === false && count($left) === count(Index::get($id)['avif']) - 1, count($left) . ' left');
	drain();
	check('the next pass finishes it', get_post_meta($id, Index::STAMP, true) === Config::fingerprint());

	section('Lock');
	check('first worker gets the lock', Lock::acquire('test', 60));
	check('second one does not', !Lock::acquire('test', 60));
	Lock::release('test');
	check('released', Lock::acquire('test', -1));
	check('an expired lock is taken over', Lock::acquire('test', 60));
	Lock::release('test');

	section('Migration from v6');
	update_option(Config::OPTION, ['avif_quality' => 75, 'webp_quality' => 85, 'pregenerate_widths' => '640,1024', 'max_inline_conversions' => 10, 'only_if_smaller' => true]);
	Config::migrate();
	check('defaults and obsolete keys dropped, choices kept', get_option(Config::OPTION) === ['webp_quality' => 85], wp_json_encode(get_option(Config::OPTION)));
	Config::reset();

	$dir = dir_of($id);
	file_put_contents("$dir/photo-640x427.avif", 'v6');
	copy("$dir/photo-640x427.jpg", "$dir/photo-640x0-c-default.jpg");
	file_put_contents("$dir/photo-640x0-c-default.avif", 'v6');
	file_put_contents("$dir/photo-640x427.avif.lock", '');
	$v7 = count(glob("$dir/*.jpg.avif"));
	$removed = Tools::purge_v6();
	check('v6 copies and locks removed', $removed === 3 && !file_exists("$dir/photo-640x427.avif") && !file_exists("$dir/photo-640x0-c-default.avif") && !file_exists("$dir/photo-640x427.avif.lock"), "$removed removed");
	@unlink("$dir/photo-640x0-c-default.jpg");
	check('v7 files untouched', count(glob("$dir/*.jpg.avif")) === $v7);

	section('Deletion');
	drain();
	$owned = Index::files($id, Index::get($id));
	check('the index knows its files', count($owned) > 9, count($owned) . ' files');
	wp_delete_attachment($id, true);
	check('deleting the attachment deletes every one of them', !array_filter($owned, 'file_exists'));
	$GLOBALS['tavif_it']['uploaded'] = array_diff($GLOBALS['tavif_it']['uploaded'], [$id]);

} catch (Throwable $e) {
	$GLOBALS['tavif_it']['failures']++;
	echo "\n  ERROR " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
	foreach ($GLOBALS['tavif_it']['uploaded'] as $leftover) wp_delete_attachment($leftover, true);
	Config::reset();
	array_map('unlink', glob("$work/*") ?: []);
	@rmdir($work);
}

['failures' => $failures, 'count' => $count] = $GLOBALS['tavif_it'];
echo $failures ? "\n$failures of $count checks failed.\n" : "\n$count checks passed.\n";
if ($failures) exit(1);
