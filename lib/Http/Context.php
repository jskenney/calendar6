<?php

declare(strict_types=1);

namespace Cal6\Http;

use Cal6\Access\Viewer;
use Cal6\Course\Course;
use Cal6\Course\CourseConfig;

/** One request: the course, the viewer, the clock. */
final class Context
{
    private ?Course $course = null;
    private ?Viewer $viewer = null;
    private ?string $fingerprint = null;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function __construct(
        public readonly string $courseDir,
        public readonly CourseConfig $config,
        public readonly array $server,
        public readonly array $query,
        public readonly array $post,
        public readonly int $now,
    ) {
    }

    public static function fromGlobals(): self
    {
        $dir = Locator::courseDir($_SERVER);
        $post = $_POST;
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with(strtolower($type), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
            // lists are kept, so an endpoint can refuse them (a list is a 400 in calendar.php,
            // DOC-33); post() reads only single values
            $post = is_array($decoded) ? array_filter($decoded, static fn ($v): bool => is_scalar($v) || is_array($v)) : [];
        }
        return new self($dir, CourseConfig::load($dir), $_SERVER, $_GET, $post, self::clock());
    }

    /**
     * The time.  Tests running PHP's built-in server may set it through the file named
     * in the environment variable CAL6_TEST_CLOCK (T-3); no other server can.
     */
    private static function clock(): int
    {
        $file = getenv('CAL6_TEST_CLOCK');
        if (PHP_SAPI === 'cli-server' && is_string($file) && $file !== '' && is_file($file)) {
            $time = trim((string) file_get_contents($file));
            if (preg_match('/^\d+$/', $time)) {
                return (int) $time;
            }
        }
        return time();
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function post(string $name): ?string
    {
        $value = $this->post[$name] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    /** The course model: from the model cache while the course is unchanged, else built (and cached). */
    public function course(): Course
    {
        if ($this->course === null) {
            $cache = ModelCache::forCourse($this->courseDir, $this->config->secret);
            $this->course = $cache->get($this->fingerprint());
            if ($this->course === null) {
                $this->course = Course::load($this->courseDir, $this->config);
                $cache->put($this->fingerprint(), $this->course);
            }
        }
        return $this->course;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint ??= Fingerprint::key($this->courseDir);
    }

    /** The logged-in account (read from config/auth/ now), or anonymous. */
    /** What a session remembers of the password it logged in with (a hash of its hash). */
    public static function secretMark(string $passwordHash): string
    {
        return hash('sha256', 'cal6-session|' . $passwordHash);
    }

    public function viewer(bool $createSession = false): Viewer
    {
        if ($this->viewer !== null) {
            return $this->viewer;
        }
        $viewer = Viewer::anonymous();
        if (Session::start($this, $createSession)) {
            $user = Session::get('user');
            $login = (int) Session::get('login', 0);
            if (is_string($user)) {
                $account = Viewer::account($this->config, $user);
                // a changed password ends every session of that account (L-100)
                $sameSecret = is_string(Session::get('secret')) && $account !== null
                    && hash_equals(self::secretMark($account->passwordHash), (string) Session::get('secret'));
                if ($account !== null && $sameSecret && $login + $this->config->sessionSeconds > $this->now && $account->isValidAt($this->now, $this->config->zone)) {
                    $viewer = $account;
                } else {
                    Session::forget('user');
                    Session::forget('login');
                    Session::forget('secret');
                }
            }
        }
        return $this->viewer = $viewer;
    }

    /**
     * The client's address.  Behind a reverse proxy listed in [server] trusted_proxies,
     * the right-most address in X-Forwarded-For that is not a trusted proxy.
     */
    public function address(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        if (!$this->fromTrustedProxy()) {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($hops as $hop) {
            // a port is dropped (203.0.113.7:5555, [2001:db8::1]:443); a hop that is still not an
            // address (unknown, a name) ends the walk: what lies left of it was written by the
            // client, so it is never used (L-93)
            if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d+)?$/', $hop, $m) || preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $m)) {
                $hop = $m[1];
            }
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!self::isTrusted($hop, $this->config->trustedProxies)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** HTTPS, also when a trusted proxy terminates TLS and says so in X-Forwarded-Proto. */
    public function https(): bool
    {
        return Session::https($this->server)
            || ($this->fromTrustedProxy() && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }

    private function fromTrustedProxy(): bool
    {
        return self::isTrusted((string) ($this->server['REMOTE_ADDR'] ?? ''), $this->config->trustedProxies);
    }

    /** An IPv4-mapped IPv6 address (::ffff:10.0.0.5) as its IPv4 address, in binary (L-81). */
    private static function unmapped(string|false $packed): string|false
    {
        if ($packed !== false && strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }
        return $packed;
    }

    /** An IP address, or a CIDR range (10.0.0.0/8, 2001:db8::/32). */
    public static function validProxy(string $proxy): bool
    {
        [$address, $bits] = array_pad(explode('/', $proxy, 2), 2, null);
        $packed = @inet_pton((string) $address);
        if ($packed === false) {
            return false;
        }
        return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($packed) * 8);
    }

    /**
     * Whether $address is one of $proxies (addresses compared in binary, so any spelling of an
     * IPv6 address matches, and CIDR ranges work, L-76).
     *
     * @param list<string> $proxies
     */
    public static function isTrusted(string $address, array $proxies): bool
    {
        $packed = self::unmapped(@inet_pton($address));
        if ($packed === false) {
            return false;
        }
        foreach ($proxies as $proxy) {
            [$network, $bits] = array_pad(explode('/', $proxy, 2), 2, null);
            $raw = @inet_pton((string) $network);
            $net = self::unmapped($raw);
            if ($net === false || strlen($net) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($net) * 8 : max(0, (int) $bits - ($raw !== $net ? 96 : 0));
            $bytes = intdiv($bits, 8);
            if (substr($net, 0, $bytes) !== substr($packed, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($net[$bytes]) ^ ord($packed[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    public function iso(?int $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->config->zone)->format(DATE_ATOM);
    }
}
