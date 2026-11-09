<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Course;
use Cal6\Render\PageRenderer;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

final class RendererTest extends TestCase
{
    private string $dir = '';

    public function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    /** @param array<string, string|null> $files */
    private function course(array $files): Course
    {
        $this->dir = Fixture::course($files);
        return Course::load($this->dir);
    }

    private function render(Course $course, string $place, array $hostNames = [], ?Viewer $viewer = null, ?int $now = null): string
    {
        $renderer = new PageRenderer($course, new Policy($course), $viewer ?? Viewer::anonymous(), $now ?? time(), '/', hostNames: $hostNames);
        foreach ($course->places as $p) {
            if ($p->id() === $place) {
                return $renderer->render($p);
            }
        }
        throw new \RuntimeException("no place $place");
    }

    public function testHostMatchesOnlyServerNamesAndAddresses(): void
    {
        $course = $this->course(['class/01.Introduction/index.html' => '<host server="other, intranet">ONLY-INTRANET</host><host server="10.0.0.5">BY-ADDRESS</host>']);
        $this->assertNotContains('ONLY-INTRANET', $this->render($course, 'class_1/index.html', ['www']));
        $this->assertContains('ONLY-INTRANET', $this->render($course, 'class_1/index.html', ['Intranet']), 'a list, not case sensitive');
        $this->assertContains('BY-ADDRESS', $this->render($course, 'class_1/index.html', ['www', '10.0.0.5']), 'the server address (v4)');
        $_SERVER['SERVER_NAME'] = 'intranet';
        $this->assertNotContains('ONLY-INTRANET', $this->render($course, 'class_1/index.html', PageRenderer::serverNames(['SERVER_ADDR' => '127.0.0.1'])), 'never the Host header (H-1)');
        unset($_SERVER['SERVER_NAME']);
    }

    public function testScriptsStylesSrcsetAndPageAssets(): void
    {
        $course = $this->course([
            'pages/home.html' => '<img src="logo.png"><a href="more.html">more</a><link rel="stylesheet" href="site.css">',
            'pages/logo.png' => 'PNG', 'pages/site.css' => 'p{}', 'pages/more.html' => '<img src="img/deep.png">', 'pages/img/deep.png' => 'P',
            'class/01.Introduction/index.html' => '<script src="x.js"></script><link href="s.css" rel="stylesheet"><img srcset="a.png 1x, instructor.png 2x, b.png 3x">',
            'class/01.Introduction/x.js' => 'var a;', 'class/01.Introduction/s.css' => 'p{}', 'class/01.Introduction/a.png' => 'P',
            'class/01.Introduction/b.png' => 'P', 'class/01.Introduction/instructor.png' => 'P',
            'config/default.ini' => "class_1 = always\nclass_1/instructor.png = never\n",
        ]);
        $home = $this->render($course, 'page/home');
        $this->assertNotContains('data-cal6-hidden', $home, 'files next to a page get keys (M-5)');
        $this->assertContains('/calendar/php/get.php?file=', $home);
        $this->assertContains('href="#/view/', $home, 'a page next to it');
        $this->assertNotContains('data-cal6-hidden', $this->render($course, 'page/home/pages/more.html'), 'and the files that page links to');
        $class = $this->render($course, 'class_1/index.html');
        $this->assertContains('<script src="/calendar/php/get.php?file=', $class, 'scripts (M-4)');
        $this->assertContains('<link href="/calendar/php/get.php?file=', $class, 'style sheets (M-4)');
        $this->assertSame(2, substr_count($class, 'get.php?file=', strpos($class, 'srcset')), 'srcset: the hidden candidate is dropped (L-9)');
        $this->assertContains(' 3x"', $class);
    }

    public function testInjectObeysReleaseRules(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<inject src="answers.html"><inject src="../02.Two/index.html"><inject src="../../shared/part.html"><codeinject src="../02.Two/index.html">',
            'class/01.Introduction/answers.html' => 'HIDDEN-ANSWERS',
            'class/02.Two/index.html' => 'OTHER-EVENT',
            'shared/part.html' => 'SHARED-PART',
            'config/default.ini' => "class_1 = always\nclass_1/answers.html = never\nclass_2 = never\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertNotContains('HIDDEN-ANSWERS', $page, 'a hidden file of the same event (M-2)');
        $this->assertNotContains('OTHER-EVENT', $page, 'a hidden event, also with <codeinject>');
        $this->assertContains('SHARED-PART', $page, 'files outside the events');
        $prof = Viewer::account($course->config, 'prof');
        $this->assertContains('HIDDEN-ANSWERS', $this->render($course, 'class_1/index.html', [], $prof), 'instructors');
    }

    public function testInjectAndCalphpStayInsideTheCourse(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<inject src="../../config/config.ini"><codeinject src="../../config/auth/prof.ini"><inject src="../../../../etc/hostname"><calphp src="../../config/x.php"><calphp src="../../calendar/php/get.php">',
            'config/x.php' => '<?php echo "CONFIG-SCRIPT";',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertNotContains('secret', $page, 'no config.ini (T-1)');
        $this->assertNotContains('password_hash', $page, 'no accounts');
        $this->assertNotContains('CONFIG-SCRIPT', $page, 'no scripts from config/');
        $this->assertSame('', trim($page), 'nothing from outside the course or the software');
    }

    public function testPagesInSubdirectories(): void
    {
        $course = $this->course([
            'class/01.Introduction/part2/page.html' => '<img src="fig.png"><img src="../top.png"><a href="../index.html">up</a><a href="../../02.Two/index.html">other</a>',
            'class/01.Introduction/part2/fig.png' => 'P',
            'class/01.Introduction/top.png' => 'P',
            'class/02.Two/index.html' => 'two',
            'config/default.ini' => "class_1 = always\nclass_2 = never\n",
        ]);
        $page = $this->render($course, 'class_1/part2/page.html');
        $this->assertSame(2, substr_count($page, 'get.php?file='), 'files next to it and above it get keys (T-4)');
        $this->assertContains('href="#/view/' . $course->keysByEvent['class_1']['index.html'] . '"', $page, 'the event\'s own page');
        $this->assertContains('data-cal6-hidden="1">other', $page, 'another event\'s hidden page');
    }

    public function testPagesAreLinkedOnlyThroughHtmlPhp(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<a href="other.html">a</a><iframe src="other.html"></iframe><img src="other.html"><script src="notes.md"></script><a href="data.txt">d</a>',
            'class/01.Introduction/other.html' => 'O',
            'class/01.Introduction/data.txt' => 'D',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $other = $course->keysByEvent['class_1']['other.html'];
        $this->assertContains('href="#/view/' . $other . '"', $page);
        $this->assertContains('<iframe src="/calendar/php/html.php?file=' . $other . '&amp;full=1"', $page, 'frames get the processed page (C-1)');
        $this->assertContains('<img data-cal6-hidden="1">', $page, 'a page is never an image');
        $this->assertContains('<script data-cal6-hidden="1">', $page, 'or a script');
        $this->assertSame(1, substr_count($page, 'get.php?file='), 'only the text file goes through get.php');
    }

    public function testALinkToAPhpFileIsNeitherMaterialNorAPage(): void
    {
        $course = $this->course(['class/01.Introduction/db.php' => '<?php $password = "x";']);
        symlink("$this->dir/class/01.Introduction/db.php", "$this->dir/class/01.Introduction/view.html");
        $course = Course::load($this->dir);
        $this->assertFalse(isset($course->keysByEvent['class_1']['view.html']), 'a link to a .php file is not listed (L-22)');
    }

    public function testHiddenBlocksStayHiddenWhenLargeNestedOrUnclosed(): void
    {
        $big = str_repeat('A', 1_200_000);
        $image = '<img src="data:image/png;base64,' . str_repeat('QUFB', 300_000) . '">';
        $course = $this->course([
            'class/01.Introduction/index.html' => "<p>START</p><inst>SECRET-BIG $big</inst><lock code=\"s3cret\">$image LOCKED-BIG</lock>"
                . '<postmeeting>' . $big . 'AFTER-BIG</postmeeting><host server="nowhere">' . $big . 'HOST-BIG</host><p>VISIBLE-END</p>',
            'class/01.Introduction/nested.html' => '<inst>A<inst>B</inst>SECRET-NESTED</inst><lock code="x">L1<lock code="y">L2</lock>LOCK-NESTED</lock>'
                . '<host server="nowhere">H1<host server="nowhere">H2</host>HOST-NESTED</host><INST >UPPER-CASE</Inst ><p>AFTER-NESTED</p>',
            'class/01.Introduction/unclosed.html' => '<p>BEFORE</p><inst>NEVER-CLOSED <p>everything after</p>',
            'class/01.Introduction/photo.html' => "<p>PHOTO</p>$image",
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html', [], null, 0);
        foreach (['SECRET-BIG', 'LOCKED-BIG', 's3cret', 'AFTER-BIG', 'HOST-BIG'] as $hidden) {
            $this->assertNotContains($hidden, $page, "large blocks stay hidden (H-4): $hidden");
        }
        $this->assertContains('START', $page);
        $this->assertContains('VISIBLE-END', $page, 'and the rest of the page is still there');
        $nested = $this->render($course, 'class_1/nested.html');
        foreach (['SECRET-NESTED', 'LOCK-NESTED', 'L2', 'HOST-NESTED', 'H2', 'UPPER-CASE', '</inst>', '</lock>', '</host>'] as $hidden) {
            $this->assertNotContains($hidden, $nested, "nested blocks stay hidden (M-13): $hidden");
        }
        $this->assertContains('AFTER-NESTED', $nested);
        $prof = Viewer::account($course->config, 'prof');
        $instructor = $this->render($course, 'class_1/nested.html', [], $prof);
        $this->assertContains('ABSECRET-NESTED', $instructor, 'instructors see nested content, without the tags');
        $this->assertNotContains('<inst>', $instructor);
        $unclosed = $this->render($course, 'class_1/unclosed.html');
        $this->assertContains('BEFORE', $unclosed);
        $this->assertNotContains('NEVER-CLOSED', $unclosed, 'an unclosed block hides the rest of the page (fail closed)');
        $this->assertNotContains('everything after', $unclosed);
        $this->assertContains('base64,QUFB', $this->render($course, 'class_1/photo.html'), 'a large inline image still renders');
    }

    public function testVirtualFilesLinkRelativeToTheirRealFile(): void
    {
        $course = $this->course([
            'homework/hw1.html' => '<img src="fig.png"><a href="data.csv">data</a>',
            'homework/fig.png' => 'REAL-FIG',
            'homework/data.csv' => 'x',
            'class/01.Introduction/fig.png' => 'EVENT-FIG',
            'config/virtual.ini' => "class_1/homework.html = homework/hw1.html\n",
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/homework.html');
        preg_match('/<img src="[^"]*file=([A-Za-z0-9_-]+)"/', $page, $m);
        $this->assertSame('homework/fig.png', $course->places[$m[1] ?? '']->file->name ?? null, 'its own figure, not the event\'s fig.png (L-20)');
        $this->assertNotContains('data-cal6-hidden', $page, 'the files it links to get keys');
        Fixture::remove($this->dir);
        $hidden = $this->course([
            'homework/hw1.html' => '<img src="fig.png">', 'homework/fig.png' => 'P',
            'config/virtual.ini' => "class_1/homework.html = homework/hw1.html\n",
            'config/default.ini' => "class_1 = always\nclass_1/homework.html = never\n",
        ]);
        foreach ($hidden->places as $place) {
            if ($place->file->name === 'homework/fig.png') {
                $this->assertFalse((new Policy($hidden))->decide(Viewer::anonymous(), $place, time())->visible, 'decided by the virtual page that links to it');
            }
        }
    }

    public function testInjectedFilesKeepTheirOwnRelativePaths(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<inject src="../../shared/footer.html">',
            'shared/footer.html' => '<img src="logo.png"><calphp src="c.php"><showlink src="logo.png"><a href="../class/01.Introduction/notes.md#x">notes</a>',
            'shared/logo.png' => 'LOGO',
            'shared/c.php' => '<?php echo "FOOTER-SCRIPT";',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertContains('FOOTER-SCRIPT', $page, '<calphp> relative to the injected file (L-21)');
        $this->assertNotContains('data-cal6-hidden', $page, 'its image has a key');
        $this->assertSame(2, substr_count($page, 'get.php?file='), 'the image and <showlink>');
        $this->assertContains('href="#/view/' . $course->keysByEvent['class_1']['notes.md'] . '#x"', $page, 'and its link back into the event');
    }
}
