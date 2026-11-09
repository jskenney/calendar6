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
            $post = is_array($decoded) ? array_filter($decoded, 'is_scalar') : [];
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
                if ($account !== null && $login + $this->config->sessionSeconds > $this->now && $account->isValidAt($this->now, $this->config->zone)) {
                    $viewer = $account;
                } else {
                    Session::forget('user');
                    Session::forget('login');
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
            if (filter_var($hop, FILTER_VALIDATE_IP) !== false && !in_array($hop, $this->config->trustedProxies, true)) {
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
        return in_array((string) ($this->server['REMOTE_ADDR'] ?? ''), $this->config->trustedProxies, true);
    }

    public function iso(?int $timestamp): ?string
    {
        return $timestamp === null ? null : (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->config->zone)->format(DATE_ATOM);
    }
}
