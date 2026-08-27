<?php

declare(strict_types=1);

/**
 * Test bootstrap. Uses Composer's autoloader when the package has been
 * installed, and otherwise registers an equivalent PSR-4 loader — so the suite
 * runs on a bare PHP with nothing fetched, matching the SDK's own promise of
 * zero runtime dependencies.
 */

$root = \dirname(__DIR__);

if (\is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';

    return;
}

\spl_autoload_register(static function (string $class) use ($root): void {
    // Longest prefix first: WALayer\Tests\ is a sub-namespace of WALayer\.
    $prefixes = [
        'WALayer\\Tests\\' => $root . '/tests/',
        'WALayer\\' => $root . '/src/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        if (!\str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = \substr($class, \strlen($prefix));
        $file = $dir . \str_replace('\\', '/', $relative) . '.php';
        if (\is_file($file)) {
            require $file;
        }

        return;
    }
});

// Namespaced functions are not autoloadable; Composer lists this under `files`.
require $root . '/src/functions.php';
