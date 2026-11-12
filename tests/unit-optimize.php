<?php
/**
 * Unit tests for the parts of Optimize that need no WordPress: `php tests/unit-optimize.php`.
 */

use TimberAVIF\Optimize;

define('ABSPATH', __DIR__ . '/');

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

/* ── Optimize::stale_ratios ── */

$needs = ['' => 1280, '16x9' => 1024];
check('a crop no use needs is stale', Optimize::stale_ratios(['4x1', '16x9'], $needs, false, false), ['4x1']);
check('a crop a use needs is not', Optimize::stale_ratios(['16x9'], $needs, false, false), []);
check('a width asked for uncropped is no crop', Optimize::stale_ratios(['', ''], ['16x9' => 1024], false, false), []);
check('wants of several attachments name a ratio once', Optimize::stale_ratios(['4x1', '4x1', '1x1'], $needs, false, false), ['4x1', '1x1']);
check('a use whose ratio is not known keeps every want', Optimize::stale_ratios(['4x1'], $needs, true, false), []);
check('a template call not followed keeps every want', Optimize::stale_ratios(['4x1'], $needs, false, true), []);
check('a need of every width counts as a need', Optimize::stale_ratios(['4x1'], ['4x1' => null], false, false), []);
// A numeric ratio key ('2') is an integer as an array key.
check('a numeric ratio key matches its need', Optimize::stale_ratios(['2', '1.5'], ['2' => 640], false, false), ['1.5']);

echo $failures ? "\n$failures of $count checks failed.\n" : "$count checks passed.\n";
exit($failures ? 1 : 0);
