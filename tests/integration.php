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
use TimberAVIF\Admin;
use TimberAVIF\Config;
use TimberAVIF\Engine;
use TimberAVIF\Index;
use TimberAVIF\Lock;
use TimberAVIF\Optimize;
use TimberAVIF\Renderer;
use TimberAVIF\Server;
use TimberAVIF\Tools;
use TimberAVIF\Worker;
use TimberAVIF\Cache;

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

	// WordPress's own pick for fetchpriority="high": the first large image it handles, while its flag is free.
	$large = ['width' => 1200, 'height' => 800];
	wp_high_priority_element_flag(true);
	render('{{ m.image(img, { sizes: "100vw" }) }}', ['img' => $img]);
	check('a lazy image leaves WordPress\'s pick alone', (wp_maybe_add_fetchpriority_high_attr([], 'img', $large)['fetchpriority'] ?? '') === 'high');
	wp_high_priority_element_flag(true);
	render('{{ m.image(img, { sizes: "100vw", atf: true }) }}', ['img' => $img]);
	check('an atf image takes the place: WordPress adds no second fetchpriority', !isset(wp_maybe_add_fetchpriority_high_attr([], 'img', $large)['fetchpriority']));
	wp_high_priority_element_flag(true);
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

	section('A crop to the proportions the image has');
	// A pixel wider than square: WordPress refuses that crop with either editor. At exactly
	// 1200×1200 its resize() returns early; GD's _resize(), which the worker calls, does not.
	$square_id = upload(solid("$work/square.jpg", 1201, 1200, 'green'));
	// As one rendered before 7.0.5 left it: a 1x1 request, whose widest crop is the image itself.
	add_post_meta($square_id, Index::WANT, '1x1');
	$sq = Renderer::sources($square_id, ['ratio' => '1/1']);
	check('asked as 1/1, a square image is served uncropped', !str_contains($sq['src'] . $sq['srcset'], '-tavif') && abs($sq['width'] / $sq['height'] - 1) < 0.01, $sq['src']);
	check('and asks for no crop', array_column(Index::wants($square_id), 'ratio') === ['1x1']);
	drain();
	$sq = Renderer::sources($square_id, ['ratio' => '1/1']);
	check('converted without an issue', $sq['modern'] !== null && !get_post_meta($square_id, Index::ISSUE, true), wp_json_encode(get_post_meta($square_id, Index::ISSUE, true)));
	check('no crop written: they would only repeat the uncropped files', !glob(dir_of($square_id) . '/square-*-tavif.*'));
	check('|best_src at its own proportions is uncropped too', !str_contains(Renderer::url($square_id, 600, 600), '-tavif'));

	section('Deadline');
	Config::bump_generation();
	$partial = Worker::process($id, microtime(true) - 1);
	$left = array_filter(Index::get($id)['avif'], fn($e) => ($e['key'] ?? '') !== Config::encoding_key());
	check('an expired deadline still encodes one file, then stops', $partial === false && count($left) === count(Index::get($id)['avif']) - 1, count($left) . ' left');
	drain();
	check('the next pass finishes it', get_post_meta($id, Index::STAMP, true) === Config::fingerprint());

	section('The worker carries on by itself');
	// The loopback is recorded instead of sent, and wp_die() throws instead of exiting.
	$loopbacks = [];
	$capture = function ($pre, $args, $url) use (&$loopbacks) {
		if (($args['body']['action'] ?? '') !== Worker::RELAY) return $pre;
		$loopbacks[] = ['url' => $url, 'args' => $args];
		return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
	};
	add_filter('pre_http_request', $capture, 10, 3);
	$die = fn() => function ($message, $title, $args) { throw new RuntimeException('wp_die ' . (int) ($args['response'] ?? 0)); };
	add_filter('wp_die_handler', $die, PHP_INT_MAX);
	$arrive = function (string $token) {
		$_POST = ['action' => Worker::RELAY, 'token' => $token];
		try { Worker::on_relay(); } catch (RuntimeException $e) { return $e->getMessage(); } finally { $_POST = []; }
		return '';
	};
	delete_option(Worker::RELAY);
	$relay_ids = [upload(solid("$work/relay-a.jpg", 2000, 1300, 'red')), upload(solid("$work/relay-b.jpg", 2000, 1300, 'blue'))];

	Worker::run(-1, true);
	check('a pass that converted nothing starts no other', !$loopbacks);
	Worker::run(0.01, true);
	$sent = $loopbacks[0] ?? null;
	check('a pass that leaves work pending starts the next one: a loopback, not waited for', $sent && str_ends_with($sent['url'], '/wp-admin/admin-ajax.php') && $sent['args']['blocking'] === false, wp_json_encode($sent['args'] ?? null));
	$token = (string) ($sent['args']['body']['token'] ?? '');
	check('with the token it stored', $token !== '' && (get_option(Worker::RELAY)['token'] ?? '') === $token);
	check('while it travels, nothing is known about loopbacks', Worker::relay_works() === null);
	check('a wrong token is turned away', $arrive('nope') === 'wp_die 403' && (get_option(Worker::RELAY)['token'] ?? '') === $token);
	$loopbacks = [];
	check('the right one runs a pass', $arrive($token) === 'wp_die 200' && Worker::count_pending() === 0, (string) Worker::count_pending());
	check('and is used up: arrived once, loopbacks work', Worker::relay_works() === true && $arrive($token) === 'wp_die 403');
	check('with the queue empty, no pass follows', !$loopbacks);

	foreach ($relay_ids as $relay_id) Worker::stale($relay_id);
	set_transient(Worker::DRIVEN, 1, 30);
	wp_clear_scheduled_hook(Worker::HOOK);
	Worker::run(0.01, true);
	check('while Process now drives, a pass starts no other', !$loopbacks);
	$pending = Worker::count_pending();
	// WP-Cron unschedules an event before running it.
	wp_clear_scheduled_hook(Worker::HOOK);
	Worker::on_cron();
	check('and WP-Cron looks again a minute later instead of taking the lock', Worker::count_pending() === $pending && wp_next_scheduled(Worker::HOOK) >= time() + 55);
	delete_transient(Worker::DRIVEN);
	wp_clear_scheduled_hook(Worker::HOOK);

	update_option(Worker::RELAY, ['token' => 'lost', 'at' => time() - Worker::RELAY_LOST - 1], false);
	check('a token never collected: the site cannot reach itself', Worker::relay_works() === false);
	check('and it has expired', $arrive('lost') === 'wp_die 403');
	drain();
	delete_option(Worker::RELAY);
	remove_filter('pre_http_request', $capture, 10);
	remove_filter('wp_die_handler', $die, PHP_INT_MAX);
	foreach ($relay_ids as $relay_id) wp_delete_attachment($relay_id, true);
	$GLOBALS['tavif_it']['uploaded'] = array_diff($GLOBALS['tavif_it']['uploaded'], $relay_ids);

	section('Lock');
	check('first worker gets the lock', Lock::acquire('test', 60));
	check('second one does not', !Lock::acquire('test', 60));
	Lock::release('test');
	check('released', Lock::acquire('test', -1));
	check('an expired lock is taken over', Lock::acquire('test', 60));
	Lock::release('test');

	section('Translations of one file (WPML, Polylang)');
	$it_id = upload(solid("$work/translated.jpg", 1800, 1200, 'red'));
	Renderer::sources($it_id, ['ratio' => '1/1']);
	drain();
	// What WPML makes for a translation: a second attachment with the same file and metadata.
	$en_id = wp_insert_attachment(['post_title' => 'translated (en)', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], get_attached_file($it_id));
	$GLOBALS['tavif_it']['uploaded'][] = $en_id;
	update_post_meta($en_id, '_wp_attachment_metadata', wp_get_attachment_metadata($it_id));
	check('the two share the file', get_attached_file($en_id) === get_attached_file($it_id) && Index::twins($en_id) === [$it_id]);
	Renderer::sources($en_id, ['ratio' => '1/1']);
	$shared_files = Index::files($it_id, Index::get($it_id));
	$inodes = array_map('fileinode', $shared_files);
	drain();
	clearstatcache();
	check('the translation is served in AVIF, crop included', Renderer::sources($en_id)['modern'] && Renderer::sources($en_id, ['ratio' => '1/1'])['modern']);
	check('from the copies the other language made: nothing encoded twice', $inodes === array_map('fileinode', $shared_files));
	// Every language of a file fails together: Issues counts and lists the file once, whatever the admin language.
	$issue_count = new ReflectionMethod(Admin::class, 'issue_count');
	$render_issues = new ReflectionMethod(Admin::class, 'render_issues');
	$before = $issue_count->invoke(null);
	foreach ([$it_id, $en_id] as $twin) update_post_meta($twin, Index::ISSUE, ['text' => 'test', 'at' => time()]);
	ob_start();
	$render_issues->invoke(null);
	$issues_html = (string) ob_get_clean();
	check('Issues counts and lists a file once, not once per language', $issue_count->invoke(null) === $before + 1 && substr_count($issues_html, '>' . wp_basename(get_attached_file($it_id)) . '</a>') === 1);
	foreach ([$it_id, $en_id] as $twin) delete_post_meta($twin, Index::ISSUE);
	// WPML keeps the JPEGs another language still uses; this stands in for its wp_delete_file filter.
	add_filter('wp_delete_file', '__return_false', PHP_INT_MAX);
	wp_delete_attachment($en_id, true);
	remove_filter('wp_delete_file', '__return_false', PHP_INT_MAX);
	$GLOBALS['tavif_it']['uploaded'] = array_diff($GLOBALS['tavif_it']['uploaded'], [$en_id]);
	clearstatcache();
	check('deleting one language keeps the copies the other serves', count(array_filter($shared_files, 'file_exists')) === count($shared_files), count(array_filter($shared_files, 'file_exists')) . ' of ' . count($shared_files));
	wp_delete_attachment($it_id, true);
	$GLOBALS['tavif_it']['uploaded'] = array_diff($GLOBALS['tavif_it']['uploaded'], [$it_id]);
	check('deleting the last one deletes them', !array_filter($shared_files, 'file_exists'));

	section('Optimize');
	if (!function_exists('acf_add_local_field_group')) {
		echo "  skipped: ACF is not active\n";
	} else {
		// A theme's worth of templates, in a location of their own.
		$views = "$work/views";
		$tpl = [
			'macros.twig' => "{% macro image(image, options = {}) %}{% import '@timber-avif/macros.twig' as tavif %}{{ tavif.image(image, options) }}{% endmacro %}",
			'page.twig' => "{% import 'macros.twig' as macros %}{% include 'header.twig' with { header: post.header } %}{% for content in post.meta('content') %}{% include 'blocks/block-' ~ content.acf_fc_layout ~ '.twig' %}{% endfor %}{% include 'footer.twig' %}",
			'header.twig' => "{% import 'macros.twig' as macros %}{% if header.cover %}{{ macros.image(get_image(header.cover), { sizes: '(min-width: 64rem) 50vw, 100vw' }) }}{% endif %}",
			'blocks/block-cards.twig' => "{% import 'macros.twig' as macros %}{% for card in content.cards %}{% set cover = card.cover ? get_image(card.cover) : null %}{{ macros.image(cover, { sizes: (loop.length > 2 ? '(min-width: 64rem) 300px' : '(min-width: 64rem) 700px') ~ ', (min-width: 40rem) 50vw, calc(100vw - 2rem)' }) }}{% endfor %}",
			'blocks/block-hero.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(get_image(content.image), { sizes: '100vw', atf: true }) }}",
			'blocks/block-logos.twig' => "{% import 'macros.twig' as macros %}{% for logo in content.logos %}{{ macros.image(logo, { sizes: '120px', max: 240 }) }}{% endfor %}",
			'footer.twig' => "{% import 'macros.twig' as macros %}{{ macros.image(get_image(options.logo), { sizes: '200px' }) }}",
			'archive.twig' => "{% import 'macros.twig' as macros %}{% for post in posts %}{{ macros.image(post.thumbnail, { sizes: '400px' }) }}{% endfor %}",
		];
		foreach ($tpl as $name => $source) {
			wp_mkdir_p(dirname("$views/$name"));
			file_put_contents("$views/$name", $source);
		}
		$location = function ($paths) use ($views) { $paths['__main__'][] = $views; return $paths; };
		add_filter('timber/locations', $location);

		acf_add_local_field_group(['key' => 'group_tavif', 'title' => 'tavif', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]], 'fields' => [
			['key' => 'field_tavif_header', 'name' => 'header', 'type' => 'group', 'sub_fields' => [
				['key' => 'field_tavif_header_cover', 'name' => 'cover', 'type' => 'image'],
			]],
			['key' => 'field_tavif_content', 'name' => 'content', 'type' => 'flexible_content', 'layouts' => [
				'l_cards' => ['key' => 'l_cards', 'name' => 'cards', 'sub_fields' => [
					['key' => 'field_tavif_cards', 'name' => 'cards', 'type' => 'repeater', 'sub_fields' => [['key' => 'field_tavif_card_cover', 'name' => 'cover', 'type' => 'image']]],
				]],
				'l_hero' => ['key' => 'l_hero', 'name' => 'hero', 'sub_fields' => [['key' => 'field_tavif_hero_image', 'name' => 'image', 'type' => 'image']]],
				'l_logos' => ['key' => 'l_logos', 'name' => 'logos', 'sub_fields' => [['key' => 'field_tavif_logos', 'name' => 'logos', 'type' => 'gallery']]],
			]],
			['key' => 'field_tavif_secret', 'name' => 'secret', 'type' => 'image'],
		]]);
		acf_add_local_field_group(['key' => 'group_tavif_options', 'title' => 'tavif options', 'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'tavif']]], 'fields' => [
			['key' => 'field_tavif_logo', 'name' => 'logo', 'type' => 'image'],
		]]);

		$opt = [];
		foreach (['card', 'card2', 'hero', 'cover', 'logo_a', 'site_logo', 'featured', 'unused', 'content', 'plugin', 'secret'] as $i => $name) {
			// The second card also gets a theme size as wide as tavif-1920: one file, two names.
			if ($name === 'card2') add_image_size('opt-hero', 1920, 0);
			$opt[$name] = upload(solid("$work/opt-$name.jpg", 3000, 2000, ['red', 'blue', 'green', 'orange', 'purple', 'gray', 'yellow', 'pink', 'brown', 'navy', 'teal'][$i]));
			if ($name === 'card2') remove_image_size('opt-hero');
		}
		$page = wp_insert_post(['post_title' => 'tavif optimize', 'post_type' => 'page', 'post_status' => 'publish',
			'post_content' => '<img class="wp-image-' . $opt['content'] . '" src="' . wp_get_attachment_url($opt['content']) . '">']);
		update_field('field_tavif_header', ['field_tavif_header_cover' => $opt['cover']], $page);
		update_field('field_tavif_content', [
			['acf_fc_layout' => 'cards', 'field_tavif_cards' => [['field_tavif_card_cover' => $opt['card']], ['field_tavif_card_cover' => $opt['card2']]]],
			['acf_fc_layout' => 'hero', 'field_tavif_hero_image' => $opt['hero']],
			['acf_fc_layout' => 'logos', 'field_tavif_logos' => [$opt['logo_a']]],
		], $page);
		update_field('field_tavif_secret', $opt['secret'], $page);
		update_field('field_tavif_logo', $opt['site_logo'], 'option');
		$blog = wp_insert_post(['post_title' => 'tavif post', 'post_status' => 'publish']);
		set_post_thumbnail($blog, $opt['featured']);
		update_post_meta($blog, 'some_plugin_image', (string) $opt['plugin']);
		drain();

		$card = $opt['card'];
		$dir = dir_of($card);
		// What an older version, a crash and Timber leave next to the files.
		$orphans = ["$dir/opt-card-640x427.avif.lock", "$dir/gone-640x427.jpg.avif", "$dir/opt-card-640x427.jpg.tavif-Ab12Cd.avif"];
		foreach ($orphans as $path) file_put_contents($path, 'x');
		copy("$dir/opt-card-640x427.jpg", $timber_resize = "$dir/opt-card-640x0-c-default.jpg");
		// |towebp's name, and the one an older version gave its copies.
		file_put_contents($towebp = "$dir/opt-card-640x427.webp", 'x');

		Optimize::start();
		do { $job = Optimize::analyse(30); } while ($job['phase'] !== 'done');
		$caps = fn($id) => $job['plan'][$id]['caps'] ?? null;
		check('the templates are read: no call left untraced', empty($job['untraced']), wp_json_encode($job['untraced'] ?? []));
		check('cards: the wider of the two sizes a ternary gives, 700px at 2x, up to 1600', $caps($card) === ['' => 1600], wp_json_encode($caps($card)));
		check('a hero at 100vw keeps every width', !isset($job['plan'][$opt['hero']]) && ($job['images']['kept']['whole'] ?? 0) >= 2);
		check('a logo in a gallery shown at 120px: not below 1024', $caps($opt['logo_a']) === ['' => 1024], wp_json_encode($caps($opt['logo_a'])));
		check('the options logo, through the options variable', $caps($opt['site_logo']) === ['' => 1024], wp_json_encode($caps($opt['site_logo'])));
		check('a featured image shown by a loop over posts', $caps($opt['featured']) === ['' => 1024], wp_json_encode($caps($opt['featured'])));
		check('an image used nowhere keeps up to 1024', $caps($opt['unused']) === ['' => 1024] && $job['images']['unused'] >= 1);
		check('an image in post content keeps everything', !isset($job['plan'][$opt['content']]) && ($job['images']['kept']['content'] ?? 0) >= 1);
		check('an image in another plugin\'s meta keeps everything', !isset($job['plan'][$opt['plugin']]) && ($job['images']['kept']['other'] ?? 0) >= 1);
		check('an image in a field no template shows keeps everything', !isset($job['plan'][$opt['secret']]) && isset($job['unmatched']['post|secret']), wp_json_encode($job['unmatched'] ?? []));
		check('orphans counted, what Timber made apart', $job['totals']['orphans']['files'] >= 3 && $job['totals']['timber']['files'] >= 2, wp_json_encode($job['totals']));
		check('a dry run deletes nothing', is_file("$dir/opt-card-1920x1280.jpg") && is_file("$dir/opt-card-scaled.jpg.avif") && !Index::caps($card));

		$GLOBALS['tavif_rocket'] = [];
		$stamp = get_post_meta($card, Index::STAMP, true);
		$announced = [];
		$listen = function ($ids) use (&$announced) { $announced = array_merge($announced, $ids); };
		add_action('timber_avif/changed', $listen);
		do { $job = Optimize::apply(30); } while (empty($job['applied']));
		remove_action('timber_avif/changed', $listen);
		check('each step announces the images it changed, for any page cache', in_array($card, $announced, true) && in_array($opt['unused'], $announced, true));
		$c2 = wp_get_attachment_metadata($opt['card2']);
		check('a file another size shares stays, under that name', isset($c2['sizes']['opt-hero']) && !isset($c2['sizes']['tavif-1920']) && is_file(dir_of($opt['card2']) . '/' . $c2['sizes']['opt-hero']['file']));
		check('its modern copy goes', !is_file(dir_of($opt['card2']) . '/' . $c2['sizes']['opt-hero']['file'] . '.avif'));
		clearstatcache();
		check('past the cap: the JPEG, its copy and the full-size copy go', !is_file("$dir/opt-card-1920x1280.jpg") && !is_file("$dir/opt-card-1920x1280.jpg.avif") && !is_file("$dir/opt-card-scaled.jpg.avif"));
		check('up to it, everything stays, and the original', is_file("$dir/opt-card-1600x1067.jpg") && is_file("$dir/opt-card-1600x1067.jpg.avif") && is_file("$dir/opt-card-scaled.jpg"));
		check('WordPress\'s own sizes stay', is_file("$dir/opt-card-2048x1365.jpg") && is_file("$dir/opt-card-1536x1024.jpg"));
		check('the metadata no longer lists them', !isset(wp_get_attachment_metadata($card)['sizes']['tavif-1920']) && isset(wp_get_attachment_metadata($card)['sizes']['tavif-1600']));
		check('the cap is recorded, and nothing is queued again', Index::caps($card) === ['' => 1600] && get_post_meta($card, Index::STAMP, true) === $stamp);
		$data = Renderer::sources($card, ['sizes' => '(min-width: 64rem) 700px, (min-width: 40rem) 50vw, calc(100vw - 2rem)']);
		check('served up to 1600, in AVIF, complete', $data['modern'] && str_contains($data['modern']['srcset'], ' 1600w') && !str_contains($data['modern']['srcset'], ' 1920w') && !str_contains($data['srcset'], ' 1920w') && !str_contains($data['srcset'], ' 2048w'), $data['srcset']);
		check('and that asks for nothing', Index::needs($card) === []);
		check('orphans deleted, what Timber made kept', !array_filter($orphans, 'file_exists') && is_file($timber_resize) && is_file($towebp));
		check('and the page cache purged', in_array('domain', purged(), true));
		check('the other images are untouched', is_file(dir_of($opt['hero']) . '/opt-hero-scaled.jpg.avif') && is_file(dir_of($opt['content']) . '/opt-content-1920x1280.jpg.avif'));
		drain();
		check('the worker makes nothing past the cap', !is_file("$dir/opt-card-1920x1280.jpg") && !is_file("$dir/opt-card-1920x1280.jpg.avif"));
		Config::bump_generation();
		drain();
		check('not even when the library is re-encoded', !is_file("$dir/opt-card-1920x1280.jpg.avif") && !is_file("$dir/opt-card-2048x1365.jpg.avif") && is_file("$dir/opt-card-1600x1067.jpg.avif"));

		// The same image, now in a hero.
		Renderer::sources($card, ['sizes' => '100vw']);
		check('shown wider: the need is recorded and the image queued', Index::needs($card) !== [] && get_post_meta($card, Index::STAMP, true) === '');
		check('meanwhile the capped set is served, still in AVIF', Renderer::sources($card, ['sizes' => '100vw'])['modern'] !== null);
		drain();
		$data = Renderer::sources($card, ['sizes' => '100vw']);
		check('then every width is built again and the cap is gone', !Index::caps($card) && is_file("$dir/opt-card-1920x1280.jpg") && str_contains($data['modern']['srcset'] ?? '', ' 2560w'), $data['modern']['srcset'] ?? '-');

		check('the macro\'s sizes, within the cap, records nothing', (function () use ($opt) {
			render('{{ m.image(img, { sizes: "120px", max: 240 }) }}', ['img' => $opt['logo_a']]);
			return Index::needs($opt['logo_a']) === [];
		})());
		check('a call that says nothing of its width may need every one', (function () use ($opt) {
			Renderer::sources($opt['site_logo']);
			return array_column(Index::needs($opt['site_logo']), 'pixels') === [\TimberAVIF\Need::WHOLE];
		})());
		check('|best_src wider than the cap records the need', (function () use ($opt) {
			Renderer::url($opt['logo_a'], 1920);
			return Index::needs($opt['logo_a']) !== [];
		})());

		// A render finding the image too small while the worker is on it: the need waits for the next pass.
		$mid = $opt['unused'];
		Worker::stale($mid);
		$inject = function ($meta_id, $object_id, $key) use ($mid) {
			if ((int) $object_id === $mid && $key === Index::ATTEMPTS) Index::need($mid, '', 1500);
		};
		add_action('added_post_meta', $inject, 10, 3);
		Worker::process($mid, microtime(true) + 60);
		remove_action('added_post_meta', $inject, 10);
		check('a need recorded during the pass keeps the image pending', get_post_meta($mid, Index::STAMP, true) === '' && Index::needs($mid) !== []);
		drain();
		check('and the next pass raises the cap', Index::caps($mid) === ['' => 1600] && Index::needs($mid) === [], wp_json_encode(Index::caps($mid)));

		// A translation of a capped file: raised together.
		$twin = wp_insert_attachment(['post_title' => 'twin', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], get_attached_file($opt['featured']));
		$GLOBALS['tavif_it']['uploaded'][] = $twin;
		update_post_meta($twin, '_wp_attachment_metadata', wp_get_attachment_metadata($opt['featured']));
		Index::put_caps($twin, Index::caps($opt['featured']));
		update_post_meta($twin, Index::STAMP, Config::fingerprint());
		Renderer::sources($opt['featured'], ['sizes' => '100vw']);
		drain();
		check('raising one language\'s cap raises the other\'s, and builds it', !Index::caps($opt['featured']) && !Index::caps($twin) && Renderer::sources($twin, ['sizes' => '100vw'])['modern'] !== null);

		$was = Optimize::capped();
		check('Remove the limits: every capped image is queued', Optimize::reset() === $was && !Optimize::capped() && Worker::count_pending() >= $was - 1);
		drain();
		check('and gets every width back', is_file(dir_of($opt['unused']) . '/opt-unused-1920x1280.jpg') && is_file(dir_of($opt['unused']) . '/opt-unused-scaled.jpg.avif'));

		// Another plugin writing its own copies next to the originals: those are its files.
		$ewww = $opt['hero'];
		$edir = dir_of($ewww);
		file_put_contents($theirs = "$edir/opt-hero-640x427.jpg.webp", 'x');
		file_put_contents($theirs_v = "$edir/opt-hero-640x427.webp", 'x');
		file_put_contents($lost = "$edir/deleted-photo-640x427.jpg.webp", 'x');
		if (!defined('EWWW_IMAGE_OPTIMIZER_VERSION')) define('EWWW_IMAGE_OPTIMIZER_VERSION', 'test');
		$found = array_map('basename', \TimberAVIF\Optimize\Orphans::scan($edir)['orphans']['files']);
		check('another converter active: its copies of existing files are left alone', !in_array(basename($theirs), $found, true) && !in_array(basename($theirs_v), $found, true));
		check('a copy whose original is gone is still an orphan', in_array(basename($lost), $found, true), implode(', ', $found));
		array_map('unlink', [$theirs, $theirs_v, $lost]);

		@unlink($timber_resize);
		@unlink($towebp);
		remove_filter('timber/locations', $location);
		delete_field('field_tavif_logo', 'option');
		wp_delete_post($page, true);
		wp_delete_post($blog, true);
		Optimize::discard();
		foreach (array_keys($tpl) as $name) @unlink("$views/$name");
		@rmdir("$views/blocks");
		@rmdir($views);
	}

	section('Server type');
	$htaccess = get_home_path() . '.htaccess';
	$had = is_file($htaccess) ? file_get_contents($htaccess) : null;
	file_put_contents($htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n");
	$was_apache = $GLOBALS['is_apache'] ?? false;
	$GLOBALS['is_apache'] = true;
	check('on Apache the type of .avif goes in .htaccess', Server::ensure() && str_contains(file_get_contents($htaccess), 'AddType image/avif .avif'));
	$written = file_get_contents($htaccess);
	Server::ensure();
	check('once, next to what was there', file_get_contents($htaccess) === $written && str_contains($written, "# BEGIN WordPress\nRewriteEngine On"));
	Server::remove();
	check('removed with the theme', !str_contains(file_get_contents($htaccess), 'AddType'));
	$GLOBALS['is_apache'] = false;
	file_put_contents($htaccess, "# BEGIN WordPress\n# END WordPress\n");
	check('on other servers nothing is written', !Server::ensure() && !str_contains(file_get_contents($htaccess), 'AddType'));
	$GLOBALS['is_apache'] = $was_apache;
	$had === null ? unlink($htaccess) : file_put_contents($htaccess, $had);

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
