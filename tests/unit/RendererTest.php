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
            'class/01.Introduction/index.html' => '<a href="other.html">a</a><iframe src="other.html"></iframe><img src="other.html"><script src="reading.md"></script><a href="data.txt">d</a>',
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
            'shared/footer.html' => '<img src="logo.png"><calphp src="c.php"><showlink src="logo.png"><a href="../class/01.Introduction/reading.md#x">notes</a>',
            'shared/logo.png' => 'LOGO',
            'shared/c.php' => '<?php echo "FOOTER-SCRIPT";',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertContains('FOOTER-SCRIPT', $page, '<calphp> relative to the injected file (L-21)');
        $this->assertNotContains('data-cal6-hidden', $page, 'its image has a key');
        $this->assertSame(2, substr_count($page, 'get.php?file='), 'the image and <showlink>');
        $this->assertContains('href="#/view/' . $course->keysByEvent['class_1']['reading.md'] . '#x"', $page, 'and its link back into the event');
    }

    public function testCommentsScriptsAndDeepNesting(): void
    {
        $deep = str_repeat('<inst>', 4000) . 'DEEP-SECRET' . str_repeat('</inst>', 4000);
        $course = $this->course([
            'class/01.Introduction/index.html' => '<inst>A<!-- </inst> -->SECRET-COMMENT</inst><inst><script>var s = "</inst>";</script>SECRET-SCRIPT</inst><p>AFTER</p>',
            'class/01.Introduction/deep.html' => $deep . '<p>DEEP-AFTER</p>',
            'class/01.Introduction/quote.md' => str_repeat('> ', 20000) . 'quoted',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertNotContains('SECRET-COMMENT', $page, 'a closing tag in a comment does not end the block (L-29)');
        $this->assertNotContains('SECRET-SCRIPT', $page, 'nor one in a script');
        $this->assertContains('AFTER', $page);
        $start = microtime(true);
        $deepPage = $this->render($course, 'class_1/deep.html');
        $this->assertTrue(microtime(true) - $start < 2.0, 'deep nesting is fast (L-34)');
        $this->assertNotContains('DEEP-SECRET', $deepPage);
        $this->assertContains('DEEP-AFTER', $deepPage);
        $this->assertContains('quoted', $this->render($course, 'class_1/quote.md'), 'a very deep Markdown quote does not exhaust memory (L-34)');
    }

    public function testCodeinjectOfAPageHidesWhatTheViewerMayNotSee(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<pre><codeinject src="other.html"></pre>',
            'class/01.Introduction/other.html' => '<p>shown</p><inst>INST-TEXT</inst><lock code="s3cret">LOCKED-TEXT</lock>',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertContains('&lt;p&gt;shown&lt;/p&gt;', $page, 'the source is shown');
        foreach (['INST-TEXT', 'LOCKED-TEXT', 's3cret'] as $hidden) {
            $this->assertNotContains($hidden, $page, "but not what the viewer may not see (L-30): $hidden");
        }
        $this->assertContains('INST-TEXT', $this->render($course, 'class_1/index.html', [], Viewer::account($course->config, 'prof')), 'instructors see all of it');
    }

    public function testInjectedImagesInPagesBoxAssetsAndCopies(): void
    {
        $course = $this->course([
            'pages/home.html' => '<inject src="../shared/frag.html">',
            'shared/frag.html' => '<img src="pic.png">',
            'shared/pic.png' => 'P',
            'lab/01.Setup/lab-solution-part2.html' => '<inject src="../../shared/frag.html">',
            'config/duplicate.ini' => "class_2 = class_1\n",
            'class/01.Introduction/index.html' => '<inject src="../../shared/frag.html">',
            'config/default.ini' => "class_1 = never\nclass_2 = always\nlab = always\n",
        ]);
        $this->assertNotContains('data-cal6-hidden', $this->render($course, 'page/home'), 'a paths.ini page that injects (L-32)');
        $prof = Viewer::account($course->config, 'prof');
        $this->assertNotContains('data-cal6-hidden', $this->render($course, 'lab_1/lab-solution-part2.html', [], $prof), 'a box-asset page that injects');
        $this->assertNotContains('data-cal6-hidden', $this->render($course, 'class_2/index.html'), 'a numbered copy, while its source is hidden');
    }

    public function testCommentsAndScriptsDoNotCarryHiddenContent(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => 'A <!-- <inject src="../../shared/part.html"> --> <!-- <inst>COMMENTED-INST</inst> --><script>var t = `<inject src="../../shared/part.html">`; // <!-- kept --></script><p>END</p>',
            'shared/part.html' => '<inst>INJ-SECRET</inst><postmeeting>PM</postmeeting>',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html', [], null, 0);
        foreach (['INJ-SECRET', 'COMMENTED-INST', '<!-- <inst>'] as $hidden) {
            $this->assertNotContains($hidden, $page, "comments are removed, injects in comments and scripts are not expanded (M-23): $hidden");
        }
        $this->assertContains('`<inject src="../../shared/part.html">`', $page, 'text in a script stays as written');
        $this->assertContains('// <!-- kept -->', $page, 'comments inside scripts are part of the script');
        $this->assertContains('END', $page);
    }

    public function testPostmeetingOfAnInjectedPageFollowsItsOwnEvent(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => 'C1 <inject src="../02.Two/index.html"> <pre><codeinject src="../02.Two/index.html"></pre>',
            'class/02.Two/index.html' => 'C2 <postmeeting>PM-ANSWER</postmeeting>',
            'pages/home.html' => '<inject src="../class/02.Two/index.html">',
            'config/default.ini' => "class = always\n",
        ]);
        $classTwo = $course->events['class_2'];
        $before = $classTwo->time->start()->getTimestamp() - 60; // class 1 is long over, class 2 not yet
        foreach (['class_1/index.html', 'page/home'] as $page) {
            $this->assertNotContains('PM-ANSWER', $this->render($course, $page, [], null, $before), "class 2's answers wait for class 2 (M-24): $page");
        }
        $after = $classTwo->time->end()->getTimestamp() + 60;
        $this->assertContains('PM-ANSWER', $this->render($course, 'class_1/index.html', [], null, $after), 'and appear after it');
    }

    public function testANulByteInALinkIsAMissingFile(): void
    {
        $course = $this->course([
            'pages/home.html' => '<img src="x%00.png"><a href="y%00.html">y</a>',
            'class/01.Introduction/index.html' => '<img src="z%00.png">',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $this->assertContains('x%00.png', $this->render($course, 'page/home'), 'the course loads and the link is a missing file, left as written (L-35)');
        $this->assertContains('z%00.png', $this->render($course, 'class_1/index.html'));
    }

    public function testLinksDoNotTellWhetherAPathExists(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<a href="<url value=a>">a</a>',
            'lab/01.Setup/answers.pdf' => 'A',
            'config/default.ini' => "class_1 = always\nlab = never\n",
        ]);
        $answers = [];
        foreach (['../../../../../../../../etc/passwd', '../../../../../../../../etc/nonexistent', '../../lab/01.Setup/answers.pdf', 'nope.pdf'] as $value) {
            $answers[] = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['a' => $value]))
                ->render($course->places[$course->keysByEvent['class_1']['index.html']]);
        }
        $this->assertSame(1, count(array_unique($answers)), 'through <url>, hidden, missing and outside give the same answer (L-51): ' . implode(' | ', $answers));
    }

    public function testUrlTargetsMayOnlyBeFilesWithTheirOwnRule(): void
    {
        $course = $this->course([
            'config/paths.ini' => "[pages]\nhome = pages/home.html\n",
            'config/default.ini' => "class_1 = always\n",
            'pages/home.html' => '<inst><a href="answers/exam-key.pdf">key</a></inst><a href="https://cnn.com/x">news</a> <a href="gone.html">gone</a> <a href="../../../../../../etc/passwd">p</a> <a href="../config/config.ini">c</a>',
            'pages/answers/exam-key.pdf' => 'KEY',
            'class/01.Introduction/index.html' => '<a href="<url value=next>">next</a>',
            'class/01.Introduction/notes.html' => 'N',
            'class/01.Introduction/img/pic.png' => 'P',
        ]);
        $home = $this->render($course, 'page/home');
        $this->assertContains('<a href="https://cnn.com/x">news</a>', $home, 'external links are unchanged');
        $this->assertContains('<a href="gone.html">gone</a>', $home, 'a missing file the author linked is left as written');
        $this->assertContains('href="../../../../../../etc/passwd"', $home, 'a path outside the course is left as written, whether it exists or not');
        $this->assertContains('href="../config/config.ini"', $home, 'and so is one into config/');
        $place = $course->places[$course->keysByEvent['class_1']['index.html']];
        $link = fn (string $next): string => (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['next' => $next]))->render($place);
        $this->assertContains('<a data-cal6-hidden="1">next</a>', $link('../../pages/answers/exam-key.pdf'), 'a <url> target that borrows a page\'s rule is not handed out (M-36)');
        $this->assertContains('<a data-cal6-hidden="1">next</a>', $link('img/pic.png'), 'nor a file in a subfolder');
        $this->assertContains('#/view/', $link('index.html'), 'a box file still works through <url>');
        $this->assertContains('<a data-cal6-hidden="1">next</a>', $link('notes.html'), 'a file calendar.php does not list does not');
        $this->assertContains('#/view/', $link('../../pages/home.html'), 'and so does a paths.ini page');
        $this->assertContains('href="https://cnn.com/"', $link('https://cnn.com/'), 'and an external address');
    }

    public function testUrlValuesAndNumericNames(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<a href="<url value="next" default="#">">go</a> <url value="7" default="none">',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['next' => ' JavaScript:alert(1)', '7' => 'seven']))
            ->render($course->places[$course->keysByEvent['class_1']['index.html']]);
        $this->assertNotContains('alert', $page, 'no javascript: URLs from <url> (L-39)');
        $this->assertContains('seven', $page, 'numeric names (L-40)');
    }

    public function testTheScannerFollowsTheBrowser(): void
    {
        $cases = [
            'cdata' => '<![CDATA[ <inst>S1</inst> ]]>',
            'abrupt' => '<!--> <inst>S2</inst> <!-- x -->',
            'abrupt2' => '<!---> <inst>S3</inst>',
            'custom' => '<script-x> <inst>S4</inst> </script-x><style-guide><inst>S5</inst></style-guide>',
            'attribute' => '<img alt="<!--"> <inst>S6</inst> <img alt="-->"><a title="<script>"><inst>S7</inst></a><a title="</inst>"><inst>S8</inst></a>',
            'apostrophe' => "<img alt=it's src=a.png><inst>S9</inst>",
            'stray' => '<td width=50%"><inst>S10</inst></td><p data-x"y><inst>S11</inst></p> <p class="z">',
            'unclosed-style' => '<style>p{}</stlye><inst>S12</inst><lock code="xyz">S13</lock>',
            'unclosed-script' => '<script src="a.js"><inst>S14</inst>',
        ];
        $files = ['config/default.ini' => "class_1 = always\n"];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html . '<p>END</p>';
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $page = $this->render($course, "class_1/$name.html");
            $this->assertSame(0, preg_match('/S(?:[1-9]|1[0-4])\b|xyz/', $page), "hidden as a browser would parse it (L-36, H-5, M-27): $name: " . $page);
            $this->assertContains('END', $page, $name);
        }
    }

    public function testTagsAreProcessedOnlyWhereTheBrowserSeesTags(): void
    {
        $course = $this->course([
            'class/01.Introduction/url.html' => '<button onclick="show(\'<url value="n" default="1">\')">x</button><script>var n = <url value="n" default="1">;</script><p><url value="n" default="1"></p><a href="?x=<url value="n" default="1">" title="<replace value="course">">a</a><p style="color:<url value="n">" onmouseover=\'f("<url value="n">")\'>s</p><script>x</script>',
            'class/01.Introduction/ctx.html' => '<a href="#" onclick="if (n < 3) f(\'<url value=n>\')">a</a><img/onerror="f(\'<url value=n>\')" src=x><a href="x"onclick="f(\'<url value=n>\')">c</a>'
                . '<a href="javascript:go(\'<url value=n>\')">d</a><a href=<url value=n>>e</a><iframe srcdoc="<url value=n>"></iframe>'
                . "<script><!--\ndocument.write('<script>i()</script>');\nvar a='<url value=n>';\n// --></script>"
                . '<p title="<url value=n>" data-x=\'<url value=n>\'>OK <url value=n></p><a href="https://example.org/?q=<url value=n>">f</a>',
            'class/01.Introduction/open.html' => '<p><url value="n"></p><script>var n = <url value="n">;',
            'class/01.Introduction/attr.html' => '<img alt="<inject src=\'secret.txt\'>" src="a.png"><p>A-END</p>',
            'class/01.Introduction/secret.txt' => 'INJECTED',
            'class/01.Introduction/lock.html' => '<lock code="a>b">LOCKED</lock>',
            'class/01.Introduction/many.html' => str_repeat('<script></script><inject src="nope.html">', 20000),
            'config/default.ini' => "class_1 = always\n",
        ]);
        $place = static fn (string $f) => $course->places[$course->keysByEvent['class_1'][$f]];
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['n' => 'EVIL']))->render($place('url.html'));
        $this->assertSame(2, substr_count($page, 'EVIL'), '<url> is substituted in text and ordinary attributes (as v4), not in handlers, style or scripts (M-28): ' . $page);
        $this->assertContains('href="?x=EVIL"', $page, 'as v4');
        $this->assertNotContains('<replace', $page, '<replace> anywhere');
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['n' => 'EVIL']))->render($place('ctx.html'));
        $this->assertSame(4, substr_count($page, 'EVIL'), 'only in text, title, data-* and after an http(s) URL (M-32), not in handlers, javascript: links, unquoted values, srcdoc or an escaped script (L-52): ' . $page);
        $this->assertContains('q=EVIL', $page);
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['n' => 'EVIL']))->render($place('open.html'));
        $this->assertSame(1, substr_count($page, 'EVIL'), 'not in a script that is never closed: ' . $page);
        $this->assertNotContains('INJECTED', $this->render($course, 'class_1/attr.html'), 'an <inject> inside an attribute value is text (L-47)');
        $this->assertSame(['a>b'], PageRenderer::lockCodes('<lock code="a>b">x</lock>'), 'a lock code may contain > (L-45)');
        $unlocked = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', ['a>b']))->render($place('lock.html'));
        $this->assertContains('LOCKED', $unlocked, 'and unlocks');
        $start = microtime(true);
        $this->render($course, 'class_1/many.html');
        $this->assertTrue(microtime(true) - $start < 3.0, 'many scripts and injects stay fast (L-44)');
    }

    public function testMarkdownCommentsAndIndentedHiddenBlocks(): void
    {
        $course = $this->course([
            'class/01.Introduction/c.md' => "Text <!-- start\n\nM1 -->after\n\n`<!-- shown -->`\n\n```\n<!-- fenced -->\n```\n\n<img alt=\"<!--\"><inst>-->M2</inst>",
            'class/01.Introduction/i.md' => "Intro\n\n    <inst>\n    M3\n    </inst>\n\n- item\n\n        <inst>\n\n        M4\n\n        </inst>\n\nEND",
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/c.md');
        $this->assertSame(0, preg_match('/M[12]\b/', $page), 'a Markdown comment that spans a blank line is removed (M-30): ' . $page);
        $this->assertContains('after', $page);
        $this->assertContains('&lt;!-- shown --&gt;', $page, 'code keeps its comments');
        $this->assertContains('&lt;!-- fenced --&gt;', $page);
        $page = $this->render($course, 'class_1/i.md');
        $this->assertSame(0, preg_match('/M[34]\b/', $page), 'an indented <inst> in Markdown is HTML, not code (L-42): ' . $page);
        $this->assertContains('END', $page);
    }

    public function testScriptsAreNotEditedByKeywordOrAnchorRemoval(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => "<a name=\"top\">T</a><keyword x>K</keyword> <search>S\n<script>var s = \"<a name='x'>\"; var t = '<keyword x>'; if (a<search.length) { b(); } // > end\n</script>",
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = $this->render($course, 'class_1/index.html');
        $this->assertContains("var s = \"<a name='x'>\"; var t = '<keyword x>'; if (a<search.length) { b(); } // > end", $page, 'script text is left as written (L-54)');
        $this->assertContains('<a id="top" name="top">', $page, 'real anchors get ids');
        $this->assertContains('</a>K S', $page, 'real <keyword>/<search> tags are removed');
    }

    public function testIncludePagesAndMarkdownAttributes(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<inject src="../../shared/menu.inc.html"><p>END</p>',
            'class/01.Introduction/a.md' => "![diagram <inst>ALT-SECRET</inst>](pic.png)\n\n[link](x.html \"<inst>TITLE-SECRET</inst>\")\n",
            'class/01.Introduction/x.php.html' => 'X',
            'shared/menu.inc.html' => '<nav>MENU</nav>',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $this->assertContains('MENU', $this->render($course, 'class_1/index.html'), 'a .inc.html page can be injected (L-57)');
        $this->assertSame(1, count(array_filter($course->problems, static fn ($p): bool => str_contains($p->message, 'x.php.html'))), 'a page with a PHP extension is a problem');
        $page = $this->render($course, 'class_1/a.md');
        $this->assertSame(0, preg_match('/ALT-SECRET|TITLE-SECRET/', $page), 'hidden blocks in Markdown alt text and titles are removed (L-56): ' . $page);
    }

    public function testUrlValuesCanNotAddAttributes(): void
    {
        $course = $this->course([
            'class/01.Introduction/index.html' => '<a title="<url value=t>" href="index.html">a</a><img alt="<url value=t>" class="<url value=t>" src="pic.png">'
                . '<a href="<url value=a><url value=b>">b</a><p title="<url value=z>">z</p><calphp src="../../shared/x.php">',
            'class/01.Introduction/pic.png' => 'P',
            'shared/x.php' => '<?php echo "CALPHP";',
            'config/default.ini' => "class_1 = always\n",
        ]);
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], [
            't' => ' src=nope.png data-injected=1 x', 'a' => 'javascript', 'b' => ':void(0)', 'z' => "\x00cal6-calphp-0\x00",
        ]))->render($course->places[$course->keysByEvent['class_1']['index.html']]);
        $this->assertSame(0, preg_match('/<[^>]*\sdata-injected=/', preg_replace('/"[^"]*"/', '""', $page) ?? ''), 'a value stays inside its attribute (H-6): ' . $page);
        $this->assertContains('title=" src=nope.png data-injected=1 x"', $page);
        $this->assertNotContains('javascript:', $page, 'two values side by side can not make a javascript: link (M-34)');
        $this->assertSame(1, substr_count($page, 'CALPHP'), 'a value can not pull in <calphp> output (L-60)');
    }

    public function testTheLexerEndsTagsAndScriptsAsTheBrowserDoes(): void
    {
        $cases = [
            'abrupt-in-script' => '<script><!--> x="<script>";</script><inst>S1</inst></script>',
            'quote-in-name' => '<p"x="y> <inst>S2</inst> ">',
            'odd-name' => "<scriptx\u{e9}><inst>S4</inst></scriptx\u{e9}>",
        ];
        $files = ['config/default.ini' => "class_1 = always\n", 'class/01.Introduction/h.md' => "## Quiz 3 <inst>answer is 42</inst>\n"];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $page = $this->render($course, "class_1/$name.html");
            $this->assertSame(0, preg_match('/S[1-4]\b/', $page), "hidden as a browser parses it (L-59): $name: $page");
        }
        $this->assertNotContains('42', $this->render($course, 'class_1/h.md'), 'a heading id keeps no hidden text (L-58)');
        $this->dir !== '' && file_put_contents("$this->dir/class/01.Introduction/vt.html", "<script>var a = 1;</script\x0B>\n// <url value=n>\n</script>");
        $course = Course::load($this->dir);
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['n' => 'EVIL']))
            ->render($course->places[$course->keysByEvent['class_1']['vt.html']]);
        $this->assertNotContains('EVIL', $page, '</script> followed by a vertical tab does not end the script (L-59)');
    }

    public function testBoundedElementsFormedCommentsAndMarkdownLeftovers(): void
    {
        $cases = [
            'textarea' => '<textarea><b title="</textarea><inst>S1</inst>"></b></textarea>',
            'noscript' => '<noscript><b title="</noscript><inst>S2</inst>"></b></noscript>',
            'inside' => '<textarea><inst>S3</inst></textarea>',
            'formed' => 'A<<!-- x -->!-- <inst>S4</inst> -->B',
            'formed2' => 'A<<inst>x</inst>!-- <lock code=abc>S5</lock> -->B',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/n.md' => "## Intro <inst>a <inst>b</inst> S6</inst>\n\n![pic <inst>x <inst>y</inst> S7</inst>](a.png)\n\n<inst>\n[r]: x.html \"S8\"\n</inst>\n\n[solutions][r]\n\n[" . str_repeat('a ', 8000) . "]\n",
            'class/01.Introduction/url.html' => '<textarea><b title=\'</textarea><img src=x title="<url value=n>">\'></b></textarea><textarea title="x"><url value=n></textarea>',
            'class/01.Introduction/deep.html' => str_repeat('<student>', 40000) . 'X' . str_repeat('</student>', 40000) . 'END',
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $page = $this->render($course, "class_1/$name.html");
            $this->assertSame(0, preg_match('/S[1-5]\b/', $page), "hidden (M-37, L-61): $name: $page");
        }
        $page = $this->render($course, 'class_1/n.md');
        $this->assertSame(0, preg_match('/S[6-8]\b/', $page), 'nested blocks and references in Markdown (L-62): ' . $page);
        $this->assertContains('a a a', $page, 'a long bracket span still converts (L-66)');
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['n' => 'EVIL']))
            ->render($course->places[$course->keysByEvent['class_1']['url.html']]);
        $this->assertSame(1, substr_count($page, 'EVIL'), 'a <url> value goes where the browser sees an attribute, never into a textarea body (M-37): ' . $page);
        $this->assertContains('title="EVIL"', $page);
        $this->assertContains('<textarea title="x"><url value=n></textarea>', $page);
        $start = microtime(true);
        $this->assertContains('END', $this->render($course, 'class_1/deep.html'));
        $this->assertTrue(microtime(true) - $start < 3.0, 'deep nesting that is kept stays fast (L-67)');
    }

    public function testClosingTagsCountOnlyWhereBothReadingsSeeThem(): void
    {
        $cases = [
            'textarea' => '<p>Q1</p><inst>Answer: write <textarea>the closing tag </inst> here</textarea> S1</inst><p>end</p>',
            'title' => '<inst><title>x</inst></title>S2</inst>',
            'svg' => '<inst><svg><title><a x="</title></inst>">S3</inst>',
            'bogus' => '<inst>A </1 </inst> > S4 </inst><p>pub</p>',
            'endattr' => '<inst>A</x y="></inst>">B S5</inst><p>pub</p>',
            'replace' => '<replace value="><inst>S6</inst>">',
            'codeinject' => '<codeinject src="m.md"><codeinject src="f.html">',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/m.md' => "Start a link with `<a href=\"` and close it.\n\n<inst>\nS7\n</inst>\n\n[f](https://forms.example.com/f?k=<inst>S8</inst>)\n",
            'class/01.Introduction/f.html' => 'A<<inst>x</inst>inst>S9</inst>',
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $page = $this->render($course, "class_1/$name.html");
            $this->assertSame(0, preg_match('/S[1-9]\b/', $page), "hidden (M-39, L-68, L-69, L-73): $name: $page");
        }
        $this->assertSame(0, preg_match('/S[78]\b/', $this->render($course, 'class_1/m.md')), 'Markdown destinations (L-70)');
        $this->assertContains('&lt;a href=&quot;', $this->render($course, 'class_1/codeinject.html'), 'the code sample is shown');
    }

    public function testMathAttributesSvgAndTextareaComments(): void
    {
        $course = $this->course([
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/m.md' => "Formula: \\( x = <inst>T1</inst> \\) and \$\$a<b\$\$\n",
            'class/01.Introduction/a.html' => '<abbr title="Hint: <inst>T2</inst>">x</abbr>',
            'class/01.Introduction/s.html' => '<svg><style>rect{fill:red}</svg><p>Visible</p><inst>T3</inst><style>p{}</style>',
            'class/01.Introduction/t.html' => "<textarea><!DOCTYPE html>\n<html><!-- your code here --></html></textarea><!-- gone -->",
            'class/01.Introduction/instructor.htm' => 'I',
        ]);
        foreach (['m.md' => 'T1', 'a.html' => 'T2', 's.html' => 'T3'] as $file => $secret) {
            $this->assertNotContains($secret, $this->render($course, "class_1/$file"), "hidden (M-40, L-79, L-77): $file");
        }
        $this->assertContains('a&lt;b', $this->render($course, 'class_1/m.md'), 'math is still shown as written');
        $this->assertContains('title="Hint: "', $this->render($course, 'class_1/a.html'));
        $page = $this->render($course, 'class_1/t.html');
        $this->assertContains('<!DOCTYPE html>', $page, 'a textarea keeps its comments and doctype (L-82)');
        $this->assertContains('<!-- your code here -->', $page);
        $this->assertNotContains('gone', $page);
    }

    public function testAnEventDirectoryReachedThroughALinkIsStillAnEventDirectory(): void
    {
        $course = $this->course([
            'config/default.ini' => "class_1 = always\n",
            'pages/home.html' => '<inject src="../material/class/01.Introduction/a/b/c/d/e/deep.html">HOME',
            'config/paths.ini' => "[pages]\nhome = pages/home.html\n",
            'material/class/01.Introduction/index.html' => 'I',
            'material/class/01.Introduction/a/b/c/d/e/deep.html' => 'DEEP-SECRET',
        ]);
        Fixture::remove("$this->dir/class");
        symlink("$this->dir/material/class", "$this->dir/class");
        $course = Course::load($this->dir);
        $page = $this->render($course, 'page/home');
        $this->assertNotContains('DEEP-SECRET', $page, 'a file without a key in a linked event directory is not injected (L-83)');
    }

    public function testQueryStringsAndNumberedMarkdownCopies(): void
    {
        $course = $this->course([
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/index.html' => '<a href="notes.html?week=3&amp;file=x#q2">n</a> <a href="reading.md2">old</a> <a href="<url value=next>">u</a>',
            'class/01.Introduction/notes.html' => 'N',
            'class/01.Introduction/reading.md2' => '# OLD <inst>S</inst>',
        ]);
        $place = $course->places[$course->keysByEvent['class_1']['index.html']];
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['next' => 'reading.md2']))->render($place);
        $this->assertSame(1, preg_match('~href="#/view/[A-Za-z0-9_-]+\?week=3#q2"~', $page), 'a page link keeps its query string, without html.php\'s own names (L-75): ' . $page);
        $this->assertContains('get.php?file=', $page, 'a link the author wrote to reading.md2 works (L-92)');
        $this->assertContains('<a data-cal6-hidden="1">u</a>', $page, 'but a <url> value can not reach it');
        $copy = $course->places[$course->keysByEvent['class_1']['reading.md2']];
        $this->assertSame('file', $copy->file->kind(), 'it is never a page');
        $json = (new \Cal6\Endpoint\CalendarJson($course, new \Cal6\Http\Context($this->dir, $course->config, [], [], [], time())))->build(Viewer::anonymous(), time());
        $this->assertNotContains('reading.md2', json_encode($json), 'and calendar.php does not list it');
    }

    public function testReview14Leftovers(): void
    {
        $cases = [
            'bang' => '<p>Q1 <!-- old note --!> <inst>The answer is <!-- check --> U1</inst></p>',
            'cdata' => '<![CDATA[ if a > b <inst> ]]> U2 </inst><p>END</p>',
            'foreign' => '<math><mi><b>x</b></mi><style><inst>U3</inst></style></math>',
            'codeattr' => '<codeinject src="part.html">',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\nclass_2 = always\n",
            'class/01.Introduction/part.html' => '<img title="tip <inst>U4</inst>" src="x.png">',
            'class/01.Introduction/md.md' => "Run <kbd title=\"`\">Ctrl</kbd> then <inst>U5</inst> then <kbd title=\"`\">Esc</kbd>.\n\n\$\$ y = <inst title=\"a>b\">U6</inst> \$\$ AFTER\n\nQ <!-- x --!> <inst>U7 <!-- c --></inst>\n",
            'class/01.Introduction/c.html' => '<noscript><!-- C1 --></noscript><svg><title><!-- C2 --></title></svg><textarea><!-- KEEP --></textarea>',
            'class/01.Introduction/inj.html' => '<inject src="../02.Topic2/part.html"><inject src="../../shared/s.html">',
            'class/02.Topic2/part.html' => '<p>Q <postmeeting>U8</postmeeting></p><abbr title="Answer: <postmeeting>U9</postmeeting>">a</abbr>',
            'shared/s.html' => '<a href="page.html#<url value=sec default=top>">p</a><img src="logo-<replace value=course>.png">',
            'shared/logo-AB123.png' => 'L',
            'shared/page.html' => 'P',
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $this->assertSame(0, preg_match('/U[1-4]\b/', $this->render($course, "class_1/$name.html")), "hidden (M-42, L-84, L-88, L-86): $name");
        }
        $page = $this->render($course, 'class_1/md.md');
        $this->assertSame(0, preg_match('/U[5-7]\b/', $page), 'Markdown: backticks in attributes, math tags with ">", --!> (L-85, L-94, M-42): ' . $page);
        $this->assertContains('AFTER', $page, 'the rest of the page stays (L-94)');
        $page = $this->render($course, 'class_1/c.html');
        $this->assertNotContains('C1', $page, 'comments in noscript and svg are removed (L-89)');
        $this->assertNotContains('C2', $page);
        $this->assertContains('<!-- KEEP -->', $page);
        $before = $course->events['class_2']->time->start()->getTimestamp() - 60;
        $page = $this->render($course, 'class_1/inj.html', [], null, $before);
        $this->assertSame(0, preg_match('/U[89]\b/', $page), 'an injected page\'s attributes follow its own event (L-87): ' . $page);
        $this->assertContains('#/view/', $page, 'value tags in an injected file are filled in before its links are rebased (L-90): ' . $page);
        $this->assertContains('#top', $page);
        $this->assertContains('get.php?file=', $page);
    }

    public function testReview15Leftovers(): void
    {
        $cases = [
            'svgmi' => 'A<svg><mi><style><inst><p>V1</p></inst></style></mi></svg> Z',
            'nested' => 'A<svg><foreignObject><svg><style><inst><p>V2</p></inst></style></svg></foreignObject></svg> Z',
            'annot' => 'A<math><annotation-xml><style><inst><p>V3</p></inst></style></annotation-xml></math> Z',
            'bogus' => '<!x <img alt=">" <inst>V4</inst>',
            'bogus2' => '<? <b title=">" <lock code=k>V5</lock>',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/inj.html' => '<script>var a = 1;</scirpt><inject src="../../shared/part.html"> <url value=n>',
            'shared/part.html' => 'Hello <url value=n>!',
            'class/01.Introduction/ok.html' => 'A<svg><foreignObject><style>p{}</style><p>ok</p></foreignObject></svg><inst>H</inst> Z',
            'class/01.Introduction/many.html' => str_repeat('<!---->x', 80000),
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $this->assertSame(0, preg_match('/V[1-5]\b/', $this->render($course, "class_1/$name.html")), "hidden (L-95, L-96): $name");
        }
        $this->assertContains('<p>ok</p></foreignObject></svg> Z', $this->render($course, 'class_1/ok.html'), 'HTML inside foreignObject is HTML');
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['n' => 'EVIL']))
            ->render($course->places[$course->keysByEvent['class_1']['inj.html']]);
        $this->assertNotContains('EVIL', $page, 'an injected <url> after an unclosed script is not filled in (L-97)');
        $start = microtime(true);
        $this->render($course, 'class_1/many.html');
        $this->assertTrue(microtime(true) - $start < 3.0, 'many comments stay fast (L-98)');
    }

    public function testReview16Leftovers(): void
    {
        $cases = [
            'annot' => '<math><annotation-xml data-encoding="text/html"><style>p{}</math><inst>W1</inst><style>q{}</style>',
            'svgw' => '<svg width=10/><style>p{}</svg><inst>W2</inst><style>q{}</style>',
            'mglyph' => '<math><mtext><mglyph><style>p{}</math><inst>W3</inst><style>q{}</style>',
            'code' => '<codeinject src="n.md">',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/n.md' => "- item\n\n    More text <inst>W4</inst> here\n\n```x`y\n<inst>\nW5\n</inst>\n```\n\nPress <kbd title=\"`\">K</kbd> then <inst>W6</inst> or `x`\n",
            'class/01.Introduction/url.html' => '<p>Week <url value=week default=1></p><svg><style>.a{}</style></svg><noscript><script>x()</script></noscript><textarea><script>y()</script></textarea><p>Again <url value=week default=1></p>',
            'class/01.Introduction/frame.html' => '<iframe src="notes.html?file%00x=K2&amp;file[]=K3&amp;week=3"></iframe>',
            'class/01.Introduction/notes.html' => 'N',
            'class/01.Introduction/prose.md' => str_repeat('if x <y ', 8000) . "\n",
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $this->assertSame(0, preg_match('/W[1-6]\b/', $this->render($course, "class_1/$name.html")), "hidden (L-101, L-102): $name");
        }
        $page = (new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/', [], ['week' => '7']))
            ->render($course->places[$course->keysByEvent['class_1']['url.html']]);
        $this->assertSame(2, substr_count($page, 'Week 7') + substr_count($page, 'Again 7'), '<url> still works after an svg style, a noscript script and a textarea (L-103): ' . $page);
        $page = $this->render($course, 'class_1/frame.html');
        $this->assertNotContains('K2', $page, 'mangled names of html.php\'s own parameters are dropped (L-104): ' . $page);
        $this->assertNotContains('K3', $page);
        $this->assertContains('week=3', $page);
        $this->assertContains('if x', $this->render($course, 'class_1/prose.md'), 'long prose with "<" converts (L-106)');
    }

    public function testReview17Leftovers(): void
    {
        $cases = [
            'svgspace' => '<svg / ><style>x{}<p><inst>X1</inst></p></style></svg>',
            'fo' => '<svg><foreignObject><span>t</svg></span></foreignObject><style>x{}<p><inst>X2</inst></p></style></svg>',
            'attr' => 'A<div class="x"<inst>X3</inst></div>B',
            'attr2' => 'A<p title=x<inst>X4</inst>>B',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/deep.html' => str_repeat('<svg>', 5000) . str_repeat('<svg></x>', 5000),
            'class/01.Introduction/many.html' => '<url value=a>' . str_repeat('<textarea><script></textarea>', 16000),
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $this->assertSame(0, preg_match('/X[1-4]\b/', $this->render($course, "class_1/$name.html")), "hidden (L-109, L-110): $name");
        }
        foreach (['deep.html', 'many.html'] as $file) {
            $start = microtime(true);
            $this->render($course, "class_1/$file");
            $this->assertTrue(microtime(true) - $start < 3.0, "$file renders quickly (L-107, L-108)");
        }
    }

    public function testReview18Leftovers(): void
    {
        $cases = [
            'name' => '<p<inst>Y1</inst></p>',
            'end' => '<p>a</p<inst>Y2</inst>',
            'end2' => '<p>a</p <inst>Y3</inst>',
            'desc' => '<svg><desc><span></desc></svg></span></desc><style><inst><p>Y4</p></inst></style>',
            'enc' => '<math><annotation-xml encoding="text&#x2F;html"><p>x</p></annotation-xml><style><inst><p>Y5</p></inst></style></math>',
            'srcdoc' => '<iframe srcdoc="&lt;p&gt;Q: &lt;inst&gt;Y6&lt;/inst&gt;&lt;/p&gt;"></iframe>',
            'default' => '<a href="x" title=\'<url value=q default="<inst>Y7</inst>">\'>a</a>',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/starter.html' => "<textarea>\nfor (int i = 0; i<n; i++) {\n  <inst>sum += a[i];</inst>\n}\n</textarea>\n<p>VISIBLE-NEXT-QUESTION</p>",
            'class/01.Introduction/deep.html' => str_repeat('<b a<student>', 20000) . str_repeat('x', 200000),
            'class/01.Introduction/ends.html' => str_repeat('<math>', 300) . str_repeat('<svg>', 300) . str_repeat('</math>', 80000),
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $this->assertSame(0, preg_match('/Y[1-7]\b/', $this->render($course, "class_1/$name.html")), "hidden (L-111..L-114): $name");
        }
        $page = $this->render($course, 'class_1/starter.html');
        $this->assertContains('VISIBLE-NEXT-QUESTION', $page, 'a hidden block in a textarea does not hide the rest of the page (M-44)');
        $this->assertNotContains('sum +=', $page);
        $this->assertContains('</textarea>', $page);
        foreach (['deep.html', 'ends.html'] as $file) {
            $start = microtime(true);
            $this->render($course, "class_1/$file");
            $this->assertTrue(microtime(true) - $start < 3.0, "$file renders quickly (L-115, L-116)");
        }
    }

    public function testReview19Leftovers(): void
    {
        $cases = [
            'endtag' => "<inst>A</inst\n<inst>Z1</inst>END",
            'starttag' => "<inst\n<inst>A</inst>Z2</inst>END",
            'two' => '<div a=<inst b=<inst>X</inst>Z3</inst>END',
            'num' => '<iframe srcdoc="&#60;inst&#62;Z4&#60;/inst&#62;"></iframe><iframe srcdoc="&#60inst&#62Z5&#60/inst&#62"></iframe>',
            'comment' => '<b title="<!--<inst>Z6</inst>">x</b><b title="<a x=\'<inst>Z7</inst>\'>">y</b>',
            'docattr' => '<iframe srcdoc="&lt;p title=&quot;Hint: &lt;inst&gt;Z8&lt;/inst&gt;&quot;&gt;q&lt;/p&gt;"></iframe>',
            'skipped' => str_repeat('<inst>', 257) . '<p<inst>x' . str_repeat('</inst>', 257) . 'Z9</inst>',
        ];
        $files = [
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/ta.html' => '<textarea><script src="app.js"></textarea><inst>H</inst><p>VISIBLE-AFTER</p><script>var a=1;</script>',
            'class/01.Introduction/svgs.html' => str_repeat('<svg><desc>', 8000) . str_repeat('</desc></svg>', 8000),
            'class/01.Introduction/links.html' => '<map><area href="notes.html"></map><input type="image" src="pic.png"><svg><image href="pic.png"/></svg><table background="pic.png"></table>',
            'class/01.Introduction/notes.html' => 'N',
            'class/01.Introduction/pic.png' => 'P',
            'class/01.Introduction/locks.html' => '<lock code="outer">A <lock code="inner">B</lock></lock>',
        ];
        foreach ($cases as $name => $html) {
            $files["class/01.Introduction/$name.html"] = $html;
        }
        $course = $this->course($files);
        foreach ($cases as $name => $html) {
            $this->assertSame(0, preg_match('/Z[1-9]\b/', $this->render($course, "class_1/$name.html")), "hidden (M-46, M-47, M-48, L-121): $name");
        }
        $page = $this->render($course, 'class_1/ta.html');
        $this->assertContains('VISIBLE-AFTER', $page, 'a script inside a textarea does not hide the rest of the page (L-117)');
        $this->assertNotContains('>H<', $page);
        $start = microtime(true);
        $this->render($course, 'class_1/svgs.html');
        $this->assertTrue(microtime(true) - $start < 3.0, 'deep svg end tags stay fast (L-118)');
        $page = $this->render($course, 'class_1/links.html');
        $this->assertSame(0, preg_match('/(?:href|src|background)="(?:notes\.html|pic\.png)"/', $page), 'area, input, svg image and background are rewritten (L-122): ' . $page);
        $place = $course->places[$course->keysByEvent['class_1']['locks.html']];
        $codes = PageRenderer::lockCodes((new PageRenderer($course, new Policy($course), Viewer::anonymous(), time(), '/'))->visibleSource($place));
        $this->assertSame(['outer'], $codes, 'a code inside a lock not opened is not accepted (L-120)');
    }

    public function testReview20Leftovers(): void
    {
        $course = $this->course([
            'config/default.ini' => "class_1 = always\n",
            'class/01.Introduction/pad.html' => '<iframe srcdoc="&#00000060;inst>Q1&#00000060;/inst> &#x000000003c;inst>Q2&#x000000003c;/inst>"></iframe>',
            'class/01.Introduction/sur.html' => '<iframe srcdoc="x&#xD800;y&#55296;z"></iframe>END',
            'class/01.Introduction/heads.md' => str_repeat("## h\n\n", 20000),
        ]);
        $this->assertSame(0, preg_match('/Q[12]\b/', $this->render($course, 'class_1/pad.html')), 'zero-padded references are decoded (L-123)');
        $this->assertContains('END', $this->render($course, 'class_1/sur.html'), 'a surrogate reference does not fail the page (L-124)');
        $start = microtime(true);
        $this->assertContains('id="h-20000"', $this->render($course, 'class_1/heads.md'));
        $this->assertTrue(microtime(true) - $start < 3.0, 'many equal headings stay fast (L-127)');
    }

    public function testExtremeInputStaysFastAndSmall(): void
    {
        $course = $this->course([
            'class/01.Introduction/deep.html' => str_repeat('<inst>', 70000) . 'X' . str_repeat('</inst>', 70000) . '<p>DEEP-END</p>',
            'class/01.Introduction/scripts.html' => str_repeat('<script ', 80000),
            'class/01.Introduction/unclosed.html' => str_repeat('<script>x', 20000) . '<inst>U</inst><p>U-END</p>',
            'class/01.Introduction/self.html' => str_repeat('<inject src="self.html">', 8) . 'SELF',
            'class/01.Introduction/emph.md' => str_repeat('_a ', 50000),
            'class/01.Introduction/list.md' => implode("\n", array_map(static fn (int $i): string => str_repeat('  ', $i) . '- item', range(0, 3000))),
            'config/default.ini' => "class_1 = always\n",
        ]);
        foreach (['unclosed.html' => 'U-END', 'deep.html' => 'DEEP-END', 'scripts.html' => '', 'self.html' => 'SELF', 'emph.md' => 'a', 'list.md' => 'item'] as $file => $expect) {
            $start = microtime(true);
            $memory = memory_get_usage();
            $page = $this->render($course, "class_1/$file");
            $this->assertTrue(microtime(true) - $start < 3.0, "$file renders quickly (L-37, L-55)");
            $this->assertTrue(memory_get_peak_usage() < 256 * 1024 * 1024, "$file within memory");
            $this->assertContains($expect, $page, $file);
            unset($memory);
        }
        $this->assertTrue(substr_count($this->render($course, 'class_1/self.html'), 'SELF') <= 1, 'a page does not inject itself (L-37)');
        file_put_contents("$this->dir/shared/huge.html", str_repeat('H', 4_100_000));
        file_put_contents("$this->dir/class/01.Introduction/big.html", '<inject src="../../shared/huge.html"><p>BIG-END</p>');
        $page = $this->render(Course::load($this->dir), 'class_1/big.html');
        $this->assertNotContains('HHH', $page, 'a file over the limit is not injected, nor read (L-48)');
        $this->assertContains('BIG-END', $page);
    }
}
