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
 * `field_…`), which gives the field's type. Where it sits — which rows of which repeater or
 * flexible content, which layout — is read from the name of the value itself, against the
 * object's other meta: `content_2_media_0_image` is row 2 of `content` (its layout is item 2
 * of the `content` meta), then row 0 of `content_2_media`. The field key alone cannot say:
 * inside a clone field, ACF stores the key of the cloned field, and its ancestors are those
 * of the group it was cloned from.
 *
 * A reference that cannot be read that way is kept as "other": an ID in a field ACF does not
 * own, an SEO plugin's image, the site icon. So is an image in post content, which WordPress
 * lays out itself. Either keeps every file of the image.
 *
 * Refs, by attachment ID: ['p' => [place => true], 'o' => true (other), 'c' => true (content)].
 */
final class Usage {
	const BATCH = 200;
	// Between the ways of naming one place, any of which a template call can match.
	const ALTERNATIVE = '||';

	// Post types whose meta holds nothing a visitor sees.
	const SKIP_TYPES = ['revision', 'acf-field', 'acf-field-group', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'custom_css'];
	const SKIP_STATUSES = ['trash', 'auto-draft'];
	// Post types whose fields no template place names: what they reference keeps every file.
	const OTHER_TYPES = ['attachment', 'nav_menu_item'];
	// Meta whose numbers are not attachment IDs.
	// Other plugins' lists of the media a post cites, worked out from its content and fields,
	// which are read here themselves: WPML's media translation.
	const INDEXES = ['referenced_media_ids'];
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
				// `Timber::$dirname = ['templates']` is a list: its keys are no namespace, the main one is meant.
				$namespace = is_int($namespace) ? '__main__' : $namespace;
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
		// Skipped rows still move the cursor; their content, a revision's or an attachment's description, is not fetched.
		$skip = "'" . implode("','", array_map('esc_sql', array_merge(self::SKIP_TYPES, ['attachment']))) . "'";
		$statuses = "'" . implode("','", array_map('esc_sql', self::SKIP_STATUSES)) . "'";
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_type, post_status, IF(post_type IN ($skip) OR post_status IN ($statuses), '', post_content) AS post_content FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID LIMIT %d",
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
				// A list: a key may have many rows (Meta Box keeps a gallery as one row per image).
				$meta[(int) $m->post_id][(string) $m->meta_key][] = (string) $m->meta_value;
			}
			foreach ($posts as $id => $post) {
				self::read_content((string) $post->post_content, $refs, $images);
				// An attachment's own meta names the attachment itself.
				if (in_array($post->post_type, self::OTHER_TYPES, true)) self::read_other_meta($meta[$id] ?? [], $refs, $images, $id);
				else self::read_meta($meta[$id] ?? [], 'post', $refs, $images);
			}
		}
		return (int) end($rows)->ID;
	}

	/** ACF fields of terms, and term meta of other plugins (a product category's thumbnail). */
	public static function scan_terms(array &$refs, array $images): void {
		global $wpdb;
		// By batches of terms, each with all its meta: a term's ACF values need their `_key` rows.
		$after = 0;
		do {
			$terms = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE term_id > %d ORDER BY term_id LIMIT %d", $after, self::BATCH)));
			if (!$terms) break;
			$meta = [];
			foreach ($wpdb->get_results("SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id IN (" . implode(',', $terms) . ")") as $m) {
				$meta[(int) $m->term_id][(string) $m->meta_key][] = (string) $m->meta_value;
			}
			foreach ($meta as $rows) self::read_meta($rows, 'term', $refs, $images);
			$after = end($terms);
		} while (count($terms) === self::BATCH);
	}

	/** ACF fields of users (an author's portrait): no template path reaches them, so they keep everything. */
	public static function scan_users(array &$refs, array $images): void {
		global $wpdb;
		$after = 0;
		do {
			$users = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE user_id > %d ORDER BY user_id LIMIT %d", $after, self::BATCH)));
			if (!$users) break;
			foreach ($wpdb->get_col("SELECT u.meta_value FROM {$wpdb->usermeta} u JOIN {$wpdb->usermeta} k ON k.user_id = u.user_id AND k.meta_key = CONCAT('_', u.meta_key) AND k.meta_value LIKE 'field\\_%' WHERE u.user_id IN (" . implode(',', $users) . ")") as $value) {
				self::other((string) $value, $refs, $images);
			}
			$after = end($users);
		} while (count($users) === self::BATCH);
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
			$rows[$name[0] === '_' ? '_' . substr($name, 9) : substr($name, 8)][] = (string) $o->option_value;
		}
		self::read_meta($rows, 'option', $refs, $images);

		// ACF fields under a post_id of their own (an options page's `theme_settings`, a widget's):
		// where that name ends, the option's cannot say.
		$rows = [];
		foreach ($wpdb->get_results("SELECT o.option_name, o.option_value, k.option_value AS field_key FROM {$wpdb->options} o JOIN {$wpdb->options} k ON k.option_name = CONCAT('_', o.option_name) AND k.option_value LIKE 'field\\_%' WHERE o.option_name NOT LIKE 'options\\_%'") as $o) {
			$rows[(string) $o->option_name][] = (string) $o->option_value;
			$rows['_' . $o->option_name][] = (string) $o->field_key;
		}
		self::read_other_meta($rows, $refs, $images);

		// Theme mods and widgets, whose keys are anyone's: every number in them, and the HTML of text and block widgets.
		foreach ($wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme\\_mods\\_%' OR option_name LIKE 'widget\\_%'") as $value) {
			self::other((string) $value, $refs, $images);
			self::read_content((string) $value, $refs, $images);
		}

		$other = [(int) get_option('site_icon'), (int) get_option('site_logo'), (int) get_option('woocommerce_placeholder_image')];
		foreach ($wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpseo%'") as $value) {
			// Among them plain strings too: Yoast keeps hashes and versions under wpseo_*.
			$value = maybe_unserialize($value);
			if (!is_array($value)) continue;
			array_walk_recursive($value, function ($v, $k) use (&$other) {
				if (is_numeric($v) && preg_match('/(^|_)(custom_logo|attachment_id|ids|image_id|logo_id|header_image_data)$|image|logo/', (string) $k)) $other[] = (int) $v;
			});
		}
		foreach ($other as $id) if (isset($images[$id])) $refs[$id]['o'] = true;
	}

	/**
	 * One object's meta: `_key` → `field_…` pairs are ACF's, everything else is someone else's.
	 *
	 * @param array<string, string[]> $meta Key → its rows' values.
	 */
	private static function read_meta(array $meta, string $scope, array &$refs, array $images): void {
		foreach ($meta as $key => $values) {
			$key = (string) $key;
			foreach ($values as $value) {
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

				if (in_array($key, self::INDEXES, true)) continue;
				$field_key = $meta['_' . $key][0] ?? '';
				$field = str_starts_with($field_key, 'field_') ? self::field($field_key) : null;
				if ($field === null) {
					self::other($value, $refs, $images);
					continue;
				}

				switch ($field['kind']) {
					case 'image':
					case 'gallery':
						// A row its repeater or flexible content no longer lists: ACF leaves the values of
						// removed rows behind, and nothing shows them.
						$place = self::place($key, $meta, $field, $scope);
						if ($place === null) break;
						if ($place === '') {
							self::other($value, $refs, $images);
							break;
						}
						foreach ($field['kind'] === 'image' ? [(int) $value] : self::ids($value) as $id) {
							if (isset($images[$id])) $refs[$id]['p'][$place] = true;
						}
						break;
					case 'content':
						self::read_content($value, $refs, $images);
						break;
					case 'other':
						self::other($value, $refs, $images);
						break;
				}
			}
		}
	}

	/**
	 * Meta whose places no template names: a menu item's, an attachment's, an options page's
	 * under a post_id of its own. Whatever it references keeps every file, except $self.
	 *
	 * @param array<string, string[]> $meta
	 */
	private static function read_other_meta(array $meta, array &$refs, array $images, int $self = 0): void {
		$found = [];
		self::read_meta($meta, 'post', $found, $images);
		unset($found[$self]);
		foreach (array_keys($found) as $id) $refs[$id]['o'] = true;
	}

	/**
	 * Where a value sits, from its meta key. The rows along the key are those of the
	 * containers the object has meta for — `content` in `content_2_cards_1_cover`, then
	 * `content_2_cards` — each one a repeater (its meta is a count) or a flexible content
	 * (its meta lists the layout of each row). The outermost flexible content gives the
	 * scope, `layout:cards`: its rows are what block templates are handed. Each other
	 * container is a name and `*`. Null for a row past those its container lists; '' when
	 * the container is one ACF does not know, so that row may be no row at all.
	 *
	 * @param array<string, string[]> $meta The object's meta.
	 */
	private static function place(string $key, array $meta, array $field, string $scope): ?string {
		$segments = [];
		$outer = null;
		$from = 0;
		if (preg_match_all('/_(\d+)_/', $key, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[0] as $j => [$match, $pos]) {
				$prefix = substr($key, 0, $pos);
				if (!isset($meta[$prefix][0], $meta['_' . $prefix][0])) continue;
				$container_key = (string) $meta['_' . $prefix][0];
				$container = str_starts_with($container_key, 'field_') ? self::field($container_key) : null;
				// A field next to it whose name it merely starts with: `banner` beside `banner_5_wide`.
				if ($container !== null && $container['kind'] !== 'rows') continue;
				$names = self::names_of($container_key, substr($prefix, $from));
				$rows = maybe_unserialize($meta[$prefix][0]);
				$row = (int) $m[1][$j][0];
				if (is_array($rows) ? !isset($rows[$row]) : (is_numeric($rows) && $row >= (int) $rows)) return $container === null ? '' : null;
				$layout = is_array($rows) ? (string) $rows[$row] : '';
				if ($outer === null && !$segments && $layout !== '') {
					$outer = [$layout, $names];
				} else {
					array_push($segments, ...$names);
					$segments[] = '*';
				}
				$from = $pos + strlen($match);
			}
		}
		array_push($segments, ...$field['segments']);
		$path = implode('.', $segments);
		if ($outer === null) return "$scope|$path";
		// The row of the layout, and the same value by its path from the object, for a theme
		// that loops over the flexible content itself: a call on either shows it.
		return "layout:{$outer[0]}|$path" . self::ALTERNATIVE . "$scope|" . implode('.', array_merge($outer[1], ['*'], $segments));
	}

	/**
	 * A container's names from its field key — `header.items` for a repeater in a group —
	 * or, when ACF does not know the key (a clone's), the text of the meta key.
	 *
	 * @return string[]
	 */
	private static function names_of(string $field_key, string $text): array {
		$field = str_starts_with($field_key, 'field_') ? self::field($field_key) : null;
		return $field ? array_values(array_diff($field['segments'], ['*'])) : [$text];
	}

	/**
	 * How the values of an ACF field are read: 'image' (an ID), 'gallery' (a list of IDs),
	 * 'content' (HTML), 'rows' (a repeater's count, a flexible content's layouts), 'none' (a
	 * value that cannot be an attachment: text, a choice), 'other' (anything else: a post, a
	 * third-party type, whatever it holds keeps every file). With its segments
	 * from the nearest row that holds it — the groups it is in, its name, and `*` for the
	 * items of a gallery. Null when ACF does not know the key.
	 */
	private static function field(string $key): ?array {
		if (array_key_exists($key, self::$fields)) return self::$fields[$key];
		if (!function_exists('acf_get_field')) return self::$fields[$key] = null;

		$field = acf_get_field($key);
		$prefix = [];
		// A field of a clone is stored under `field_{clone}_field_{field}`, which ACF only knows
		// in the clone's context: the field is the last key, the clone the one before.
		if ((!is_array($field) || empty($field['name'])) && ($at = strrpos($key, '_field_')) !== false) {
			$field = acf_get_field(substr($key, $at + 1));
			$clone = acf_get_field(substr($key, 0, $at));
			if (is_array($field) && is_array($clone) && ($clone['type'] ?? '') === 'clone') {
				// Shown as a group, the clone's fields sit under its name; with prefix_name, their names start with it.
				if (($clone['display'] ?? '') === 'group') $prefix[] = (string) $clone['name'];
				if (!empty($clone['prefix_name'])) $field['name'] = $clone['name'] . '_' . $field['name'];
			}
		}
		if (!is_array($field) || empty($field['name'])) return self::$fields[$key] = null;

		$kind = match ($field['type'] ?? '') {
			'image', 'file' => 'image',
			'gallery' => 'gallery',
			'wysiwyg', 'textarea' => 'content',
			'repeater', 'flexible_content' => 'rows',
			'text', 'number', 'range', 'email', 'url', 'password', 'true_false', 'select', 'checkbox', 'radio', 'button_group',
			'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'message', 'tab', 'accordion', 'taxonomy', 'user' => 'none',
			default => 'other',
		};
		$segments = [(string) $field['name']];
		if ($kind === 'gallery') $segments[] = '*';

		// Groups keep their sub-fields' values under the group's name; a row stops the climb.
		$child = $field;
		for ($guard = 0; $guard < 12; $guard++) {
			$parent = !empty($child['parent']) ? acf_get_field($child['parent']) : false;
			if (!is_array($parent) || ($parent['type'] ?? '') !== 'group') break;
			array_unshift($segments, (string) $parent['name']);
			$child = $parent;
		}

		return self::$fields[$key] = ['kind' => $kind, 'segments' => array_merge($prefix, $segments)];
	}

	/** Images in HTML: the editor's wp-image-N class, galleries and blocks by ID. */
	private static function read_content(string $html, array &$refs, array $images): void {
		if ($html === '' || !preg_match('/wp-image-|\[gallery|<!-- wp:/', $html)) return;
		$ids = [];
		if (preg_match_all('/wp-image-(\d+)/', $html, $m)) $ids = $m[1];
		if (preg_match_all('/\[gallery[^\]]*\bids="([\d,\s]+)"/', $html, $m)) foreach ($m[1] as $list) $ids = array_merge($ids, preg_split('/[\s,]+/', $list));
		// Every number in a block's attributes: each block names its image under a key of its own (`imgID`, `data.image`).
		if (preg_match_all('/<!-- wp:[\w\/-]+ (\{[^\n]*?\}) \/?-->/', $html, $m)) foreach ($m[1] as $json) self::numbers(json_decode($json, true), $ids);
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
		// A list of numbers, told without a pattern: one of 20,000 exhausts PCRE's JIT stack.
		if (strspn($value, "0123456789, \t\r\n") === strlen($value)) return array_map('intval', preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY));
		if (is_serialized($value)) $data = @unserialize($value, ['allowed_classes' => false]);
		elseif ($value[0] === '[' || $value[0] === '{') $data = json_decode($value, true);
		else return [];
		$ids = [];
		self::numbers($data, $ids);
		return $ids;
	}

	/** Every whole number in decoded data, at any depth, its strings read as values of their own. Objects too: theme mods keep header_image_data as one. */
	private static function numbers(mixed $data, array &$ids): void {
		if (is_int($data)) $ids[] = $data;
		elseif (is_string($data)) array_push($ids, ...self::ids($data));
		elseif (is_array($data) || is_object($data)) foreach ((array) $data as $v) self::numbers($v, $ids);
	}
}
