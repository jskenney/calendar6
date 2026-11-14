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
 * html.php?file=KEY: one page, processed (API.md, `html.php`): the page file's contents
 * after processing, and nothing else.  POST lock=CODE (with csrf) unlocks <lock>
 * sections; other query parameters fill <url> tags.
 */
final class HtmlEndpoint
{
    private const RESERVED = ['file', 'lock', 'csrf'];

    /** The token of a page's <lock> form: the page key and the hour, signed with the course secret. */
    private static function lockToken(Context $c, string $key, int $hour): string
    {
        return substr(hash_hmac('sha256', "lock|$key|$hour", $c->config->secret), 0, 40);
    }

    /** A token from this hour or the one before. */
    private static function validLockToken(Context $c, string $key, string $token): bool
    {
        $hour = intdiv($c->now, 3600);
        return $token !== '' && (hash_equals(self::lockToken($c, $key, $hour), $token) || hash_equals(self::lockToken($c, $key, $hour - 1), $token));
    }

    /** A request another site sent: Sec-Fetch-Site says so, or Origin names another host. */
    private static function crossSite(Context $c): bool
    {
        // current browsers send Sec-Fetch-Site; only without it is Origin compared with Host
        // (a reverse proxy may change Host, so Origin is the fallback, not the rule)
        $site = strtolower((string) ($c->server['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($site !== '') {
            return $site === 'cross-site';
        }
        $origin = (string) ($c->server['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return false;
        }
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST)) . (($port = parse_url($origin, PHP_URL_PORT)) !== null ? ":$port" : '');
        return $origin === 'null' || $host !== strtolower((string) ($c->server['HTTP_HOST'] ?? ''));
    }

    /**
     * The page's own query: values for its <url> tags (names of letters, digits, "_" and "-",
     * not the reserved ones; values cut to 200 characters). The lock form and its redirect
     * keep them, so unlocking does not lose them (L-132).
     *
     * @return array<string, string>
     */
    public static function pageQuery(Context $c): array
    {
        $values = [];
        foreach ($c->query as $name => $value) {
            $name = (string) $name; // numeric names come back from $_GET as integers (L-40)
            if (is_string($value) && !in_array($name, self::RESERVED, true) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
                $values[$name] = mb_substr($value, 0, 200);
            }
        }
        return $values;
    }

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
        // no session is created before the checks: only a matching lock code makes one (L-43)
        $viewer = $c->viewer();
        $policy = new Policy($course);
        // pages only (.html, .htm, .md, by name and real file), never a server-side file behind a link (L-22)
        if ($place === null || $place->file->kind() !== 'page' || Boundary::neverServed($place->file->realPath)
            || !$policy->decide($viewer, $place, $c->now)->visible) {
            Session::close();
            Response::error(404, 'not found');
            return;
        }
        // lock codes are looked for after <inject>, so a <lock> in an injected file works (L-4)
        // lock codes come only from <lock>s this viewer can reach (L-91)
        // (with the codes this session has entered, so an inner lock opens once the outer one has, M-49)
        $source = (new PageRenderer($course, $policy, $viewer, $c->now, '', unlocked: array_map('strval', (array) Session::get('unlocked', [])),
            hostNames: PageRenderer::serverNames($c->server)))->visibleSource($place);

        if ($method === 'POST') {
            // the form's token is public (page and hour), so a POST from another site is refused
            // by what the browser says about it, and creates no session (L-50)
            if (self::crossSite($c) || !self::validLockToken($c, $place->key, (string) $c->post('csrf'))) {
                Session::close();
                Response::error(403, 'the form has expired: reload the page');
                return;
            }
            $code = trim((string) $c->post('lock'));
            if ($code !== '') {
                // per address and page four times the login limit, per address twenty times it,
                // and a right code does not count, so a classroom behind one address is not
                // blocked by a few typos (M-31)
                $limiter = RateLimiter::forCourse($c->courseDir, $c->config->loginAttempts[1]);
                $address = RateLimiter::addressKey($c->address());
                $keys = ["lock:$address|" . $place->key => $c->config->loginAttempts[0] * 4, "lock:$address" => $c->config->loginAttempts[0] * 20];
                $recorded = [];
                foreach ($keys as $limitKey => $max) {
                    $wait = $limiter->hit($limitKey, $c->now, $max);
                    if ($wait > 0) {
                        foreach ($recorded as $done) {
                            $limiter->forgive($done);
                        }
                        Session::close();
                        Response::error($limiter->available() ? 429 : 503, 'too many attempts: try again later', ['Retry-After: ' . $wait]);
                        return;
                    }
                    $recorded[] = $limitKey;
                }
                // no limit across addresses (no page gate): it would let a few addresses refuse
                // a whole class its right codes (M-54); Calendar 6 serves campus networks, and
                // short codes are pointed out to instructors on the page (PageRenderer::lock)
                if (PageRenderer::codeMatches($code, PageRenderer::lockCodes($source))) {
                    Session::start($c, true);
                    $unlocked = (array) Session::get('unlocked', []);
                    $unlocked[] = $code;
                    Session::set('unlocked', array_values(array_unique($unlocked)));
                    foreach ($recorded as $done) {
                        $limiter->forgive($done);
                    }
                }
            }
            // answer with a redirect, so reloading the page does not send the form again (L-6)
            Session::close();
            Response::common();
            http_response_code(303);
            header('Location: ' . Locator::coursePath($c->server) . Locator::softwarePath($c->server) . 'php/html.php?file=' . rawurlencode($place->key) . (($query = http_build_query(self::pageQuery($c), '', '&', PHP_QUERY_RFC3986)) !== '' ? '&' . $query : ''));
            header('Cache-Control: no-store');
            return;
        }

        $urlValues = (array) Session::get('url', []);
        foreach (self::pageQuery($c) as $name => $value) {
            $urlValues[$name] = $value;
        }
        // the lock form carries a token made from the page and the hour, so showing a page
        // with <lock> creates no session: a cross-site link can not replace a logged-in
        // instructor's session cookie (L-41)
        $hasLock = PageRenderer::lockCodes($source) !== [];
        $renderer = new PageRenderer($course, $policy, $viewer, $c->now, Locator::coursePath($c->server),
            array_map('strval', (array) Session::get('unlocked', [])), array_map('strval', $urlValues),
            $place->key, $hasLock ? self::lockToken($c, $place->key, intdiv($c->now, 3600)) : '', PageRenderer::serverNames($c->server),
            http_build_query(self::pageQuery($c), '', '&', PHP_QUERY_RFC3986), Locator::softwarePath($c->server));
        $html = $renderer->render($place);
        if ($renderer->usedUrlValues !== [] && Session::isOpen()) { // remembered only in an existing session (L-41)
            $remember = (array) Session::get('url', []);
            foreach ($renderer->usedUrlValues as $name) {
                $remember[$name] = $urlValues[$name];
            }
            Session::set('url', $remember);
        }
        Session::close();

        Response::common();
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        if ($viewer->instructor && $renderer->shortCodes !== []) {
            // instructors only, and outside the page, which stays exactly the processed file
            // (M-54); the codes as a JSON list, escaped to plain ASCII, so a header stays one line
            header('X-Cal6-Warning: lock codes shorter than ' . PageRenderer::STRONG_CODE . ' characters (guessable on a server reachable from the internet): '
                . json_encode($renderer->shortCodes, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if ($method !== 'HEAD') {
            echo $html;
        }
    }
}
