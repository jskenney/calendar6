<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Viewer;
use Cal6\Http\Context;
use Cal6\Http\RateLimiter;
use Cal6\Http\Response;
use Cal6\Http\Session;

/**
 * auth.php (DESIGN.md): POST username + password + csrf logs in; POST with only
 * csrf logs out.  The same answer for a wrong password, an unknown user and an
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
        return hash_equals(hash_hmac('sha256', "device|$who|$nonce", $c->config->secret), $mac)
            && hash_equals(bin2hex($user), $who);
    }

    /** After a successful login: a signed cookie that lets this browser past the per-user gate. */
    private static function rememberDevice(Context $c, string $user): void
    {
        $who = bin2hex($user);
        $nonce = bin2hex(random_bytes(12));
        $value = "$who.$nonce." . hash_hmac('sha256', "device|$who|$nonce", $c->config->secret);
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

    public static function handle(Context $c): void
    {
        if ($c->method() !== 'POST') {
            Response::error(405, 'POST only', ['Allow: POST']);
            return;
        }
        Session::start($c, true);
        if (!Session::checkCsrf($c->post('csrf'))) {
            Session::close();
            Response::error(403, 'the form has expired: reload the page');
            return;
        }
        $username = trim((string) $c->post('username'));
        $password = (string) $c->post('password');

        if ($username === '' && $password === '') {
            // logging out clears the whole session: unlocked <lock> codes and <url> values too (L-17)
            Session::clear();
            Session::regenerate();
            $csrf = Session::csrf();
            Session::close();
            Response::json(['user' => null, 'instructor' => false, 'csrf' => $csrf]);
            return;
        }

        // Limits (M-7, L-23, M-18): per address and username the configured number, and
        // per address four times that, block (IPv6 counted per /64). Once a username has
        // ten times that many failures from anywhere, its attempts pass one per second
        // (the others get 429 at once, nobody sleeps), except from a browser that has
        // logged in as that user before (a signed device cookie), so guesses from many
        // addresses neither get far nor lock the real instructor out.
        [$limit, $seconds] = $c->config->loginAttempts;
        $limiter = RateLimiter::forCourse($c->courseDir, $seconds);
        if (!$limiter->available()) {
            error_log('calendar6: logins are refused: the private temporary directory can not be used (' . sys_get_temp_dir() . ')');
            Session::close();
            Response::error(503, 'Logging in is not possible right now: the server can not keep track of failed attempts.');
            return;
        }
        $user = strtolower($username);
        $address = RateLimiter::addressKey($c->address());
        $keys = ['pair:' . $address . '|' . $user => $limit, 'address:' . $address => $limit * 4];
        $recorded = [];
        foreach ($keys as $key => $max) {
            $wait = $limiter->hit($key, $c->now, $max);
            if ($wait > 0) {
                foreach ($recorded as $done) {
                    $limiter->forgive($done);
                }
                Session::close();
                Response::error(429, 'too many failed logins: try again later', ['Retry-After: ' . $wait]);
                return;
            }
            $recorded[] = $key;
        }
        if ($limiter->hit('user:' . $user, $c->now, $limit * 10) === 0) {
            $recorded[] = 'user:' . $user;
        } elseif (!self::knownDevice($c, $user)) {
            $wait = $limiter->gate('user:' . $user, $c->now, 1);
            if ($wait > 0) {
                foreach ($recorded as $done) {
                    $limiter->forgive($done);
                }
                Session::close();
                Response::error(429, 'too many failed logins for this user: try again in a moment', ['Retry-After: ' . $wait]);
                return;
            }
        }

        $account = Viewer::account($c->config, $username);
        $hash = $account?->passwordHash ?? '';
        $usable = $account !== null && str_starts_with($hash, '$');
        if ($usable) {
            $ok = password_verify($password, $hash) && $account->isValidAt($c->now, $c->config->zone);
        } else {
            // no such account: spend the time a real check takes at today's default cost (L-7)
            password_hash($password, PASSWORD_DEFAULT);
            $ok = false;
        }
        if (!$ok) {
            Session::close();
            Response::error(401, 'Wrong username or password.');
            return;
        }
        foreach ($recorded as $done) {
            $limiter->forgive($done);
        }
        self::rememberDevice($c, $user);
        Session::regenerate();
        Session::set('user', $account->user);
        Session::set('login', $c->now);
        Session::forget('csrf');
        $csrf = Session::csrf();
        Session::close();
        $until = $account->validUntil($c->now, $c->config->zone);
        $sessionEnd = $c->now + $c->config->sessionSeconds;
        Response::json([
            'user' => $account->user,
            'instructor' => $account->instructor,
            'valid_until' => $c->iso($until === null ? $sessionEnd : min($until, $sessionEnd)),
            'csrf' => $csrf,
        ]);
    }
}
