<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Course\Boundary;
use Cal6\Course\Course;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

final class BoundaryTest extends TestCase
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

    public function testNothingOutsideOrClosedIsReachable(): void
    {
        $this->outside = sys_get_temp_dir() . '/cal6-outside-' . bin2hex(random_bytes(4));
        mkdir($this->outside);
        file_put_contents("$this->outside/secret.html", 'OUTSIDE');
        $this->dir = Fixture::course([
            'config/virtual.ini' => "class_1/v1.html = ../outside.html\nclass_1/v2.html = /etc/passwd\nclass_1/v3.html = config/config.ini\nclass_1/ok.md = shared/checklist.md\n",
            'config/paths.ini' => "[pages]\nhome = pages/home.html\nescape = ../x.html\nconf = config/config.ini\n",
        ]);
        symlink($this->outside, "$this->dir/class/01.Introduction/out");
        symlink("$this->outside/secret.html", "$this->dir/class/01.Introduction/linked.html");
        symlink("$this->dir/config/auth/prof.ini", "$this->dir/class/01.Introduction/prof.txt");
        symlink("$this->dir/config", "$this->dir/class/01.Introduction/conf");
        $course = Course::load($this->dir);
        $names = array_keys($course->events['class_1']->files);
        sort($names);
        $this->assertSame(['index.html', 'notes.md', 'ok.md'], $names, 'links out of the course or into config/ are not material');
        $this->assertSame(['home'], array_keys($course->pageKeys), 'pages outside the course or in config/ are refused');
        $messages = implode("\n", array_map(static fn ($p): string => $p->file . ' ' . $p->message, $course->problems));
        $this->assertSame(3, substr_count($messages, 'virtual.ini the real file is missing or outside the course'));

        $boundary = new Boundary($this->dir);
        $this->assertSame(null, $boundary->resolveRelative('../etc'));
        $this->assertSame(null, $boundary->resolveRelative('class/../config/config.ini'));
        $this->assertSame(null, $boundary->resolve("$this->dir/calendar/php/get.php"), 'the software');
        $this->assertSame(null, $boundary->resolve("$this->dir/class/.hidden"));
    }

    public function testServerSideFilesUnderOtherNames(): void
    {
        foreach (['a.php', 'a.PHP5', 'a.phtml', 'a.phps', 'a.pht', 'db.inc', 'grades.php~', 'db.php.bak', 'x.inc.old', 'config.ini', '.htaccess'] as $name) {
            $this->assertTrue(Boundary::neverServed($name), "never served: $name (L-28)");
        }
        foreach (['index.html~', 'notes.md.bak', 'x.html.orig', '#index.html#', 'a.htm.1', 'b.html.swp'] as $name) {
            $this->assertTrue(Boundary::neverServed($name), "a page backup is never served (M-22): $name");
        }
        foreach (['notes.html', 'phpinfo.pdf', 'include.txt', 'slides.pptx', 'report.html.pdf', 'notes (copy).html'] as $name) {
            $this->assertFalse(Boundary::neverServed($name), "served: $name");
        }
    }

    public function testAConfigDirectoryElsewhereInTheCourseIsStillClosed(): void
    {
        $this->dir = Fixture::course();
        rename("$this->dir/config", "$this->dir/private");
        symlink("$this->dir/private", "$this->dir/config");
        symlink("$this->dir/private/auth/prof.ini", "$this->dir/class/01.Introduction/prof.txt");
        $boundary = new Boundary($this->dir);
        $this->assertSame(null, $boundary->resolve("$this->dir/class/01.Introduction/prof.txt"), 'compared by real path (L-11)');
        $this->assertSame(null, $boundary->resolve("$this->dir/private/auth/prof.ini"));
    }
}
