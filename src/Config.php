<?php

namespace TimberAVIF;

/**
 * Settings, edited under Settings → Timber AVIF.
 *
 * Only what differs from the defaults is stored. v6 wrote the whole default set into
 * the database on first run, which froze it there: when a default changed in a later
 * release — AVIF quality going back to 75 in 6.1.2 — it reached new installs only.
 * Now a value left at its default keeps following the default.
 */
final class Config {
	const OPTION     = 'timber_avif_settings';
	const GENERATION = 'timber_avif_generation';

	// Sources worth converting. WebP is here because libraries imported from older sites hold WebP originals.
	const SOURCE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

	// Generation ceiling, matching WordPress big_image_size_threshold.
	const MAX_GENERATED_WIDTH = 2560;

	// How much heavier than the file it replaces a conversion may be before it is thrown
	// away. The floor decides below ~40 KB, the ratio between ~40 and ~500 KB, the hard
	// limit above that. See Engine::exceeds_tolerance().
	const OVERSIZE_MIN_RATIO  = 0.10;
	const OVERSIZE_MIN_BYTES  = 4096;
	const OVERSIZE_HARD_LIMIT = 51200;

	// Marks an option written by v7, which holds choices only. A stored option without it
	// was written by v6, and is read through Migration\V6.
	const SCHEMA = '_v';

	private static ?array $settings = null;
	private static ?array $widths = null;

	public static function defaults(): array {
		return [
			'format_mode'          => 'auto',
			// Quality scales are not comparable across codecs: AVIF 75 already sits above JPEG 95 in perceived quality.
			'avif_quality'         => 75,
			'webp_quality'         => 90,
			// WordPress's own default. v6 used 95 because every AVIF was transcoded from these JPEGs; v7
			// encodes from the uploaded file, so the JPEG is only the fallback, and at 95 it was
			// two thirds of the disk space v7 adds.
			'jpeg_quality'         => 82,
			// One shared set of widths for the whole theme, so the same photo reuses the same files everywhere.
			'breakpoint_widths'    => '320,480,640,768,1024,1280,1600,1920,2560',
			'max_upload_dimension' => 2560,
			'max_dimension'        => 4096,
			'max_file_size'        => 50,
			'only_if_smaller'      => true,
			// Images in post content and ACF WYSIWYG fields get a <picture> too.
			'content_images'       => true,
		];
	}

	public static function all(): array {
		if (self::$settings === null) {
			$saved = get_option(self::OPTION, []);
			self::$settings = array_merge(self::defaults(), self::normalize(is_array($saved) ? $saved : []));
		}
		return self::$settings;
	}

	/**
	 * Hooked on every write of the option (Plugin::boot), not only this class's own: while v7
	 * prepares next to v6, v6's settings form writes the same option.
	 */
	public static function forget(): void {
		self::$settings = null;
		self::$widths = null;
	}

	public static function get(string $key) {
		return self::all()[$key] ?? null;
	}

	public static function is_default(string $key): bool {
		$saved = get_option(self::OPTION, []);
		return !array_key_exists($key, self::normalize(is_array($saved) ? $saved : []));
	}

	/**
	 * Store a submitted form: sanitized, and reduced to what differs from the defaults.
	 */
	public static function save(array $input): void {
		update_option(self::OPTION, [self::SCHEMA => 7] + self::diff(self::sanitize($input)));
		self::forget();
	}

	public static function reset(): void {
		delete_option(self::OPTION);
		self::forget();
	}

	/**
	 * Store the option the way v7 reads it, so that from then on every stored value is a
	 * choice. Once, when v7 takes over from v6.
	 */
	public static function migrate(): void {
		$saved = get_option(self::OPTION, []);
		if (!is_array($saved) || !$saved || !empty($saved[self::SCHEMA])) return;
		update_option(self::OPTION, [self::SCHEMA => 7] + self::normalize($saved));
		self::forget();
	}

	/**
	 * The choices in a stored option: known keys only, sanitized, without the values that are
	 * defaults. Reading and migrating go through the same function, so the settings in
	 * effect are the same before and after v7 takes over from v6.
	 */
	private static function normalize(array $saved): array {
		if ($saved && empty($saved[self::SCHEMA])) $saved = Migration\V6::settings($saved);
		$saved = array_intersect_key($saved, self::defaults());
		$clean = array_intersect_key(self::sanitize($saved + self::defaults()), $saved);
		return self::diff($clean);
	}

	public static function sanitize(array $input): array {
		$d = self::defaults();
		$int = fn(string $k, int $min, int $max) => max($min, min($max, (int) ($input[$k] ?? $d[$k])));

		$mode = (string) ($input['format_mode'] ?? $d['format_mode']);

		return [
			'format_mode'          => in_array($mode, ['auto', 'avif', 'webp', 'off'], true) ? $mode : 'auto',
			'avif_quality'         => $int('avif_quality', 1, 100),
			'webp_quality'         => $int('webp_quality', 1, 100),
			'jpeg_quality'         => $int('jpeg_quality', 60, 100),
			'breakpoint_widths'    => implode(',', self::parse_widths((string) ($input['breakpoint_widths'] ?? $d['breakpoint_widths'])) ?: self::parse_widths($d['breakpoint_widths'])),
			'max_upload_dimension' => $int('max_upload_dimension', 1024, 10000),
			'max_dimension'        => $int('max_dimension', 512, 20000),
			'max_file_size'        => $int('max_file_size', 1, 500),
			'only_if_smaller'      => filter_var($input['only_if_smaller'] ?? $d['only_if_smaller'], FILTER_VALIDATE_BOOLEAN),
			'content_images'       => filter_var($input['content_images'] ?? $d['content_images'], FILTER_VALIDATE_BOOLEAN),
		];
	}

	private static function diff(array $values): array {
		return array_filter($values, fn($v, $k) => $v !== self::defaults()[$k], ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * "640, 1024,1024, 99999" → [640, 1024, 2560]: sorted, unique, within what is ever generated.
	 */
	public static function parse_widths(string $raw): array {
		$widths = array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY));
		$widths = array_map(fn($w) => min($w, self::MAX_GENERATED_WIDTH), array_filter($widths, fn($w) => $w >= 16));
		$widths = array_values(array_unique($widths));
		sort($widths);
		return array_slice($widths, 0, 16);
	}

	/** @return int[] */
	public static function widths(): array {
		if (self::$settings === null) self::$widths = null;
		return self::$widths ??= self::parse_widths((string) self::get('breakpoint_widths'));
	}

	/**
	 * The modern format the worker generates and the markup serves, or null for none.
	 */
	public static function format(): ?string {
		$mode = self::get('format_mode');
		if ($mode === 'off') return null;
		if ($mode === 'avif' || $mode === 'webp') return $mode;
		return Engine::auto_format();
	}

	public static function quality(string $format): int {
		return (int) self::get($format . '_quality');
	}

	/**
	 * Everything an encoded file depends on. An index entry made under a different key is
	 * still served — it is a valid file — but the worker re-encodes it in the background.
	 */
	public static function encoding_key(): string {
		$format = self::format();
		return substr(md5(implode('|', [
			$format,
			$format ? self::quality($format) : 0,
			(int) self::get('only_if_smaller'),
			self::OVERSIZE_MIN_RATIO, self::OVERSIZE_MIN_BYTES, self::OVERSIZE_HARD_LIMIT,
			self::get('max_dimension'),
			self::get('max_file_size'),
			(int) get_option(self::GENERATION, 0),
		])), 0, 12);
	}

	/**
	 * What a finished attachment is stamped with. An attachment whose stamp differs, or
	 * that has none, is pending: that is the whole queue.
	 */
	public static function fingerprint(): string {
		return substr(md5(self::encoding_key() . '|' . implode(',', self::widths())), 0, 12);
	}

	/**
	 * Re-encode everything, without changing a setting. For a server whose encoder was upgraded.
	 */
	public static function bump_generation(): void {
		update_option(self::GENERATION, (int) get_option(self::GENERATION, 0) + 1, true);
	}
}
