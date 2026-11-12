<?php
/**
 * Unit tests for the core classes, the parts that need no WordPress: `php tests/unit-core.php`.
 */

use TimberAVIF\Engine;

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MB_IN_BYTES', 1048576);

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

/* ── Engine::is_animated, GIF ── */

$fixtures = __DIR__ . '/fixtures';
// Three 200×150 frames, ~12.6 KB each: every frame starts in a read of its own.
check('gif: an animation whose frames are longer than one read', Engine::is_animated("$fixtures/animated-big.gif", 'image/gif'), true);
check('gif: a small animation', Engine::is_animated("$fixtures/animated-small.gif", 'image/gif'), true);
check('gif: a still image', Engine::is_animated("$fixtures/still.gif", 'image/gif'), false);

// A graphic control extension, as is_animated() looks for it, among filler that never matches.
$gce = "\x00\x21\xF9\x04\x00\x00\x00\x00\x00\x2C";
$gif = function (array $at, int $length) use ($gce): string {
	$data = 'GIF89a' . str_repeat('x', $length - 6);
	foreach ($at as $offset) $data = substr_replace($data, $gce, $offset, strlen($gce));
	$path = tempnam(sys_get_temp_dir(), 'tavif-gif');
	file_put_contents($path, $data);
	return $path;
};

$path = $gif([8180], 20000);
check('gif: one frame in the bytes carried over to the next read is counted once', Engine::is_animated($path, 'image/gif'), false);
unlink($path);

$path = $gif([8188], 20000);
check('gif: one frame across a read boundary is one frame', Engine::is_animated($path, 'image/gif'), false);
unlink($path);

$path = $gif([8188, 15000], 20000);
check('gif: a frame across a read boundary counts', Engine::is_animated($path, 'image/gif'), true);
unlink($path);

echo $failures ? "\n$failures of $count checks failed.\n" : "$count checks passed.\n";
exit($failures ? 1 : 0);
