<?php

declare(strict_types=1);

namespace Cal6\Http;

/**
 * The PHP session of one course: its own cookie name and path, HttpOnly,
 * SameSite=Strict, Secure on HTTPS.  Endpoints that only read open it only when
 * the browser already has the cookie, and close it at once (no locking).
 */
final class Session
{
    private const KEY = 'cal6';

    private static bool $open = false;

    public static function start(Context $c, bool $create): bool
    {
        if (self::$open) {
            return true;
        }
        $name = 'cal6_' . substr(sha1((string) realpath($c->courseDir)), 0, 12);
        if (!$create && !isset($_COOKIE[$name])) {
            return false;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            self::bind($c->courseDir);
            return self::$open = true;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        // a login lasts [users] session; PHP's own cleanup must not end it sooner, and
        // the course keeps its sessions in its private directory when it can (DOC-4)
        ini_set('session.gc_maxlifetime', (string) max(1440, $c->config->sessionSeconds));
        $path = TempDir::for($c->courseDir, 'sessions');
        if ($path !== null) {
            session_save_path($path);
            // a read-only request opens only a session that exists: an unknown or expired id
            // is not replaced by a new one (strict mode would make one, L-72)
            $id = (string) ($_COOKIE[$name] ?? '');
            if (!$create && (!preg_match('/^[A-Za-z0-9,-]{22,256}$/', $id) || !is_file("$path/sess_$id"))) {
                return false;
            }
            if ($create && random_int(1, 50) === 1) {
                self::sweep($path, max(1440, $c->config->sessionSeconds));
            }
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Locator::coursePath($c->server),
            'secure' => $c->https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        self::$open = session_start();
        if (self::$open) {
            self::bind($c->courseDir);
        }
        return self::$open;
    }

    private const MAX_FILES = 20000;

    /**
     * Removes session files older than the session lifetime, and the oldest beyond MAX_FILES,
     * because PHP's own cleanup is often switched off (Debian: gc_probability = 0) and the
     * system's cleans only php.ini's directory (L-72).
     */
    public static function sweep(string $path, int $lifetime): void
    {
        clearstatcache();
        $files = [];
        $now = time();
        foreach (glob($path . '/sess_*') ?: [] as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime < $now - $lifetime) {
                @unlink($file);
            } else {
                $files[$file] = $mtime;
            }
        }
        if (count($files) > self::MAX_FILES) {
            // the oldest first, but never a session that holds a login: anyone can create
            // anonymous sessions (config.php?csrf=1), and must not push real logins out (L-157)
            asort($files);
            $excess = count($files) - self::MAX_FILES;
            foreach (array_keys($files) as $file) {
                if ($excess <= 0) {
                    break;
                }
                if (str_contains((string) @file_get_contents($file, false, null, 0, 65536), '"user";s:')) {
                    continue;
                }
                @unlink($file);
                $excess--;
            }
        }
    }

    /**
     * A session belongs to one course: it records the real path of the course directory,
     * and a session that names another course is emptied, not trusted.  The cookie name,
     * cookie path and storage are per course already; this also covers a misconfigured
     * shared session directory or a name collision (former M-16, L-25).
     */
    private static function bind(string $courseDir): void
    {
        $course = (string) realpath($courseDir);
        if (isset($_SESSION[self::KEY]['course']) && $_SESSION[self::KEY]['course'] !== $course) {
            $_SESSION[self::KEY] = [];
        }
        $_SESSION[self::KEY]['course'] = $course;
    }

    public static function isOpen(): bool
    {
        return self::$open;
    }

    public static function https(array $server): bool
    {
        return (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
            || ($server['SERVER_PORT'] ?? '') === '443';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::KEY][$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[self::KEY][$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[self::KEY][$key]);
    }

    public static function clear(): void
    {
        $_SESSION[self::KEY] = ['course' => $_SESSION[self::KEY]['course'] ?? ''];
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function close(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function csrf(): string
    {
        $token = self::get('csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(24));
            self::set('csrf', $token);
        }
        return $token;
    }

    public static function checkCsrf(?string $token): bool
    {
        $expected = self::get('csrf');
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }
}
