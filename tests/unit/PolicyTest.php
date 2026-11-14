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
            'config/auth/ta.ini' => "password_hash = " . password_hash(Fixture::PASSWORD, PASSWORD_DEFAULT) . "\nvalid = always\n[grants]\nlab_1 = always\nexam_1/solution.html = always\n",
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
        if ($this->dir !== '') {
            Fixture::remove($this->dir); // a test that loads twice leaves nothing behind (T-17)
        }
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
        $this->assertTrue($see('class_1/reading.md'), 'class/notes');
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

    public function testOnlyTheFilesConfigNamesFillABox(): void
    {
        $course = $this->load([
            'lab/01.Setup/lab-solution-figure.png' => 'P',
            'lab/01.Setup/lab-solution.pdf' => 'P',
            'lab/01.Setup/lab-solution/data.csv' => 'x',
        ]);
        $now = self::now($course);
        $anon = Viewer::anonymous();
        $this->assertFalse(self::sees($course, $anon, 'lab_1/lab-solution.html', $now), 'the box file follows lab/lab-solution = never');
        foreach (['lab_1/lab-solution-figure.png', 'lab_1/lab-solution.pdf', 'lab_1/lab-solution/data.csv'] as $id) {
            $this->assertTrue(self::sees($course, $anon, $id, $now), "a similar name is not a lab-solution: names are never guessed ($id)");
            $this->assertSame(null, $course->places[$course->keysByEvent['lab_1'][substr($id, 6)]]->box);
        }
    }

    public function testAVirtualFileBorrowedFromAnotherEventObeysThatEvent(): void
    {
        $course = $this->load(['config/virtual.ini' => "class_1/borrowed.html = lab/01.Setup/lab-solution.html\nclass_1/shared.md = shared/checklist.md\n"]);
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/borrowed.html', $now), 'its real file is a hidden lab solution (T-4)');
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/shared.md', $now), 'shared/checklist.md = never');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'class_1/index.html', $now));
    }

    public function testVirtualFilesKeepTheirRealFilesRules(): void
    {
        $course = $this->load([
            'config/pages.ini' => "[pages]\nhome = pages/home.html\nsolutions = pages/syllabus.html\n",
            'config/default.ini' => "class_1 = always\npage/solutions = never\nexam_2/answers.pdf = always\n",
            'config/virtual.ini' => "class_1/sol.html = pages/syllabus.html\nclass_1/final.pdf = exam/02.Final/answers.pdf\nclass_1/handout.md = shared/checklist.md\n",
            'config/duplicate.ini' => "class_2 = class_1\n",
            'exam/02.Final/answers.pdf' => 'answers',
        ]);
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/sol.html', $now), 'a virtual copy of a page keeps page/solutions = never (L-31)');
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/final.pdf', $now), 'a virtual copy of a file of an event on no day is hidden (R14)');
        $this->assertTrue(isset($course->keysByEvent['class_2']['handout.md']), 'numbered copies carry the virtual files of their source (L-33)');
    }

    public function testBoxNamesAreNotCaseSensitive(): void
    {
        $course = $this->load([
            'lab/01.Setup/lab-solution.html' => null,
            'lab/01.Setup/Lab-Solution.HTML' => 'SOL',
            'lab/01.Setup/LAB-SOLUTION-fig.png' => 'P',
        ]);
        $now = self::now($course);
        $this->assertSame('lab-solution', $course->places[$course->keysByEvent['lab_1']['Lab-Solution.HTML']]->box, 'Lab-Solution.HTML fills the box (M-25)');
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/Lab-Solution.HTML', $now), 'lab/lab-solution = never');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'lab_1/LAB-SOLUTION-fig.png', $now), 'a similar name is not part of the box');
    }

    public function testAFileSharedByTwoPagesFollowsEachPage(): void
    {
        $course = $this->load([
            'config/pages.ini' => "[pages]\nhome = pages/home.html\nresources = pages/resources.html\n",
            'config/default.ini' => "page/home = never\n",
            'pages/home.html' => '<img src="logo.png">',
            'pages/resources.html' => '<img src="logo.png">',
            'pages/logo.png' => 'P',
        ]);
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'page/home/pages/logo.png', $now), 'hidden with the hidden page');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'page/resources/pages/logo.png', $now), 'visible with the visible page (L-38)');
    }

    public function testAVirtualNameOfAHiddenPagesAttachmentIsHidden(): void
    {
        $course = $this->load([
            'config/pages.ini' => "[pages]\nhome = pages/home.html\nanswers = pages/answers.html\n",
            'config/default.ini' => "class_1 = always\npage/answers = never\n",
            'config/virtual.ini' => "class_1/answers.pdf = pages/answers.pdf\nclass_1/logo.png = pages/logo.png\n",
            'pages/answers.html' => '<a href="answers.pdf">answers</a>',
            'pages/answers.pdf' => 'A',
            'pages/home.html' => '<img src="logo.png">',
            'pages/logo.png' => 'P',
        ]);
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/answers.pdf', $now), 'the hidden page decides its attachment, also under a virtual name (L-49)');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'class_1/logo.png', $now), 'a visible page\'s attachment stays visible');
    }

    public function testSectionsInDefaultIniAndAccountKeysAfterGrants(): void
    {
        $course = $this->load([
            'config/default.ini' => "[rules]\nclass_1 = always\nlab/lab-solution = never\nexam = never\n",
            'config/auth/late.ini' => "password_hash = \$2y\$x\n[grants]\nlab_1 = always\nvalid = 2020-01-01 until 2020-01-02\ninstructor = true\n",
        ]);
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/lab-solution.html', $now), 'a rule under a header still applies (M-33)');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'class_1/index.html', $now));
        $this->assertTrue(count(array_filter($course->problems, static fn ($p): bool => str_contains($p->message, '[rules]'))) === 1, 'and the header is a problem');
        $late = Viewer::account($course->config, 'late');
        $this->assertTrue($late !== null && !$late->isValidAt($now, $course->config->zone), 'valid after [grants] is the account\'s own window');
        $this->assertTrue($late !== null && $late->instructor && $late->grants->get('valid') === null, 'not a grant');
    }

    public function testLeadingZerosAndRulesForMissingEvents(): void
    {
        $course = $this->load([
            'config/default.ini' => "class_1 = always\nclass_01/secret.pdf = never\nclass_99 = never\n",
            'class/01.Introduction/secret.pdf' => 'S',
        ]);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/secret.pdf', self::now($course)), 'class_01 is class_1 (M-35)');
        $this->assertSame(1, count(array_filter($course->problems, static fn ($p): bool => str_contains($p->message, 'class_99'))), 'a rule for an event that does not exist is a problem');
        $course = $this->load([
            'config/default.ini' => "class_1 = always\nreport_01 = never\nsolution_1.2 = never\n",
            'class/01.Introduction/report_01' => 'R',
            'class/01.Introduction/solution_1.2' => 'S',
        ]);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/report_01', self::now($course)), 'a file rule shaped like an event still matches its file (L-65)');
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/solution_1.2', self::now($course)));
    }

    public function testOldStyleLinesHideTheirTarget(): void
    {
        $course = $this->load(['config/default.ini' => "class = always\nlab\t99 99 9999\nexam never\nclass_1/Lecture Notes.pdf\t99 99 9999\n", 'class/01.Introduction/Lecture Notes.pdf' => 'L']);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/Lecture Notes.pdf', self::now($course)), 'a tab-separated path with a space (L-80)');
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/index.html', $now), 'a line without "=" hides its target (L-74)');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'class_1/index.html', $now));
    }

    public function testABoxPageInMarkdownFollowsTheBox(): void
    {
        $course = $this->load(['class/01.Introduction/instructor.html' => null, 'class/01.Introduction/instructor.md' => 'I']);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/instructor.md', self::now($course)), 'instructor.md fills the instructor box when instructor.html is not there');
    }

    public function testAFileNameRuleMatchesInSubfolders(): void
    {
        $course = $this->load([
            'config/default.ini' => "class_1 = always\nsolution.pdf = never\n",
            'class/01.Introduction/index.html' => '<a href="img/solution.pdf">s</a>',
            'class/01.Introduction/img/solution.pdf' => 'S',
        ]);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/img/solution.pdf', self::now($course)), 'R2 also in a subfolder (L-105)');
    }

    public function testAFolderNamedLikeABoxIsNotTheBox(): void
    {
        $course = $this->load([
            'config/default.ini' => "lab = always\nlab/lab-solution = never\ndata.csv = never\n",
            'lab/01.Setup/lab-solution/data.csv' => 'D',
            'lab/01.Setup/lab-solution/index.html' => 'S',
        ]);
        $now = self::now($course);
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'lab_1/lab-solution/index.html', $now), 'not part of the lab-solution box: names are never guessed');
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/lab-solution/data.csv', $now), 'its own rules apply');
    }

    public function testTypeFileTargetsAndRulesThatMatchNothing(): void
    {
        $course = $this->load([
            'config/default.ini' => "class = always\nclass/quiz.html = never\nlabs = never\nlab_1/lab-soluton = never\n",
            'class/01.Introduction/quiz.html' => 'Q',
        ]);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/quiz.html', self::now($course)), 'type/file targets work (M-45)');
        $messages = implode("\n", array_map(static fn ($p): string => $p->message, $course->problems));
        $this->assertContains('labs matches no file', $messages, 'a rule that matches nothing is a problem');
        $this->assertContains('lab_1/lab-soluton matches no file', $messages);
        $this->assertNotContains('class/quiz.html matches no file', $messages);
    }

    public function testGrantsForMissingEventsAreProblems(): void
    {
        $course = $this->load(['config/auth/late.ini' => "password_hash = \$2y\$x\nvalid = always\n[grants]\nlab_40 = always\n"]);
        $messages = implode("\n", array_map(static fn ($p): string => $p->message, $course->problems));
        $this->assertContains('lab_40: there is no lab 40, so this grant does nothing', $messages, 'L-119');
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
        $config = (string) file_get_contents(__DIR__ . '/../../template/course/config/config.ini');
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
        $config = (string) file_get_contents(__DIR__ . '/../../template/course/config/config.ini');
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

    public function testTargetsAreNotCaseSensitive(): void
    {
        $course = $this->load([
            'config/default.ini' => "CLASS_1/INDEX.HTML = never\npage/Solutions = never\nLab/Lab-Solution = 2000-01-01\n",
            'config/pages.ini' => "[pages]\nhome = pages/home.html\nSolutions = pages/syllabus.html\n",
            'config/dates.ini' => "2026-09-18 = EXAM\n",
            'class/01.Introduction/Reading.md' => 'two names that differ only in case',
        ]);
        $now = self::now($course);
        $anon = Viewer::anonymous();
        $this->assertFalse(self::sees($course, $anon, 'class_1/index.html', $now), 'CLASS_1/INDEX.HTML is class_1/index.html (M-14)');
        $this->assertFalse(self::sees($course, $anon, 'page/solutions', $now), 'page/Solutions matches the page "Solutions"');
        $this->assertTrue(self::sees($course, $anon, 'lab_1/lab-solution.html', $now), 'Lab/Lab-Solution');
        $this->assertSame('2026-09-18', $course->events['exam_1']->date, 'dates.ini: EXAM is the type exam');
        $messages = implode("\n", array_map(static fn ($p): string => $p->message, $course->problems));
        $this->assertContains('"Reading.md" and "reading.md" differ only in case', $messages, 'a case clash is a problem');
    }

    public function testNamesMadeOfDigits(): void
    {
        $course = $this->load([
            'config/default.ini' => "2024 = never\nlab_1 = always\n",
            'config/pages.ini' => "[pages]\n2027 = pages/home.html\n",
            'config/virtual.ini' => "lab_1/42 = shared/checklist.md\n",
            'lab/01.Setup/2024' => 'old exam',
            'lab/01.Setup/1' => 'one',
        ]);
        $this->assertTrue(isset($course->keysByEvent['lab_1']['2024']), 'a course with numeric names loads (M-17)');
        $this->assertTrue(isset($course->pageKeys['2027']));
        $now = self::now($course);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'lab_1/2024', $now), '2024 = never');
        $ta = Viewer::account($course->config, 'ta');
        $this->assertFalse($ta !== null && self::sees($course, $ta, 'lab_1/2024', $now), 'a grant for lab_1 does not drop the rule for 2024 (L-26)');
        $this->assertTrue(self::sees($course, Viewer::anonymous(), 'page/2027', $now));
    }

    public function testAMissingDefaultIniHidesEverything(): void
    {
        $course = $this->load(['config/default.ini' => null]);
        $this->assertFalse(self::sees($course, Viewer::anonymous(), 'class_1/index.html', self::now($course)), 'a missing default.ini fails closed (M-19)');
        $this->assertContains('default.ini is missing', implode("\n", array_map(static fn ($p): string => $p->message, $course->problems)));
        $this->assertTrue(self::sees($course, Viewer::account($course->config, 'prof'), 'class_1/index.html', self::now($course)), 'instructors still see everything');
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
