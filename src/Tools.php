<?php

namespace TimberAVIF;

/**
 * The destructive tools, shared by the admin and WP-CLI.
 */
final class Tools {

	/**
	 * Delete every file Timber AVIF made and every stamp, so the whole library is pending.
	 */
	public static function purge(): int {
		global $wpdb;
		$deleted = 0;

		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Index::META)));
		foreach (array_chunk($ids, 200) as $chunk) {
			update_meta_cache('post', $chunk);
			foreach ($chunk as $id) $deleted += Index::forget($id);
		}

		// Stamps can exist without an index (animated or already-modern sources): those go too.
		foreach ([Index::STAMP, Index::RETRY, Index::ATTEMPTS, Index::ISSUE] as $key) {
			delete_metadata('post', 0, $key, '', true);
		}

		// After the files and the index, not before: a page cached in between would point at
		// files that are gone, and a browser does not fall back from a <source> that fails.
		Cache::purge_all(true);

		return $deleted;
	}
}
