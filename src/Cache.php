<?php

namespace TimberAVIF;

/**
 * Keeps page caches in step with the files.
 *
 * The markup depends only on what the index records, so a cached page stays right until
 * the index changes for one of its images: an upload converted after the page was cached,
 * a crop or a small width built on request, a file replaced in place. The worker announces
 * each of those as `timber_avif/changed` with the attachment IDs; the listener here purges
 * the pages that show them in WP Rocket. Any other cache can listen to the same action.
 *
 * Without it such a page kept serving JPEG — complete, not broken — until the cache
 * expired: ten hours on Mobilissimo.
 */
final class Cache {
	// A full purge ran recently: the next one waits, and runs when the queue empties.
	const RECENT   = 'timber_avif_full_purge';
	const DEFERRED = 'timber_avif_full_purge_deferred';
	const INTERVAL = 15 * MINUTE_IN_SECONDS;
	// Past this many pages to purge, the whole cache goes instead.
	const MAX_PAGES = 50;

	public static function boot(): void {
		add_action('timber_avif/changed', [self::class, 'changed']);
		add_action('timber_avif/idle', [self::class, 'idle']);
	}

	public static function available(): bool {
		return function_exists('rocket_clean_post') && function_exists('rocket_clean_domain');
	}

	/** @param int[] $ids */
	public static function changed(array $ids): void {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (!$ids || !self::available()) return;
		if (count($ids) > self::MAX_PAGES) {
			self::purge_all();
			return;
		}

		[$posts, $terms, $everywhere] = self::shown_on($ids);
		if ($everywhere || count($posts) + count($terms) > self::MAX_PAGES) {
			self::purge_all();
			return;
		}

		foreach ($posts as $post_id) rocket_clean_post($post_id);
		$urls = [];
		foreach ($terms as $term_id) {
			$link = get_term_link($term_id);
			if (is_string($link)) $urls[] = $link;
		}
		if ($urls && function_exists('rocket_clean_files')) rocket_clean_files($urls);
	}

	/** The queue is empty: a full purge that was held back runs now. */
	public static function idle(): void {
		if (get_option(self::DEFERRED)) self::purge_all(true);
	}

	/**
	 * Purge every cached page. At most once per INTERVAL while the worker is going through
	 * a backlog, then once more when it is done; $now for the tools that delete files.
	 */
	public static function purge_all(bool $now = false): void {
		if (!self::available()) return;
		if (!$now && get_transient(self::RECENT)) {
			update_option(self::DEFERRED, 1, false);
			return;
		}
		rocket_clean_domain();
		set_transient(self::RECENT, 1, self::INTERVAL);
		delete_option(self::DEFERRED);
	}

	/**
	 * Where these images are shown: published posts that use them — featured image, an
	 * image in the content, a custom field, or the post they were uploaded to — terms that
	 * do, such as a category's header image, and whether any is used site-wide: in an ACF
	 * options field, as the logo or the site icon. Then every page needs purging.
	 *
	 * @param int[] $ids
	 * @return array{0: int[], 1: int[], 2: bool}
	 */
	private static function shown_on(array $ids): array {
		global $wpdb;
		$list = implode(',', $ids);
		$quoted = "'" . implode("','", $ids) . "'";
		// A field holding the ID, or a serialized list holding it (ACF galleries, relationships).
		$holds = fn(string $col) => implode(' OR ', array_map(fn($id) => "$col = '$id' OR $col LIKE '%\"$id\"%'", $ids));
		$in_content = implode(' OR ', array_map(fn($id) => "post_content LIKE '%wp-image-$id\"%' OR post_content LIKE '%wp-image-$id %'", $ids));

		$candidates = array_merge(
			$wpdb->get_col("SELECT post_parent FROM {$wpdb->posts} WHERE ID IN ($list) AND post_parent > 0"),
			$wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ($quoted)"),
			$wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE post_id NOT IN ($list) AND meta_key NOT LIKE '\\_%' AND ({$holds('meta_value')})"),
			$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ($in_content)")
		);
		$candidates = array_values(array_unique(array_map('intval', $candidates)));

		$posts = [];
		if ($candidates) {
			// Revisions and autosaves carry the parent's fields: their parent is the page.
			$rows = $wpdb->get_results("SELECT ID, post_type, post_parent, post_status FROM {$wpdb->posts} WHERE ID IN (" . implode(',', $candidates) . ")");
			foreach ($rows as $row) {
				if ($row->post_type === 'revision') $posts[] = (int) $row->post_parent;
				elseif ($row->post_status === 'publish' && !in_array($row->post_type, ['attachment', 'nav_menu_item', 'acf-field', 'acf-field-group'], true)) $posts[] = (int) $row->ID;
			}
		}

		$terms = array_map('intval', $wpdb->get_col("SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key NOT LIKE '\\_%' AND ({$holds('meta_value')})"));

		$everywhere = (bool) $wpdb->get_var("SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE 'options\\_%' AND ({$holds('option_value')}) LIMIT 1")
			|| in_array((int) get_option('site_icon'), $ids, true)
			|| in_array((int) get_theme_mod('custom_logo'), $ids, true);

		return [array_values(array_unique(array_filter($posts))), $terms, $everywhere];
	}
}
