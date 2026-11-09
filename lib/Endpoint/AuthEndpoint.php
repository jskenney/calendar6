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

        // Limits (M-7): per address and username the configured number; per address
        // four times that; per username ten times that, so one person can not keep an
        // account locked by guessing from a single address.
        [$limit, $seconds] = $c->config->loginAttempts;
        $limiter = RateLimiter::forCourse($c->courseDir, $seconds);
        if (!$limiter->available()) {
            error_log('calendar6: logins are refused: the private temporary directory can not be used (' . sys_get_temp_dir() . ')');
            Session::close();
            Response::error(503, 'Logging in is not possible right now: the server can not keep track of failed attempts.');
            return;
        }
        $user = strtolower($username);
        $keys = ['pair:' . $c->address() . '|' . $user => $limit, 'address:' . $c->address() => $limit * 4, 'user:' . $user => $limit * 10];
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
