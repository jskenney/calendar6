<?php

declare(strict_types=1);

namespace Cal6\Tests;

abstract class TestCase
{
    /** @var list<string> */
    public array $failures = [];
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function check(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $line = $trace[1]['line'] ?? 0;
            $this->failures[] = "line $line: $message";
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected === $actual, trim($message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
    }

    protected function assertTrue(bool $actual, string $message = ''): void
    {
        $this->check($actual, $message !== '' ? $message : 'expected true');
    }

    protected function assertFalse(bool $actual, string $message = ''): void
    {
        $this->check(!$actual, $message !== '' ? $message : 'expected false');
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(str_contains($haystack, $needle), trim("$message: expected to find " . var_export($needle, true) . ' in ' . var_export(mb_substr($haystack, 0, 600), true)));
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(!str_contains($haystack, $needle), trim("$message: did not expect " . var_export($needle, true)));
    }

    /** @param callable(): mixed $fn */
    protected function assertThrows(callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            $this->check(true, $message);
            return;
        }
        $this->check(false, $message !== '' ? $message : 'expected an exception');
    }
}
