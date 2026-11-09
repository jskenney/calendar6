<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Course\Course;
use Cal6\Http\Fingerprint;
use Cal6\Http\ModelCache;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

final class CacheTest extends TestCase
{
    private string $dir = '';
    private string $outside = '';

    public function tearDown(): void
    {
        Fixture::remove($this->dir);
        if ($this->outside !== '') {
            array_map('unlink', glob("$this->outside/*") ?: []);
            @rmdir($this->outside);
        }
    }

    public function testModelCacheRoundTripAndSignature(): void
    {
        $this->dir = Fixture::course();
        $course = Course::load($this->dir);
        $state = sys_get_temp_dir() . '/cal6-model-' . bin2hex(random_bytes(4));
        mkdir($state, 0700);
        $cache = new ModelCache($state, Fixture::SECRET);
        $cache->put('fp1', $course);
        $this->assertNotContains(Fixture::SECRET, (string) file_get_contents("$state/model"), 'the secret is not cached (L-15)');
        $this->assertSame(Fixture::SECRET, $course->config->secret, 'and the model keeps it');
        $back = $cache->get('fp1');
        $this->assertTrue($back instanceof Course, 'read back');
        $this->assertSame(Fixture::SECRET, $back?->config->secret, 'restored from config.ini when read');
        $this->assertSame(array_keys($course->places), array_keys($back?->places ?? []), 'the same keys');
        $this->assertSame(null, $cache->get('fp2'), 'another fingerprint');
        $this->assertSame(null, (new ModelCache($state, 'another-secret-another-secret-123'))->get('fp1'), 'a signature made with another secret is refused');
        $raw = (string) file_get_contents("$state/model");
        file_put_contents("$state/model", substr($raw, 0, -5) . 'XXXXX');
        $this->assertSame(null, $cache->get('fp1'), 'a changed file is refused');
        unlink("$state/model");
        rmdir($state);
    }

    public function testFingerprintIgnoresLinksOutOfTheCourse(): void
    {
        $this->dir = Fixture::course();
        $this->outside = sys_get_temp_dir() . '/cal6-outside-' . bin2hex(random_bytes(4));
        mkdir($this->outside);
        symlink($this->outside, "$this->dir/class/99.Link");
        $before = Fingerprint::of($this->dir);
        file_put_contents("$this->outside/new.txt", 'x');
        $this->assertSame($before, Fingerprint::of($this->dir), 'not followed (M-10)');
        file_put_contents("$this->dir/class/01.Introduction/new.txt", 'x');
        $this->assertTrue($before !== Fingerprint::of($this->dir), 'changes inside are seen');
    }

    public function testCacheKeysChangeWithTheSoftware(): void
    {
        $this->dir = Fixture::course();
        $key = Fingerprint::key($this->dir);
        $this->assertSame($key, Fingerprint::key($this->dir), 'stable');
        $this->assertTrue($key !== Fingerprint::of($this->dir), 'not the course fingerprint alone');
        $software = new \ReflectionProperty(Fingerprint::class, 'software');
        $before = $software->getValue();
        $software->setValue(null, 'another-version');
        $this->assertTrue(Fingerprint::key($this->dir) !== $key, 'another software version misses every cache (M-11, T-7)');
        $software->setValue(null, $before);
    }

    public function testSoftwareVersionFollowsContentsNotTimes(): void
    {
        // on a copy of lib/ and php/, never on the repository itself (T-10)
        $this->outside = sys_get_temp_dir() . '/cal6-software-' . bin2hex(random_bytes(4));
        mkdir("$this->outside/lib/Http", 0777, true);
        mkdir("$this->outside/php");
        file_put_contents("$this->outside/lib/Http/A.php", "<?php // one\n");
        file_put_contents("$this->outside/php/get.php", "<?php // get\n");
        $first = Fingerprint::softwareOf($this->outside);
        touch("$this->outside/lib/Http/A.php", time() - 3600);
        $this->assertSame($first, Fingerprint::softwareOf($this->outside), 'a new time alone is not a new version');
        file_put_contents("$this->outside/lib/Http/A.php", "<?php // two\n");
        touch("$this->outside/lib/Http/A.php", time() - 3600);
        $this->assertTrue($first !== Fingerprint::softwareOf($this->outside), 'a same-size change with an old time is (L-18)');
        $this->assertSame(Fingerprint::softwareOf(dirname(__DIR__, 2)), Fingerprint::software(), 'the running software');
        unlink("$this->outside/lib/Http/A.php");
        unlink("$this->outside/php/get.php");
        rmdir("$this->outside/lib/Http");
        rmdir("$this->outside/lib");
        rmdir("$this->outside/php");
        rmdir($this->outside);
        $this->outside = '';
    }
}
