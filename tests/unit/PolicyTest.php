<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Course;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;
use DateTimeImmutable;

final class PolicyTest extends TestCase
{
    private string $dir = '';

    public const RULES = <<<'INI'
class_1          = always
class/notes      = always
class/instructor = never
lab              = 2000-01-01
lab/lab-solution = never
lab_2/lab-solution = 2000-01-01
exam             = never
exam_2           = always
class_2          = 2999-01-01
class_2          = 2000-01-01
class_3..4       = never
page/resources   = never
shared/checklist.md = never
INI;

    /** @return array<string, string|null> */
    public static function files(): array
    {
        return [
            'config/default.ini' => self::RULES,
            'config/dates.ini' => "2026-09-18 = exam\n",
            'class/01.Introduction/instructor.html' => '<p>secret</p>',
            'class/02.Second/index.html' => '<p>two</p>',
            'class/03.Third/index.html' => '<p>three</p>',
            'lab/02.Two/index.html' => '<p>lab two</p>',
            'lab/02.Two/lab-solution.html' => '<p>lab two solution</p>',
            'exam/01.Midterm/index.html' => '<p>exam</p>',
            'exam/01.Midterm/solution.html' => '<p>exam solution</p>',
            'exam/02.Final/index.html' => '<p>final</p>',
        ];
    }

    public function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    /** @param array<string, string|null> $extra */
    private function load(array $extra = []): Course
    {
        $this->dir = Fixture::course(array_merge(self::files(), $extra));
        return Course::load($this->dir);
    }

    private static function now(Course $course, string $time = '2026-10-01 12:00'): int
    {
        return (new DateTimeImmutable($time, $course->config->zone))->getTimestamp();
    }

    private static function sees(Course $course, Viewer $viewer, string $place, int $now): bool
    {
        foreach ($course->places as $p) {
            if ($p->id() === $place) {
                return (new Policy($course))->decide($viewer, $p, $now)->visible;
            }
        }
        throw new \RuntimeException("no place $place");
    }

    public function testAnonymousRules(): void
    {
        $course = $this->load();
        $now = self::now($course);
        $anon = Viewer::anonymous();
        $see = fn (string $place): bool => self::sees($course, $anon, $place, $now);
        $this->assertTrue($see('class_1/index.html'), 'class_1 = always');
        $this->assertFalse($see('class_1/instructor.html'), 'a box beats an event (class/instructor = never)');
        $this->assertTrue($see('class_1/notes.md'), 'class/notes');
        $this->assertTrue($see('class_2/index.html'), 'the later line wins');
        $this->assertFalse($see('class_3/index.html'), 'a range');
        $this->assertTrue($see('lab_1/index.html'), 'a date in the past');
        $this->assertFalse($see('lab_1/lab-solution.html'), 'lab/lab-solution = never');
        $this->assertTrue($see('lab_2/lab-solution.html'), 'one event\'s box beats every event\'s box');
        $this->assertFalse($see('exam_1/index.html'));
        $this->assertFalse($see('exam_2/index.html'), 'an event on no calendar day is never shown, whatever the rules');
        $this->assertTrue($see('page/home'), 'no rule: visible');
        $this->assertFalse($see('page/resources'));
        $this->assertFalse($see('lab_1/checklist.md'), 'a virtual file must be allowed under its real file too');
        $decision = (new Policy($course))->decide($anon, $course->places[$course->keysByEvent['class_3']['index.html']], $now);
        $this->assertSame('class_3 = never', $decision->rule?->describe());
    }

    public function testBoxAssetsFollowTheirBox(): void
    {
        $course = $this->load([
            'lab/01.Setup/lab-solution-figure.png' => 'P',
            'lab/01.Setup/lab-solution/data.csv' => 'x',
            'lab/01.Setup/lab.png' => 'P',
        ]);
        $now = self::now($course);
        $anon = Viewer::anonymous();
        $this->assertFalse(self::sees($course, $anon, 'lab_1/lab-solution-figure.png', $now), 'lab/lab-solution = never covers its assets (M-3)');
        $this->assertFalse(self::sees($course, $anon, 'lab_1/lab-solution/data.csv', $now), 'and a directory named after it');
        $this->assertTrue(self::sees($course, $anon, 'lab_1/lab.png', $now), 'other files follow the event');
        $this->assertTrue(self::sees($course, $anon, 'lab_1/index.html', $now));
    }

    public function testOpensAndNextChange(): void
    {
        $course = $this->load(['config/default.ini' => "lab/lab-solution = +7 17:00\n"]);
        $place = $course->places[$course->keysByEvent['lab_1']['lab-solution.html']];
        $policy = new Policy($course);
        $lab = $course->events['lab_1']->date;
        $this->assertSame('2026-08-26', $lab);
        $before = self::now($course, '2026-09-02 16:59');
        $decision = $policy->decide(Viewer::anonymous(), $place, $before);
        $this->assertFalse($decision->visible);
        $this->assertSame(self::now($course, '2026-09-02 17:00'), $decision->opens);
        $this->assertSame(self::now($course, '2026-09-02 17:00'), $decision->nextChange);
        $this->assertTrue($policy->decide(Viewer::anonymous(), $place, self::now($course, '2026-09-02 17:00'))->visible, 'at the minute');
    }

    public function testLockdown(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $course = $this->load(['config/config.ini' => str_replace(';lockdown    = 2026-12-18 17:00', 'lockdown = 2026-09-30 17:00', $config)]);
        $before = self::now($course, '2026-09-30 16:59');
        $after = self::now($course, '2026-09-30 17:00');
        $anon = Viewer::anonymous();
        $this->assertTrue(self::sees($course, $anon, 'lab_1/index.html', $before));
        $this->assertFalse(self::sees($course, $anon, 'lab_1/index.html', $after), 'only "always" rules during the lockdown');
        $this->assertTrue(self::sees($course, $anon, 'class_1/index.html', $after), 'class_1 = always');
        $this->assertFalse(self::sees($course, $anon, 'class_1/instructor.html', $after), 'precedence still runs');
        $this->assertFalse(self::sees($course, $anon, 'page/home', $after), 'no rule is not "always"');
        $ta = Viewer::account($course->config, 'ta');
        $this->assertTrue($ta !== null && self::sees($course, $ta, 'exam_1/solution.html', $after), 'grants are not affected');
        $this->assertFalse($ta !== null && self::sees($course, $ta, 'lab_2/index.html', $after), 'a user does not escape the lockdown without a grant');
    }

    public function testUnreadableLockdownLocks(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $course = $this->load(['config/config.ini' => str_replace(';lockdown    = 2026-12-18 17:00', 'lockdown = 2026-12-32', $config)]);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/index.html', self::now($course)), 'locked until fixed (L-10)');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'class_1/index.html', self::now($course)), '"always" rules still show');
    }

    public function testGrantsAndInstructors(): void
    {
        $course = $this->load();
        $now = self::now($course);
        $ta = Viewer::account($course->config, 'ta');
        $prof = Viewer::account($course->config, 'prof');
        $this->assertTrue($ta !== null && $prof !== null, 'accounts');
        $this->assertTrue(self::sees($course, $ta, 'exam_1/solution.html', $now), 'a file grant');
        $this->assertFalse(self::sees($course, $ta, 'exam_1/index.html', $now), 'only that file');
        $this->assertFalse(self::sees($course, $ta, 'lab_1/lab-solution.html', $now), 'a grant for lab_1 does not open a box with its own rule');
        $this->assertTrue(self::sees($course, $ta, 'class_1/index.html', $now), 'everything anonymous viewers see');
        foreach (['class_1/instructor.html', 'class_3/index.html', 'exam_2/index.html', 'page/resources', 'lab_1/checklist.md'] as $place) {
            $this->assertTrue(self::sees($course, $prof, $place, $now), "instructors see everything: $place");
        }
        $this->assertSame(null, Viewer::account($course->config, '../ta'), 'no paths in usernames');
        $this->assertSame(null, Viewer::account($course->config, 'nobody'));
        file_put_contents("$this->dir/config/auth/old.ini", "password_hash = x\nvalid = 2020-01-01 until 2020-12-31\n[grants]\n* = always\n");
        $old = Viewer::account($course->config, 'old');
        $this->assertFalse($old !== null && $old->isValidAt($now, $course->config->zone), 'outside its window');
        $this->assertFalse($old !== null && self::sees($course, $old, 'exam_1/index.html', $now), 'grants only inside the window');
    }

    public function testAnUnreadableLineHidesItsTarget(): void
    {
        $course = $this->load(['config/default.ini' => "exam_2 = always\nlab = nver\nclass_9..2 = never\n"]);
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/index.html', $now), 'a typo hides, never releases (H-2)');
        $rule = (new Policy($course))->decide(Viewer::anonymous(), $course->places[$course->keysByEvent['lab_1']['index.html']], $now)->rule;
        $this->assertSame('lab = nver (can not be read: hidden)', $rule?->describe());
        $messages = implode("\n", array_map(static fn ($p): string => "$p->file:$p->line $p->message", $course->problems));
        $this->assertContains('default.ini:2', $messages);
        $this->assertContains('default.ini:3', $messages, 'a bad target is a problem');
    }

    public function testUnreadableRulesHideEverything(): void
    {
        $course = $this->load(['config/default.ini' => null]);
        mkdir("$this->dir/config/default.ini");
        $course = Course::load($this->dir);
        $this->assertTrue($course->rulesUnreadable);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/index.html', self::now($course)), 'fail closed');
        $this->assertTrue(self::sees($course, (Viewer::account($course->config, 'prof')), 'class_1/index.html', self::now($course)));
    }
}
