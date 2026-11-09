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
        $this->assertContains('x%00.png', $this->render($course, 'page/home'), 'the course loads and the link is left alone (L-35)');
        $this->assertContains('z%00.png', $this->render($course, 'class_1/index.html'));
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

    public function testExtremeInputStaysFastAndSmall(): void
    {
        $course = $this->course([
            'class/01.Introduction/deep.html' => str_repeat('<inst>', 70000) . 'X' . str_repeat('</inst>', 70000) . '<p>DEEP-END</p>',
            'class/01.Introduction/scripts.html' => str_repeat('<script ', 80000),
            'class/01.Introduction/self.html' => str_repeat('<inject src="self.html">', 8) . 'SELF',
            'class/01.Introduction/emph.md' => str_repeat('_a ', 50000),
            'class/01.Introduction/list.md' => implode("\n", array_map(static fn (int $i): string => str_repeat('  ', $i) . '- item', range(0, 3000))),
            'config/default.ini' => "class_1 = always\n",
        ]);
        foreach (['deep.html' => 'DEEP-END', 'scripts.html' => '', 'self.html' => 'SELF', 'emph.md' => 'a', 'list.md' => 'item'] as $file => $expect) {
            $start = microtime(true);
            $memory = memory_get_usage();
            $page = $this->render($course, "class_1/$file");
            $this->assertTrue(microtime(true) - $start < 3.0, "$file renders quickly (L-37)");
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
