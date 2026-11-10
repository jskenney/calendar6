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

    /** @return array<string, string> "event/name" => key */
    private static function keys(array $calendar): array
    {
        $keys = [];
        foreach ($calendar['events'] ?? [] as $id => $event) {
            foreach ($event['boxes'] as $box) {
                foreach ($box['files'] as $file) {
                    $keys["$id/$file[name]"] = $file['key'];
                }
            }
            foreach ($event['files'] as $file) {
                $keys["$id/$file[name]"] = $file['key'];
            }
        }
        foreach ($calendar['pages'] ?? [] as $page) {
            $keys["page/$page[name]"] = $page['key'];
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
        $keys = self::keys($data);
        $this->assertTrue(isset($keys['class_1/index.html']));
        $this->assertFalse(isset($keys['class_1/instructor.html']), 'hidden files are left out');
        $this->assertFalse(isset($keys['lab_1/lab-solution.html']));
        $this->assertFalse(isset($data['events']['exam_2']), 'events on no day are left out');
        $this->assertSame('', $data['events']['class_3']['title'] ?? 'x', 'no title when nothing is visible');
        $this->assertSame(['class_1'], $data['days'][0]['events'] ?? null);
        $this->assertSame(filesize("$this->dir/class/01.Introduction/index.html"), $data['events']['class_1']['boxes'][0]['files'][0]['size'] ?? null, 'files carry their size (DOC-12)');
        $this->assertSame(['home', 'policy'], array_column($data['pages'], 'name'));
        $this->assertFalse(isset($data['problems']), 'problems are for instructors');
        $this->assertNotContains('instructor.html', $anon->body);
        $this->assertNotContains('Midterm', $anon->body, 'the titles of hidden events');

        $again = $client->get('calendar/php/calendar.php', ['If-None-Match' => $anon->header('etag')]);
        $this->assertSame(304, $again->status, 'unchanged: 304');
        $this->assertSame('private, no-cache', $anon->header('cache-control'));

        $this->login($client);
        $prof = $client->get('calendar/php/calendar.php')->json();
        $profKeys = self::keys($prof);
        $this->assertTrue(isset($profKeys['class_1/instructor.html']), 'instructors see everything');
        $this->assertSame(true, $prof['viewer']['instructor'] ?? null);
        $hidden = $prof['events']['class_1']['boxes'];
        $instructorBox = array_values(array_filter($hidden, static fn (array $b): bool => $b['box'] === 'instructor'))[0]['files'][0] ?? [];
        $this->assertSame(false, $instructorBox['visible'] ?? null, 'marked hidden for anonymous viewers');
        $this->assertSame('class/instructor = never', $instructorBox['rule'] ?? null);
        $this->assertTrue(array_key_exists('exam_2', $prof['events']) && $prof['events']['exam_2']['date'] === null, 'events on no day, for instructors');
        $this->assertTrue(isset($prof['problems']));

        $preview = $client->get('calendar/php/calendar.php?as=anonymous')->json();
        $this->assertSame(self::keys($data), self::keys($preview), 'view as anonymous');
        $past = $client->get('calendar/php/calendar.php?as=anonymous&at=' . rawurlencode('1999-06-01 10:00'))->json();
        $this->assertFalse(isset(self::keys($past)['lab_1/index.html']), 'at a moment before lab = 2000-01-01');
        $this->assertSame(400, $client->get('calendar/php/calendar.php?as=nobody')->status);
        $student = new HttpClient($this->server);
        $this->assertSame(self::keys($data), self::keys($student->get('calendar/php/calendar.php?as=prof')->json()), 'as/at are ignored for others');
    }

    public function testNamesThatAreNotUtf8(): void
    {
        $client = $this->start(["class/01.Introduction/caf\xe9.txt" => 'x']);
        $response = $client->get('calendar/php/calendar.php');
        $this->assertSame(200, $response->status, 'one bad name does not take the calendar down (M-1)');
        $this->assertContains("caf\u{FFFD}.txt", $response->body);
        $this->login($client);
        $this->assertContains('not UTF-8', $client->get('calendar/php/calendar.php')->body, 'reported to instructors');
    }

    public function testPageBackupsAreNeverServed(): void
    {
        $client = $this->start(['class/01.Introduction/index.html~' => '<inst>INST-SECRET</inst><lock code="hunter2">x</lock>', 'class/01.Introduction/#notes.md#' => 'x']);
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
        $keys = self::keys($client->get('calendar/php/calendar.php')->json());
        $this->assertSame('one', $client->get('calendar/php/get.php?file=' . ($keys['class_1/1'] ?? ''))->body);
        $this->assertSame(200, $client->get('calendar/php/html.php?file=' . ($keys['page/2027'] ?? ''))->status);
    }

    public function testCalendarCacheFollowsChanges(): void
    {
        $client = $this->start();
        $first = $client->get('calendar/php/calendar.php');
        $this->assertSame($first->header('etag'), $client->get('calendar/php/calendar.php')->header('etag'), 'cached');
        sleep(1); // modification times have a resolution of one second
        file_put_contents("$this->dir/class/01.Introduction/extra.pdf", '%PDF-1.4');
        $second = $client->get('calendar/php/calendar.php');
        $this->assertTrue($second->header('etag') !== $first->header('etag'), 'a new file changes the answer');
        $this->assertContains('extra.pdf', $second->body);
    }

    public function testGet(): void
    {
        $client = $this->start(['class/01.Introduction/data.txt' => "0123456789", 'class/01.Introduction/run.php' => '<?php echo 1;', 'class/01.Introduction/page2.html' => '<p>x</p>', 'class/01.Introduction/app.js' => 'var a = 1;']);
        $keys = self::keys($client->get('calendar/php/calendar.php')->json());
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
        foreach (['class_1/page2.html', 'class_1/index.html', 'class_1/notes.md'] as $page) {
            $this->assertSame(404, $client->get('calendar/php/get.php?file=' . $keys[$page])->status, "get.php never sends a page's source (C-1): $page");
        }
        $this->assertContains('attachment', $client->get('calendar/php/get.php?download=1&file=' . $keys['class_1/data.txt'])->header('content-disposition'));

        $this->login($client);
        $profKeys = self::keys($client->get('calendar/php/calendar.php')->json());
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
<img src="pic.png" alt="p"> <a href="notes.md#part">notes</a> <a href="instructor.html">hidden</a>
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
        $keys = self::keys($client->get('calendar/php/calendar.php')->json());
        $index = $keys['class_1/index.html'];
        $response = $client->get("calendar/php/html.php?file=$index&who=%3Cb%3EEve");
        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->header('content-type'));
        $body = $response->body;
        $this->assertContains('<h2>Class 1: Introduction</h2>', $body, '<replace>');
        $this->assertContains('src="/calendar/php/get.php?file=' . $keys['class_1/pic.png'] . '"', $body, 'images become keys');
        $this->assertContains('href="#/view/' . $keys['class_1/notes.md'] . '#part"', $body, 'pages become front end routes');
        $this->assertContains('data-cal6-hidden="1">hidden</a>', $body, 'links to hidden files lose their target');
        $this->assertNotContains('instructor.html', $body);
        $this->assertContains('href="https://example.com/x"', $body);
        $this->assertContains('href="#top"', $body);
        $this->assertContains('<a data-cal6-hidden="1">missing</a>', $body, 'a link to nothing looks like a hidden one (L-51)');
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
        $full = $client->get("calendar/php/html.php?file=$index&full=1")->body;
        $this->assertContains('<!doctype html>', $full);
        $this->assertContains('href="/calendar/php/html.php?file=' . $keys['class_1/notes.md'] . '&amp;full=1#part"', $full);
        $this->assertSame(404, $client->get('calendar/php/html.php?file=' . $keys['class_1/pic.png'])->status, 'only pages');
        $token = (string) $client->get('calendar/php/config.php')->json()['csrf'];
        $client->post('calendar/php/auth.php', ['csrf' => $token]);
        $this->assertNotContains('LOCKED-PART', $client->get("calendar/php/html.php?file=$index")->body, 'logging out forgets unlocked codes (L-17)');
        $this->assertContains('Hello nobody', $client->get("calendar/php/html.php?file=$index")->body, 'and <url> values');

        $this->login($client);
        $prof = $client->get("calendar/php/html.php?file=$index")->body;
        $this->assertContains('INST-ONLY', $prof, 'instructors');
        $this->assertContains('LOCKED-PART', $prof);
        $profKeys = self::keys($client->get('calendar/php/calendar.php')->json());
        $this->assertSame(404, (new HttpClient($this->server))->get('calendar/php/html.php?file=' . $profKeys['class_1/instructor.html'])->status);
        $markdown = $client->get('calendar/php/html.php?file=' . $profKeys['class_1/notes.md'])->body;
        $this->assertContains('<h1', $markdown, 'Markdown');
    }

    public function testAuth(): void
    {
        $client = $this->start();
        $this->assertSame(405, $client->get('calendar/php/auth.php')->status);
        $noToken = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD]);
        $this->assertSame(403, $noToken->status, 'no token');
        $this->assertSame('', $noToken->header('set-cookie'), 'and no session is created (L-43)');
        $cross = $client->get('calendar/php/config.php?csrf=1', ['Sec-Fetch-Site' => 'cross-site']);
        $this->assertSame(null, $cross->json()['csrf'], 'a cross-site csrf=1 creates no session (L-43)');
        $this->assertSame('', $cross->header('set-cookie'));
        $csrf = (string) $client->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $wrong = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => 'nope', 'csrf' => $csrf]);
        $this->assertSame(401, $wrong->status);
        $unknown = $client->post('calendar/php/auth.php', ['username' => 'nobody', 'password' => 'nope', 'csrf' => $csrf]);
        $this->assertSame($wrong->body, $unknown->body, 'the same answer for an unknown user');
        $cookieBefore = $client->cookies;
        $ok = $client->post('calendar/php/auth.php', ['username' => 'prof', 'password' => Fixture::PASSWORD, 'csrf' => $csrf]);
        $this->assertSame(200, $ok->status);
        $this->assertSame('prof', $ok->json()['user'] ?? null);
        $this->assertSame(true, $ok->json()['instructor'] ?? null);
        $this->assertTrue($cookieBefore !== $client->cookies, 'a new session id at login');
        $this->assertTrue(($ok->json()['csrf'] ?? $csrf) !== $csrf, 'a new token');
        $this->assertSame('prof', $client->get('calendar/php/config.php')->json()['viewer']['user'] ?? null);
        $out = $client->post('calendar/php/auth.php', ['csrf' => (string) $ok->json()['csrf']]);
        $this->assertSame(null, $out->json()['user'], 'logged out');
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
        $this->assertSame(['class_1@2026-08-25'], $data['days'][1]['events'] ?? null, 'a "same" repeat on day two (T-4)');
        $repeat = $data['events']['class_1@2026-08-25'] ?? [];
        $this->assertSame(1, $repeat['number'] ?? null);
        $this->assertSame('same', $repeat['mode'] ?? null);
        $first = $data['events']['class_1']['boxes'][0]['files'][0]['key'];
        $second = $repeat['boxes'][0]['files'][0]['key'] ?? '';
        $this->assertTrue($first !== $second && $second !== '', 'its own keys');
        $page = $client->get("calendar/php/html.php?file=$second")->body;
        $this->assertSame(1, preg_match('/get\.php\?file=([A-Za-z0-9_-]{22})/', $page, $m), 'a nested file gets a key');
        $this->assertSame('PNG', $client->get('calendar/php/get.php?file=' . ($m[1] ?? ''))->body);
        $this->assertNotContains('img/pic.png', json_encode($data['events']['class_1']['files']), 'nested files are not listed');

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
            'class/01.Introduction/notes.md' => "# Notes\n\n## First part\n\nText with **bold** and [index](index.html).\n",
        ]);
        $data = $client->get('calendar/php/config.php')->json();
        $this->assertSame(['number' => 'AB123', 'name' => 'Web and Internet Programming', 'timezone' => 'America/New_York'], $data['course'] ?? null);
        $this->assertSame(['start' => '2026-08-24', 'end' => '2026-12-18', 'weekends' => false], $data['semester'] ?? null);
        $this->assertSame(['mon' => 'class', 'tue' => 'class', 'wed' => 'lab', 'fri' => 'class'], $data['schedule'] ?? null);
        $this->assertSame([
            ['date' => '2026-09-07', 'events' => [], 'kind' => 'holiday', 'label' => 'Labor Day'],
            ['date' => '2026-10-12', 'events' => [], 'kind' => 'holiday', 'label' => 'Columbus Day'],
            ['date' => '2026-10-13', 'events' => ['class'], 'as' => 'mon', 'label' => 'Monday schedule'],
            ['date' => '2026-10-14', 'events' => ['lab'], 'label' => 'Lab day'],
            ['date' => '2026-11-11', 'events' => [], 'kind' => 'holiday', 'label' => 'Veterans Day'],
            ['date' => '2026-11-25', 'events' => [], 'kind' => 'holiday', 'label' => 'Thanksgiving'],
            ['date' => '2026-11-26', 'events' => [], 'kind' => 'holiday', 'label' => 'Thanksgiving'],
            ['date' => '2026-11-27', 'events' => [], 'kind' => 'holiday', 'label' => 'Thanksgiving'],
        ], $data['days'] ?? null, 'the days of dates.ini (T-6)');
        $this->assertSame('normal', $data['period'] ?? null);
        $this->assertSame(['label' => '2 Hour Delay', 'times' => ['1' => '09:45-10:35', '2' => '10:45-11:35', '3' => '12:45-13:35', '4' => '13:45-14:35', '5' => '14:45-15:35', '6' => '15:45-16:35', '7' => '16:45-17:35', '8' => '09:45-11:00', '9' => '12:45-14:00', '10' => '14:45-16:00']], $data['periods']['2_hour_delay'] ?? null);
        $this->assertSame([
            ['section' => '1121', 'meetings' => [['meets' => 'MF1', 'type' => 'class', 'room' => 'HP111'], ['meets' => 'T12', 'type' => 'class', 'room' => 'HP111']], 'instructor' => 'SMITH'],
            ['section' => '4345', 'meetings' => [['meets' => 'MF4', 'type' => 'class', 'room' => 'HP132'], ['meets' => 'R34', 'type' => 'class', 'room' => 'HP301']], 'instructor' => 'JONES'],
            ['section' => '6541', 'meetings' => [['meets' => 'MF6', 'type' => 'class', 'room' => 'HP139'], ['meets' => 'R56', 'type' => 'class', 'room' => 'HP139']], 'instructor' => 'JONES'],
        ], $data['sections'] ?? null);
        $this->assertSame(['class', 'lab', 'project', 'exam'], array_keys($data['types'] ?? []));
        $this->assertSame(['box' => 'title', 'label' => 'Lecture'], $data['boxes'][0] ?? null);
        $this->assertSame(['classyear' => '27', 'office' => 'Michelson 342'], $data['replace'] ?? null);

        $keys = self::keys($client->get('calendar/php/calendar.php')->json());
        $markdown = $client->get('calendar/php/html.php?file=' . $keys['class_1/notes.md'])->body;
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
