<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Http\Context;
use Cal6\Tests\TestCase;

final class ClockTest extends TestCase
{
    public function testTheTestClockWorksOnlyUnderTheBuiltInServer(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cal6-clock-');
        file_put_contents($file, '1000');
        putenv("CAL6_TEST_CLOCK=$file");
        $clock = new \ReflectionMethod(Context::class, 'clock');
        $now = $clock->invoke(null);
        putenv('CAL6_TEST_CLOCK');
        unlink($file);
        $this->assertTrue(abs($now - time()) < 5, 'not cli-server: the real time, whatever the environment says (' . PHP_SAPI . ')');
    }
}
