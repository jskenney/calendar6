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
        return new self($dir, CourseConfig::load($dir), $_SERVER, $_GET, $post, time());
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

    public function course(): Course
    {
        return $this->course ??= Course::load($this->courseDir, $this->config);
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
