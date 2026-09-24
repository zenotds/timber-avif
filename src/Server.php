<?php

namespace TimberAVIF;

/**
 * The type the web server sends the copies with.
 *
 * Copies are named after the file they stand in for plus the new extension: photo.jpg.avif.
 * Apache reads every extension of a name, and where its mime.types has no entry for .avif —
 * MAMP's, an older host's — it takes the type from .jpg: the AVIF goes out as image/jpeg.
 * Browsers look at the bytes and show it anyway, but the header is what developer tools list,
 * what PageSpeed counts and what a CDN goes by. A type declared for .avif wins over .jpg,
 * being the last extension, so on Apache (and LiteSpeed) the rule is kept in the site's
 * .htaccess, next to WordPress's own. Other servers get told what to add.
 */
final class Server {
	const MARKER = 'Timber AVIF';
	const CHECK  = 'timber_avif_served_type';

	/** @return string[] */
	public static function rules(): array {
		return [
			'# photo.jpg.avif is an AVIF: without these lines Apache sends it as image/jpeg',
			'<IfModule mod_mime.c>',
			"\tAddType image/avif .avif",
			"\tAddType image/webp .webp",
			'</IfModule>',
		];
	}

	/**
	 * Add the rules to .htaccess if they are missing. Checked hourly, so a deploy that
	 * overwrites .htaccess loses them for an hour at most. Only a .htaccess that exists and
	 * is writable is touched: a site without one is not served by it.
	 */
	public static function ensure(): bool {
		global $is_apache;
		if (empty($is_apache)) return false;

		if (self::has_rules()) return true;
		$file = self::htaccess();
		if (!is_file($file) || !is_writable($file)) return false;

		if (!function_exists('insert_with_markers')) require_once ABSPATH . 'wp-admin/includes/misc.php';
		$written = insert_with_markers($file, self::MARKER, self::rules());
		if ($written) delete_transient(self::CHECK);
		return $written;
	}

	/** On theme switch: the rules go with the code that added them. */
	public static function remove(): void {
		$file = self::htaccess();
		if (!is_file($file) || !is_writable($file)) return;
		// insert_with_markers() would add an empty block to a file that never had one.
		if (!str_contains((string) @file_get_contents($file), '# BEGIN ' . self::MARKER)) return;

		if (!function_exists('insert_with_markers')) require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers($file, self::MARKER, []);
	}

	/**
	 * The type the server sends a copy with, asked of the server itself: once a day, from the
	 * settings page and `wp timber-avif status`. Null when there is no copy yet, or the
	 * request failed — a staging site behind a password, a host that blocks loopback requests.
	 */
	public static function served_type(bool $fresh = false): ?string {
		$format = Config::format();
		if (!$format) return null;

		$cached = $fresh ? false : get_transient(self::CHECK);
		if (is_array($cached) && ($cached['format'] ?? '') === $format) return $cached['type'] !== '' ? $cached['type'] : null;

		$url = self::sample_url($format);
		$type = '';
		if ($url) {
			$response = wp_remote_head($url, ['timeout' => 3, 'redirection' => 2, 'sslverify' => false]);
			if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
				$type = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($response, 'content-type'))[0]));
			}
		}
		// Nothing to ask about yet: again in an hour, when the worker may have made a copy.
		set_transient(self::CHECK, ['format' => $format, 'type' => $type], $url ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
		return $type !== '' ? $type : null;
	}

	/**
	 * What to do about a wrong type, for the settings page.
	 *
	 * @return array{0: string, 1: string} Advice, and the lines to add ('' when there are none).
	 */
	public static function advice(): array {
		global $is_apache;
		if (!empty($is_apache)) {
			if (self::has_rules()) {
				return [__('The rule is in .htaccess, but the server ignores it: .htaccess files need AllowOverride FileInfo, or a CDN in front of the site sends its own type.', 'timber-avif'), ''];
			}
			return [__('Add these lines to the .htaccess file in the site root (it is not writable, so Timber AVIF could not):', 'timber-avif'), implode("\n", self::rules())];
		}
		return [__('Add this line to the server\'s mime.types file (on nginx, inside the types block), then reload it:', 'timber-avif'), 'image/avif avif;'];
	}

	private static function has_rules(): bool {
		$current = (string) @file_get_contents(self::htaccess());
		return str_contains($current, '# BEGIN ' . self::MARKER) && str_contains($current, 'AddType image/avif .avif');
	}

	private static function htaccess(): string {
		if (!function_exists('get_home_path')) require_once ABSPATH . 'wp-admin/includes/file.php';
		return get_home_path() . '.htaccess';
	}

	/** Any copy the worker has made. */
	private static function sample_url(string $format): string {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1",
			Index::META,
			'%' . $wpdb->esc_like('.' . $format . '"') . '%'
		));
		$index = $row ? maybe_unserialize($row->meta_value) : null;
		if (!is_array($index)) return '';

		foreach ((array) ($index[$format] ?? []) as $entry) {
			if (empty($entry['file'])) continue;
			$url = wp_get_attachment_url((int) $row->post_id);
			return $url ? trailingslashit(dirname($url)) . $entry['file'] : '';
		}
		return '';
	}
}
