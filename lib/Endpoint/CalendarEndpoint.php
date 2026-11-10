<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Viewer;
use Cal6\Http\AnswerCache;
use Cal6\Http\Context;
use Cal6\Http\Response;
use Cal6\Http\Session;

/**
 * calendar.php: everything this viewer may see, cached per viewer while the
 * course is unchanged and until the next moment a rule changes it (not sent: E-10).  Instructors may ask
 * for another viewer (as=anonymous | as=<username>) or another moment
 * (at=YYYY-MM-DD HH:MM); those previews are never cached.
 */
final class CalendarEndpoint
{
    public static function handle(Context $c): void
    {
        if (!in_array($c->method(), ['GET', 'HEAD', 'POST'], true)) {
            Response::error(405, 'GET or POST only', ['Allow: GET, HEAD, POST']);
            return;
        }
        // every field by GET or POST: the first non-empty value, the query's first; a list is an
        // error (DOC-22)
        $param = static function (string $name) use ($c): ?string {
            foreach ([$c->query, $c->post] as $source) {
                $value = $source[$name] ?? null;
                if (is_array($value)) {
                    throw new \InvalidArgumentException("$name: expected one value");
                }
                if ($value !== null && (string) $value !== '') {
                    return (string) $value;
                }
            }
            return null;
        };
        try {
            $section = $param('section'); // that section's times, period, instructor and room per event
            $as = $param('as');
            $at = $param('at');
        } catch (\InvalidArgumentException $e) {
            Response::error(400, $e->getMessage());
            return;
        }
        if ($section !== null && !isset($c->config->sections->sections[$section])) {
            Response::error(404, 'no such section');
            return;
        }
        $viewer = $c->viewer();
        Session::close();
        $now = $c->now;
        $preview = false;
        if ($viewer->instructor) {
            if ($as !== null) {
                $other = $as === 'anonymous' ? Viewer::anonymous() : Viewer::account($c->config, $as);
                if ($other === null) {
                    Response::error(400, 'no such user');
                    return;
                }
                $viewer = $other;
                $preview = true;
            }
            if ($at !== null) {
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
        $fingerprint = $preview ? '' : $c->fingerprint();
        $cacheName = $viewer->cacheName() . ($section !== null ? '|section=' . $section : '');
        $body = $preview ? null : $cache->get($cacheName, $fingerprint, $now);
        if ($body === null) {
            $json = new CalendarJson($c->course(), $c);
            $body = Response::encode($json->build($viewer, $now, $section));
            if (!$preview) {
                $cache->put($cacheName, $fingerprint, $json->nextChange, $body);
            }
        }
        Response::jsonWithEtag($body, $c->server);
    }
}
