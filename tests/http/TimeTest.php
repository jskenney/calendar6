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
            if ($box['filename'] === $name) {
                return true;
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
        $key = (string) ($client->get('calendar/php/calendar.php')->json()['events']['class_1']['boxes'][0]['key'] ?? '');
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

    public function testGuessesFromManyAddressesAreGatedButTheInstructorGetsIn(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $config = str_replace('login_attempts = 5 per 15m', 'login_attempts = 1 per 15m', $config) . "\n[server]\ntrusted_proxies = 127.0.0.1\n";
        $prof = $this->start(['config/config.ini' => $config], '2026-10-01 10:00');
        $post = function (HttpClient $c, string $user, string $password, string $from): int {
            $csrf = (string) $c->get('calendar/php/config.php?csrf=1')->json()['csrf'];
            return $c->post('calendar/php/auth.php', ['username' => $user, 'password' => $password, 'csrf' => $csrf], ['X-Forwarded-For' => $from])->status;
        };
        $this->assertSame(200, $post($prof, 'prof', Fixture::PASSWORD, '198.51.100.7'), 'an earlier login gives this browser a device cookie');
        $token = (string) $prof->get('calendar/php/config.php')->json()['csrf'];
        $prof->post('calendar/php/auth.php', ['csrf' => $token]);

        $attacker = new HttpClient($this->server);
        for ($i = 0; $i < 10; $i++) { // fills the per-username bucket (10 x 1), one address each
            $this->assertSame(401, $post($attacker, 'prof', "bad$i", "203.0.113.$i"));
        }
        $this->assertSame(401, $post($attacker, 'prof', 'bad-a', '203.0.113.50'), 'once full, one attempt passes this second');
        $this->assertSame(429, $post($attacker, 'prof', 'bad-b', '203.0.113.51'), 'and the next waits: parallel guesses do not get through (M-18)');
        $this->assertSame(429, $post($attacker, 'prof', 'bad-c', '2001:db8::1'), 'IPv6 too');
        $this->assertSame(200, $post($prof, 'prof', Fixture::PASSWORD, '198.51.100.8'), 'a browser that logged in before is not gated: no lockout');
        $token = (string) $prof->get('calendar/php/config.php')->json()['csrf'];
        $prof->post('calendar/php/auth.php', ['csrf' => $token]);
        for ($i = 0; $i < 4; $i++) { // a shared address (campus NAT) fills its per-address bucket (4 x 1)
            $post($attacker, "user$i", 'bad', '203.0.113.99');
        }
        $this->assertSame(429, $post($attacker, 'other', 'bad', '203.0.113.99'), 'the address is blocked');
        $this->assertSame(200, $post($prof, 'prof', Fixture::PASSWORD, '203.0.113.99'), 'but not for a browser that logged in before (M-31)');
        $this->at('2026-10-01 10:00:01');
        $this->assertSame(401, $post($attacker, 'prof', 'bad-d', '203.0.113.52'), 'a second later, one more attempt');
    }

    public function testAPasswordChangeEndsTheDeviceCookie(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $config = str_replace('login_attempts = 5 per 15m', 'login_attempts = 1 per 15m', $config) . "\n[server]\ntrusted_proxies = 127.0.0.1\n";
        $prof = $this->start(['config/config.ini' => $config], '2026-10-01 10:00');
        $post = function (HttpClient $c, string $user, string $password, string $from): int {
            $csrf = (string) $c->get('calendar/php/config.php?csrf=1')->json()['csrf'];
            return $c->post('calendar/php/auth.php', ['username' => $user, 'password' => $password, 'csrf' => $csrf], ['X-Forwarded-For' => $from])->status;
        };
        $this->assertSame(200, $post($prof, 'prof', Fixture::PASSWORD, '198.51.100.7'), 'a device cookie is set');
        $file = "$this->dir/config/auth/prof.ini";
        file_put_contents($file, preg_replace('/^password_hash = .*$/m', 'password_hash = ' . password_hash('new-password', PASSWORD_DEFAULT), (string) file_get_contents($file)));
        $attacker = new HttpClient($this->server);
        for ($i = 0; $i < 11; $i++) {
            $post($attacker, 'prof', "bad$i", "203.0.113.$i");
        }
        $this->assertSame(429, $post($prof, 'prof', 'old-guess', '198.51.100.9'), 'the old device cookie no longer skips the gate after a password change (L-126)');
    }

    public function testIpv6AddressesCountPerNetwork(): void
    {
        $this->assertSame(\Cal6\Http\RateLimiter::addressKey('2001:db8:1:2::1'), \Cal6\Http\RateLimiter::addressKey('2001:db8:1:2:ffff::9'), 'one /64 (M-18)');
        $this->assertTrue(\Cal6\Http\RateLimiter::addressKey('2001:db8:1:2::1') !== \Cal6\Http\RateLimiter::addressKey('2001:db8:1:3::1'));
        $this->assertSame('203.0.113.5', \Cal6\Http\RateLimiter::addressKey('203.0.113.5'));
        $this->assertSame('1.2.3.4', \Cal6\Http\RateLimiter::addressKey('::ffff:1.2.3.4'), 'an IPv4-mapped address is its IPv4 address (M-26)');
        $this->assertTrue(\Cal6\Http\RateLimiter::addressKey('::ffff:1.2.3.4') !== \Cal6\Http\RateLimiter::addressKey('::ffff:9.8.7.6'));
    }
}
