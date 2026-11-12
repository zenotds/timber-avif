<?php
/**
 * Unit tests for Templates' reading of the theme's Twig: `php tests/unit-templates.php`.
 */

use TimberAVIF\Optimize\Expr;
use TimberAVIF\Optimize\Templates;

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MB_IN_BYTES', 1048576);

spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'TimberAVIF\\')) require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
});

function wp_basename(string $path): string { return basename($path); }

$failures = 0;
$count = 0;
function check(string $name, $actual, $expected): void {
	global $failures, $count;
	$count++;
	if ($actual === $expected) return;
	$failures++;
	echo "FAIL  $name\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

$uses = function (array $sources, array $options = ['options']): array {
	$read = Templates::read_sources($sources, $options);
	$out = [];
	foreach ($read['uses'] as $u) $out[$u['scope'] . '|' . $u['path'] . ($u['ratio'] !== '' ? '@' . $u['ratio'] : '')] = $u['pixels'];
	ksort($out);
	return $out + ($read['untraced'] ? ['untraced' => count($read['untraced'])] : []);
};
$imp = "{% import '@timber-avif/macros.twig' as img %}";

/* ── A miss is never silent ── */

check('templates: a top-level import is seen inside the macros of the file', $uses([
	'cards.twig' => "$imp{% macro card(p) %}{{ img.image(p.thumbnail, { sizes: '100vw' }) }}{% endmacro %}",
	'list.twig' => "{% import 'cards.twig' as c %}{% for p in post.items %}{{ c.card(p) }}{% endfor %}",
	'self.twig' => "$imp{% macro card(p) %}{{ img.image(p.cover, { sizes: '100vw' }) }}{% endmacro %}{{ _self.card(post) }}",
]), ['post|cover' => 5120, 'post|items.*.thumbnail' => 5120]);

check('templates: a layout runs with the sets of the template that extends it', $uses([
	'base.twig' => "$imp{{ img.image(hero ?? post.thumbnail, { sizes: '100vw' }) }}{% block content %}{% endblock %}",
	'single.twig' => "{% extends 'base.twig' %}{% set hero = post.cover %}{% block content %}x{% endblock %}",
])['post|cover'] ?? null, 5120);

check('templates: a |default in the layout takes the value the extending template sets', $uses([
	'base.twig' => "$imp{{ img.image(post.thumbnail, { sizes: hero_sizes|default('300px') }) }}{% block content %}{% endblock %}",
	'single.twig' => "{% extends 'base.twig' %}{% set hero_sizes = '100vw' %}{% block content %}x{% endblock %}",
]), ['post|thumbnail' => 5120]);

check('templates: an include whose name is in a variable is followed, an unknown one listed', $uses([
	'page.twig' => "{% set tpl = 'card.twig' %}{% include tpl with { cover: post.cover } %}{% include other %}",
	'card.twig' => "$imp{{ img.image(cover, { sizes: '100vw' }) }}",
]), ['post|cover' => 5120, 'untraced' => 2]);

check('templates: the blocks of an embed run with what the embed passes', $uses([
	'page.twig' => "{% set sizes = '300px' %}{% set pic = post.small %}{% embed 'box.twig' with { pic: post.hero, sizes: '100vw' } %}{% block media %}$imp{{ img.image(pic, { sizes: sizes }) }}{% endblock %}{% endembed %}",
	'box.twig' => "{% block media %}{% endblock %}",
]), ['post|hero' => 5120]);

$chain = [];
for ($i = 0; $i < 18; $i++) $chain["l$i.twig"] = "{% include 'l" . ($i + 1) . ".twig' %}";
$chain['l18.twig'] = "$imp{{ img.image(post.thumbnail, { sizes: '100vw' }) }}";
$read = $uses($chain + ['x.twig' => "$imp{{ img.image(post.thumbnail, { sizes: '300px' }) }}"]);
check('templates: an include chain past the depth ceiling is listed, and its end still read', [$read['post|thumbnail'] ?? null, isset($read['untraced'])], [5120, true]);

$start = microtime(true);
$read = $uses([
	'page.twig' => "{% include 'tree.twig' with { node: post.tree } %}",
	'tree.twig' => "$imp{{ img.image(node.icon, { sizes: '100vw' }) }}{% include 'tree.twig' with { node: node.left } %}{% include 'tree.twig' with { node: node.right } %}{% include 'tree.twig' with { node: node.mid } %}",
]);
check('templates: a template including itself three ways ends at once, the recursion listed', [microtime(true) - $start < 2, $read['post|tree.icon'] ?? null, isset($read['untraced'])], [true, 5120, true]);

check('templates: a template reached only from itself or through a cycle is read', $uses([
	'menu.twig' => "$imp{{ img.image(item.icon, { sizes: '100vw' }) }}{% for item in item.children %}{% include 'menu.twig' %}{% endfor %}",
	'a.twig' => "$imp{{ img.image(post.hero, { sizes: '100vw' }) }}{% include 'b.twig' %}",
	'b.twig' => "{% include 'a.twig' %}",
]), ['any|icon' => 5120, 'post|hero' => 5120, 'untraced' => 2]);

check('templates: a literal ID given straight to the call', $uses([
	'a.twig' => "$imp{{ img.image(123, { sizes: '100vw' }) }}{{ image_sources(77, { sizes: '100vw' }) }}{{ 55|best_src(1600) }}",
]), ['id:123|' => 5120, 'id:55|' => 1600, 'id:77|' => 5120]);

check('templates: a print not read that calls an image is listed', $uses([
	'a.twig' => "$imp{{ img.image(post.thumbnail, { sizes: a === b ? '100vw' : '300px' }) }}",
]), ['untraced' => 1]);

check('templates: with, a block and a set of two names do not leave the includer a narrower value', [
	$uses([
		'page.twig' => "{% include 'card.twig' with { sizes: '100vw' } %}",
		'card.twig' => "$imp{% with { sizes: '300px' } %}{{ img.image(post.thumb, { sizes: sizes }) }}{% endwith %}{{ img.image(post.hero, { sizes: sizes }) }}",
	]),
	$uses([
		'page.twig' => "{% include 'card.twig' with { sizes: '100vw' } %}",
		'card.twig' => "$imp{% block a %}{% set sizes = '300px' %}{% endblock %}{{ img.image(post.thumbnail, { sizes: sizes }) }}",
	]),
	$uses([
		'page.twig' => "{% include 'card.twig' with { sizes: '300px' } %}",
		'card.twig' => "$imp{% set sizes, r = '100vw', '1/1' %}{{ img.image(post.thumbnail, { sizes: sizes }) }}",
	]),
], [['post|hero' => 5120, 'post|thumb' => 600], ['post|thumbnail' => 5120], ['post|thumbnail' => null]]);

check('expr: trailing tokens are not read', [Expr::parse('a b c'), Expr::parse('f(a) g(b)'), Expr::parse('a ? b : c d')], [null, null, null]);

echo $failures ? "\n$failures of $count checks failed.\n" : "$count checks passed.\n";
exit($failures ? 1 : 0);
