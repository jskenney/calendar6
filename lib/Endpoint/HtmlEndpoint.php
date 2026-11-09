<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Policy;
use Cal6\Http\Context;
use Cal6\Http\Locator;
use Cal6\Http\RateLimiter;
use Cal6\Http\Response;
use Cal6\Http\Session;
use Cal6\Render\PageRenderer;

/**
 * html.php?file=KEY: one page, processed (DESIGN.md, `html.php`).  By default an
 * HTML fragment for the front end; full=1 gives a whole document.  POST lock=CODE
 * (with csrf) unlocks <lock> sections; other parameters fill <url> tags.
 */
final class HtmlEndpoint
{
    private const RESERVED = ['file', 'full', 'lock', 'csrf'];

    public static function handle(Context $c): void
    {
        $method = $c->method();
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            Response::error(405, 'GET or POST only', ['Allow: GET, HEAD, POST']);
            return;
        }
        $key = (string) $c->query('file');
        $course = $c->course();
        $place = $course->place($key);
        $viewer = $c->viewer($method === 'POST');
        $policy = new Policy($course);
        if ($place === null || $place->file->kind() !== 'page' || !$policy->decide($viewer, $place, $c->now)->visible) {
            Session::close();
            Response::error(404, 'not found');
            return;
        }
        $source = (string) @file_get_contents($place->file->realPath);

        if ($method === 'POST') {
            if (!Session::checkCsrf($c->post('csrf'))) {
                Session::close();
                Response::error(403, 'the form has expired: reload the page');
                return;
            }
            $code = trim((string) $c->post('lock'));
            if ($code !== '') {
                $limiter = RateLimiter::forCourse($c->courseDir, $c->config->loginAttempts[0], $c->config->loginAttempts[1]);
                $wait = $limiter->wait('lock:' . $c->address(), $c->now);
                if ($wait > 0) {
                    Session::close();
                    Response::error(429, 'too many attempts: try again later', ['Retry-After: ' . $wait]);
                    return;
                }
                if (in_array($code, PageRenderer::lockCodes($source), true)) {
                    $unlocked = (array) Session::get('unlocked', []);
                    $unlocked[] = $code;
                    Session::set('unlocked', array_values(array_unique($unlocked)));
                } else {
                    $limiter->fail('lock:' . $c->address(), $c->now);
                }
            }
        }

        $urlValues = (array) Session::get('url', []);
        foreach ($c->query as $name => $value) {
            if (is_string($name) && is_string($value) && !in_array($name, self::RESERVED, true) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
                $urlValues[$name] = mb_substr($value, 0, 200);
            }
        }
        $full = $c->query('full') === '1';
        $hasLock = PageRenderer::lockCodes($source) !== [];
        if ($hasLock) {
            Session::start($c->courseDir, $c->server, true);
        }
        $renderer = new PageRenderer($course, $policy, $viewer, $c->now, Locator::coursePath($c->server),
            array_map('strval', (array) Session::get('unlocked', [])), array_map('strval', $urlValues),
            $place->key, $hasLock ? Session::csrf() : '', $full, PageRenderer::serverNames($c->server));
        $html = $renderer->render($place);
        if ($renderer->usedUrlValues !== []) {
            Session::start($c->courseDir, $c->server, true);
            $remember = (array) Session::get('url', []);
            foreach ($renderer->usedUrlValues as $name) {
                $remember[$name] = $urlValues[$name];
            }
            Session::set('url', $remember);
        }
        Session::close();

        if ($full) {
            $title = htmlspecialchars($place->event !== null ? ucfirst($place->event->type) . ' ' . $place->event->number . ': ' . $place->event->title() : $course->config->pages[$place->page]['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $html = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>$title</title>\n</head>\n<body>\n<main class=\"cal6-page\">\n$html\n</main>\n</body>\n</html>\n";
        }
        Response::common();
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        if ($method !== 'HEAD') {
            echo $html;
        }
    }
}
