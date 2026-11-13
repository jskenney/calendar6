<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Http\Locator;
use Cal6\Tests\TestCase;

/** Finding the course through course.ini (E-14) and the URLs written from the request. */
final class LocatorTest extends TestCase
{
    private string $dir = '';

    public function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testInnerCourseIniOnlyForARealDirectory(): void
    {
        $this->dir = sys_get_temp_dir() . '/cal6-locator-' . bin2hex(random_bytes(6));
        mkdir("$this->dir/web/clone", 0777, true);
        mkdir("$this->dir/shared");
        file_put_contents("$this->dir/web/clone/course.ini", "[course]\nroot = /x\n");
        file_put_contents("$this->dir/shared/course.ini", "[course]\nroot = /x\n");
        symlink("$this->dir/shared", "$this->dir/web/linked");
        $find = new \ReflectionMethod(Locator::class, 'courseIni');
        $this->assertSame("$this->dir/web/clone/course.ini", $find->invoke(null, "$this->dir/web/clone"), 'a clone for one course may hold its course.ini');
        $this->assertSame(null, $find->invoke(null, "$this->dir/web/linked"), 'a shared copy reached through a link never hands out its course.ini (L-147)');
        file_put_contents("$this->dir/web/course.ini", "[course]\nroot = /y\n");
        $this->assertSame("$this->dir/web/course.ini", $find->invoke(null, "$this->dir/web/clone"), 'the web directory\'s course.ini comes first');
    }

    public function testWrittenPathsArePercentEncoded(): void
    {
        $server = ['SCRIPT_NAME' => '/A#1 x/my cal/php/get.php'];
        $this->assertSame('/A%231%20x/', Locator::coursePath($server), 'the cookie path as browsers compare it (L-149)');
        $this->assertSame('my%20cal/', Locator::softwarePath($server));
        $this->assertSame('/~bob/AB123/', Locator::coursePath(['SCRIPT_NAME' => '/~bob/AB123/calendar/php/get.php']), 'a usual path is unchanged');
        $this->assertSame('calendar/', Locator::softwarePath(['SCRIPT_NAME' => '/~bob/AB123/calendar/php/get.php']));
    }
}
