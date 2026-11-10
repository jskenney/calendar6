<?php

declare(strict_types=1);

namespace Cal6\Tests\Http;

use Cal6\Tests\Fixture;
use Cal6\Tests\HttpClient;
use Cal6\Tests\Server;
use Cal6\Tests\TestCase;
use Cal6\Tests\Unit\PolicyTest;

require_once __DIR__ . '/../unit/PolicyTest.php';

final class EndpointsTest extends TestCase
{
    private string $dir = '';
    private ?Server $server = null;
    private bool $ignoreServerErrors = false;

    /** @param array<string, string|null> $extra */
    private function start(array $extra = []): HttpClient
    {
        $this->dir = Fixture::course(array_merge(PolicyTest::files(), $extra));
        $this->server = new Server($this->dir);
        return new HttpClient($this->server);
    }

    public function tearDown(): void
    {
        if ($this->server !== null && !$this->ignoreServerErrors) {
            $errors = $this->server->phpErrors();
            $this->assertSame([], $errors, 'no PHP errors or warnings on the server');
        }
        $this->server?->stop();
        Fixture::remove($this->dir);
    }

    private function login(HttpClient $client, string $user = 'prof'): void
    {
        $csrf = (string) ($client->get('calendar/php/config.php?csrf=1')->json()['csrf'] ?? '');
        $result = $client->post('calendar/php/auth.php', ['username' => $user, 'password' => Fixture::PASSWORD, 'csrf' => $csrf]);
        $this->assertSame(200, $result->status, "login as $user: " . $result->body);
    }

    /**
     * "event/name" => key: box files as calendar.php lists them, and (since calendar.php lists no
     * other files) the keys of an event's other files as the course itself makes them, for
     * events calendar.php shows.
     *
     * @return array<string, string>
     */
    private function keys(array $calendar): array
    {
        $keys = [];
        $course = \Cal6\Course\Course::load($this->dir);
        foreach ($calendar['events'] ?? [] as $id => $event) {
            foreach ($event['boxes'] as $box) {
                $keys["$id/$box[filename]"] = $box['key'];
            }
            foreach ($course->keysByEvent[$id] ?? [] as $name => $key) {
                if ($course->places[$key]->box === null) {
                    $keys["$id/$name"] ??= $key;
                }
            }
        }
        foreach ($calendar['pages'] ?? [] as $name => $page) {
            if (isset($page['key'])) {
                $keys["page/$name"] = $page['key'];
            }
        }
        return $keys;
    }

    public function testConfig(): void
    {
        $client = $this->start();
        $response = $client->get('calendar/php/config.php');
        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->header('content-type'));
        $this->assertSame('private, no-store', $response->header('cache-control'));
        $this->assertSame('nosniff', $response->header('x-content-type-options'));
        $data = $response->json();
        $this->assertSame('AB123', $data['course']['number'] ?? null);
        $this->assertSame('class', $data['schedule']['mon'] ?? null);
        $this->assertSame('06:45-07:35', $data['periods']['normal']['times']['X'] ?? null, 'the full periods.ini');
        $this->assertSame('HP301', $data['sections'][1]['meetings'][1]['room'] ?? null, 'rooms per meeting');
        $this->assertSame(null, $data['viewer']['user']);
        $this->assertSame(null, $data['csrf'], 'no session for anonymous visitors (L-12)');
        $this->assertSame('', $response->header('set-cookie'));
        $withToken = $client->get('calendar/php/config.php?csrf=1');
        $this->assertSame(48, strlen((string) ($withToken->json()['csrf'] ?? '')));
        foreach ([Fixture::SECRET, 'password_hash', '$2y$', 'default.ini', $this->dir, 'lockdown'] as $secret) {
            $this->assertNotContains($secret, $response->body, 'never in config.php');
        }
        $this->assertContains('HttpOnly', $withToken->header('set-cookie'));
        $this->assertContains('SameSite=Strict', $withToken->header('set-cookie'));
        $this->assertSame(48, strlen((string) ($client->get('calendar/php/config.php')->json()['csrf'] ?? '')), 'an existing session keeps its token');
        $this->assertSame(405, $client->post('calendar/php/config.php', [])->status);
    }

    public function testCalendarForEachViewer(): void
    {
        $client = $this->start();
        $anon = $client->get('calendar/php/calendar.php');
        $this->assertSame(200, $anon->status);
        $data = $anon->json();
        $keys = $this->keys($data);
        $this->assertTrue(isset($keys['class_1/index.html']));
        $this->assertFalse(isset($keys['class_1/instructor.html']), 'hidden files are left out');
        $this->assertFalse(isset($keys['lab_1/lab-solution.html']));
        $this->assertFalse(isset($data['events']['exam_2']), 'events on no day are left out');
        $this->assertSame('Third', $data['events']['class_3']['title'] ?? 'x', 'the title is always shown, even with nothing visible');
        $this->assertSame([['event' => 'class_1', 'type' => 'class', 'number' => 1, 'title' => 'Introduction']], $data['days']['2026-08-24'] ?? null, 'days by date: the events on each');
        $first = $data['events']['class_1']['boxes'][0] ?? [];
        $this->assertSame(['box', 'filename', 'key', 'kind'], array_keys($first), 'one item per file: box, filename, key, kind (no size)');
        $this->assertSame(['title', 'index.html', 'html'], [$first['box'], $first['filename'], $first['kind']]);
        $this->assertSame(['home', 'resources', 'policy'], array_keys($data['pages']), 'pages by name');
        $this->assertSame(['title' => 'Resources'], $data['pages']['resources'], 'page/resources = never: its title only');
        $this->assertSame(['title', 'key', 'filename', 'kind'], array_keys($data['pages']['home']));
        $this->assertFalse(isset($data['next_change']), 'no next_change (E-10)');
        $this->assertFalse(isset($data['events']['class_1']['start']), 'no times without a section');
        $this->assertNotContains('"opens"', $anon->body, 'no hints for anonymous viewers');
        $this->assertNotContains('"visible"', $anon->body);
        $this->assertFalse(isset($data['problems']), 'problems are for instructors');
        $this->assertNotContains('instructor.html', $anon->body);
        $this->assertSame('Midterm', $data['events']['exam_1']['title'] ?? null, 'titles are shown to everyone, even of events with nothing visible yet');
        $this->assertSame([], $data['events']['exam_1']['boxes'] ?? null);
        $this->assertNotContains('Final', $anon->body, 'but not events on no calendar day');

        $again = $client->get('calendar/php/calendar.php', ['If-None-Match' => $anon->header('etag')]);
        $this->assertSame(304, $again->status, 'unchanged: 304');
        $this->assertSame('private, no-cache', $anon->header('cache-control'));

        $this->login($client);
        $prof = $client->get('calendar/php/calendar.php')->json();
        $profKeys = $this->keys($prof);
        $this->assertTrue(isset($profKeys['class_1/instructor.html']), 'instructors see everything');
        $this->assertSame(true, $prof['viewer']['instructor'] ?? null);
        $hidden = $prof['events']['class_1']['boxes'];
        $instructorBox = array_values(array_filter($hidden, static fn (array $b): bool => $b['box'] === 'instructor'))[0] ?? [];
        $this->assertSame(false, $instructorBox['visible'] ?? null, 'marked hidden for anonymous viewers');
        $this->assertFalse(isset($instructorBox['opens']), '"never" has no opening moment');
        $this->assertTrue(array_key_exists('exam_2', $prof['events']) && $prof['events']['exam_2']['date'] === null, 'events on no day, for instructors');
        $this->assertTrue(isset($prof['problems']));

        $preview = $client->get('calendar/php/calendar.php?as=anonymous')->json();
        $this->assertSame($this->keys($data), $this->keys($preview), 'view as anonymous');
        $past = $client->get('calendar/php/calendar.php?as=anonymous&at=' . rawurlencode('1999-06-01 10:00'))->json();
        $this->assertFalse(isset($this->keys($past)['lab_1/index.html']), 'at a moment before lab = 2000-01-01');
        $this->assertSame(400, $client->get('calendar/php/calendar.php?as=nobody')->status);
        $student = new HttpClient($this->server);
        $this->assertSame($this->keys($data), $this->keys($student->get('calendar/php/calendar.php?as=prof')->json()), 'as/at are ignored for others');
    }

    public function testNamesThatAreNotUtf8(): void
    {
        $client = $this->start(["class/01.Introduction/caf\xe9.txt" => 'x', 'config/config.ini' => str_replace('slides            = slides.pdf, slides.pptx', "slides            = slides.pdf, slides.pptx, caf\xe9.txt", (string) file_get_contents(__DIR__ . '/../../template/config/config.ini'))]);
        $response = $client->get('calendar/php/calendar.php');
        $this->assertSame(200, $response->status, 'one bad name does not take the calendar down (M-1)');
        $this->assertContains("caf\u{FFFD}.txt", $response->body);
        $this->login($client);
        $this->assertContains('not UTF-8', $client->get('calendar/php/calendar.php')->body, 'reported to instructors');
    }

    public function testPageBackupsAreNeverServed(): void
    {
        $client = $this->start(['class/01.Introduction/index.html~' => '<inst>INST-SECRET</inst><lock code="hunter2">x</lock>', 'class/01.Introduction/#reading.md#' => 'x']);
        $body = $client->get('calendar/php/calendar.php')->body;
        $this->assertNotContains('index.html~', $body, 'not listed (M-22)');
        $this->login($client);
        $this->assertContains("editor's backup of a page", $client->get('calendar/php/calendar.php')->body, 'a problem for instructors');
    }

    public function testNumericNamesDoNotTakeTheCourseDown(): void
    {
        $client = $this->start(['class/01.Introduction/1' => 'one', 'class/01.Introduction/2024.pdf' => 'pdf', 'config/paths.ini' => "[pages]\n2027 = pages/home.html\n"]);
        foreach (['config.php', 'calendar.php'] as $endpoint) {
            $this->assertSame(200, $client->get("calendar/php/$endpoint")->status, "$endpoint with numeric names (M-17)");
        }
        $keys = $this->keys($client->get('calendar/php/calendar.php')->json());
        $this->assertSame('one', $client->get('calendar/php/get.php?file=' . ($keys['class_1/1'] ?? ''))->body);
        $this->assertSame(200, $client->get('calendar/php/html.php?file=' . ($keys['page/2027'] ?? ''))->status);
    }

    public function testCalendarCacheFollowsChanges(): void
    {
        $client = $this->start();
        $first = $client->get('calendar/php/calendar.php');
        $this->assertSame($first->header('etag'), $client->get('calendar/php/calendar.php')->header('etag'), 'cached');
        sleep(1); // modification times have a resolution of one second
        file_put_contents("$this->dir/class/01.Introduction/slides.pdf", '%PDF-1.4');
        $second = $client->get('calendar/php/calendar.php');
        $this->assertTrue($second->header('etag') !== $first->header('etag'), 'a new file changes the answer');
        $this->assertContains('slides.pdf', $second->body);
    }

    public function testGet(): void
    {
        $client = $this->start(['class/01.Introduction/data.txt' => "0123456789", 'class/01.Introduction/run.php' => '<?php echo 1;', 'class/01.Introduction/page2.html' => '<p>x</p>', 'class/01.Introduction/app.js' => 'var a = 1;']);
        $keys = $this->keys($client->get('calendar/php/calendar.php')->json());
        $this->assertFalse(isset($keys['class_1/run.php']), '.php files are never listed');
        $data = $client->get('calendar/php/get.php?file=' . $keys['class_1/data.txt']);
        $this->assertSame(200, $data->status);
        $this->assertSame('0123456789', $data->body);
        $this->assertSame('text/plain; charset=utf-8', $data->header('content-type'));
        $this->assertContains('inline', $data->header('content-disposition'));
        $this->assertContains("filename=\"data.txt\"; filename*=UTF-8''data.txt", $data->header('content-disposition'));
        $range = $client->get('calendar/php/get.php?file=' . $keys['class_1/data.txt'], ['Range' => 'bytes=2-4']);
        $this->assertSame(206, $range->status);
        $this->assertSame('234', $range->body);
        $this->assertSame('bytes 2-4/10', $range->header('content-range'));
        $this->assertSame(416, $client->get('calendar/php/get.php?file=' . $keys['class_1/data.txt'], ['Range' => 'bytes=50-'])->status);
        $this->assertSame(304, $client->get('calendar/php/get.php?file=' . $keys['class_1/data.txt'], ['If-None-Match' => $data->header('etag')])->status);
        $head = $client->get('calendar/php/get.php?file=' . $keys['class_1/data.txt'], [], 'HEAD');
        $this->assertSame('10', $head->header('content-length'));
        $this->assertSame('text/javascript; charset=utf-8', $client->get('calendar/php/get.php?file=' . $keys['class_1/app.js'])->header('content-type'), 'scripts can run (M-4)');
        $this->assertContains('inline', $client->get('calendar/php/get.php?file=' . $keys['class_1/app.js'])->header('content-disposition'));
        foreach (['class_1/page2.html', 'class_1/index.html', 'class_1/reading.md'] as $page) {
            $this->assertSame(404, $client->get('calendar/php/get.php?file=' . $keys[$page])->status, "get.php never sends a page's source (C-1): $page");
        }
        $this->assertContains('attachment', $client->get('calendar/php/get.php?download=1&file=' . $keys['class_1/data.txt'])->header('content-disposition'));

        $this->login($client);
        $profKeys = $this->keys($client->get('calendar/php/calendar.php')->json());
        $hidden = $profKeys['class_1/instructor.html'];
        $student = new HttpClient($this->server);
        $notFound = $student->get('calendar/php/get.php?file=' . $hidden);
        $this->assertSame(404, $notFound->status, 'a key alone never grants access');
        $this->assertSame(404, $student->get('calendar/php/get.php?file=AAAAAAAAAAAAAAAAAAAAAA')->status);
        $this->assertSame($notFound->body, $student->get('calendar/php/get.php?file=AAAAAAAAAAAAAAAAAAAAAA')->body, 'the same answer for both');
        $this->assertSame(200, $client->get('calendar/php/get.php?file=' . $profKeys['class_1/data.txt'])->status, 'the instructor');
        $this->assertSame(404, $client->get('calendar/php/get.php?file=' . $hidden)->status, 'not even instructors get a page from get.php');
        $this->assertSame(405, $student->post('calendar/php/get.php?file=' . $hidden, [])->status);
    }

    public function testHtml(): void
    {
        $page = <<<'HTML'
<h2><replace value="ctype"> <replace value="event">: <replace value="title"></h2>
<img src="pic.png" alt="p"> <a href="reading.md#part">notes</a> <a href="instructor.html">hidden</a>
<a href="https://example.com/x">out</a> <a href="#top">top</a> <a href="missing.html">missing</a>
<inst><p>INST-ONLY</p><a href="secret.pdf">s</a></inst>
<student>ANSWER</student>
<postmeeting>AFTER-MEETING</postmeeting>
<lock code="sesame, open"><p>LOCKED-PART</p></lock>
<host server="no-such-host">HOST-ONLY</host>
<inject src="part.html">
<pre><code><codeinject src="Example.java"></code></pre>
<calphp src="script.php">
<b>Hello <url value="who" default="nobody"></b>
<ace name="x">ACE-TEXT</ace> <keyword src="x"> <showlink src="pic.png">
<script>var x = 1 < 2;</script>
<a name="Intro"></a>
HTML;
        $client = $this->start([
            'class/01.Introduction/index.html' => $page,
            'class/01.Introduction/pic.png' => 'PNG',
            'class/01.Introduction/secret.pdf' => 'PDF',
            'class/01.Introduction/part.html' => '<p>INJECTED <replace value="course"></p><lock code="open"><p>INJECTED-LOCKED</p></lock>',
            'class/01.Introduction/Example.java' => 'class A { int a = 1 < 2; }',
            'class/01.Introduction/script.php' => '<?php echo "<p>PHP-OUTPUT " . $COURSE . " <inst>kept</inst></p>";',
        ]);
        $keys = $this->keys($client->get('calendar/php/calendar.php')->json());
        $index = $keys['class_1/index.html'];
        $response = $client->get("calendar/php/html.php?file=$index&who=%3Cb%3EEve");
        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->header('content-type'));
        $body = $response->body;
        $this->assertContains('<h2>Class 1: Introduction</h2>', $body, '<replace>');
        $this->assertContains('src="/calendar/php/get.php?file=' . $keys['class_1/pic.png'] . '"', $body, 'images become keys');
        $this->assertContains('href="#/view/' . $keys['class_1/reading.md'] . '#part"', $body, 'pages become front end routes');
        $this->assertContains('data-cal6-hidden="1">hidden</a>', $body, 'links to hidden files lose their target');
        $this->assertNotContains('instructor.html', $body);
        $this->assertContains('href="https://example.com/x"', $body);
        $this->assertContains('href="#top"', $body);
        $this->assertContains('<a href="missing.html">missing</a>', $body, 'a link the author wrote to a missing file is left as written, as in v4');
        $this->assertNotContains('INST-ONLY', $body);
        $this->assertNotContains('secret.pdf', $body, 'links inside removed content');
        $this->assertContains('<details class="cal6-student"><summary>Show answer</summary>ANSWER</details>', $body);
        $this->assertContains('AFTER-MEETING', $body, 'the meeting is over');
        $this->assertNotContains('LOCKED-PART', $body);
        $this->assertContains('class="cal6-lock"', $body);
        $this->assertNotContains('sesame', $body, 'lock codes never reach the browser');
        $this->assertNotContains('HOST-ONLY', $body);
        $this->assertContains('INJECTED AB123', substr($body, 600), '<inject> is processed like the page');
        $this->assertContains('class A { int a = 1 &lt; 2; }', $body, '<codeinject> is escaped');
        $this->assertContains('<p>PHP-OUTPUT AB123 <inst>kept</inst></p>', $body, '<calphp> output is passed through as-is');
        $this->assertContains('Hello &lt;b&gt;Eve', $body, '<url> is escaped');
        $this->assertContains('<pre class="cal6-ace"><code>ACE-TEXT</code></pre>', $body, 'no Ace: the text is shown');
        $this->assertNotContains('<keyword', $body);
        $this->assertContains('<script>var x = 1 < 2;</script>', $body, 'JavaScript is passed through');
        $this->assertContains('<a id="Intro" name="Intro">', $body);
        $this->assertContains('>pic.png</a>', $body, '<showlink>');

        $this->assertSame('', $client->get("calendar/php/html.php?file=$index&who=x")->header('set-cookie'), 'viewing a page with <lock> or <url> creates no session (L-41)');
        $this->assertNotContains('Hello &lt;b&gt;Eve', $client->get("calendar/php/html.php?file=$index")->body, 'without a session, <url> values are not remembered');
        $client->get('calendar/php/config.php?csrf=1');
        $client->get("calendar/php/html.php?file=$index&who=Ann");
        $this->assertContains('Hello Ann', $client->get("calendar/php/html.php?file=$index")->body, 'in an existing session they are');
        $this->assertContains('Hello nobody', $client->get("calendar/php/html.php?file=$index&who=javascript:alert(1)")->body, 'a script URL is never used (L-39)');
        $this->assertContains('Hello 7', $client->get("calendar/php/html.php?file=$index&who=7")->body);

        preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
        $client->post("calendar/php/html.php?file=$index", ['lock' => 'wrong', 'csrf' => $m[1] ?? '']);
        $this->assertNotContains('LOCKED-PART', $client->get("calendar/php/html.php?file=$index")->body, 'a wrong code');
        $this->assertSame(403, $client->post("calendar/php/html.php?file=$index", ['lock' => 'open'])->status, 'no token');
        $unlocked = $client->post("calendar/php/html.php?file=$index", ['lock' => 'open', 'csrf' => $m[1] ?? '']);
        $this->assertSame(303, $unlocked->status, 'a redirect, so a reload does not resend (L-6)');
        $this->assertSame("/calendar/php/html.php?file=$index", $unlocked->header('location'));
        $this->assertContains('LOCKED-PART', $client->get("calendar/php/html.php?file=$index")->body, 'a shared code, remembered');

        $this->assertContains('INJECTED-LOCKED', $client->get("calendar/php/html.php?file=$index")->body, 'a lock inside an injected file can be opened too (L-4)');
        $this->assertNotContains('<!doctype', $client->get("calendar/php/html.php?file=$index&full=1")->body, 'only the page\'s contents: there is no full=1');
        $this->assertSame(404, $client->get('calendar/php/html.php?file=' . $keys['class_1/pic.png'])->status, 'only pages');
        $token = (string) $client->get('calendar/php/config.php')->json()['csrf'];
        $client->post('calendar/php/auth.php', ['csrf' => $token]);
        $this->assertNotContains('LOCKED-PART', $client->get("calendar/php/html.php?file=$index")->body, 'logging out forgets unlocked codes (L-17)');
        $this->assertContains('Hello nobody', $client->get("calendar/php/html.php?file=$index")->body, 'and <url> values');

        $this->login($client);
        $prof = $client->get("calendar/php/html.php?file=$index")->body;
        $this->assertContains('INST-ONLY', $prof, 'instructors');
        $this->assertContains('LOCKED-PART', $prof);
        $profKeys = $this->keys($client->get('calendar/php/calendar.php')->json());
        $this->assertSame(404, (new HttpClient($this->server))->get('calendar/php/html.php?file=' . $profKeys['class_1/instructor.html'])->status);
        $markdown = $client->get('calendar/php/html.php?file=' . $profKeys['class_1/reading.md'])->body;
        $this->assertContains('<h1', $markdown, 'Markdown');
    }

    public function testAuth(): void
    {
        $client = $this->start();
        $this->assertSame(405, $client->request('PUT', 'calendar/php/auth.php', '')->status);
        $anonymousOut = $client->get('calendar/php/auth.php');
        $this->assertSame([200, ['user' => null, 'instructor' => false, 'valid_until' => null, 'csrf' => null, 'error' => null], ''], [$anonymousOut->status, $anonymousOut->json(), $anonymousOut->header('set-cookie')], 'logging out with no session: nothing to do, and no session is created');
        $noToken = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD]);
        $this->assertSame(403, $noToken->status, 'no token');
        $this->assertSame(['user' => null, 'instructor' => false, 'valid_until' => null, 'csrf' => null, 'error' => 'the form has expired: reload the page'], $noToken->json(), 'every answer carries every field');
        $this->assertSame('', $noToken->header('set-cookie'), 'and no session is created (L-43)');
        $bogus = new HttpClient($this->server);
        foreach (['calendar/php/calendar.php', 'calendar/php/config.php'] as $path) {
            $answer = $bogus->get($path, ['Cookie' => 'cal6_' . substr(sha1((string) realpath($this->dir)), 0, 12) . '=bogusbogusbogusbogusbogus12']);
            $this->assertSame('', $answer->header('set-cookie'), "an unknown session id creates no session (L-72): $path");
        }
        $cross = $client->get('calendar/php/config.php?csrf=1', ['Sec-Fetch-Site' => 'cross-site']);
        $this->assertSame(null, $cross->json()['csrf'], 'a cross-site csrf=1 creates no session (L-43)');
        $this->assertSame('', $cross->header('set-cookie'));
        $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $wrong = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => 'nope', 'csrf' => $csrf]);
        $this->assertSame(401, $wrong->status);
        $this->assertSame(['user', 'instructor', 'valid_until', 'csrf', 'error'], array_keys($wrong->json()));
        $unknown = $client->post('calendar/php/auth.php', ['username' => 'nobody', 'password' => 'nope', 'csrf' => $csrf]);
        $this->assertSame($wrong->body, $unknown->body, 'the same answer for an unknown user');
        $cookieBefore = $client->cookies;
        $ok = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $csrf]);
        $this->assertSame(200, $ok->status);
        $this->assertSame('prof', $ok->json()['user'] ?? null);
        $this->assertSame(true, $ok->json()['instructor'] ?? null);
        $this->assertSame(['user', 'instructor', 'valid_until', 'csrf', 'error'], array_keys($ok->json()));
        $this->assertSame(null, $ok->json()['error'], 'error: null when all went well');
        $this->assertTrue($cookieBefore !== $client->cookies, 'a new session id at login');
        $this->assertTrue(($ok->json()['csrf'] ?? $csrf) !== $csrf, 'a new token');
        $this->assertSame('prof', $client->get('calendar/php/config.php')->json()['viewer']['user'] ?? null);
        $out = $client->get('calendar/php/auth.php');
        $this->assertSame(null, $out->json()['user'], 'logged out by a GET, with no token (Decided)');
        $this->assertSame(null, $client->get('calendar/php/config.php')->json()['viewer']['user']);
        $this->assertTrue(is_string($out->json()['csrf'] ?? null), 'the session goes on, with a new token for the next login');
        $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => (string) $out->json()['csrf']]);
        $this->assertSame('prof', $client->get('calendar/php/config.php')->json()['viewer']['user'] ?? null);
        $out = $client->post('calendar/php/auth.php', []);
        $this->assertSame(null, $out->json()['user'], 'a POST without name, password or token logs out too');
        $this->assertSame(null, $client->get('calendar/php/config.php')->json()['viewer']['user']);

        $json = $client->request('POST', 'calendar/php/auth.php', (string) json_encode(['username' => 'ta', 'password' => Fixture::PASSWORD, 'csrf' => (string) $out->json()['csrf']]), ['Content-Type' => 'application/json']);
        $this->assertSame('ta', $json->json()['user'] ?? null, 'JSON bodies work too');

        $attacker = new HttpClient($this->server);
        $token = (string) $attacker->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        for ($i = 0; $i < 5; $i++) {
            $attacker->post('calendar/php/auth.php', ['username' => 'prof', 'password' => "bad$i", 'csrf' => $token]);
        }
        $limited = $attacker->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $token]);
        $this->assertSame(429, $limited->status, 'rate limited');
        $this->assertTrue((int) $limited->header('retry-after') > 0);
    }

    public function testUnknownUsersTakeAsLongAsTheCostliestAccount(): void
    {
        $hash = password_hash('slow-password', PASSWORD_BCRYPT, ['cost' => 12]);
        $client = $this->start(['config/auth/slow.ini' => "password_hash = $hash\nvalid = always\n"]);
        $time = function (string $user) use ($client): float {
            $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
            $start = microtime(true);
            $client->post('calendar/php/auth.php', ['username' => $user, 'password' => 'wrong', 'csrf' => $csrf]);
            return microtime(true) - $start;
        };
        $known = min($time('slow'), $time('slow'));
        $unknown = max($time('nosuchuser'), $time('nosuch2'));
        $this->assertTrue($unknown > $known * 0.4, sprintf('an unknown user costs about as much as the costliest account (L-63): %.3f s vs %.3f s', $unknown, $known));
    }

    public function testLockCodesInsideHiddenBlocksAreNotAccepted(): void
    {
        $client = $this->start([
            'class/01.Introduction/index.html' => '<lock code="open1">A</lock><inst><lock code="hidden1">B</lock></inst>',
        ]);
        $key = $client->get('calendar/php/calendar.php')->json()['events']['class_1']['boxes'][0]['key'] ?? '';
        preg_match('/name="csrf" value="([^"]+)"/', $client->get("calendar/php/html.php?file=$key")->body, $t);
        $hidden = $client->post("calendar/php/html.php?file=$key", ['lock' => 'hidden1', 'csrf' => $t[1] ?? '']);
        $this->assertSame('', $hidden->header('set-cookie'), 'a code inside <inst> is not accepted (L-91)');
        $open = $client->post("calendar/php/html.php?file=$key", ['lock' => 'open1', 'csrf' => $t[1] ?? '']);
        $this->assertContains('cal6_', $open->header('set-cookie'), 'a reachable code is');
    }

    public function testAChangedPasswordEndsSessions(): void
    {
        $client = $this->start();
        $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $this->assertSame(200, $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $csrf])->status);
        $this->assertSame('prof', $client->get('calendar/php/calendar.php')->json()['viewer']['user'] ?? null);
        $file = "$this->dir/config/auth/prof.ini";
        file_put_contents($file, str_replace('password_hash = ', 'password_hash = ' . password_hash('new', PASSWORD_DEFAULT) . "\n;old = ", (string) file_get_contents($file)));
        $this->assertSame(null, $client->get('calendar/php/calendar.php')->json()['viewer']['user'] ?? null, 'a new password ends the session (L-100)');
    }

    public function testNestedLocksOpenInTurn(): void
    {
        $client = $this->start(['class/01.Introduction/index.html' => '<lock code="outer">OUTER-OPEN <lock code="inner">INNER-OPEN</lock></lock>']);
        $key = $client->get('calendar/php/calendar.php')->json()['events']['class_1']['boxes'][0]['key'] ?? '';
        $token = static fn (string $body): string => preg_match('/name="csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
        $t = $token($client->get("calendar/php/html.php?file=$key")->body);
        $client->post("calendar/php/html.php?file=$key", ['lock' => 'outer', 'csrf' => $t]);
        $page = $client->get("calendar/php/html.php?file=$key")->body;
        $this->assertContains('OUTER-OPEN', $page);
        $this->assertNotContains('INNER-OPEN', $page);
        $client->post("calendar/php/html.php?file=$key", ['lock' => 'inner', 'csrf' => $token($page)]);
        $this->assertContains('INNER-OPEN', $client->get("calendar/php/html.php?file=$key")->body, 'the inner lock opens once the outer one has (M-49)');
    }

    public function testOpensOnlyForLoggedOnViewers(): void
    {
        $hash = password_hash(Fixture::PASSWORD, PASSWORD_DEFAULT);
        $client = $this->start([
            'config/default.ini' => "class = always\nclass/slides = 2099-01-01 08:00\nclass/instructor = never\npage/policy = 2099-01-01 08:00\n",
            'class/01.Introduction/slides.pdf' => 'PDF',
            'config/auth/ta.ini' => "password_hash = $hash\nvalid = always\n[grants]\nclass_1/slides.pdf = always\n",
        ]);
        $anon = $client->get('calendar/php/calendar.php');
        $this->assertNotContains('slides.pdf', $anon->body, 'anonymous viewers do not see it');
        $this->assertSame(['title' => 'Syllabus'], $anon->json()['pages']['policy'] ?? null, 'a hidden page: its title only');
        $this->assertNotContains('"opens"', $anon->body, 'and get no hint of when it opens');

        $this->login($client, 'ta');
        $ta = $client->get('calendar/php/calendar.php')->json()['events']['class_1'] ?? [];
        $slides = array_values(array_filter($ta['boxes'] ?? [], static fn (array $b): bool => $b['filename'] === 'slides.pdf'))[0] ?? [];
        $this->assertSame(['box' => 'slides', 'filename' => 'slides.pdf', 'key' => $slides['key'] ?? '', 'kind' => 'get', 'visible' => false, 'opens' => '2099-01-01T08:00:00-05:00'], $slides, 'a granted user learns when students will see it');
        $this->assertSame('2099-01-01T08:00:00-05:00', $ta['opens'] ?? null, 'and the event says its earliest opening');
        $index = array_values(array_filter($ta['boxes'], static fn (array $b): bool => $b['filename'] === 'index.html'))[0] ?? [];
        $this->assertFalse(isset($index['opens']) || isset($index['visible']), 'nothing extra on what students already see');
        $this->assertSame(['title' => 'Syllabus'], $client->get('calendar/php/calendar.php')->json()['pages']['policy'] ?? null, 'a page hidden from this user too: its title only');

        $this->login($client);
        $prof = $client->get('calendar/php/calendar.php')->json()['events']['class_1'] ?? [];
        $instructor = array_values(array_filter($prof['boxes'], static fn (array $b): bool => $b['box'] === 'instructor'))[0] ?? [];
        $this->assertSame(false, $instructor['visible'] ?? null, 'instructors see what is hidden');
        $this->assertFalse(isset($instructor['opens']), '"never" has no opening');
        $policy = $client->get('calendar/php/calendar.php')->json()['pages']['policy'] ?? [];
        $this->assertSame([false, '2099-01-01T08:00:00-05:00'], [$policy['visible'] ?? null, $policy['opens'] ?? null], 'pages carry the same hints');
    }

    public function testSectionTimes(): void
    {
        $client = $this->start(['config/dates.ini' => "2026-08-28 = lab | lab day\n"]);
        $plain = $client->get('calendar/php/calendar.php')->json();
        $this->assertFalse(isset($plain['section']) || isset($plain['events']['class_1']['start']), 'no times without a section');

        $data = $client->get('calendar/php/calendar.php?section=1121')->json();
        $this->assertSame('1121', $data['section'] ?? null);
        $one = $data['events']['class_1'] ?? [];
        $this->assertSame(['start' => '07:45', 'end' => '08:35', 'period' => '1', 'instructor' => 'SMITH', 'room' => 'HP111'],
            array_intersect_key($one, array_flip(['start', 'end', 'period', 'instructor', 'room'])), 'Monday: MF1');
        $two = $data['events']['class_2'] ?? [];
        $this->assertSame(['07:45', '09:35', '12'], [$two['start'] ?? null, $two['end'] ?? null, $two['period'] ?? null], 'Tuesday: T12');
        $this->assertFalse(isset($data['events']['lab_1']['start']), 'the section does not meet for labs');

        $posted = $client->post('calendar/php/calendar.php', ['section' => '4345'])->json();
        $this->assertSame(['10:45', '11:35', 'HP132', 'JONES'], [$posted['events']['class_1']['start'] ?? null, $posted['events']['class_1']['end'] ?? null, $posted['events']['class_1']['room'] ?? null, $posted['events']['class_1']['instructor'] ?? null], 'section by POST');
        $this->assertSame(404, $client->get('calendar/php/calendar.php?section=9999')->status, 'an unknown section');

        $day = $data['days']['2026-08-28'] ?? [];
        $this->assertSame(['event' => '', 'title' => '', 'label' => 'lab day'], $day[0] ?? null, 'a day given another type: its label, no weekday or holiday');
        $this->assertSame('lab', $day[1]['type'] ?? null);
        $this->assertSame(1, preg_match('/^lab_\d+$/', $day[1]['event'] ?? ''));
        $this->assertFalse(isset($day[1]['start']), 'and no times: section 1121 meets for no lab');
        $this->assertSame(['event' => 'class_1', 'type' => 'class', 'number' => 1, 'title' => 'Introduction', 'start' => '07:45', 'end' => '08:35', 'period' => '1', 'instructor' => 'SMITH', 'room' => 'HP111'], $data['days']['2026-08-24'][0] ?? null, 'a day\'s items carry the section\'s times');
        $this->assertSame('1121', $one['section'] ?? null, 'and so does the event, with the section');
        $this->assertSame([], $data['days']['2026-08-27'] ?? null, 'a day with nothing on it');
        $this->assertSame(117, count($data['days']), 'every day of the semester');
        $this->assertSame('2026-12-18', array_key_last($data['days']));
    }

    public function testNotesComeBackAsText(): void
    {
        $client = $this->start([
            'config/default.ini' => "class = always\nclass/notes = always\nclass_2/notes = never\n",
            'class/01.Introduction/notes.txt' => "  The class meets in the auditorium today.\n",
            'class/02.Second/notes.txt' => 'Hidden remark',
        ]);
        $events = $client->get('calendar/php/calendar.php')->json()['events'];
        $this->assertSame([['filename' => 'notes.txt', 'text' => 'The class meets in the auditorium today.']], $events['class_1']['notes'] ?? null, 'notes are text, not keys');
        $this->assertSame([], array_filter($events['class_1']['boxes'], static fn (array $b): bool => $b['filename'] === 'notes.txt'), 'and never as a file');
        $this->assertSame(false, isset($events['class_2']['notes']), 'released by rules like a box: class_2/notes = never hides them');
        $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $csrf]);
        $note = $client->get('calendar/php/calendar.php')->json()['events']['class_2']['notes'][0] ?? [];
        $this->assertSame(false, $note['visible'] ?? null, 'an instructor sees a hidden note, marked');
        $this->assertSame('Hidden remark', $note['text'] ?? null);
        $boxes = array_column($client->get('calendar/php/config.php')->json()['boxes'] ?? [], 'box');
        $this->assertSame(false, in_array('notes', $boxes, true), 'notes are not a box in config.php');
    }

    public function testNotesAsWrittenAndNotABox(): void
    {
        $config = str_replace("[boxes]\n", "[boxes]\nnotes = notes.md | Lecture notes\n", (string) file_get_contents(__DIR__ . '/../../template/config/config.ini'));
        $long = "\n\n\n" . str_repeat('é', 1500) . "\n";
        $client = $this->start([
            'config/config.ini' => $config,
            'config/default.ini' => "class = always\nlab = always\nlab/notes = -0\n",
            'class/01.Introduction/notes.txt' => null,
            'class/01.Introduction/notes.html' => '<p>Bring a <b>laptop</b>.</p>',
            'class/02.Second/notes.md' => $long,
            'lab/01.Setup/notes.txt' => 'Lab remark',
        ]);
        $events = $client->get('calendar/php/calendar.php')->json()['events'];
        $this->assertSame([['filename' => 'notes.html', 'text' => '<p>Bring a <b>laptop</b>.</p>']], $events['class_1']['notes'] ?? null, 'any file [notes] names, as written, HTML too (Decided, H-7)');
        $text = $events['class_2']['notes'][0]['text'] ?? '';
        $this->assertTrue(strlen($text) <= 2000 && str_ends_with($text, '…') && str_starts_with($text, 'é'), 'at most 2000 bytes, cut with "…", leading whitespace trimmed (L-128)');
        $this->assertSame([], array_filter($events['class_2']['boxes'], static fn (array $b): bool => $b['box'] === 'notes'), '"notes" in [boxes] is ignored (H-7)');

        $this->login($client);
        $data = $client->get('calendar/php/calendar.php')->json();
        $problems = implode("\n", array_column($data['problems'] ?? [], 'message'));
        $this->assertContains('[boxes] notes', $problems, 'and listed as a problem');
        $before = $client->get('calendar/php/calendar.php?as=anonymous&at=' . rawurlencode('2026-08-25 23:59'))->json();
        $after = $client->get('calendar/php/calendar.php?as=anonymous&at=' . rawurlencode('2026-08-26 00:00'))->json();
        $this->assertFalse(isset($before['events']['lab_1']['notes']), 'lab/notes = -0: not before the lab\'s day');
        $this->assertSame('Lab remark', $after['events']['lab_1']['notes'][0]['text'] ?? null, 'and from its start');
    }

    public function testFilesNamedLikeABoxAreReported(): void
    {
        $client = $this->start(['lab/02.Two/lab-solution.pdf' => 'PDF', 'class/01.Introduction/index.md' => '# also']);
        $this->login($client);
        $problems = implode("\n", array_column($client->get('calendar/php/calendar.php')->json()['problems'] ?? [], 'message'));
        $this->assertContains("lab_2/lab-solution.pdf is named like box lab-solution", $problems, 'L-131');
        $this->assertContains("class_1/index.md is named like box title", $problems, 'a second candidate when the first is there');
        $this->assertNotContains('lab_2/lab-solution.html is named', $problems, 'not the box file itself');
    }

    public function testDayMarkers(): void
    {
        $client = $this->start(['config/dates.ini' => "2026-08-27 = lab\n2026-08-31 = none\n"]);
        $days = $client->get('calendar/php/calendar.php')->json()['days'];
        $this->assertSame([['event' => '', 'title' => 'none']], $days['2026-08-31'] ?? null, 'a "none" day is marked (L-130)');
        $this->assertSame(['event' => '', 'title' => ''], $days['2026-08-27'][0] ?? null, 'a type-only line without a label is marked');
        $this->assertSame('lab', $days['2026-08-27'][1]['type'] ?? null);
        $config = $client->get('calendar/php/config.php')->json()['days'];
        $this->assertSame(['date' => '2026-08-31', 'events' => [], 'as' => 'none'], array_values(array_filter($config, static fn (array $d): bool => $d['date'] === '2026-08-31'))[0] ?? null, 'config.php too');
    }

    public function testCancelledSectionMeetings(): void
    {
        $client = $this->start(['config/dates.ini' => "2026-08-24 = period 4_hour_delay | late start\n2026-08-28 10:00 until 2026-08-28 12:00 = holiday | assembly\n"]);
        $early = $client->get('calendar/php/calendar.php?section=1121')->json();
        $one = $early['events']['class_1'] ?? [];
        $this->assertSame(['period' => '1', 'instructor' => 'SMITH', 'room' => 'HP111', 'cancelled' => true, 'section' => '1121'],
            array_intersect_key($one, array_flip(['start', 'end', 'period', 'instructor', 'room', 'section', 'cancelled'])), 'period 1 is cancelled on a 4-hour delay: no times, "cancelled" (L-129)');
        $this->assertSame(true, $early['days']['2026-08-24'][1]['cancelled'] ?? null, 'on the day\'s item too');
        $this->assertFalse(isset($early['events']['class_3']['cancelled']), '1121 meets at 07:45, before the assembly');
        $late = $client->get('calendar/php/calendar.php?section=4345')->json()['events']['class_3'] ?? [];
        $this->assertSame(['10:45', '11:35', true], [$late['start'] ?? null, $late['end'] ?? null, $late['cancelled'] ?? null], '4345 starts inside the assembly: its times, cancelled');
    }

    public function testConfigDuplicatesAndCancelWindows(): void
    {
        $client = $this->start([
            'config/duplicate.ini' => "class_2 = class_1 same\n",
            'config/dates.ini' => "2026-09-18 10:00 until 2026-09-18 12:00 = holiday | assembly\n2026-09-21 13:00 until 2026-09-22 09:00 = none\n",
        ]);
        $data = $client->get('calendar/php/config.php')->json();
        $this->assertSame([['event' => 'class_1@2026-08-25', 'copy_of' => 'class_1', 'mode' => 'same']], $data['duplicates'] ?? null, 'duplicate.ini as the calendar applied it');
        $days = array_column($data['days'], null, 'date');
        $this->assertSame(['date' => '2026-09-18', 'cancel' => [['from' => '10:00', 'until' => '12:00', 'as' => 'holiday']], 'label' => 'assembly'], $days['2026-09-18'] ?? null, 'a window with times');
        $this->assertSame([['from' => '13:00', 'until' => '24:00', 'as' => 'none']], $days['2026-09-21']['cancel'] ?? null, 'a window over two days, per day');
        $this->assertSame([['from' => '00:00', 'until' => '09:00', 'as' => 'none']], $days['2026-09-22']['cancel'] ?? null);
    }

    public function testParametersByGetOrPost(): void
    {
        $client = $this->start();
        $this->assertSame('07:45', $client->post('calendar/php/calendar.php?section=', ['section' => '1121'])->json()['events']['class_1']['start'] ?? null, 'an empty query value does not hide a posted one (DOC-22)');
        $this->assertSame('10:45', $client->post('calendar/php/calendar.php?section=4345', ['section' => '1121'])->json()['events']['class_1']['start'] ?? null, 'a non-empty query value comes first');
        $this->assertSame(400, $client->get('calendar/php/calendar.php?section[]=1121')->status, 'a list is an error');
        $this->login($client);
        $csrf = (string) ($client->get('calendar/php/config.php?csrf=1')->json()['csrf'] ?? '');
        $posted = $client->post('calendar/php/calendar.php', ['as' => 'anonymous', 'at' => '1999-06-01 10:00', 'csrf' => $csrf])->json();
        $this->assertSame(['user' => null, 'instructor' => false], $posted['viewer'] ?? null, 'as and at by POST too');
        $this->assertFalse(isset($this->keys($posted)['lab_1/index.html']), 'at a moment before lab = 2000-01-01');
    }

    public function testSectionAndPlainAnswersAreCachedApart(): void
    {
        $client = $this->start();
        $this->assertSame('07:45', $client->get('calendar/php/calendar.php?section=1121')->json()['events']['class_1']['start'] ?? null);
        $plain = $client->get('calendar/php/calendar.php')->json();
        $this->assertFalse(isset($plain['section']) || isset($plain['events']['class_1']['start']) || isset($plain['days']['2026-08-24'][0]['start']), 'section first, then the plain answer: no times');
        $this->assertSame('10:45', $client->get('calendar/php/calendar.php?section=4345')->json()['events']['class_1']['start'] ?? null, 'nor another section\'s');
    }

    public function testLoggedOnUserWithoutGrantsGetsNoHints(): void
    {
        $hash = password_hash(Fixture::PASSWORD, PASSWORD_DEFAULT);
        $client = $this->start(['config/auth/student.ini' => "password_hash = $hash\nvalid = always\n", 'config/default.ini' => "class = always\nclass/slides = 2099-01-01 08:00\n", 'class/01.Introduction/slides.pdf' => 'PDF']);
        $this->login($client, 'student');
        $data = $client->get('calendar/php/calendar.php');
        $this->assertSame('student', $data->json()['viewer']['user'] ?? null);
        $this->assertNotContains('slides.pdf', $data->body, 'what students may not see is left out');
        $this->assertNotContains('"opens"', $data->body, 'with no hint: a hint is only on what this viewer may see');
        $this->assertNotContains('"visible"', $data->body);
    }

    public function testRateLimitsAndProxies(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $client = $this->start(['config/config.ini' => $config . "\n[server]\ntrusted_proxies = 127.0.0.1\n"]);
        $from = fn (string $ip): array => ['X-Forwarded-For' => "203.0.113.9, $ip"];
        $attacker = new HttpClient($this->server);
        $token = (string) $attacker->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $attacker->post('calendar/php/auth.php', ['username' => 'prof', 'password' => "bad$i", 'csrf' => $token], $from('198.51.100.1'))->status);
        }
        $this->assertSame(429, $attacker->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $token], $from('198.51.100.1'))->status, 'that address and username');
        $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $this->assertSame(200, $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $csrf], $from('198.51.100.2'))->status, 'the instructor is not locked out from elsewhere (M-7)');
        $this->assertSame(401, $attacker->post('calendar/php/auth.php', ['username' => 'ta', 'password' => 'bad', 'csrf' => $token], $from('198.51.100.1'))->status, 'another username from the same address is still allowed');
    }

    public function testLoginFailsClosedWithoutRateLimiter(): void
    {
        $client = $this->start();
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $state = sys_get_temp_dir() . "/cal6-$uid-" . substr(sha1((string) realpath($this->dir)), 0, 16);
        @mkdir($state, 0700);
        chmod($state, 0777); // as if someone else had made it: not ours to trust
        $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $response = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $csrf]);
        $this->assertSame(503, $response->status, 'no limiter, no login (H-3)');
        chmod($state, 0700);
        $this->ignoreServerErrors = true;
    }

    public function testSameCopiesNestedFilesAndLockLimit(): void
    {
        $client = $this->start([
            'config/duplicate.ini' => "class_2 = class_1 same\n",
            'class/01.Introduction/img/pic.png' => 'PNG',
            'class/01.Introduction/index.html' => '<img src="img/pic.png"><lock code="sesame">LOCKED</lock>',
        ]);
        $data = $client->get('calendar/php/calendar.php')->json();
        $this->assertSame(['class_1@2026-08-25'], array_column($data['days']['2026-08-25'] ?? [], 'event'), 'a "same" repeat on day two (T-4)');
        $repeat = $data['events']['class_1@2026-08-25'] ?? [];
        $this->assertSame(1, $repeat['number'] ?? null);
        $this->assertSame('same', $repeat['mode'] ?? null);
        $first = $data['events']['class_1']['boxes'][0]['key'];
        $second = $repeat['boxes'][0]['key'] ?? '';
        $this->assertTrue($first !== $second && $second !== '', 'its own keys');
        $page = $client->get("calendar/php/html.php?file=$second")->body;
        $this->assertSame(1, preg_match('/get\.php\?file=([A-Za-z0-9_-]{22})/', $page, $m), 'a nested file gets a key');
        $this->assertSame('PNG', $client->get('calendar/php/get.php?file=' . ($m[1] ?? ''))->body);
        $this->assertNotContains('pic.png', json_encode($data['events']['class_1']), 'only box files are listed (no files list)');

        preg_match('/name="csrf" value="([^"]+)"/', $client->get("calendar/php/html.php?file=$first")->body, $t);
        $cross = $client->post("calendar/php/html.php?file=$first", ['lock' => 'sesame', 'csrf' => $t[1] ?? ''], ['Sec-Fetch-Site' => 'cross-site']);
        $this->assertSame(403, $cross->status, 'a lock code from another site is refused (L-50)');
        $this->assertSame('', $cross->header('set-cookie'), 'and creates no session');
        $this->assertSame(403, $client->post("calendar/php/html.php?file=$first", ['lock' => 'sesame', 'csrf' => $t[1] ?? ''], ['Origin' => 'https://evil.example'])->status, 'also by Origin');
        $wrong = $client->post("calendar/php/html.php?file=$first", ['lock' => 'wrong', 'csrf' => $t[1] ?? ''], ['Origin' => rtrim($this->server->url(''), '/')]);
        $this->assertSame(303, $wrong->status);
        $this->assertNotContains('cal6_', $wrong->header("set-cookie"), 'a wrong code creates no session (L-43)');
        for ($i = 0; $i < 18; $i++) {
            $this->assertSame(303, $client->post("calendar/php/html.php?file=$first", ['lock' => "wrong$i", 'csrf' => $t[1] ?? ''])->status);
        }
        $right = $client->post("calendar/php/html.php?file=$first", ['lock' => 'sesame', 'csrf' => $t[1] ?? '']);
        $this->assertSame(303, $right->status, 'twenty tries per address and page; a right code does not count (M-31)');
        $this->assertContains('cal6_', $right->header("set-cookie"), 'and makes the session');
        $this->assertSame(303, $client->post("calendar/php/html.php?file=$first", ['lock' => 'last', 'csrf' => $t[1] ?? ''])->status);
        $limited = $client->post("calendar/php/html.php?file=$first", ['lock' => 'sesame', 'csrf' => $t[1] ?? '']);
        $this->assertSame(429, $limited->status, 'then lock codes are limited per address and page');
    }

    public function testConfigShapeAndMarkdown(): void
    {
        $client = $this->start([
            'config/dates.ini' => (string) file_get_contents(__DIR__ . '/../../template/config/dates.ini'), // the template's own
            'class/01.Introduction/reading.md' => "# Notes\n\n## First part\n\nText with **bold** and [index](index.html).\n",
        ]);
        $data = $client->get('calendar/php/config.php')->json();
        $this->assertSame(['number' => 'AB123', 'name' => 'Web and Internet Programming', 'timezone' => 'America/New_York'], $data['course'] ?? null);
        $this->assertSame(['start' => '2026-08-24', 'end' => '2026-12-18', 'weekends' => false], $data['semester'] ?? null);
        $this->assertSame(['mon' => 'class', 'tue' => 'class', 'wed' => 'lab', 'fri' => 'class'], $data['schedule'] ?? null);
        $this->assertSame([
            ['date' => '2026-09-07', 'events' => [], 'as' => 'holiday', 'label' => 'Labor Day'],
            ['date' => '2026-10-12', 'events' => [], 'as' => 'holiday', 'label' => 'Columbus Day'],
            ['date' => '2026-10-13', 'events' => ['class'], 'as' => 'mon', 'label' => 'Monday schedule'],
            ['date' => '2026-10-14', 'events' => ['lab'], 'label' => 'Lab day'],
            ['date' => '2026-11-11', 'events' => [], 'as' => 'holiday', 'label' => 'Veterans Day'],
            ['date' => '2026-11-25', 'events' => [], 'as' => 'holiday', 'label' => 'Thanksgiving'],
            ['date' => '2026-11-26', 'events' => [], 'as' => 'holiday', 'label' => 'Thanksgiving'],
            ['date' => '2026-11-27', 'events' => [], 'as' => 'holiday', 'label' => 'Thanksgiving'],
        ], $data['days'] ?? null, 'the days of dates.ini (T-6)');
        $days = $client->get('calendar/php/calendar.php')->json()['days'] ?? [];
        $this->assertSame([['event' => '', 'title' => 'holiday', 'label' => 'Labor Day']], $days['2026-09-07'] ?? null, 'calendar.php: a holiday is an item with no event');
        $this->assertSame(['event' => '', 'title' => 'mon', 'label' => 'Monday schedule'], $days['2026-10-13'][0] ?? null, 'another weekday');
        $this->assertSame('normal', $data['period'] ?? null);
        $this->assertSame(['label' => '2 Hour Delay', 'times' => ['1' => '09:45-10:35', '2' => '10:45-11:35', '3' => '12:45-13:35', '4' => '13:45-14:35', '5' => '14:45-15:35', '6' => '15:45-16:35', '7' => '16:45-17:35', '8' => '09:45-11:00', '9' => '12:45-14:00', '10' => '14:45-16:00']], $data['periods']['2_hour_delay'] ?? null);
        $this->assertSame([
            ['section' => '1121', 'meetings' => [['meets' => 'MF1', 'type' => 'class', 'room' => 'HP111'], ['meets' => 'T12', 'type' => 'class', 'room' => 'HP111']], 'instructor' => 'SMITH'],
            ['section' => '4345', 'meetings' => [['meets' => 'MF4', 'type' => 'class', 'room' => 'HP132'], ['meets' => 'R34', 'type' => 'class', 'room' => 'HP301']], 'instructor' => 'JONES'],
            ['section' => '6541', 'meetings' => [['meets' => 'MF6', 'type' => 'class', 'room' => 'HP139'], ['meets' => 'R56', 'type' => 'class', 'room' => 'HP139']], 'instructor' => 'JONES'],
        ], $data['sections'] ?? null);
        $this->assertSame(['class', 'lab', 'project', 'exam'], array_keys($data['types'] ?? []));
        $this->assertSame(['box' => 'title', 'label' => 'Lecture'], $data['boxes'][0] ?? null);
        $this->assertFalse(isset($data['replace']), '[replace] values are not sent');
        $this->assertSame([['page' => 'home', 'label' => 'Home'], ['page' => 'resources', 'label' => 'Resources'], ['page' => 'policy', 'label' => 'Syllabus']], $data['pages'] ?? null, 'paths.ini pages, in order');
        $this->assertSame(8 * 3600, $data['session_seconds'] ?? null);
        $this->assertSame(1, $data['version']['format'] ?? null);
        $this->assertSame(1, preg_match('/^[0-9a-f]{12}$/', $data['version']['software'] ?? ''));
        $this->assertSame([], $data['duplicates'] ?? null);

        $keys = $this->keys($client->get('calendar/php/calendar.php')->json());
        $markdown = $client->get('calendar/php/html.php?file=' . $keys['class_1/reading.md'])->body;
        $this->assertContains('<h1>Notes</h1>', $markdown, 'Markdown');
        $this->assertContains('<h2 id="first-part">First part</h2>', $markdown, 'level-2 headings get ids for the menu');
        $this->assertContains('<strong>bold</strong>', $markdown);
        $this->assertContains('href="#/view/' . $keys['class_1/index.html'] . '"', $markdown, 'Markdown links become keys');
    }

    public function testProxyHeadersOnlyFromTrustedProxies(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $client = $this->start(['config/config.ini' => $config . "\n[server]\ntrusted_proxies = 127.0.0.1\n"]);
        $secure = $client->get('calendar/php/config.php?csrf=1', ['X-Forwarded-Proto' => 'https']);
        $this->assertContains('secure', strtolower($secure->header('set-cookie')), 'Secure behind a trusted proxy that says https (T-8)');
        $this->server?->stop();
        Fixture::remove($this->dir);

        $client = $this->start();
        $plain = $client->get('calendar/php/config.php?csrf=1', ['X-Forwarded-Proto' => 'https']);
        $this->assertNotContains('secure', strtolower($plain->header('set-cookie')), 'not from anyone else');
        $token = (string) $plain->json()['csrf'];
        for ($i = 0; $i < 5; $i++) {
            $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => "bad$i", 'csrf' => $token], ['X-Forwarded-For' => "198.51.100.$i"]);
        }
        $this->assertSame(429, $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $token], ['X-Forwarded-For' => '198.51.100.99'])->status, 'a forged X-Forwarded-For does not get around the limit');
    }

    public function testCourseMustBeReachedThroughCalendarLink(): void
    {
        $client = $this->start();
        rename("$this->dir/calendar", "$this->dir/software");
        $response = $client->get('software/php/config.php');
        $this->assertSame(500, $response->status);
        $this->assertContains('course_directory/calendar', $response->body);
        rename("$this->dir/software", "$this->dir/calendar");
        $config = (string) file_get_contents("$this->dir/config/config.ini");
        file_put_contents("$this->dir/config/config.ini", (string) preg_replace('/^secret\s*=.*$/m', 'secret = REPLACE-WITH-64-HEX-DIGITS', $config));
        $this->assertContains('secret must be set', $client->get('calendar/php/calendar.php')->body, 'no keys without a secret');
    }
}
