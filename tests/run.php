<?php

/**
 * Framework-free test runner for the plugin's pure helpers in src/Support.
 *
 * Usage: php tests/run.php
 *
 * Classes under src/Support must not depend on Laravel, Filament or the panel,
 * so they can be tested here without a panel install. Each tests/*Test.php file
 * registers cases with test() and checks them with assertSame()/assertTrue().
 */

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'JoanFo\\DiscordWebhooks\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** @var array<string, callable> $GLOBALS['__tests'] */
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

final class AssertionFailed extends RuntimeException {}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(trim($message . "\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function assertFalse(bool $condition, string $message = 'expected false'): void
{
    assertTrue(!$condition, $message);
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$failed = 0;
foreach ($GLOBALS['__tests'] as $name => $fn) {
    try {
        $fn();
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  {$name}\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
    }
}

$total = count($GLOBALS['__tests']);
echo "\n" . ($total - $failed) . "/{$total} passed\n";
exit($failed > 0 ? 1 : 0);
