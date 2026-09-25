<?php

namespace TimberAVIF\Optimize;

use TimberAVIF\Config;

/**
 * Where each image is placed, from the database, as the places Templates speaks of:
 *
 *     post|thumbnail                   the featured image of a post
 *     post|header.cover                an ACF field of a post (here a group's sub-field)
 *     layout:blocks|blocks.*.cover     a field of a row of the flexible content layout `blocks`
 *     term|cover  option|logo          an ACF field of a term, of the options page
 *
 * ACF writes, next to every value, the key of the field it belongs to (`_blocks_2_cover` →
 * `field_…`), so the field's type and its ancestors — repeaters, groups, the layout of a
 * flexible content — are known exactly, whatever the numbering of the rows.
 *
 * A reference that cannot be read that way is kept as "other": an ID in a field ACF does not
 * own, an SEO plugin's image, the site icon. So is an image in post content, which WordPress
 * lays out itself. Either keeps every file of the image.
 *
 * Refs, by attachment ID: ['p' => [place => true], 'o' => true (other), 'c' => true (content)].
 */
final class Usage {
	const BATCH = 200;

	// Post types whose meta holds nothing a visitor sees.
	const SKIP_TYPES = ['attachment', 'revision', 'nav_menu_item', 'acf-field', 'acf-field-group', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'custom_css'];
	const SKIP_STATUSES = ['trash', 'auto-draft', 'inherit'];
	// Meta whose numbers are not attachment IDs.
	const NOT_REFERENCES = '/^_(edit_lock|edit_last|wp_old_slug|wp_old_date|wp_trash_meta_\w+|wp_desired_post_slug|wp_page_template|encloseme|pingme|menu_item_\w+|tavif\w*|yoast_wpseo_(linkdex|content_score|estimated-reading-time-minutes|wordproof_timestamp|is_cornerstone|meta-robots-\w+)|wpml_\w+|icl_\w+|last_translation_edit_mode|oembed_\w+|wp_attachment_\w+|wp_attached_file)$/';

	/** @var array<string, ?array> ACF field key → how its values are read */
	private static array $fields = [];

	/* ─────────────────────────────────────────────
	 * The theme
	 * ───────────────────────────────────────────── */

	/**
	 * Timber's template locations, namespace → directories: the theme's `Timber::$dirname`
	 * folders and what `timber/locations` adds. The theme's own root only when it has no such
	 * folder: next to `templates/` it holds nothing Timber renders, and a module library
	 * kept there would read as templates.
	 *
	 * @return array<string, string[]>
	 */
	public static function locations(): array {
		$roots = array_values(array_unique(array_filter(array_map('realpath', [get_stylesheet_directory(), get_template_directory()]))));
		$dirnames = class_exists('Timber\\LocationManager') ? \Timber\LocationManager::get_locations_theme_dir() : ['__main__' => ['views']];

		$locations = [];
		foreach ($roots as $root) {
			foreach ((array) $dirnames as $namespace => $names) {
				foreach ((array) $names as $name) {
					$dir = realpath(trailingslashit($root) . $name);
					if ($dir && is_dir($dir)) $locations[$namespace][] = $dir;
				}
			}
		}
		if (!$locations) $locations['__main__'] = $roots;

		foreach ((array) apply_filters('timber/locations', []) as $namespace => $dirs) {
			if ($namespace === 'timber-avif') continue;
			foreach ((array) $dirs as $dir) {
				$dir = realpath((string) $dir);
				if ($dir && is_dir($dir)) $locations[is_int($namespace) ? '__main__' : $namespace][] = $dir;
			}
		}
		return array_map(fn($dirs) => array_values(array_unique($dirs)), $locations);
	}

	/**
	 * From the theme's PHP, the context variables it fills with ACF options:
	 * `$context['settings'] = get_fields('options')`.
	 *
	 * @return string[]
	 */
	public static function option_vars(): array {
		$options = [];
		foreach (array_unique([get_stylesheet_directory(), get_template_directory()]) as $root) {
			if (!is_dir($root)) continue;
			$it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				fn($f) => !($f->isDir() && in_array($f->getFilename(), ['vendor', 'node_modules', '.git'], true))
			));
			foreach ($it as $file) {
				if (!$file->isFile() || $file->getExtension() !== 'php') continue;
				$src = (string) file_get_contents($file->getPathname());
				if (preg_match_all('/\[\s*[\'"](\w+)[\'"]\s*\]\s*=\s*(?:\\\\?\w+::)?get_fields\s*\(\s*[\'"]options?[\'"]/', $src, $m)) $options = array_merge($options, $m[1]);
			}
		}
		return array_values(array_unique($options ?: ['options']));
	}

	/* ─────────────────────────────────────────────
	 * The database
	 * ───────────────────────────────────────────── */

	/** @return array<int, true> The images the worker converts. */
	public static function images(): array {
		global $wpdb;
		$mimes = "'" . implode("','", array_map('esc_sql', Config::SOURCE_MIMES)) . "'";
		return array_fill_keys(array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mimes)")), true);
	}

	/**
	 * The next batch of posts after $after: their featured images, ACF fields, other meta and
	 * content. Returns the last ID read, or 0 once there is nothing left.
	 */
	public static function scan_posts(int $after, array &$refs, array $images): int {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_type, post_status, post_content FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID LIMIT %d",
			$after,
			self::BATCH
		));
		if (!$rows) return 0;

		$posts = [];
		foreach ($rows as $row) {
			if (in_array($row->post_type, self::SKIP_TYPES, true) || in_array($row->post_status, self::SKIP_STATUSES, true)) continue;
			$posts[(int) $row->ID] = $row;
		}
		if ($posts) {
			$meta = [];
			$ids = implode(',', array_keys($posts));
			foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ids)") as $m) {
				$meta[(int) $m->post_id][(string) $m->meta_key] = (string) $m->meta_value;
			}
			foreach ($posts as $id => $post) {
				self::read_content((string) $post->post_content, $refs, $images);
				self::read_meta($meta[$id] ?? [], 'post', $refs, $images);
			}
		}
		return (int) end($rows)->ID;
	}

	/** ACF fields of terms, and term meta of other plugins (a product category's thumbnail). */
	public static function scan_terms(array &$refs, array $images): void {
		global $wpdb;
		$meta = [];
		foreach ($wpdb->get_results("SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}") as $m) {
			$meta[(int) $m->term_id][(string) $m->meta_key] = (string) $m->meta_value;
		}
		foreach ($meta as $rows) self::read_meta($rows, 'term', $refs, $images);
	}

	/** ACF fields of users (an author's portrait): no template path reaches them, so they keep everything. */
	public static function scan_users(array &$refs, array $images): void {
		global $wpdb;
		$meta = [];
		foreach ($wpdb->get_results("SELECT u.user_id, u.meta_key, u.meta_value FROM {$wpdb->usermeta} u JOIN {$wpdb->usermeta} k ON k.user_id = u.user_id AND k.meta_key = CONCAT('_', u.meta_key) AND k.meta_value LIKE 'field\\_%'") as $m) {
			$meta[(int) $m->user_id][(string) $m->meta_key] = (string) $m->meta_value;
			$meta[(int) $m->user_id]['_' . $m->meta_key] = 'field_';
		}
		foreach ($meta as $rows) {
			foreach ($rows as $key => $value) if ($key[0] !== '_') self::other($value, $refs, $images);
		}
	}

	/**
	 * ACF options pages, and the images WordPress and SEO plugins keep in options: the site
	 * icon, the logo, image widgets, Yoast's default and company images.
	 */
	public static function scan_options(array &$refs, array $images): void {
		global $wpdb;
		$rows = [];
		foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options\\_%' OR option_name LIKE '\\_options\\_%'") as $o) {
			// ACF names options fields options_{name}, as if they were the meta of an object.
			$name = (string) $o->option_name;
			$rows[$name[0] === '_' ? '_' . substr($name, 9) : substr($name, 8)] = (string) $o->option_value;
		}
		self::read_meta($rows, 'option', $refs, $images);

		$other = [(int) get_option('site_icon')];
		foreach ($wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme\\_mods\\_%' OR option_name LIKE 'widget\\_media\\_%' OR option_name LIKE 'wpseo%'") as $value) {
			$value = maybe_unserialize($value);
			array_walk_recursive($value, function ($v, $k) use (&$other) {
				if (is_numeric($v) && preg_match('/(^|_)(custom_logo|attachment_id|ids|image_id|logo_id|header_image_data)$|image|logo/', (string) $k)) $other[] = (int) $v;
			});
		}
		foreach ($other as $id) if (isset($images[$id])) $refs[$id]['o'] = true;
	}

	/**
	 * One object's meta: `_key` → `field_…` pairs are ACF's, everything else is someone else's.
	 *
	 * @param array<string, string> $meta
	 */
	private static function read_meta(array $meta, string $scope, array &$refs, array $images): void {
		foreach ($meta as $key => $value) {
			$key = (string) $key;
			if ($key === '' || $value === '') continue;

			if ($key === '_thumbnail_id') {
				$id = (int) $value;
				if (isset($images[$id])) $refs[$id]['p']["$scope|thumbnail"] = true;
				continue;
			}
			if ($key[0] === '_') {
				// ACF's own record of the field, or internal meta; the rest may hold IDs.
				if (str_starts_with($value, 'field_') || preg_match(self::NOT_REFERENCES, $key)) continue;
				self::other($value, $refs, $images);
				continue;
			}

			$field_key = $meta['_' . $key] ?? '';
			$field = str_starts_with($field_key, 'field_') ? self::field($field_key) : null;
			if ($field === null) {
				self::other($value, $refs, $images);
				continue;
			}

			switch ($field['kind']) {
				case 'image':
					$id = (int) $value;
					if (isset($images[$id])) $refs[$id]['p'][self::place($field, $scope)] = true;
					break;
				case 'gallery':
					foreach (self::ids($value) as $id) if (isset($images[$id])) $refs[$id]['p'][self::place($field, $scope)] = true;
					break;
				case 'content':
					self::read_content($value, $refs, $images);
					break;
			}
		}
	}

	/** A field's place: its layout or the object, then the names down to it. */
	private static function place(array $field, string $scope): string {
		return ($field['layout'] !== null ? 'layout:' . $field['layout'] : $scope) . '|' . $field['path'];
	}

	/**
	 * How the values of an ACF field are read: 'image' (an ID), 'gallery' (a list of IDs),
	 * 'content' (HTML), 'none' (anything else: text, a choice, a post). With its path from
	 * the object, or from the row of the flexible content layout it sits in, and '*' for
	 * each repeater row and gallery item. Null when ACF does not know the key.
	 */
	private static function field(string $key): ?array {
		if (array_key_exists($key, self::$fields)) return self::$fields[$key];
		if (!function_exists('acf_get_field')) return self::$fields[$key] = null;

		$field = acf_get_field($key);
		if (!is_array($field) || empty($field['name'])) return self::$fields[$key] = null;

		$kind = match ($field['type'] ?? '') {
			'image', 'file' => 'image',
			'gallery' => 'gallery',
			'wysiwyg', 'textarea' => 'content',
			default => 'none',
		};
		$segments = [(string) $field['name']];
		if ($kind === 'gallery') $segments[] = '*';

		$layout = null;
		$child = $field;
		for ($guard = 0; $guard < 12; $guard++) {
			$parent = !empty($child['parent']) ? acf_get_field($child['parent']) : false;
			if (!is_array($parent) || empty($parent['type'])) break;
			if ($parent['type'] === 'flexible_content') {
				foreach ((array) ($parent['layouts'] ?? []) as $l) {
					if (($l['key'] ?? '') === ($child['parent_layout'] ?? '')) $layout = (string) $l['name'];
				}
				// Rows of an unknown layout still sit in the flexible field.
				if ($layout === null) array_unshift($segments, (string) $parent['name'], '*');
				break;
			}
			if ($parent['type'] === 'repeater') array_unshift($segments, (string) $parent['name'], '*');
			elseif ($parent['type'] === 'group') array_unshift($segments, (string) $parent['name']);
			$child = $parent;
		}

		return self::$fields[$key] = ['kind' => $kind, 'layout' => $layout, 'path' => implode('.', $segments)];
	}

	/** Images in HTML: the editor's wp-image-N class, galleries and blocks by ID. */
	private static function read_content(string $html, array &$refs, array $images): void {
		if ($html === '' || !preg_match('/wp-image-|\[gallery|<!-- wp:/', $html)) return;
		$ids = [];
		if (preg_match_all('/wp-image-(\d+)/', $html, $m)) $ids = $m[1];
		if (preg_match_all('/\[gallery[^\]]*\bids="([\d,\s]+)"/', $html, $m)) foreach ($m[1] as $list) $ids = array_merge($ids, preg_split('/[\s,]+/', $list));
		if (preg_match_all('/<!-- wp:[\w\/-]+ (\{[^\n]*?\}) \/?-->/', $html, $m)) {
			foreach ($m[1] as $json) {
				$attrs = json_decode($json, true);
				if (!is_array($attrs)) continue;
				array_walk_recursive($attrs, function ($v, $k) use (&$ids) {
					if (in_array((string) $k, ['id', 'ids', 'mediaId'], true) && is_numeric($v)) $ids[] = $v;
				});
			}
		}
		foreach ($ids as $id) if (isset($images[(int) $id])) $refs[(int) $id]['c'] = true;
	}

	/** A value nothing here owns, holding what may be image IDs: a number, a list of them, a serialized array. */
	private static function other(string $value, array &$refs, array $images): void {
		foreach (self::ids($value) as $id) if (isset($images[$id])) $refs[$id]['o'] = true;
	}

	/** @return int[] */
	private static function ids(string $value): array {
		$value = trim($value);
		if ($value === '') return [];
		if (ctype_digit($value)) return [(int) $value];
		if (preg_match('/^\d+(\s*,\s*\d+)+$/', $value)) return array_map('intval', preg_split('/\s*,\s*/', $value));
		if (is_serialized($value)) {
			$data = @unserialize($value, ['allowed_classes' => false]);
			$ids = [];
			if (is_array($data)) array_walk_recursive($data, function ($v) use (&$ids) { if (is_int($v) || (is_string($v) && ctype_digit($v))) $ids[] = (int) $v; });
			elseif (is_int($data)) $ids[] = $data;
			return $ids;
		}
		return [];
	}
}
