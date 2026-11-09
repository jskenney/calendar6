<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Viewer;
use Cal6\Http\AnswerCache;
use Cal6\Http\Context;
use Cal6\Http\Fingerprint;
use Cal6\Http\Response;
use Cal6\Http\Session;

/**
 * calendar.php: everything this viewer may see, cached per viewer while the
 * course is unchanged and until the answer's next_change.  Instructors may ask
 * for another viewer (as=anonymous | as=<username>) or another moment
 * (at=YYYY-MM-DD HH:MM); those previews are never cached.
 */
final class CalendarEndpoint
{
    public static function handle(Context $c): void
    {
        if ($c->method() !== 'GET' && $c->method() !== 'HEAD') {
            Response::error(405, 'GET only', ['Allow: GET, HEAD']);
            return;
        }
        $viewer = $c->viewer();
        Session::close();
        $now = $c->now;
        $preview = false;
        if ($viewer->instructor) {
            $as = $c->query('as');
            if ($as !== null && $as !== '') {
                $other = $as === 'anonymous' ? Viewer::anonymous() : Viewer::account($c->config, $as);
                if ($other === null) {
                    Response::error(400, 'no such user');
                    return;
                }
                $viewer = $other;
                $preview = true;
            }
            $at = $c->query('at');
            if ($at !== null && $at !== '') {
                $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $at, $c->config->zone)
                    ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $at, $c->config->zone);
                if ($moment === false) {
                    Response::error(400, 'at: expected YYYY-MM-DD HH:MM');
                    return;
                }
                $now = $moment->getTimestamp();
                $preview = true;
            }
        }

        $cache = AnswerCache::forCourse($c->courseDir);
        $fingerprint = $preview ? '' : Fingerprint::of($c->courseDir);
        $body = $preview ? null : $cache->get($viewer->cacheName(), $fingerprint, $now);
        if ($body === null) {
            $json = new CalendarJson($c->course(), $c);
            $body = Response::encode($json->build($viewer, $now));
            if (!$preview) {
                $cache->put($viewer->cacheName(), $fingerprint, $json->nextChange, $body);
            }
        }
        Response::jsonWithEtag($body, $c->server);
    }
}
