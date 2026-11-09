<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Http\RateLimiter;
use Cal6\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $dir = '';

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cal6-limits-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    public function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*") ?: []);
        rmdir($this->dir);
    }

    public function testHitForgiveAndWindow(): void
    {
        $limiter = new RateLimiter($this->dir, 900);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, $limiter->hit('k', 1000 + $i, 3));
        }
        $this->assertSame(897, $limiter->hit('k', 1003, 3), 'the fourth waits until the first leaves the window (1000 + 900 - 1003)');
        $limiter->forgive('k');
        $this->assertSame(0, $limiter->hit('k', 1004, 3), 'a forgiven attempt does not count');
        $this->assertSame(0, $limiter->hit('k', 1900, 3), 'old attempts leave the window');
        $this->assertSame(0, $limiter->hit('other', 1004, 3), 'keys are separate');
    }

    public function testUnavailableFailsClosed(): void
    {
        $limiter = new RateLimiter(null, 900);
        $this->assertFalse($limiter->available());
        $this->assertTrue($limiter->hit('k', 1, 5) > 0, 'without storage nothing is allowed');
    }
}
