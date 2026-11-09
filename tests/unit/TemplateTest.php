<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Course\Course;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

/** The course template is where every course starts: it must load cleanly (T-2). */
final class TemplateTest extends TestCase
{
    private string $dir = '';

    public function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    public function testTemplateLoadsWithoutProblems(): void
    {
        $this->dir = Fixture::course();
        $course = Course::load($this->dir);
        $this->assertSame([], array_map(static fn ($p): string => "$p->file:$p->line $p->message", $course->problems), 'no problems');
        $this->assertSame(['home', 'resources', 'policy'], array_keys($course->pageKeys));
        $this->assertSame(['class_1', 'lab_1'], array_keys($course->events));
        $this->assertSame(['checklist.md', 'index.html', 'lab-solution.html'], array_keys($course->events['lab_1']->files), 'with the virtual file');
        $this->assertSame(['normal', 'early', 'no_midday_break', '2_hour_delay', '4_hour_delay'], array_keys($course->config->periods->schedules));
        $this->assertSame(3, count($course->config->sections->sections));
        foreach (glob(dirname(__DIR__, 2) . '/template/config/*.ini') ?: [] as $file) {
            $this->assertNotContains("\t", (string) file_get_contents($file), basename($file) . ': no tabs');
        }
    }
}
