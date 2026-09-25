<?php

namespace TimberAVIF;

/**
 * A site-wide mutex in the options table, so at most one worker encodes at a time.
 *
 * A budget counted per request would let twenty concurrent requests run two hundred
 * encodes. add_option() is not atomic — it reads the cache, then upserts — so this talks to the
 * table directly: the INSERT that wins is the lock, and an expired lock is taken over
 * with a compare-and-swap, so two workers finding it expired cannot both get it.
 */
final class Lock {
	private static function option(string $name): string {
		return 'timber_avif_lock_' . $name;
	}

	public static function acquire(string $name, int $ttl): bool {
		global $wpdb;
		$option = self::option($name);
		$now = time();

		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$option,
			(string) ($now + $ttl)
		));
		if ($inserted) return true;

		$expires = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
		if ((int) $expires > $now) return false;

		return (bool) $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) ($now + $ttl),
			$option,
			$expires
		));
	}

	public static function release(string $name): void {
		global $wpdb;
		$wpdb->delete($wpdb->options, ['option_name' => self::option($name)]);
	}

	public static function held(string $name): bool {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option($name))) > time();
	}
}
