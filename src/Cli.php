<?php

namespace TimberAVIF;

/**
 * wp timber-avif …
 */
final class Cli {

	public static function register(): void {
		\WP_CLI::add_command('timber-avif status', [self::class, 'status']);
		\WP_CLI::add_command('timber-avif work', [self::class, 'work']);
		\WP_CLI::add_command('timber-avif rebuild', [self::class, 'rebuild']);
		\WP_CLI::add_command('timber-avif purge', [self::class, 'purge']);
		\WP_CLI::add_command('timber-avif detect', [self::class, 'detect']);
		\WP_CLI::add_command('timber-avif clear-cache', [self::class, 'clear_cache']);
		// v6 names, so scripts and habits keep working.
		\WP_CLI::add_command('timber-avif bulk', fn() => self::work([], ['all' => true]));
		\WP_CLI::add_command('timber-avif queue', [self::class, 'work']);
	}

	/**
	 * Format, engine and how much of the library is ready.
	 */
	public static function status(): void {
		$format = Config::format();
		\WP_CLI::log('Format:  ' . ($format ? strtoupper($format) : 'off'));
		\WP_CLI::log('Engine:  ' . ($format ? Engine::detect($format) : '—') . ' (' . Engine::runtime() . ')');
		\WP_CLI::log('Widths:  ' . implode(', ', Config::widths()));
		\WP_CLI::log('Pending: ' . Worker::count_pending());
	}

	/**
	 * Convert pending images.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Keep going until the queue is empty.
	 *
	 * [--budget=<seconds>]
	 * : Seconds per pass. Default 60.
	 */
	public static function work(array $args = [], array $assoc = []): void {
		$budget = max(5.0, (float) ($assoc['budget'] ?? 60));
		$all = !empty($assoc['all']);
		$total = Worker::count_pending();
		if (!$total) {
			\WP_CLI::success('Nothing pending.');
			return;
		}

		$done = 0;
		$waits = 0;
		do {
			$result = Worker::run($budget);
			if ($result['error']) \WP_CLI::error($result['error']);
			if ($result['busy']) {
				if (++$waits > 12) \WP_CLI::error('Another worker has held the lock for a minute. Try again later.');
				\WP_CLI::log('Another worker is running, waiting…');
				sleep(5);
				continue;
			}
			$waits = 0;
			$done += $result['finished'];
			\WP_CLI::log(sprintf('%d done, %d pending', $done, $result['remaining']));
			if (!$result['processed']) break;
		} while ($all && $result['remaining'] > 0);

		\WP_CLI::success(sprintf('%d images processed.', $done));
	}

	/**
	 * Re-encode the whole library with the current settings.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public static function rebuild(array $args = [], array $assoc = []): void {
		\WP_CLI::confirm('Re-encode every image in the background?', $assoc);
		Config::bump_generation();
		Worker::hint();
		\WP_CLI::success(sprintf('%d images pending. Run `wp timber-avif work --all` to convert them now.', Worker::count_pending()));
	}

	/**
	 * Delete generated files.
	 *
	 * [--v6]
	 * : Delete what v6 left behind instead: photo.avif beside photo.jpg, and .lock files.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public static function purge(array $args = [], array $assoc = []): void {
		$v6 = !empty($assoc['v6']);
		\WP_CLI::confirm($v6 ? 'Delete every file left by v6?' : 'Delete every generated AVIF and WebP file?', $assoc);
		$deleted = $v6 ? Tools::purge_v6() : Tools::purge();
		if (!$v6) Worker::hint();
		\WP_CLI::success(sprintf('%d files deleted.', $deleted));
	}

	public static function detect(): void {
		foreach (['avif', 'webp'] as $format) \WP_CLI::log(strtoupper($format) . ': ' . Engine::detect($format));
	}

	public static function clear_cache(): void {
		Engine::flush();
		\WP_CLI::success('Capability cache cleared. AVIF: ' . Engine::detect('avif') . ', WebP: ' . Engine::detect('webp'));
	}
}
