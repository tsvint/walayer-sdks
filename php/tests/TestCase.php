<?php

declare(strict_types=1);

namespace WALayer\Tests;

/**
 * A deliberately tiny PHPUnit-shaped assertion base class.
 *
 * PHPUnit is not a runtime dependency of this SDK and is not assumed to be
 * installed, so `tests/run.php` drives these classes directly. The assertion
 * names match PHPUnit's, so migrating is a one-line change per file:
 * swap `use WALayer\Tests\TestCase` for `use PHPUnit\Framework\TestCase`.
 */
abstract class TestCase
{
    /** @var list<string> */
    public array $failures = [];

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail($message !== '' ? $message : \sprintf(
                'expected %s, got %s',
                $this->render($expected),
                $this->render($actual)
            ));
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) { // phpcs:ignore -- loose compare is the point
            $this->fail($message !== '' ? $message : \sprintf(
                'expected %s, got %s',
                $this->render($expected),
                $this->render($actual)
            ));
        }
    }

    public function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message !== '' ? $message : 'expected true, got ' . $this->render($actual));
    }

    public function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->assertSame(false, $actual, $message !== '' ? $message : 'expected false, got ' . $this->render($actual));
    }

    public function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message !== '' ? $message : 'expected null, got ' . $this->render($actual));
    }

    /** @param array<array-key,mixed> $haystack */
    public function assertArrayHasKey(string|int $key, array $haystack, string $message = ''): void
    {
        if (!\array_key_exists($key, $haystack)) {
            $this->fail($message !== '' ? $message : \sprintf('missing key "%s"', (string) $key));
        }
    }

    /** @param array<array-key,mixed> $haystack */
    public function assertArrayNotHasKey(string|int $key, array $haystack, string $message = ''): void
    {
        if (\array_key_exists($key, $haystack)) {
            $this->fail($message !== '' ? $message : \sprintf('unexpected key "%s"', (string) $key));
        }
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!\str_contains($haystack, $needle)) {
            $this->fail($message !== '' ? $message : \sprintf('"%s" not found in "%s"', $needle, $haystack));
        }
    }

    public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (\str_contains($haystack, $needle)) {
            $this->fail($message !== '' ? $message : \sprintf('"%s" unexpectedly present', $needle));
        }
    }

    public function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private function render(mixed $value): string
    {
        if (\is_scalar($value) || $value === null) {
            return \var_export($value, true);
        }
        $encoded = \json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? \gettype($value) : $encoded;
    }
}
