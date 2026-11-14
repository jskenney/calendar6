<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Boundary;
use Cal6\Course\Course;
use Cal6\Course\CourseConfig;
use Cal6\Endpoint\AuthEndpoint;
use Cal6\Http\Context;
use Cal6\Http\Download;
use Cal6\Http\Session;
use Cal6\Render\PageRenderer;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

/** Review 26's fixes: M-52, L-152, L-154..L-157 and the file types (T-33). */
final class ReviewTwentySixTest extends TestCase
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

    public function testCommentsNeverTakeABlockTagAndStayLinear(): void
    {
        $leak = "Use `<!--` to open a comment.\n\n<inst>\nAnswer: a comment ends with `-->`, and the quiz answer is 42.\n</inst>\n";
        $this->dir = Fixture::course([
            'config/security.ini' => "class = always\n",
            'class/01.Introduction/m52.md' => $leak,
            'class/01.Introduction/lock.md' => "Use `<!--` here.\n\n<lock code=\"x\">\nends `-->` CODE-SECRET\n</lock>\n",
            'class/01.Introduction/many.md' => str_repeat('<!--a-->', 40000) . "\nEND",
            'class/01.Introduction/pm.html' => '<postmeeting title="<inst>">S1</inst></postmeeting> tail',
            'class/01.Introduction/host.md' => '<host server="localhost" title="<inst>">S2</inst></host> tail',
            'class/01.Introduction/src.html' => '<iframe srcdoc="<inst>">S3</inst></iframe> tail',
        ]);
        $course = Course::load($this->dir);
        $this->assertNotContains('42', $this->render($course, 'class_1/m52.md'), 'a comment ends before a block tag in it (M-52)');
        // the comment as a plain scan reads it ends inside the block: removed with the block, for
        // instructors too, so neither reading's hidden text comes out (M-53)
        $this->assertNotContains('42', $this->render($course, 'class_1/m52.md', Viewer::account($course->config, 'prof')));
        $this->assertNotContains('CODE-SECRET', $this->render($course, 'class_1/lock.md'));
        $start = microtime(true);
        $this->assertContains('END', $this->render($course, 'class_1/many.md'));
        $this->assertTrue(microtime(true) - $start < 3.0, 'comment removal is linear (L-155)');
        foreach (['pm.html' => 'S1', 'host.md' => 'S2', 'src.html' => 'S3'] as $name => $secret) {
            $page = $this->render($course, "class_1/$name");
            $this->assertNotContains($secret, $page, "a block in a visible block's tag or a srcdoc value (L-154): $name");
            $this->assertContains('tail', $page);
        }
    }

    public function testBackupsWithTextTypesAndFileTypes(): void
    {
        foreach (['index.html.log', 'x.html.diff', 'notes.md.txt', 'x.htm.json', 'page.html.patch'] as $name) {
            $this->assertTrue(Boundary::isPageBackup($name), "raw page source behind a text type (L-152): $name");
        }
        foreach (['report.html.pdf', 'slides.md.zip', 'notes.html.md', 'a.html.png'] as $name) {
            $this->assertFalse(Boundary::isPageBackup($name), "a derived file that is not text: $name");
        }
        foreach (['x.svg', 'x.html', 'x.xhtml', 'x.xml', 'x.xsl', 'x.epub'] as $name) {
            $this->assertFalse(Download::typeOf($name)[1], "never inline: $name");
        }
        $this->assertSame(['text/plain', true], Download::typeOf('Makefile'), 'no extension: plain text');
        $this->assertSame(['application/octet-stream', false], Download::typeOf('x.unknownthing'));
    }

    public function testDeviceCookieMarkUsesTheAccountsOwnName(): void
    {
        $hash = password_hash('pw', PASSWORD_DEFAULT);
        $this->dir = Fixture::course(['config/auth/JDoe.ini' => "password_hash = $hash\nvalid = always\n"]);
        $config = CourseConfig::load($this->dir);
        $c = new Context($this->dir, $config, [], [], [], time());
        $mark = new \ReflectionMethod(AuthEndpoint::class, 'passwordMark');
        $this->assertSame(Context::secretMark($hash), $mark->invoke(null, $c, 'JDoe'), 'bound to the real password hash (L-156)');
        $this->assertFalse(Context::secretMark('') === $mark->invoke(null, $c, 'JDoe'));
    }

    public function testTheSweepKeepsLogins(): void
    {
        $this->dir = sys_get_temp_dir() . '/cal6-sweep-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $old = time() - 600;
        file_put_contents("$this->dir/sess_login", 'cal6|a:2:{s:6:"course";s:1:"x";s:4:"user";s:4:"prof";}');
        touch("$this->dir/sess_login", $old - 10);
        for ($i = 0; $i < 20005; $i++) {
            file_put_contents("$this->dir/sess_anon$i", 'cal6|a:1:{s:4:"csrf";s:1:"x";}');
        }
        touch("$this->dir/sess_anon0", $old);
        Session::sweep($this->dir, 86400);
        $this->assertTrue(is_file("$this->dir/sess_login"), 'the oldest session holds a login: kept (L-157)');
        $this->assertFalse(is_file("$this->dir/sess_anon0"), 'old anonymous sessions go instead');
        $this->assertSame(20000, count(glob("$this->dir/sess_*") ?: []));
    }
}
