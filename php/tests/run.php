<?php

declare(strict_types=1);

namespace WALayer\Tests;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Minimal test runner. PHPUnit is not assumed to be installed anywhere this
 * SDK is developed, so the suite is driven directly: every `test*` method on
 * every TestCase subclass is invoked, and the process exits non-zero on the
 * first failure count above zero.
 *
 *   php tests/run.php
 *
 * The test classes are written against a PHPUnit-shaped assertion API, so
 * dropping PHPUnit in later means changing the `use` line in each test file.
 */

require __DIR__ . '/bootstrap.php';

/** @var list<class-string<TestCase>> */
$suites = [ClientTest::class, WebhookTest::class];

$passed = 0;
/** @var list<string> $failures */
$failures = [];

foreach ($suites as $suite) {
    $reflection = new ReflectionClass($suite);
    echo $reflection->getShortName() . "\n";

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!\str_starts_with($method->getName(), 'test')) {
            continue;
        }

        $instance = new $suite();
        try {
            $method->invoke($instance);
            ++$passed;
            echo '  ok   ' . $method->getName() . "\n";
        } catch (Throwable $e) {
            $label = $suite . '::' . $method->getName();
            $failures[] = $label . ' — ' . $e->getMessage();
            echo '  FAIL ' . $method->getName() . ' — ' . $e->getMessage() . "\n";
        }
    }
}

echo "\n";
echo \sprintf("%d passed, %d failed\n", $passed, \count($failures));

if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit(1);
}

exit(0);
