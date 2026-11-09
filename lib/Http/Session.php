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

    public static function start(string $courseDir, array $server, bool $create): bool
    {
        if (self::$open) {
            return true;
        }
        $name = 'cal6_' . substr(sha1((string) realpath($courseDir)), 0, 12);
        if (!$create && !isset($_COOKIE[$name])) {
            return false;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            return self::$open = true;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Locator::coursePath($server),
            'secure' => self::https($server),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        self::$open = session_start();
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
