<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Policy;
use Cal6\Course\Boundary;
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
        // pages only (.html, .htm, .md, by name and real file), never a server-side file behind a link (L-22)
        if ($place === null || $place->file->kind() !== 'page' || Boundary::neverServed($place->file->realPath)
            || !$policy->decide($viewer, $place, $c->now)->visible) {
            Session::close();
            Response::error(404, 'not found');
            return;
        }
        // lock codes are looked for after <inject>, so a <lock> in an injected file works (L-4)
        $source = (new PageRenderer($course, $policy, $viewer, $c->now, ''))->expandedSource($place);
        $full = $c->query('full') === '1';

        if ($method === 'POST') {
            if (!Session::checkCsrf($c->post('csrf'))) {
                Session::close();
                Response::error(403, 'the form has expired: reload the page');
                return;
            }
            $code = trim((string) $c->post('lock'));
            if ($code !== '') {
                $limiter = RateLimiter::forCourse($c->courseDir, $c->config->loginAttempts[1]);
                $wait = $limiter->hit('lock:' . RateLimiter::addressKey($c->address()), $c->now, $c->config->loginAttempts[0] * 2);
                if ($wait > 0) {
                    Session::close();
                    Response::error($limiter->available() ? 429 : 503, 'too many attempts: try again later', ['Retry-After: ' . $wait]);
                    return;
                }
                if (PageRenderer::codeMatches($code, PageRenderer::lockCodes($source))) {
                    $unlocked = (array) Session::get('unlocked', []);
                    $unlocked[] = $code;
                    Session::set('unlocked', array_values(array_unique($unlocked)));
                    $limiter->forgive('lock:' . RateLimiter::addressKey($c->address()));
                }
            }
            // answer with a redirect, so reloading the page does not send the form again (L-6)
            Session::close();
            Response::common();
            http_response_code(303);
            header('Location: ' . Locator::coursePath($c->server) . 'calendar/php/html.php?file=' . rawurlencode($place->key) . ($full ? '&full=1' : ''));
            header('Cache-Control: no-store');
            return;
        }

        $urlValues = (array) Session::get('url', []);
        foreach ($c->query as $name => $value) {
            if (is_string($name) && is_string($value) && !in_array($name, self::RESERVED, true) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
                $urlValues[$name] = mb_substr($value, 0, 200);
            }
        }
        $hasLock = PageRenderer::lockCodes($source) !== [];
        if ($hasLock) {
            Session::start($c, true);
        }
        $renderer = new PageRenderer($course, $policy, $viewer, $c->now, Locator::coursePath($c->server),
            array_map('strval', (array) Session::get('unlocked', [])), array_map('strval', $urlValues),
            $place->key, $hasLock ? Session::csrf() : '', $full, PageRenderer::serverNames($c->server));
        $html = $renderer->render($place);
        if ($renderer->usedUrlValues !== []) {
            Session::start($c, true);
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
