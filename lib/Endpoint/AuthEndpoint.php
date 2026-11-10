<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Viewer;
use Cal6\Http\Context;
use Cal6\Http\RateLimiter;
use Cal6\Http\Response;
use Cal6\Http\Session;

/**
 * auth.php (DESIGN.md): POST username + password + csrf logs in; a GET, or a POST
 * without username and password, logs out (no token needed).  The same answer for a wrong password, an unknown user and an
 * account outside its window.
 */
final class AuthEndpoint
{
    private const DEVICE_DAYS = 180;

    private static function deviceCookie(Context $c): string
    {
        return 'cal6_device_' . substr(sha1((string) realpath($c->courseDir)), 0, 12);
    }

    /** Whether this browser carries a device cookie from an earlier login as $user. */
    private static function knownDevice(Context $c, string $user): bool
    {
        $value = $_COOKIE[self::deviceCookie($c)] ?? '';
        if (!is_string($value) || substr_count($value, '.') !== 2) {
            return false;
        }
        [$who, $nonce, $mac] = explode('.', $value);
        return hash_equals(hash_hmac('sha256', "device|$who|$nonce|" . self::passwordMark($c, $user), $c->config->secret), $mac)
            && hash_equals(bin2hex($user), $who);
    }

    /**
     * The device cookie is bound to the account's password: a reset after a leak ends the
     * cookies made with the old one too (L-126).
     */
    private static function passwordMark(Context $c, string $user): string
    {
        $account = Viewer::account($c->config, $user);
        return Context::secretMark($account?->passwordHash ?? '');
    }

    /** After a successful login: a signed cookie that lets this browser past the per-user gate. */
    private static function rememberDevice(Context $c, string $user): void
    {
        $who = bin2hex($user);
        $nonce = bin2hex(random_bytes(12));
        $value = "$who.$nonce." . hash_hmac('sha256', "device|$who|$nonce|" . self::passwordMark($c, $user), $c->config->secret);
        if (!headers_sent()) {
            setcookie(self::deviceCookie($c), $value, [
                'expires' => $c->now + self::DEVICE_DAYS * 86400,
                'path' => \Cal6\Http\Locator::coursePath($c->server),
                'secure' => $c->https(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
    }

    /** The most expensive password hash among the course's accounts, or null. */
    private static function costliestHash(Context $c): ?string
    {
        $best = null;
        $bestCost = -1;
        foreach (glob($c->config->configDir() . '/auth/*.ini') ?: [] as $file) {
            if (!preg_match('/^\s*password_hash\s*=\s*(\$\S+)/mi', (string) @file_get_contents($file), $m)) {
                continue;
            }
            $options = password_get_info($m[1])['options'] ?? [];
            $cost = isset($options['cost']) ? 2 ** (int) $options['cost'] : (int) ($options['memory_cost'] ?? 0) * (int) ($options['time_cost'] ?? 1);
            if ($cost > $bestCost) {
                [$best, $bestCost] = [$m[1], $cost];
            }
        }
        return $best;
    }

    /**
     * Every answer carries every field (Decided): user, instructor, valid_until, csrf and error,
     * null or false when they do not apply.
     *
     * @param array{user?: ?string, instructor?: bool, valid_until?: ?string, csrf?: ?string, error?: ?string} $fields
     * @param list<string> $headers
     */
    private static function answer(array $fields, int $status = 200, array $headers = []): void
    {
        foreach ($headers as $header) {
            header($header);
        }
        Response::json(array_replace(['user' => null, 'instructor' => false, 'valid_until' => null, 'csrf' => null, 'error' => null], $fields), $status);
    }

    public static function handle(Context $c): void
    {
        $method = $c->method();
        if ($method !== 'GET' && $method !== 'POST') {
            self::answer(['error' => 'GET (log out) or POST (log in or out) only'], 405, ['Allow: GET, POST']);
            return;
        }
        $username = $method === 'POST' ? trim((string) $c->post('username')) : '';
        $password = $method === 'POST' ? (string) $c->post('password') : '';

        if ($username === '' && $password === '') {
            // logging out is easy (Decided): a GET, or a POST without a name and password, and no
            // token. It touches only a session the browser already has, and clears all of it:
            // unlocked <lock> codes and <url> values too (L-17)
            $csrf = null;
            if (Session::start($c, false)) {
                Session::clear();
                Session::regenerate();
                $csrf = Session::csrf();
            }
            Session::close();
            self::answer(['csrf' => $csrf]);
            return;
        }

        // logging in needs the token: only an existing session (made by config.php?csrf=1) can
        // carry a valid one, so a cross-site POST creates none (L-43)
        Session::start($c, false);
        if (!Session::isOpen() || !Session::checkCsrf($c->post('csrf'))) {
            Session::close();
            self::answer(['error' => 'the form has expired: reload the page'], 403);
            return;
        }

        // Limits (M-7, L-23, M-18, M-31): per address and username the configured number,
        // and per address four times that, block (IPv6 counted per /64). Once a username has
        // ten times that many failures from anywhere, its attempts pass one per second
        // (the others get 429 at once, nobody sleeps). A browser that has logged in as that
        // user before (a signed device cookie) skips the per-address and per-user limits and
        // keeps only its own address+username one, so guesses from many addresses, or from
        // a shared address (campus NAT), neither get far nor lock the real instructor out.
        [$limit, $seconds] = $c->config->loginAttempts;
        $limiter = RateLimiter::forCourse($c->courseDir, $seconds);
        if (!$limiter->available()) {
            error_log('calendar6: logins are refused: the private temporary directory can not be used (' . sys_get_temp_dir() . ')');
            Session::close();
            self::answer(['error' => 'Logging in is not possible right now: the server can not keep track of failed attempts.'], 503);
            return;
        }
        $user = strtolower($username);
        $address = RateLimiter::addressKey($c->address());
        $known = self::knownDevice($c, $user);
        $keys = ['pair:' . $address . '|' . $user => $limit] + ($known ? [] : ['address:' . $address => $limit * 4]);
        $recorded = [];
        foreach ($keys as $key => $max) {
            $wait = $limiter->hit($key, $c->now, $max);
            if ($wait > 0) {
                foreach ($recorded as $done) {
                    $limiter->forgive($done);
                }
                Session::close();
                self::answer(['error' => 'too many failed logins: try again later'], 429, ['Retry-After: ' . $wait]);
                return;
            }
            $recorded[] = $key;
        }
        if ($known) {
            // a browser this user logged in from: only its own pair limit
        } elseif ($limiter->hit('user:' . $user, $c->now, $limit * 10) === 0) {
            $recorded[] = 'user:' . $user;
        } else {
            $wait = $limiter->gate('user:' . $user, $c->now, 1);
            if ($wait > 0) {
                foreach ($recorded as $done) {
                    $limiter->forgive($done);
                }
                Session::close();
                self::answer(['error' => 'too many failed logins for this user: try again in a moment'], 429, ['Retry-After: ' . $wait]);
                return;
            }
        }

        $account = Viewer::account($c->config, $username);
        $hash = $account?->passwordHash ?? '';
        $usable = $account !== null && str_starts_with($hash, '$');
        if ($usable) {
            $ok = password_verify($password, $hash) && $account->isValidAt($c->now, $c->config->zone);
        } else {
            // no such account: spend the time the costliest real check takes (L-7, L-63), so the
            // answer's timing does not tell which usernames exist
            $dummy = self::costliestHash($c);
            $dummy === null ? password_hash($password, PASSWORD_DEFAULT) : password_verify($password . "\0unknown", $dummy);
            $ok = false;
        }
        if (!$ok) {
            Session::close();
            self::answer(['error' => 'Wrong username or password.'], 401);
            return;
        }
        foreach ($recorded as $done) {
            $limiter->forgive($done);
        }
        self::rememberDevice($c, $user);
        Session::regenerate();
        Session::set('user', $account->user);
        Session::set('login', $c->now);
        Session::set('secret', Context::secretMark($account->passwordHash));
        Session::forget('csrf');
        $csrf = Session::csrf();
        Session::close();
        $until = $account->validUntil($c->now, $c->config->zone);
        $sessionEnd = $c->now + $c->config->sessionSeconds;
        self::answer([
            'user' => $account->user,
            'instructor' => $account->instructor,
            'valid_until' => $c->iso($until === null ? $sessionEnd : min($until, $sessionEnd)),
            'csrf' => $csrf,
        ]);
    }
}
