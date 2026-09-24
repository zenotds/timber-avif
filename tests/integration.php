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
use TimberAVIF\Cache;
use TimberAVIF\Plugin;
use TimberAVIF\Migration\V6;

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
	// The fixtures' synthetic XMP makes exif_read_data() in WordPress core warn; real exports do not.
	set_error_handler(fn($no, $msg) => str_contains($msg, 'exif_read_data'), E_WARNING);
	$id = media_handle_sideload(['name' => basename($path), 'tmp_name' => $tmp], 0);
	restore_error_handler();
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

// WP Rocket's purge API, recorded instead of run — unless the real plugin is active here.
$GLOBALS['tavif_rocket'] = [];
if (!function_exists('rocket_clean_post')) {
	function rocket_clean_post($id) { $GLOBALS['tavif_rocket'][] = "post:$id"; return true; }
	function rocket_clean_domain($lang = '') { $GLOBALS['tavif_rocket'][] = 'domain'; return true; }
	function rocket_clean_files($urls) { foreach ((array) $urls as $u) $GLOBALS['tavif_rocket'][] = "url:$u"; }
}
function purged(): array { return $GLOBALS['tavif_rocket']; }

function solid(string $path, int $w, int $h, string $color): string {
	$im = new Imagick();
	$im->newImage($w, $h, $color);
	$im->setImageFormat('jpeg');
	$im->writeImage($path);
	return $path;
}
function colour_of(string $path): string {
	$c = (new Imagick($path))->getImagePixelColor(5, 5)->getColor();
	return $c['r'] > $c['b'] ? 'red' : 'blue';
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
	$im->profileImage('xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/" dc:creator="tavif-test"/></rdf:RDF></x:xmpmeta>');
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
	check('and nothing else: no EXIF or XMP in the copies', array_diff($avif->getImageProfiles('*', false), ['icc']) === [], implode(',', $avif->getImageProfiles('*', false)));
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
	check('Timber\'s image class is left alone', get_class($img) === 'Timber\\Image', get_class($img));
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
	// A resize a template made with Timber's |resize, before the worker adds the canonical sizes.
	$timber_url = \Timber\ImageHelper::resize(wp_get_attachment_url($legacy_id), 333, 0);
	$timber_file = dir_of($legacy_id) . '/' . wp_basename($timber_url);
	drain();
	check('adding the canonical sizes leaves Timber\'s resizes alone', is_file($timber_file), $timber_file);
	check('and Timber\'s own hook is back afterwards', has_filter('wp_generate_attachment_metadata', ['Timber\\ImageHelper', 'generate_attachment_metadata']) !== false);
	@unlink($timber_file);
	$data = Renderer::sources($legacy_id, []);
	check('the worker builds the canonical sizes it lacked', str_contains($data['srcset'], '-1280x') && !str_contains($data['srcset'], ' 300w'), $data['srcset']);
	check('and then serves AVIF', $data['modern'] !== null);

	section('Edited in the media library');
	wp_update_attachment_metadata($legacy_id, wp_get_attachment_metadata($legacy_id));
	check('a metadata change puts the image back in the queue', get_post_meta($legacy_id, Index::STAMP, true) === '');
	check('its files keep being served', Renderer::sources($legacy_id, [])['modern'] !== null);
	drain();

	section('Edited in the media modal');
	require_once ABSPATH . 'wp-admin/includes/image-edit.php';
	wp_set_current_user(1);
	$edit_src = "$work/edit.jpg";
	$im = new Imagick();
	$im->newImage(3000, 2000, 'blue');
	$draw = new ImagickDraw();
	$draw->setFillColor('red');
	$draw->rectangle(0, 0, 1499, 1999);
	$im->drawImage($draw);
	$im->setImageFormat('jpeg');
	$im->writeImage($edit_src);
	$edit_id = upload($edit_src);
	drain();
	$_REQUEST = $_POST = ['history' => wp_json_encode([['r' => 90]]), 'target' => 'all', 'do' => 'save', 'context' => '', 'postid' => $edit_id];
	wp_save_image($edit_id);
	$_REQUEST = $_POST = [];
	drain();
	$mismatch = [];
	foreach (Index::get($edit_id)['avif'] ?? [] as $jpg => $e) {
		if (empty($e['file'])) continue;
		$a = wp_getimagesize(dir_of($edit_id) . '/' . $e['file']);
		$j = wp_getimagesize(dir_of($edit_id) . '/' . $jpg);
		if (!$a || !$j || abs($a[0] - $j[0]) > 1 || abs($a[1] - $j[1]) > 1) $mismatch[] = "$jpg {$j[0]}x{$j[1]} vs {$a[0]}x{$a[1]}";
	}
	check('a rotated image is encoded rotated, from the edited file', !$mismatch && count(Index::get($edit_id)['avif'] ?? []) > 3, implode('; ', $mismatch));
	$files = array_filter(array_column(Index::get($edit_id)['avif'] ?? [], 'file'));
	check('its copies are named after the edited files', $files && !array_filter($files, fn($f) => !str_contains($f, '-e1')), implode(', ', $files));
	$orphans = array_filter(glob(dir_of($edit_id) . '/edit-*.avif') ?: [], fn($f) => !str_contains($f, '-e1'));
	check('and the copies of the unedited file are gone', !$orphans, implode(', ', array_map('basename', $orphans)));

	section('Displayed small');
	Config::save(['breakpoint_widths' => '480,640,1024,1600'] + Config::all());
	$small_id = $edit_id;
	drain();
	$data = Renderer::sources($small_id, ['max' => 200]);
	check('max below every width: the smallest is served meanwhile', str_contains($data['srcset'], ' 480w') && substr_count($data['srcset'], 'w,') === 0, $data['srcset']);
	check('and the width is asked for', in_array(200, array_column(Index::wants($small_id), 'width'), true), wp_json_encode(Index::wants($small_id)));
	drain();
	$data = Renderer::sources($small_id, ['max' => 200]);
	check('once built it is the candidate, in AVIF too', str_contains($data['srcset'], ' 200w') && !str_contains($data['srcset'], ' 480w') && $data['modern'] && str_contains($data['modern']['srcset'], '-tavif.jpg.avif 200w'), $data['srcset'] . ' | ' . ($data['modern']['srcset'] ?? '-'));
	$sq = Renderer::sources($small_id, ['max' => 160, 'ratio' => '1/1']);
	$asked = array_map(fn($w) => $w['ratio'] . '@' . $w['width'], Index::wants($small_id));
	check('a small crop asks for the crop at that width, not for an uncropped one', in_array('1x1@160', $asked, true) && !in_array('@160', $asked, true), implode(', ', $asked));
	drain();
	$sq = Renderer::sources($small_id, ['max' => 160, 'ratio' => '1/1']);
	check('the same for a crop', str_contains($sq['srcset'], ' 160w') && $sq['modern'] !== null, $sq['srcset']);
	Config::reset();
	drain();

	section('Images in post content');
	$post_id = wp_insert_post(['post_title' => 'tavif', 'post_status' => 'publish', 'post_content' => '']);
	$content_id = $webp_id;
	$large = wp_get_attachment_image($content_id, 'large', false, ['class' => 'alignleft wp-image-' . $content_id]);
	$thumb = wp_get_attachment_image($content_id, 'thumbnail', false, ['class' => 'wp-image-' . $content_id]);
	$html = wp_filter_content_tags($large, 'the_content');
	check('an editor image gets a <picture> with the modern set', str_contains($html, '<picture class="tavif-content" style="display:contents"><source type="image/avif"') && str_contains($html, 'alignleft'), substr($html, 0, 300));
	check('its <img> is WordPress\'s own, untouched', str_contains($html, $large) || str_contains($html, 'class="alignleft wp-image-' . $content_id));
	check('a cropped thumbnail is left alone', !str_contains(wp_filter_content_tags($thumb, 'the_content'), '<picture'));
	Config::save(['content_images' => false] + Config::all());
	check('and the setting turns it off', !str_contains(wp_filter_content_tags($large, 'the_content'), '<picture'));
	Config::reset();
	wp_delete_post($post_id, true);

	section('Single URL: |best_src');
	check('no global TimberAVIF class', !class_exists('TimberAVIF', false));
	$webp_img = Timber::get_image($webp_id);
	$tiny = render('{{ img|best_src(96) }}', ['img' => $webp_img]);
	check('a width far below every candidate: the smallest meanwhile', str_contains($tiny, '-320x'), $tiny);
	check('and asked of the worker', in_array(96, array_column(Index::wants($webp_id), 'width'), true), wp_json_encode(Index::wants($webp_id)));
	render('{{ img|best_src(300) }}', ['img' => $webp_img]);
	check('a width close to the smallest asks for nothing', !in_array(300, array_column(Index::wants($webp_id), 'width'), true));
	drain();
	$tiny = render('{{ img|best_src(96) }}', ['img' => $webp_img]);
	check('once built, served at that width in AVIF', str_contains($tiny, '-96x') && str_ends_with($tiny, '.avif'), $tiny);

	section('Page cache');
	delete_transient(Cache::RECENT);
	delete_option(Cache::DEFERRED);
	$page = wp_insert_post(['post_title' => 'tavif cache', 'post_status' => 'publish', 'post_content' => '']);
	$cache_id = upload(solid("$work/cache.jpg", 1800, 1200, 'green'));
	set_post_thumbnail($page, $cache_id);
	$term = wp_insert_term('tavif-cache', 'category');
	update_term_meta($term['term_id'], 'header_image', (string) $cache_id);
	$GLOBALS['tavif_rocket'] = [];
	drain();
	check('once converted, the page that features it is purged', in_array("post:$page", purged(), true), implode(', ', purged()));
	check('and the category that shows it', in_array('url:' . get_term_link($term['term_id']), purged(), true), implode(', ', purged()));
	check('but not the whole cache', !in_array('domain', purged(), true));
	$GLOBALS['tavif_rocket'] = [];
	drain();
	check('nothing new, nothing purged', !purged(), implode(', ', purged()));

	update_option('options_footer_logo', (string) $cache_id);
	Worker::changed($cache_id);
	Worker::announce();
	check('used in an ACF options field: everything is purged', in_array('domain', purged(), true), implode(', ', purged()));
	$GLOBALS['tavif_rocket'] = [];
	Worker::changed($cache_id);
	Worker::announce();
	check('a second full purge within 15 minutes waits', !in_array('domain', purged(), true) && get_option(Cache::DEFERRED));
	do_action('timber_avif/idle');
	check('and runs when the queue empties', in_array('domain', purged(), true) && !get_option(Cache::DEFERRED));
	delete_option('options_footer_logo');
	wp_delete_term($term['term_id'], 'category');
	wp_delete_post($page, true);
	delete_transient(Cache::RECENT);

	section('Replaced in place');
	$rep_id = upload(solid("$work/replace.jpg", 2000, 1300, 'red'));
	Renderer::sources($rep_id, ['ratio' => '1/1']);
	drain();
	$crop = Renderer::sources($rep_id, ['ratio' => '1/1']);
	check('before: served in AVIF, crop included', Renderer::sources($rep_id)['modern'] && $crop['modern'] && str_contains($crop['src'], '-tavif.jpg'));
	// What an import or a sync does: a new picture written over the same file, metadata regenerated.
	$attached = get_attached_file($rep_id);
	solid($attached, 2000, 1300, 'blue');
	$announced = [];
	add_action('timber_avif/changed', function ($ids) use (&$announced) { $announced = array_merge($announced, $ids); });
	wp_update_attachment_metadata($rep_id, wp_generate_attachment_metadata($rep_id, $attached));
	check('its copies stop being served at once', Renderer::sources($rep_id)['modern'] === null);
	Worker::announce();
	check('and the change is announced, for the page cache', in_array($rep_id, $announced, true));
	drain();
	$data = Renderer::sources($rep_id);
	$modern_file = dir_of($rep_id) . '/' . wp_basename(explode(' ', explode(', ', (string) ($data['modern']['srcset'] ?? ''))[0])[0]);
	check('then the new picture is served in AVIF', $data['modern'] && is_file($modern_file) && colour_of($modern_file) === 'blue', $modern_file);
	$crop = Renderer::sources($rep_id, ['ratio' => '1/1']);
	$crop_file = dir_of($rep_id) . '/' . wp_basename($crop['src']);
	check('and the crop is remade from it', is_file($crop_file) && colour_of($crop_file) === 'blue', $crop_file);
	$on_disk = array_map('basename', array_merge(glob(dir_of($rep_id) . '/replace*.avif') ?: [], glob(dir_of($rep_id) . '/replace*-tavif.*') ?: []));
	$owned = array_map('basename', Index::files($rep_id, Index::get($rep_id)));
	check('no file of ours left that the index does not know', !array_diff($on_disk, $owned), implode(', ', array_diff($on_disk, $owned)));

	section('Settings change');
	$fp = Config::fingerprint();
	Config::save(['avif_quality' => 50] + Config::all());
	check('only the changed value is stored', get_option(Config::OPTION) === ['_v' => 7, 'avif_quality' => 50], wp_json_encode(get_option(Config::OPTION)));
	check('the fingerprint moved', Config::fingerprint() !== $fp);
	check('the whole library is pending again', Worker::count_pending() === count($GLOBALS['tavif_it']['uploaded']), (string) Worker::count_pending());
	check('old files are still served meanwhile', Renderer::sources($id, [])['modern'] !== null);
	drain();
	$requality = array_sum(array_column(Index::get($id)['avif'], 'bytes'));
	check('re-encoded lighter at quality 50', $requality < $bytes, "$requality vs $bytes");

	section('Crop on request');
	$data = Renderer::sources($id, ['ratio' => '4/1']);
	check('first request registers the ratio', in_array('4x1', array_column(Index::wants($id), 'ratio'), true), wp_json_encode(Index::wants($id)));
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
	// As v6 left it: every default written on first run, 65 from 6.0, one real choice.
	update_option(Config::OPTION, ['avif_quality' => 65, 'webp_quality' => 85, 'jpeg_quality' => 95, 'pregenerate_widths' => '640,1024', 'max_inline_conversions' => 10, 'only_if_smaller' => true]);
	check('before migrating, v6 frozen defaults already read as defaults', Config::quality('avif') === 75 && (int) Config::get('jpeg_quality') === 82 && Config::quality('webp') === 85);
	$fp_v6 = Config::fingerprint();
	Config::migrate();
	check('defaults, v6 defaults and obsolete keys dropped, choices kept', get_option(Config::OPTION) === ['_v' => 7, 'webp_quality' => 85], wp_json_encode(get_option(Config::OPTION)));
	check('same fingerprint before and after: nothing re-encoded at the switch', Config::fingerprint() === $fp_v6);
	Config::save(['jpeg_quality' => 95] + Config::all());
	check('a value chosen in v7 that equals an old v6 default is kept', (int) Config::get('jpeg_quality') === 95);
	Config::reset();

	$dir = dir_of($id);
	file_put_contents("$dir/photo-640x427.avif", 'v6');
	copy("$dir/photo-640x427.jpg", "$dir/photo-640x0-c-default.jpg");
	file_put_contents("$dir/photo-640x0-c-default.avif", 'v6');
	file_put_contents("$dir/photo-640x427.avif.lock", '');
	$v7 = count(glob("$dir/*.jpg.avif"));

	// Taking over from a site where v6 ran: its option without v7's marker, its hourly cron.
	update_option(Config::OPTION, ['avif_quality' => 75, 'jpeg_quality' => 95, 'max_inline_conversions' => 10]);
	wp_schedule_event(time(), 'hourly', 'timber_avif_process_queue');
	$GLOBALS['tavif_rocket'] = [];
	V6::take_over();
	check('taking over: settings stored the v7 way, v6\'s cron gone', get_option(Config::OPTION) === ['_v' => 7] && !wp_next_scheduled('timber_avif_process_queue'), wp_json_encode(get_option(Config::OPTION)));
	check('the page cache is purged: its pages point at v6\'s files', in_array('domain', purged(), true));
	check('Tools offer to remove v6\'s files', (bool) get_option(Plugin::V6_LEFTOVERS));
	delete_option(Plugin::V6_LEFTOVERS);
	V6::take_over();
	check('a site without v6 traces: taking over does nothing', !get_option(Plugin::V6_LEFTOVERS));
	update_option(Plugin::V6_LEFTOVERS, 1);
	Config::reset();

	$removed = V6::purge();
	check('v6 copies and locks removed', $removed === 3 && !file_exists("$dir/photo-640x427.avif") && !file_exists("$dir/photo-640x0-c-default.avif") && !file_exists("$dir/photo-640x427.avif.lock"), "$removed removed");
	@unlink("$dir/photo-640x0-c-default.jpg");
	check('v7 files untouched', count(glob("$dir/*.jpg.avif")) === $v7);
	check('once they are gone, so is the tool', !get_option(Plugin::V6_LEFTOVERS));

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
