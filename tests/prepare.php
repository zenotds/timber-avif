<?php
/**
 * Prepare mode — v7 loaded next to v6 — against a real WordPress:
 *
 *     wp eval-file tests/prepare.php --require=tests/fake-v6.php
 *
 * Use a throwaway site, as for tests/integration.php.
 */

use TimberAVIF\{Config, Index, Plugin, Twig, Content, Worker};

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$GLOBALS['tavif_pt'] = ['failures' => 0, 'count' => 0];
function pcheck(string $name, $ok, string $detail = ''): void {
	$GLOBALS['tavif_pt']['count']++;
	if ($ok) { echo "  ok    $name\n"; return; }
	$GLOBALS['tavif_pt']['failures']++;
	echo "  FAIL  $name" . ($detail !== '' ? "\n        $detail" : '') . "\n";
}

$id = 0;
try {
	echo "\nPrepare mode\n";
	pcheck('v6 is seen and v7 prepares', Plugin::preparing() && TimberAVIF::VERSION === '6.1.3');
	pcheck('v7 leaves the markup to v6', !has_filter('timber/twig', [Twig::class, 'register']) && !has_filter('wp_content_img_tag', [Content::class, 'img_tag']));
	pcheck('nor purges the page cache, which holds v6\'s pages', !has_action('timber_avif/changed', [\TimberAVIF\Cache::class, 'changed']));

	// An image as v6 knew it: no canonical sizes yet.
	$src = get_temp_dir() . 'tavif-prepare.jpg';
	$im = new Imagick();
	$im->newPseudoImage(2400, 1600, 'plasma:');
	$im->setImageFormat('jpeg');
	$im->writeImage($src);
	$no_canonical = fn($sizes) => array_filter($sizes, fn($k) => !str_starts_with($k, 'tavif-'), ARRAY_FILTER_USE_KEY);
	add_filter('intermediate_image_sizes_advanced', $no_canonical, 99);
	$tmp = wp_tempnam('tavif-prepare.jpg');
	copy($src, $tmp);
	$id = media_handle_sideload(['name' => 'tavif-prepare.jpg', 'tmp_name' => $tmp], 0);
	remove_filter('intermediate_image_sizes_advanced', $no_canonical, 99);
	@unlink($src);
	pcheck('uploaded, v6 saw the upload once', !is_wp_error($id) && TimberAVIF::$uploads === 1, (string) TimberAVIF::$uploads);

	while (Worker::process($id, microtime(true) + 60) === false) {}
	$meta = wp_get_attachment_metadata($id);
	pcheck('the worker built the canonical sizes', isset($meta['sizes']['tavif-640']), implode(',', array_keys($meta['sizes'] ?? [])));
	pcheck('without running v6 again on them', TimberAVIF::$uploads === 1, (string) TimberAVIF::$uploads);
	pcheck('and made the AVIF copies', count(array_filter(array_column(Index::get($id)['avif'] ?? [], 'file'))) >= 5);
	pcheck('stamped: ready for v7', get_post_meta($id, Index::STAMP, true) === Config::fingerprint());

	echo "\nSettings at the switch\n";
	update_option(Config::OPTION, ['avif_quality' => 75, 'webp_quality' => 90, 'jpeg_quality' => 95, 'breakpoint_widths' => '320,480,640,768,1024,1280,1600,1920,2560', 'max_dimension' => 4096, 'max_file_size' => 50, 'only_if_smaller' => true, 'pregenerate_widths' => '640,1024,1600,1920']);
	$fp_v6 = Config::fingerprint();
	delete_option(Config::OPTION);
	pcheck('v6 option full of defaults = no option at all', Config::fingerprint() === $fp_v6);
	pcheck('so the image prepared under v6 stays done', get_post_meta($id, Index::STAMP, true) === $fp_v6);
} catch (Throwable $e) {
	$GLOBALS['tavif_pt']['failures']++;
	echo "\n  ERROR " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
	if ($id && !is_wp_error($id)) wp_delete_attachment($id, true);
	delete_option(Config::OPTION);
}

['failures' => $failures, 'count' => $count] = $GLOBALS['tavif_pt'];
echo $failures ? "\n$failures of $count checks failed.\n" : "\n$count checks passed.\n";
if ($failures) exit(1);
