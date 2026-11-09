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
    /** A hash to verify against when there is no such account, so both take as long. */
    private const DUMMY_HASH = '$2y$10$jtpPieyUAx2tDNdeJvSM9O8ud08uvRx6UATZrw6R/NUWfxie13XTm';

    public static function handle(Context $c): void
    {
        if ($c->method() !== 'POST') {
            Response::error(405, 'POST only', ['Allow: POST']);
            return;
        }
        Session::start($c->courseDir, $c->server, true);
        if (!Session::checkCsrf($c->post('csrf'))) {
            Session::close();
            Response::error(403, 'the form has expired: reload the page');
            return;
        }
        $username = trim((string) $c->post('username'));
        $password = (string) $c->post('password');

        if ($username === '' && $password === '') {
            Session::forget('user');
            Session::forget('login');
            Session::regenerate();
            Session::forget('csrf');
            $csrf = Session::csrf();
            Session::close();
            Response::json(['user' => null, 'instructor' => false, 'csrf' => $csrf]);
            return;
        }

        [$limit, $seconds] = $c->config->loginAttempts;
        $limiter = RateLimiter::forCourse($c->courseDir, $limit, $seconds);
        $keys = ['address:' . $c->address(), 'user:' . strtolower($username)];
        $wait = max(array_map(static fn (string $k): int => $limiter->wait($k, $c->now), $keys));
        if ($wait > 0) {
            Session::close();
            Response::error(429, 'too many failed logins: try again later', ['Retry-After: ' . $wait]);
            return;
        }

        $account = Viewer::account($c->config, $username);
        $hash = $account?->passwordHash ?? '';
        $ok = password_verify($password, $hash !== '' && str_starts_with($hash, '$') ? $hash : self::DUMMY_HASH)
            && $account !== null && $hash !== ''
            && $account->isValidAt($c->now, $c->config->zone);
        if (!$ok) {
            foreach ($keys as $k) {
                $limiter->fail($k, $c->now);
            }
            Session::close();
            Response::error(401, 'Wrong username or password.');
            return;
        }
        $limiter->clear('user:' . strtolower($username));
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
