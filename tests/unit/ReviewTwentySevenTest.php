<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Boundary;
use Cal6\Course\Course;
use Cal6\Course\CourseConfig;
use Cal6\Http\Session;
use Cal6\Render\PageRenderer;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

/** Review 27's fixes: M-53, M-54, L-159..L-165 (T-34). */
final class ReviewTwentySevenTest extends TestCase
{
    private string $dir = '';

    public function tearDown(): void
    {
        if ($this->dir !== '') {
            str_contains($this->dir, 'cal6-sweep-') ? exec('rm -rf ' . escapeshellarg($this->dir)) : Fixture::remove($this->dir);
        }
    }

    private function render(Course $course, string $id, ?Viewer $viewer = null): string
    {
        foreach ($course->places as $place) {
            if ($place->id() === $id) {
                $renderer = new PageRenderer($course, new Policy($course), $viewer ?? Viewer::anonymous(), strtotime('2030-01-01'), '/', hostNames: ['localhost']);
                return str_ends_with($id, '.md') ? $renderer->renderMarkdown($place) : $renderer->render($place);
            }
        }
        throw new \RuntimeException($id);
    }

    public function testHiddenRangesNeverRevealText(): void
    {
        $draft = "Before\n\n<!-- old draft:\n<inst>key: B</inst>\nDRAFT-SECRET: answer is C\n-->\n\nAfter\n";
        $crossed = "<p title=\"<lock code=x>\">Hint</p>\n<inst>Answer</lock> key 42</inst>\nAfter\n";
        $srcdoc = "<iframe title=\"<host server=localhost>\" srcdoc=\"<inst>\">SECRET</inst></iframe>\nAfter\n";
        $this->dir = Fixture::course([
            'config/security.ini' => "class = always\n",
            'class/01.Introduction/draft.md' => $draft,
            'class/01.Introduction/draft.html' => $draft,
            'class/01.Introduction/mention.md' => "<!-- put this in <inst> tags -->\n\nVISIBLE\n",
            'class/01.Introduction/crossed.html' => $crossed,
            'class/01.Introduction/crossed.md' => $crossed,
            'class/01.Introduction/srcdoc.html' => $srcdoc,
            'class/01.Introduction/rest.html' => "<p title='<inst>x</inst><inst><p title='SECRET10<</inst>\nAfter\n<p title=\"<inst>\" data-x=\"SECRET11\" class=\"ok\">T</p></inst>\n",
            'class/01.Introduction/nested.md' => '<!--' . str_repeat('<inst>', 30000) . '-->' . str_repeat('</inst>', 30000) . "\nEND\n",
            'class/01.Introduction/nested.html' => '<p title="' . str_repeat('<inst>', 3000) . '">' . str_repeat('</inst>', 3000) . "\nEND\n",
        ]);
        $course = Course::load($this->dir);
        $prof = Viewer::account($course->config, 'prof');
        foreach (['draft.md', 'draft.html'] as $name) {
            foreach ([null, $prof] as $viewer) {
                $page = $this->render($course, "class_1/$name", $viewer);
                $this->assertNotContains('DRAFT-SECRET', $page, "commented-out text never comes out (M-53): $name");
                $this->assertContains('Before', $page);
                $this->assertContains('After', $page, "the page goes on after the comment: $name");
            }
        }
        // a comment that names a block tag never closed: as a plain scan reads it, the block goes
        // on to the page's end, and so does what is removed (fail closed; DESIGN.md)
        $this->assertNotContains('VISIBLE', $this->render($course, 'class_1/mention.md'));
        foreach (['crossed.html', 'crossed.md'] as $name) {
            $page = $this->render($course, "class_1/$name");
            $this->assertNotContains('42', $page, "a range goes on over blocks opened in it (L-159): $name");
            $this->assertNotContains('Answer', $page);
        }
        foreach (['nested.md', 'nested.html'] as $name) {
            $start = microtime(true);
            $this->assertContains('END', $this->render($course, "class_1/$name"));
            $this->assertTrue(microtime(true) - $start < 3.0, "extending a range is linear: $name");
        }
        $page = $this->render($course, 'class_1/rest.html');
        $this->assertNotContains('SECRET1', $page, 'the rest of a tag after a value that left a block open goes too (L-165)');
        $this->assertContains("<p title=''>", $page, 'the value and the tag stay closed');
        $page = $this->render($course, 'class_1/srcdoc.html');
        $this->assertNotContains('SECRET', $page, "a srcdoc's open block beside another value's (L-160)");
        $this->assertContains('After', $page);
    }

    public function testShortLockCodesAreNotedOutsideThePage(): void
    {
        $this->dir = Fixture::course([
            'config/security.ini' => "class = always\n",
            'class/01.Introduction/short.html' => '<lock code="1234, long-enough-code">A</lock><lock code="eightchr">B</lock><lock code="1234">C</lock>',
        ]);
        $course = Course::load($this->dir);
        foreach ($course->places as $place) {
            if ($place->id() === 'class_1/short.html') {
                $prof = new PageRenderer($course, new Policy($course), Viewer::account($course->config, 'prof'), strtotime('2030-01-01'), '/');
                $this->assertSame('ABC', $prof->render($place), 'the page is only the processed file: no notice in it (M-54)');
                $this->assertSame(['1234'], $prof->shortCodes, 'short codes noted once; 8 characters or more are not');
                $anonymous = new PageRenderer($course, new Policy($course), Viewer::anonymous(), strtotime('2030-01-01'), '/');
                $anonymous->render($place);
                $this->assertSame([], $anonymous->shortCodes, 'only for instructors');
            }
        }
    }

    public function testTheSweepCanNotBeFooledAndHasALimit(): void
    {
        $this->dir = sys_get_temp_dir() . '/cal6-sweep-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $old = time() - 600;
        file_put_contents("$this->dir/sess_login", 'cal6|a:2:{s:6:"course";s:1:"x";s:4:"user";s:4:"prof";}');
        touch("$this->dir/sess_login", $old - 20);
        // a remembered URL that holds what a login looks like as text (L-161)
        file_put_contents("$this->dir/sess_forged", 'cal6|a:1:{s:3:"url";s:22:"x"user";s:4:"prof";}x";}');
        touch("$this->dir/sess_forged", $old - 10);
        for ($i = 0; $i < 20005; $i++) {
            file_put_contents("$this->dir/sess_anon$i", 'cal6|a:1:{s:4:"csrf";s:1:"x";}');
        }
        Session::sweep($this->dir, 86400);
        $this->assertTrue(is_file("$this->dir/sess_login"), 'a real login is kept');
        $this->assertFalse(is_file("$this->dir/sess_forged"), 'text that looks like a login is not one (L-161)');
        $this->assertSame(20000, count(glob("$this->dir/sess_*") ?: []));
    }

    public function testLoginsHaveAHardLimit(): void
    {
        $this->dir = sys_get_temp_dir() . '/cal6-sweep-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $login = 'cal6|a:1:{s:4:"user";s:4:"prof";}';
        for ($i = 0; $i < 40010; $i++) {
            file_put_contents("$this->dir/sess_$i", $login);
        }
        touch("$this->dir/sess_0", time() - 600);
        Session::sweep($this->dir, 86400);
        $this->assertFalse(is_file("$this->dir/sess_0"), 'beyond twice the limit the oldest go, logins too (L-161)');
        $this->assertSame(40000, count(glob("$this->dir/sess_*") ?: []));
    }

    public function testPeriodsTypesAndBackups(): void
    {
        $this->dir = Fixture::course([
            'config/periods.ini' => "[normal]\n1 = 08:00-08:50\n2 = 09:00-09:50\n",
            'config/sections.ini' => "[1111]\nmeets = MF1\n[2222]\nmeets = MF0, T7\n",
        ]);
        $problems = implode("\n", array_map(static fn ($p): string => (string) $p->message, CourseConfig::load($this->dir)->problems));
        $this->assertContains('[2222] meets = MF0: no period schedule has period 0', $problems, 'a period no schedule has (L-162)');
        $this->assertContains('[2222] meets = T7: no period schedule has period 7', $problems);
        $this->assertNotContains('[1111]', $problems);

        foreach (['index.html.gz', 'notes.md.br', 'x.html.zst', 'x.htm.bz2', 'x.html.rtf', 'x.html.ps', 'x.html.mht', 'x.md.eps'] as $name) {
            $this->assertTrue(Boundary::isPageBackup($name), "a compressed or text-format copy of a page (L-164): $name");
        }
        foreach (['report.html.pdf', 'slides.md.zip'] as $name) {
            $this->assertFalse(Boundary::isPageBackup($name), "a derived file: $name");
        }
    }
}
