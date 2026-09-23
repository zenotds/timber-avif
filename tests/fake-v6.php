<?php
/**
 * Stands in for v6's avif.php, so tests/prepare.php can check prepare mode:
 *
 *     wp eval-file tests/prepare.php --require=tests/fake-v6.php
 *
 * Loaded by WP-CLI before WordPress, hence WP_CLI::add_wp_hook() instead of add_filter().
 */

class TimberAVIF {
	const VERSION = '6.1.3';
	public static int $uploads = 0;

	public static function on_upload($metadata, $id) {
		self::$uploads++;
		return $metadata;
	}
}

WP_CLI::add_wp_hook('wp_generate_attachment_metadata', ['TimberAVIF', 'on_upload'], 20, 2);
