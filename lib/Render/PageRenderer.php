<?php

declare(strict_types=1);

namespace Cal6\Render;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Boundary;
use Cal6\Course\Course;
use Cal6\Course\Place;
use Throwable;

/**
 * html.php's processing (DESIGN.md, "Tags"): every Calendar 4 tag except <ace>,
 * decided on the server so hidden content never reaches the browser, and links to
 * files rewritten to keys.  JavaScript is passed through unchanged.
 */
final class PageRenderer
{
    private const MAX_INJECT_DEPTH = 5;

    /** @var list<string> <url> values that were used, to remember in the session */
    public array $usedUrlValues = [];
    /** @var array<string, string> placeholder => <calphp> output */
    private array $outputs = [];

    /**
     * @param list<string> $unlocked lock codes this viewer has entered
     * @param array<string, string> $urlValues
     */
    public function __construct(
        private readonly Course $course,
        private readonly Policy $policy,
        private readonly Viewer $viewer,
        private readonly int $now,
        private readonly string $baseUrl,     // the course's URL path, e.g. /~user/AB123/
        private readonly array $unlocked = [],
        private readonly array $urlValues = [],
        private readonly string $lockKey = '',
        private readonly string $csrf = '',
        private readonly bool $full = false,
    ) {
    }

    public function render(Place $place): string
    {
        $dir = dirname($place->file->realPath);
        $html = $this->load($place->file->realPath);
        $html = $this->injects($html, $dir, 0);
        $html = $this->postmeeting($html, $place);
        $html = self::block($html, 'inst', fn (string $inner): string => $this->viewer->instructor ? $inner : '');
        $html = $this->host($html);
        $html = $this->lock($html);
        // only scripts that are still in the page run; their output is passed through as-is
        $html = $this->calphp($html, $dir, $place);
        $html = self::block($html, 'student', static fn (string $inner): string =>
            '<details class="cal6-student"><summary>Show answer</summary>' . $inner . '</details>');
        $html = $this->replace($html, $place);
        $html = $this->url($html);
        $html = self::block($html, 'ace', static fn (string $inner): string => '<pre class="cal6-ace"><code>' . $inner . '</code></pre>');
        $html = preg_replace('/<\/?(keyword|search)\b[^>]*>/i', '', $html) ?? $html;
        $html = $this->showlinks($html, $dir, $place);
        $html = $this->links($html, $dir, $place);
        $html = $this->anchors($html);
        return strtr($html, $this->outputs);
    }

    private function load(string $path): string
    {
        $text = (string) @file_get_contents($path);
        if (preg_match('/\.md$/i', $path)) {
            $text = (new Markdown())->text($text);
        }
        return $text;
    }

    /** <inject src="file"> and <codeinject src="file">: relative to the page, inside the course. */
    private function injects(string $html, string $dir, int $depth): string
    {
        return preg_replace_callback('/<(code)?inject\b[^>]*>/i', function (array $m) use ($dir, $depth): string {
            $src = self::attribute($m[0], 'src');
            $real = $src === null ? null : $this->course->boundary->resolve($dir . '/' . $src);
            if ($real === null || !is_file($real) || Boundary::neverServed($real)) {
                return '';
            }
            if (($m[1] ?? '') !== '') {
                return htmlspecialchars((string) file_get_contents($real), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $inner = $this->load($real);
            return $depth < self::MAX_INJECT_DEPTH ? $this->injects($inner, dirname($real), $depth + 1) : $inner;
        }, $html) ?? $html;
    }

    /** <calphp src="file.php">: runs the course's own PHP file and puts its output in place, as-is. */
    private function calphp(string $html, string $dir, Place $place): string
    {
        return preg_replace_callback('/<calphp\b[^>]*>/i', function (array $m) use ($dir, $place): string {
            $src = self::attribute($m[0], 'src');
            $real = $src === null ? null : $this->course->boundary->resolve($dir . '/' . $src);
            if ($real === null || !is_file($real) || !preg_match('/\.php$/i', $real)) {
                return $this->viewer->instructor ? '<p class="cal6-error">calphp: no such script: ' . htmlspecialchars((string) $src) . '</p>' : '';
            }
            $cwd = (string) getcwd();
            chdir(dirname($real));
            ob_start();
            try {
                (static function (string $cal6Script, array $cal6Variables): void {
                    extract($cal6Variables, EXTR_SKIP);
                    include $cal6Script;
                })($real, [
                    'COURSE' => $this->course->config->number,
                    'COURSENAME' => $this->course->config->name,
                    'INSTRUCTOR' => $this->viewer->instructor,
                    'TYPE' => $place->event?->type,
                    'EVENT' => $place->event?->number,
                ]);
                $output = (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                $output = $this->viewer->instructor
                    ? '<p class="cal6-error">calphp ' . htmlspecialchars((string) $src) . ': ' . htmlspecialchars($e->getMessage()) . '</p>'
                    : '';
            } finally {
                chdir($cwd);
            }
            $placeholder = "\x00cal6-calphp-" . count($this->outputs) . "\x00";
            $this->outputs[$placeholder] = $output;
            return $placeholder;
        }, $html) ?? $html;
    }

    /** <postmeeting>: once the last section's meeting that day is over (instructors always). */
    private function postmeeting(string $html, Place $place): string
    {
        $time = $place->event?->time;
        $show = $this->viewer->instructor || $place->event === null
            || ($time !== null && $this->now >= $time->end()->getTimestamp());
        return self::block($html, 'postmeeting', static fn (string $inner): string => $show ? $inner : '');
    }

    private function host(string $html): string
    {
        $names = array_filter([gethostname() ?: '', (string) ($_SERVER['SERVER_NAME'] ?? '')]);
        return preg_replace_callback('/<host\b([^>]*)>([\s\S]*?)<\/host\s*>/i', static function (array $m) use ($names): string {
            $server = self::attribute('<host' . $m[1] . '>', 'server');
            return $server !== null && in_array(strtolower($server), array_map('strtolower', $names), true) ? $m[2] : '';
        }, $html) ?? $html;
    }

    /** <lock code="a,b">: a shared code unlocks the section; otherwise a form takes its place. */
    private function lock(string $html): string
    {
        return preg_replace_callback('/<lock\b([^>]*)>([\s\S]*?)<\/lock\s*>/i', function (array $m): string {
            $codes = array_filter(array_map('trim', explode(',', (string) self::attribute('<lock' . $m[1] . '>', 'code'))), static fn (string $c): bool => $c !== '');
            foreach ($codes as $code) {
                if (in_array($code, $this->unlocked, true)) {
                    return $m[2];
                }
            }
            if ($this->viewer->instructor) {
                return $m[2];
            }
            return '<form class="cal6-lock" method="post" action="' . htmlspecialchars($this->baseUrl . 'calendar/php/html.php?file=' . rawurlencode($this->lockKey) . ($this->full ? '&full=1' : '')) . '">'
                . '<input type="hidden" name="csrf" value="' . htmlspecialchars($this->csrf) . '">'
                . '<label>Enter the code to see this section <input type="password" name="lock" autocomplete="off"></label>'
                . '<button type="submit">Unlock</button></form>';
        }, $html) ?? $html;
    }

    /** All lock codes on a page (to check a code that was sent). @return list<string> */
    public static function lockCodes(string $html): array
    {
        preg_match_all('/<lock\b[^>]*>/i', $html, $m);
        $codes = [];
        foreach ($m[0] as $tag) {
            foreach (explode(',', (string) self::attribute($tag, 'code')) as $code) {
                if (trim($code) !== '') {
                    $codes[] = trim($code);
                }
            }
        }
        return $codes;
    }

    private function replace(string $html, Place $place): string
    {
        $event = $place->event;
        $values = [
            'type' => $event?->type ?? '',
            'ctype' => ucfirst($event?->type ?? ''),
            'event' => $event !== null ? (string) $event->number : '',
            'zevent' => $event !== null ? sprintf('%02d', $event->number) : '',
            'title' => $event?->title() ?? '',
            'course' => $this->course->config->number,
            'coursename' => $this->course->config->name,
        ];
        $values = array_merge($values, $this->course->config->replace);
        $html = preg_replace_callback('/<replace\b[^>]*>/i', static function (array $m) use ($values): string {
            $name = (string) self::attribute($m[0], 'value');
            return htmlspecialchars($values[$name] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html) ?? $html;
        return preg_replace('/<\/replace\s*>/i', '', $html) ?? $html;
    }

    /** <url value="name" default="x">: from the page's URL (remembered in the session), HTML-escaped. */
    private function url(string $html): string
    {
        $html = preg_replace_callback('/<url\b[^>]*>/i', function (array $m): string {
            $name = (string) self::attribute($m[0], 'value');
            if (isset($this->urlValues[$name])) {
                $this->usedUrlValues[] = $name;
                $value = $this->urlValues[$name];
            } else {
                $value = (string) self::attribute($m[0], 'default');
            }
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html) ?? $html;
        return preg_replace('/<\/url\s*>/i', '', $html) ?? $html;
    }

    private function showlinks(string $html, string $dir, Place $place): string
    {
        return preg_replace_callback('/<showlink\b[^>]*>/i', function (array $m) use ($dir, $place): string {
            $src = (string) self::attribute($m[0], 'src');
            $target = $this->visiblePlace($place, $dir, $src);
            $text = htmlspecialchars(basename($src), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $target === null ? $text : '<a href="' . htmlspecialchars($this->urlFor($target, 'a')) . '">' . $text . '</a>';
        }, $html) ?? $html;
    }

    /**
     * Relative links to files become keys: pages to the front end's #/view/KEY, other
     * files to get.php.  Links to files the viewer may not see lose their target.
     */
    private function links(string $html, string $dir, Place $place): string
    {
        return preg_replace_callback('/<(a|img|source|video|audio|track|iframe|embed|object)\b[^>]*>/i', function (array $tag) use ($dir, $place): string {
            $element = strtolower($tag[1]);
            return preg_replace_callback('/(\s)(href|src|poster|data)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', function (array $m) use ($dir, $place, $element): string {
                $value = html_entity_decode($m[4] !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : ($m[6] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($value === '' || preg_match('#^([a-z][a-z0-9+.-]*:|/|\#|\?)#i', $value)) {
                    return $m[0];
                }
                $fragment = '';
                if (($hash = strpos($value, '#')) !== false) {
                    $fragment = substr($value, $hash);
                    $value = substr($value, 0, $hash);
                }
                $value = explode('?', $value, 2)[0];
                $target = $this->visiblePlace($place, $dir, $value);
                if ($target !== null) {
                    return $m[1] . $m[2] . '="' . htmlspecialchars($this->urlFor($target, $element) . $fragment) . '"';
                }
                $exists = realpath($dir . '/' . rawurldecode($value)) !== false;
                return $exists ? $m[1] . 'data-cal6-hidden="1"' : $m[0];
            }, $tag[0]) ?? $tag[0];
        }, $html) ?? $html;
    }

    private function visiblePlace(Place $from, string $dir, string $link): ?Place
    {
        if ($link === '') {
            return null;
        }
        $target = $this->course->placeForPath($from->event, $dir, $link);
        if ($target === null || !$this->policy->decide($this->viewer, $target, $this->now)->visible) {
            return null;
        }
        return $target;
    }

    private function urlFor(Place $target, string $element): string
    {
        if ($element === 'a' && $target->file->kind() === 'page') {
            return $this->full
                ? $this->baseUrl . 'calendar/php/html.php?file=' . rawurlencode($target->key) . '&full=1'
                : '#/view/' . rawurlencode($target->key);
        }
        return $this->baseUrl . 'calendar/php/get.php?file=' . rawurlencode($target->key);
    }

    /** <a name="X"> gets id="X", so the front end can build the page's menu. */
    private function anchors(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*\bname\s*=\s*"([^"]*)"[^>]*>/i', static function (array $m): string {
            return preg_match('/\sid\s*=/i', $m[0]) ? $m[0] : substr($m[0], 0, 2) . ' id="' . $m[1] . '"' . substr($m[0], 2);
        }, $html) ?? $html;
    }

    /** @param callable(string): string $replace */
    public static function block(string $html, string $tag, callable $replace): string
    {
        return preg_replace_callback('/<' . $tag . '\b[^>]*>([\s\S]*?)<\/' . $tag . '\s*>/i', static fn (array $m): string => $replace($m[1]), $html) ?? $html;
    }

    public static function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
            return html_entity_decode($m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }
}
