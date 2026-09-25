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
		if ($format) \WP_CLI::log('Served:  ' . (Server::served_type(true) ?? '— (no copy yet, or the site did not answer)'));
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
				// Long enough for a worker that died holding the lock: it expires on its own.
				if (++$waits > 40) \WP_CLI::error('Another worker has held the lock for over three minutes. Try again later.');
				if ($waits === 1) \WP_CLI::log('Another worker is running, waiting for it…');
				sleep(5);
				continue;
			}
			$waits = 0;
			$done += $result['finished'];
			\WP_CLI::log(sprintf('%d done, %d pending', $done, $result['remaining']));
			if (!$result['processed']) break;
		} while ($result['busy'] || ($all && $result['remaining'] > 0));

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
	 * Delete every generated file. They are rebuilt in the background.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public static function purge(array $args = [], array $assoc = []): void {
		\WP_CLI::confirm('Delete every generated AVIF and WebP file?', $assoc);
		$deleted = Tools::purge();
		Worker::hint();
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
