<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Policy;
use Cal6\Course\Boundary;
use Cal6\Http\Context;
use Cal6\Http\Locator;
use Cal6\Http\Response;
use Cal6\Http\Session;
use Cal6\Render\PageRenderer;

/**
 * md.php?file=KEY: one Markdown page (`kind: "md"`), returned as Markdown, processed for
 * this viewer (API.md, `md.php`): hidden blocks removed by a plain scan, <lock> sections
 * removed (no lock codes here), links rewritten to keys. Other query parameters fill <url>.
 */
final class MdEndpoint
{
    public static function handle(Context $c): void
    {
        $method = $c->method();
        if ($method !== 'GET' && $method !== 'HEAD') {
            Response::error(405, 'GET only', ['Allow: GET, HEAD']);
            return;
        }
        $course = $c->course();
        $place = $course->place((string) $c->query('file'));
        $viewer = $c->viewer();
        $policy = new Policy($course);
        // Markdown pages only (by the real file); anything else, or not allowed: the same 404
        if ($place === null || $place->file->kind() !== 'page' || !PageRenderer::isMarkdown($place->file->realPath)
            || Boundary::neverServed($place->file->realPath) || !$policy->decide($viewer, $place, $c->now)->visible) {
            Session::close();
            Response::error(404, 'not found');
            return;
        }
        $urlValues = (array) Session::get('url', []);
        foreach (HtmlEndpoint::pageQuery($c) as $name => $value) {
            $urlValues[$name] = $value;
        }
        $renderer = new PageRenderer($course, $policy, $viewer, $c->now, Locator::coursePath($c->server),
            [], array_map('strval', $urlValues), hostNames: PageRenderer::serverNames($c->server));
        $markdown = $renderer->renderMarkdown($place);
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
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: private, no-store');
        if ($method !== 'HEAD') {
            echo $markdown;
        }
    }
}
