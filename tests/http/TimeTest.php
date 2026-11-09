<?php

declare(strict_types=1);

namespace Cal6\Tests\Http;

use Cal6\Tests\Fixture;
use Cal6\Tests\HttpClient;
use Cal6\Tests\Server;
use Cal6\Tests\TestCase;

/** Time-dependent behaviour end to end, with the server's clock moved by the test (T-3). */
final class TimeTest extends TestCase
{
    private string $dir = '';
    private string $clock = '';
    private ?Server $server = null;

    /** @param array<string, string|null> $files */
    private function start(array $files, string $at): HttpClient
    {
        $this->dir = Fixture::course($files);
        $this->clock = (string) tempnam(sys_get_temp_dir(), 'cal6-clock-');
        $this->at($at);
        $this->server = new Server($this->dir, $this->clock);
        return new HttpClient($this->server);
    }

    private function at(string $time): void
    {
        file_put_contents($this->clock, (string) (new \DateTimeImmutable($time, new \DateTimeZone('America/New_York')))->getTimestamp());
    }

    public function tearDown(): void
    {
        if ($this->server !== null) {
            $this->assertSame([], $this->server->phpErrors(), 'no PHP errors on the server');
            $this->server->stop();
        }
        @unlink($this->clock);
        Fixture::remove($this->dir);
    }

    private static function hasFile(array $calendar, string $event, string $name): bool
    {
        foreach ($calendar['events'][$event]['boxes'] ?? [] as $box) {
            foreach ($box['files'] as $file) {
                if ($file['name'] === $name) {
                    return true;
                }
            }
        }
        return false;
    }

    public function testCachedAnswerChangesAtNextChange(): void
    {
        $client = $this->start(['config/default.ini' => "lab/lab-solution = 2026-09-02 17:00\n"], '2026-09-02 16:59');
        $before = $client->get('calendar/php/calendar.php')->json();
        $this->assertFalse(self::hasFile($before, 'lab_1', 'lab-solution.html'), 'not yet');
        $this->assertSame('2026-09-02T17:00:00-04:00', $before['next_change'] ?? null);
        $this->assertFalse(self::hasFile($client->get('calendar/php/calendar.php')->json(), 'lab_1', 'lab-solution.html'), 'cached');
        $this->at('2026-09-02 17:00');
        $this->assertTrue(self::hasFile($client->get('calendar/php/calendar.php')->json(), 'lab_1', 'lab-solution.html'), 'the cached answer expires at next_change');
    }

    public function testPostmeetingOpensWhenTheLastSectionEnds(): void
    {
        $client = $this->start([
            'class/01.Introduction/index.html' => '<p>BEFORE</p><postmeeting>AFTER-MEETING</postmeeting>',
        ], '2026-08-24 15:29');
        $key = (string) ($client->get('calendar/php/calendar.php')->json()['events']['class_1']['boxes'][0]['files'][0]['key'] ?? '');
        $this->assertNotContains('AFTER-MEETING', $client->get("calendar/php/html.php?file=$key")->body, 'section 6541 meets until 15:30');
        $this->at('2026-08-24 15:30');
        $this->assertContains('AFTER-MEETING', $client->get("calendar/php/html.php?file=$key")->body, 'shown once every section has met');
    }

    public function testSessionsAndAccountWindowsEnd(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $hash = password_hash(Fixture::PASSWORD, PASSWORD_DEFAULT);
        $client = $this->start([
            'config/config.ini' => str_replace('session        = 8h', 'session        = 1h', $config),
            'config/auth/short.ini' => "password_hash = $hash\nvalid = 2026-09-10 until 2026-09-10 12:00\n",
        ], '2026-09-10 10:00');
        $login = function (HttpClient $c, string $user): void {
            $csrf = (string) $c->get('calendar/php/config.php?csrf=1')->json()['csrf'];
            $this->assertSame(200, $c->post('calendar/php/auth.php', ['username' => $user, 'password' => Fixture::PASSWORD, 'csrf' => $csrf])->status, "login as $user");
        };
        $login($client, 'prof');
        $this->at('2026-09-10 10:59');
        $this->assertSame('prof', $client->get('calendar/php/config.php')->json()['viewer']['user']);
        $this->at('2026-09-10 11:00');
        $this->assertSame(null, $client->get('calendar/php/config.php')->json()['viewer']['user'], '[users] session = 1h');

        $other = new HttpClient($this->server);
        $login($other, 'short');
        $this->at('2026-09-10 11:59');
        $this->assertSame('short', $other->get('calendar/php/calendar.php')->json()['viewer']['user']);
        $this->at('2026-09-10 12:00');
        $this->assertSame(null, $other->get('calendar/php/calendar.php')->json()['viewer']['user'], 'the account window ends the login');
        $csrf = (string) $other->get('calendar/php/config.php?csrf=1')->json()['csrf'];
        $this->assertSame(401, $other->post('calendar/php/auth.php', ['username' => 'short', 'password' => Fixture::PASSWORD, 'csrf' => $csrf])->status, 'and no new login');
    }
}
