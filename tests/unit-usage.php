<?php
/**
 * Unit tests for Optimize\Usage, the reading of the database, on a stubbed $wpdb and ACF:
 * `php tests/unit-usage.php`.
 */

use TimberAVIF\Optimize\Usage;

spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'TimberAVIF\\')) require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
});

$failures = 0;
$count = 0;
function check(string $name, $actual, $expected): void {
	global $failures, $count;
	$count++;
	if ($actual === $expected) return;
	$failures++;
	echo "FAIL  $name\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

/* ── WordPress, ACF and the database, as far as Usage reads them ── */

function esc_sql($s) { return $s; }
function is_serialized($data): bool {
	if (!is_string($data)) return false;
	$data = trim($data);
	if ($data === 'N;' || $data === 'b:0;') return true;
	return strlen($data) >= 4 && $data[1] === ':' && @unserialize($data) !== false;
}
function maybe_unserialize($data) { return is_serialized($data) ? @unserialize(trim($data)) : $data; }
function get_option(string $name, $default = false) { return $GLOBALS['wpdb']->option($name) ?? $default; }

$FIELDS = [
	'field_img'       => ['name' => 'hero', 'type' => 'image'],
	'field_banner'    => ['name' => 'banner', 'type' => 'image'],
	'field_wide'      => ['name' => 'banner_5_wide', 'type' => 'image'],
	'field_crop'      => ['name' => 'cropped', 'type' => 'image_aspect_ratio_crop'],
	'field_rel'       => ['name' => 'media', 'type' => 'post_object'],
	'field_text'      => ['name' => 'caption', 'type' => 'text'],
	'field_menuimg'   => ['name' => 'menu_image', 'type' => 'image'],
	'field_slides'    => ['name' => 'slides', 'type' => 'repeater'],
	'field_slide_img' => ['name' => 'image', 'type' => 'image', 'parent' => 'field_slides'],
];
function acf_get_field($key) { return $GLOBALS['FIELDS'][$key] ?? false; }

/** Tables as lists of rows; each query answered the way MySQL would for the few Usage sends. */
final class DB {
	public $posts = 'wp_posts', $postmeta = 'wp_postmeta', $termmeta = 'wp_termmeta', $usermeta = 'wp_usermeta', $options = 'wp_options';
	public array $P = [], $PM = [], $TM = [], $UM = [], $O = [];
	public array $queries = [];
	public int $widest = 0; // most rows one termmeta or usermeta query returned

	public function prepare(string $query, ...$args): string { return vsprintf(str_replace('%d', '%s', $query), $args); }
	public function option(string $name): ?string {
		foreach ($this->O as $o) if ($o->option_name === $name) return $o->option_value;
		return null;
	}
	public function get_col(string $query): array {
		preg_match('/^SELECT (?:DISTINCT )?(?:\w+\.)?(\w+)/', $query, $m);
		return array_map(fn($row) => $row->{$m[1]}, $this->get_results($query));
	}

	public function get_results(string $query): array {
		$this->queries[] = $query;
		$in = fn(string $col) => preg_match("/$col IN \(([\d,]+)\)/", $query, $m) ? array_map('intval', explode(',', $m[1])) : null;
		$after = preg_match('/> (\d+) ORDER BY \w+ LIMIT (\d+)/', $query, $m) ? [(int) $m[1], (int) $m[2]] : null;

		if (str_contains($query, 'FROM wp_posts')) return array_slice(array_values(array_filter($this->P, fn($p) => $p->ID > $after[0])), 0, $after[1]);
		if (str_contains($query, 'FROM wp_postmeta')) {
			$ids = $in('post_id');
			return array_values(array_filter($this->PM, fn($m) => in_array($m->post_id, $ids, true)));
		}
		if (str_contains($query, 'FROM wp_termmeta')) return $this->owned($query, $this->TM, 'term_id', $in('term_id'), $after);
		if (str_contains($query, 'FROM wp_usermeta')) {
			$rows = array_values(array_filter($this->UM, fn($u) => str_starts_with((string) $this->meta($u->user_id, '_' . $u->meta_key), 'field_')));
			if (!str_contains($query, 'JOIN')) $rows = $this->UM;
			return $this->owned($query, $rows, 'user_id', $in('u.user_id'), $after);
		}
		if (str_contains($query, 'FROM wp_options')) {
			if (str_contains($query, 'JOIN')) {
				$rows = [];
				foreach ($this->O as $o) {
					$key = $this->option('_' . $o->option_name);
					if ($key !== null && str_starts_with($key, 'field_') && !str_starts_with($o->option_name, 'options_')) $rows[] = (object) ['option_name' => $o->option_name, 'option_value' => $o->option_value, 'field_key' => $key];
				}
				return $rows;
			}
			preg_match_all("/option_name LIKE '([^']+)'/", $query, $m);
			$likes = array_map(fn($p) => '/^' . str_replace(['\\\\_', '%'], ['_', '.*'], preg_quote($p, '/')) . '$/', $m[1]);
			return array_values(array_filter($this->O, function ($o) use ($likes) {
				foreach ($likes as $re) if (preg_match($re, $o->option_name)) return true;
				return false;
			}));
		}
		return [];
	}

	private function meta(int $user, string $key): ?string {
		foreach ($this->UM as $u) if ($u->user_id === $user && $u->meta_key === $key) return $u->meta_value;
		return null;
	}

	/** Rows of a meta table: one owner ID per row for SELECT DISTINCT, those of some owners, or all. */
	private function owned(string $query, array $rows, string $owner, ?array $ids, ?array $after): array {
		if (str_contains($query, 'DISTINCT')) {
			$owners = array_unique(array_map(fn($r) => $r->$owner, $rows));
			sort($owners);
			$owners = array_slice(array_values(array_filter($owners, fn($id) => $id > $after[0])), 0, $after[1]);
			return array_map(fn($id) => (object) [$owner => $id], $owners);
		}
		if ($ids !== null) $rows = array_values(array_filter($rows, fn($r) => in_array($r->$owner, $ids, true)));
		$this->widest = max($this->widest, count($rows));
		return $rows;
	}
}

$wpdb = new DB;
$images = array_fill_keys(range(1, 400), true);
$post = fn(int $id, string $content = '', string $type = 'page', string $status = 'publish') => (object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_content' => $content];
$meta = fn(int $id, string $key, string $value) => (object) ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value];
$option = fn(string $name, string $value) => (object) ['option_name' => $name, 'option_value' => $value];

/** The refs of the posts given, scanned as Optimize does: batch after batch. */
function posts(array $posts, array $meta): array {
	global $wpdb, $images;
	$wpdb->P = $posts;
	$wpdb->PM = $meta;
	$refs = [];
	$after = 0;
	while ($after = Usage::scan_posts($after, $refs, $images));
	ksort($refs);
	return $refs;
}

/* ── Meta ── */

$refs = posts([$post(1)], [$meta(1, 'mb_gallery', '150'), $meta(1, 'mb_gallery', '151'), $meta(1, 'mb_gallery', '152')]);
check('every row of a repeated meta key is read', array_keys($refs), [150, 151, 152]);

$refs = posts([$post(1)], [
	$meta(1, '_elementor_data', '[{"settings":{"image":{"url":"x","id":140}}}]'),
	$meta(1, 'slider', '[141,142]'),
	$meta(1, 'x', 's:3:"143";'),
	$meta(1, 'floaty', '144.0'),
]);
check('IDs in JSON and in a serialized string', array_keys($refs), [140, 141, 142, 143]);

$refs = posts([$post(1)], [
	$meta(1, 'cropped', '180'), $meta(1, '_cropped', 'field_crop'),
	$meta(1, 'media', '181'), $meta(1, '_media', 'field_rel'),
	$meta(1, 'caption', '182'), $meta(1, '_caption', 'field_text'),
]);
check('an ACF field of a type not known keeps its image; a text field names none', $refs, [180 => ['o' => true], 181 => ['o' => true]]);

$refs = posts([$post(1)], [
	$meta(1, 'banner', '3'), $meta(1, '_banner', 'field_banner'),
	$meta(1, 'banner_5_wide', '170'), $meta(1, '_banner_5_wide', 'field_wide'),
	$meta(1, 'slides', '2'), $meta(1, '_slides', 'field_slides'),
	$meta(1, 'slides_5_image', '171'), $meta(1, '_slides_5_image', 'field_slide_img'),
	$meta(1, 'gone', '0'), $meta(1, '_gone', 'field_gone'),
	$meta(1, 'gone_3_image', '172'), $meta(1, '_gone_3_image', 'field_slide_img'),
]);
check('a numeric field is no repeater; a removed row is; a container ACF does not know keeps its image', $refs, [
	3   => ['p' => ['post|banner' => true]],
	170 => ['p' => ['post|banner_5_wide' => true]],
	172 => ['o' => true],
]);

$refs = posts([$post(1, '', 'nav_menu_item'), $post(2, '', 'attachment', 'inherit'), $post(3, '', 'revision', 'inherit')], [
	$meta(1, 'menu_image', '160'), $meta(1, '_menu_image', 'field_menuimg'),
	$meta(2, 'menu_image', '161'), $meta(2, '_menu_image', 'field_menuimg'),
	$meta(2, '_wp_attached_file', '2026/09/a.jpg'), $meta(2, '_wp_attachment_metadata', serialize(['width' => 300, 'height' => 200])), $meta(2, '_thumbnail_id', '2'),
	$meta(3, 'menu_image', '162'), $meta(3, '_menu_image', 'field_menuimg'),
]);
check('the fields of a menu item and of an attachment, not the attachment itself nor a revision', $refs, [160 => ['o' => true], 161 => ['o' => true]]);

$long = implode(',', range(1, 20000));
check('a list of 20,000 IDs', count(posts([$post(1)], [$meta(1, 'ids', $long)])), 400);

/* ── Content ── */

$refs = posts([$post(1, '<!-- wp:acf/hero {"name":"acf/hero","data":{"image":123,"_image":"field_img","gallery":["124","125"]}} /-->' . "\n"
	. '<!-- wp:gallery {"ids":[126]} --><!-- /wp:gallery -->' . "\n"
	. '<!-- wp:kadence/image {"imgID":127,"uniqueID":"x"} /-->')], []);
check('images of blocks under any attribute', array_keys($refs), [123, 124, 125, 126, 127]);

posts([$post(1)], []);
$query = (string) current(array_filter($wpdb->queries, fn($q) => str_contains($q, 'FROM wp_posts')));
check('the content of revisions and attachments is not fetched', (bool) preg_match("/IF\(post_type IN \([^)]*'revision'[^)]*'attachment'.*, '', post_content\) AS post_content/", $query), true);

/* ── Terms, users ── */

$wpdb->TM = $wpdb->UM = [];
foreach (range(1, 450) as $id) {
	$wpdb->TM[] = (object) ['term_id' => $id, 'meta_key' => 'thumbnail_id', 'meta_value' => (string) ($id % 400 + 1)];
	$wpdb->UM[] = (object) ['user_id' => $id, 'meta_key' => 'portrait', 'meta_value' => (string) ($id % 400 + 1)];
	$wpdb->UM[] = (object) ['user_id' => $id, 'meta_key' => '_portrait', 'meta_value' => 'field_img'];
}
$refs = [];
$wpdb->widest = 0;
Usage::scan_terms($refs, $images);
Usage::scan_users($refs, $images);
check('term and user meta are read by batches, all of them', [$wpdb->widest <= 2 * Usage::BATCH, count($refs)], [true, 400]);

/* ── Options ── */

$header = new stdClass;
$header->attachment_id = 200;
$header->url = 'x';
$wpdb->O = [
	$option('theme_mods_x', serialize(['custom_logo' => 201, 'header_image_data' => $header, 'hero_bg' => 202, 'footer_image' => '203'])),
	$option('widget_media_gallery', serialize([2 => ['title' => '', 'ids' => [204, 205], 'size' => 'thumbnail']])),
	$option('widget_media_image', serialize([2 => ['attachment_id' => 206]])),
	$option('widget_block', serialize([2 => ['content' => '<!-- wp:image {"id":207} --><img class="wp-image-207"/>']])),
	$option('theme_settings_logo', '208'), $option('_theme_settings_logo', 'field_img'),
	$option('options_hero', '209'), $option('_options_hero', 'field_img'),
	$option('woocommerce_placeholder_image', '210'),
	$option('posts_per_page', '10'),
];
$refs = [];
Usage::scan_options($refs, $images);
ksort($refs);
check('images of theme mods, widgets, options pages of any name, WooCommerce; not every number', array_keys($refs), range(200, 210));
check('an options page field keeps its place', $refs[209] ?? null, ['p' => ['option|hero' => true]]);

echo $failures ? "\n$failures of $count checks failed.\n" : "$count checks passed.\n";
exit($failures ? 1 : 0);
