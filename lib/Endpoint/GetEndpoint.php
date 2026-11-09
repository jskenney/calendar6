<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Policy;
use Cal6\Course\Boundary;
use Cal6\Http\Context;
use Cal6\Http\Download;
use Cal6\Http\Response;
use Cal6\Http\Session;

/**
 * get.php?file=KEY: one file, never a page: .html, .htm and .md files are served
 * only by html.php, processed, so their raw source (<inst>, <lock> codes, ...) is
 * never sent (C-1).  The access rule is checked again for the current viewer (a key
 * alone never grants access); unknown, a page, and not allowed all answer 404, so
 * hidden files can not be discovered.
 */
final class GetEndpoint
{
    public static function handle(Context $c): void
    {
        if ($c->method() !== 'GET' && $c->method() !== 'HEAD') {
            Response::error(405, 'GET only', ['Allow: GET, HEAD']);
            return;
        }
        $viewer = $c->viewer();
        Session::close();
        $place = $c->course()->place((string) $c->query('file'));
        if ($place === null || Boundary::neverServed($place->file->realPath) || $place->file->kind() === 'page'
            || !(new Policy($c->course()))->decide($viewer, $place, $c->now)->visible
            || $c->course()->boundary->resolve($place->file->realPath) === null) {
            Response::error(404, 'not found');
            return;
        }
        Download::send($place->file->realPath, $place->file->name, $c->server, $c->query('download') === '1');
    }
}
