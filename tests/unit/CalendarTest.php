<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Course\Course;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

final class CalendarTest extends TestCase
{
    private string $dir = '';

    public function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    private function course(string $dates, string $duplicates = '', string $end = '2026-09-30'): Course
    {
        $files = [
            'config/dates.ini' => $dates,
            'config/duplicate.ini' => $duplicates,
            'config/virtual.ini' => '',
            'class/01.Introduction' => null,
        ];
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $files['config/config.ini'] = str_replace('end      = 2026-12-18', 'end      = ' . $end, $config);
        for ($i = 1; $i <= 10; $i++) {
            $files[sprintf('class/%02d.Class %d/index.html', $i, $i)] = "<p>class $i</p>";
        }
        for ($i = 2; $i <= 3; $i++) {
            $files[sprintf('lab/%02d.Lab %d/index.html', $i, $i)] = "<p>lab $i</p>";
        }
        $this->dir = Fixture::course($files);
        return Course::load($this->dir);
    }

    /** @return array<string, list<string>> date => event ids */
    private static function days(Course $course): array
    {
        $out = [];
        foreach ($course->days as $key => $day) {
            if ($day->events !== []) {
                $out[$key] = $day->events;
            }
        }
        return $out;
    }

    public function testPlacementWithDatesAndDuplicates(): void
    {
        $course = $this->course(
            "[days]\n2026-08-31 = holiday | Holiday\n2026-09-01 = mon | Monday schedule\n2026-09-02 = lab, class\n"
            . "2026-09-03 = class, class, class\n2026-09-04 = period 2_hour_delay | Delay\n",
            "class_6 = class_2\nclass_8 = class_3 same\n",
            '2026-09-04'
        );
        $this->assertSame([], array_map(static fn ($p): string => $p->message, $course->problems), 'no problems');
        $days = self::days($course);
        $this->assertSame(['class_1'], $days['2026-08-24']);
        $this->assertSame(['class_2'], $days['2026-08-25']);
        $this->assertSame(['lab_1'], $days['2026-08-26'], 'the lab directory 01 is lab 1');
        $this->assertSame(['class_3'], $days['2026-08-28']);
        $this->assertFalse(isset($days['2026-08-31']), 'the holiday moves the calendar');
        $this->assertSame(['class_4'], $days['2026-09-01'], 'Tuesday follows Monday');
        $this->assertSame(['lab_2', 'class_5'], $days['2026-09-02'], 'several events, in the order written');
        $this->assertSame(['class_6', 'class_7', 'class_3@2026-09-03'], $days['2026-09-03'], 'three classes: a numbered copy, the next class, a "same" repeat');
        $this->assertSame(['class_8'], $days['2026-09-04']);

        $copy = $course->events['class_6'];
        $this->assertSame('Class 2', $copy->title(), 'a numbered copy has the source material');
        $this->assertSame('class_2', $copy->copyOf);
        $this->assertSame('Class 6', $course->events['class_7']->title(), 'directory 6 moves to class 7');
        $same = $course->events['class_3@2026-09-03'];
        $this->assertSame(3, $same->number, 'a "same" repeat keeps the number');
        $this->assertSame('class_3', $same->target());
        $this->assertSame('Class 7', $course->events['class_8']->title());

        $this->assertSame('07:45', $course->events['class_4']->time?->start?->format('H:i'), 'Monday sections');
        $this->assertSame('15:30', $course->events['class_4']->time?->end?->format('H:i'));
        $this->assertSame('09:45', $course->events['class_6']->time?->start?->format('H:i'), 'Thursday sections: R34 and R56');
        $this->assertSame('09:45', $course->events['class_8']->time?->start?->format('H:i'), '2-hour delay');
        $this->assertSame('16:35', $course->events['class_8']->time?->end?->format('H:i'));
        $this->assertSame('2_hour_delay', $course->days['2026-09-04']->period);
        $this->assertSame(null, $course->events['lab_1']->time?->start, 'no lab sections: no times');

        $this->assertSame(null, $course->events['class_9']->date, 'directories that do not fit are on no day');
        $this->assertSame('Class 9', $course->events['class_10']->title());
    }

    public function testPartialCancellationAndProblems(): void
    {
        $course = $this->course(
            "2026-09-14 07:00 until 2026-09-14 08:00 = none | Late start\n2026-09-15 12:00 = none\n2026-09-16 = nonsense\n2026-09-17 = mon, class\n"
        );
        $days = self::days($course);
        $this->assertFalse(isset($days['2026-09-14']), 'the first section starts inside the window: cancelled');
        $this->assertTrue(isset($days['2026-09-15']), 'the meeting starts before the window');
        $messages = implode("\n", array_map(static fn ($p): string => $p->file . ':' . $p->line . ' ' . $p->message, $course->problems));
        $this->assertContains('dates.ini:3', $messages, 'an unknown word');
        $this->assertContains('dates.ini:4', $messages, 'a weekday and types together');
    }
}
