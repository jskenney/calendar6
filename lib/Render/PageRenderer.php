<?php

declare(strict_types=1);

namespace Cal6\Render;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Content\ContentFile;
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
        /** names and addresses <host server="..."> matches: never anything the client chooses */
        private readonly array $hostNames = [],
    ) {
    }

    /** The page after <inject>: what its <lock> codes are looked for in. */
    public function expandedSource(Place $place): string
    {
        return $this->injects($this->load($place->file->realPath), dirname($place->file->realPath), 0, $place);
    }

    /** Constant-time comparison of a code with each of the page's codes (L-5). @param list<string> $codes */
    public static function codeMatches(string $code, array $codes): bool
    {
        $match = false;
        foreach ($codes as $candidate) {
            $match = hash_equals($candidate, $code) || $match;
        }
        return $match;
    }

    public function render(Place $place): string
    {
        $dir = dirname($place->file->realPath);
        $html = $this->load($place->file->realPath);
        $html = $this->injects($html, $dir, 0, $place);
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
        $html = preg_replace('/<\/?(keyword|search)\b[^>]*>/i', '', $html) ?? self::failed();
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
    private function injects(string $html, string $dir, int $depth, Place $place): string
    {
        return preg_replace_callback('/<(code)?inject\b[^>]*>/i', function (array $m) use ($dir, $depth, $place): string {
            $src = self::attribute($m[0], 'src');
            $real = $src === null ? null : $this->course->boundary->resolve($dir . '/' . $src);
            if ($real === null || !is_file($real) || Boundary::neverServed($real) || !$this->mayInject($real)) {
                return '';
            }
            if (($m[1] ?? '') !== '') {
                $source = (string) file_get_contents($real);
                if (ContentFile::isPage($real)) {
                    // the source of a page, shown as code, still loses what this viewer may
                    // not see: raw page source never leaves the server (L-30, C-1)
                    $source = $this->hideForSource($source, $place);
                }
                return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $inner = $this->load($real);
            $inner = $depth < self::MAX_INJECT_DEPTH ? $this->injects($inner, dirname($real), $depth + 1, $place) : $inner;
            // its links, <calphp> and <showlink> are relative to the injected file, not the page (L-21)
            return dirname($real) === $dir ? $inner : self::rebase($inner, dirname($real), $dir);
        }, $html) ?? self::failed();
    }

    /** A page's source with the blocks this viewer may not see removed (for <codeinject>). */
    private function hideForSource(string $source, Place $place): string
    {
        $source = $this->postmeeting($source, $place);
        $source = self::block($source, 'inst', fn (string $inner, string $opening): string => $this->viewer->instructor ? $opening . $inner . '</inst>' : '');
        $source = $this->host($source);
        return self::block($source, 'lock', function (string $inner, string $opening): string {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string) self::attribute($opening, 'code'))), static fn (string $c): bool => $c !== ''));
            foreach ($this->unlocked as $entered) {
                if (self::codeMatches($entered, $codes)) {
                    return $inner;
                }
            }
            return $this->viewer->instructor ? $opening . $inner . '</lock>' : '<lock>(locked)</lock>';
        });
    }

    /**
     * An injected file obeys its own release rule (M-2): a file with a key must be
     * visible to this viewer; a file without one may be injected only from outside
     * the event directories (shared/, pages/, ...).
     */
    private function mayInject(string $real): bool
    {
        $keys = $this->course->byRealPath[$real] ?? [];
        foreach ($keys as $key) {
            if ($this->policy->decide($this->viewer, $this->course->places[$key], $this->now)->visible) {
                return true;
            }
        }
        if ($keys !== []) {
            return false;
        }
        $first = explode('/', $this->course->boundary->relative($real))[0];
        return !isset($this->course->config->types[$first]);
    }

    /** Rewrites relative URLs written for $fromDir so they mean the same from $toDir. */
    private static function rebase(string $html, string $fromDir, string $toDir): string
    {
        $fix = static function (string $url) use ($fromDir, $toDir): string {
            if ($url === '' || self::isAbsolute($url)) {
                return $url;
            }
            $suffix = '';
            if (($cut = strcspn($url, '?#')) < strlen($url)) {
                [$url, $suffix] = [substr($url, 0, $cut), substr($url, $cut)];
            }
            return self::relativePath($toDir, $fromDir . '/' . $url) . $suffix;
        };
        $html = preg_replace_callback(self::LINK_ELEMENTS, static function (array $tag) use ($fix): string {
            return preg_replace_callback(self::LINK_ATTRIBUTES, static function (array $m) use ($fix): string {
                $value = self::attributeValue($m);
                if (strtolower($m[2]) === 'srcset') {
                    $value = implode(', ', array_map(static function (string $candidate) use ($fix): string {
                        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [''];
                        return trim($fix($parts[0]) . ' ' . ($parts[1] ?? ''));
                    }, explode(',', $value)));
                } else {
                    $value = $fix($value);
                }
                return $m[1] . $m[2] . '="' . htmlspecialchars($value) . '"';
            }, $tag[0]) ?? self::failed();
        }, $html) ?? self::failed();
        return preg_replace_callback('/<(calphp|showlink)\b[^>]*>/i', static function (array $m) use ($fix): string {
            $src = self::attribute($m[0], 'src');
            return $src === null ? $m[0] : '<' . $m[1] . ' src="' . htmlspecialchars($fix($src)) . '">';
        }, $html) ?? self::failed();
    }

    /** The relative path from directory $from to $to (both absolute, not resolved). */
    private static function relativePath(string $from, string $to): string
    {
        $normal = static function (string $path): array {
            $parts = [];
            foreach (explode('/', $path) as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    array_pop($parts);
                } else {
                    $parts[] = $part;
                }
            }
            return $parts;
        };
        [$a, $b] = [$normal($from), $normal($to)];
        $common = 0;
        while ($common < count($a) && $common < count($b) - 1 && $a[$common] === $b[$common]) {
            $common++;
        }
        return str_repeat('../', count($a) - $common) . implode('/', array_slice($b, $common));
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
        }, $html) ?? self::failed();
    }

    /** <postmeeting>: once the last section's meeting that day is over (instructors always). */
    private function postmeeting(string $html, Place $place): string
    {
        $time = $place->event?->time;
        $show = $this->viewer->instructor || $place->event === null
            || ($time !== null && $this->now >= $time->end()->getTimestamp());
        return self::block($html, 'postmeeting', static fn (string $inner): string => $show ? $inner : '');
    }

    /**
     * <host server="a, b">: shown only when served by one of those servers.  Matched
     * against the host's own name and address, never SERVER_NAME (it comes from the
     * request's Host header, which anyone can set).
     */
    private function host(string $html): string
    {
        $names = array_map('strtolower', array_filter($this->hostNames, static fn (string $n): bool => $n !== ''));
        return self::block($html, 'host', static function (string $inner, string $opening) use ($names): string {
            foreach (explode(',', (string) self::attribute($opening, 'server')) as $server) {
                if (trim($server) !== '' && in_array(strtolower(trim($server)), $names, true)) {
                    return $inner;
                }
            }
            return '';
        });
    }

    /** The names <host> may match on this server: its host name and address. @return list<string> */
    public static function serverNames(array $server): array
    {
        $host = (string) (gethostname() ?: '');
        return array_values(array_filter([$host, explode('.', $host)[0], (string) ($server['SERVER_ADDR'] ?? '')]));
    }

    /** <lock code="a,b">: a shared code unlocks the section; otherwise a form takes its place. */
    private function lock(string $html): string
    {
        return self::block($html, 'lock', function (string $inner, string $opening): string {
            $codes = array_filter(array_map('trim', explode(',', (string) self::attribute($opening, 'code'))), static fn (string $c): bool => $c !== '');
            foreach ($this->unlocked as $entered) {
                if (self::codeMatches($entered, array_values($codes))) {
                    return $inner;
                }
            }
            if ($this->viewer->instructor) {
                return $inner;
            }
            return '<form class="cal6-lock" method="post" action="' . htmlspecialchars($this->baseUrl . 'calendar/php/html.php?file=' . rawurlencode($this->lockKey) . ($this->full ? '&full=1' : '')) . '">'
                . '<input type="hidden" name="csrf" value="' . htmlspecialchars($this->csrf) . '">'
                . '<label>Enter the code to see this section <input type="password" name="lock" autocomplete="off"></label>'
                . '<button type="submit">Unlock</button></form>';
        });
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
        $values = $this->course->config->replace + $values; // not array_merge: numeric names (L-26)
        $html = preg_replace_callback('/<replace\b[^>]*>/i', static function (array $m) use ($values): string {
            $name = (string) self::attribute($m[0], 'value');
            return htmlspecialchars($values[$name] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html) ?? self::failed();
        return preg_replace('/<\/replace\s*>/i', '', $html) ?? self::failed();
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
        }, $html) ?? self::failed();
        return preg_replace('/<\/url\s*>/i', '', $html) ?? self::failed();
    }

    private function showlinks(string $html, string $dir, Place $place): string
    {
        return preg_replace_callback('/<showlink\b[^>]*>/i', function (array $m) use ($dir, $place): string {
            $src = (string) self::attribute($m[0], 'src');
            $target = $this->visiblePlace($place, $dir, $src);
            $text = htmlspecialchars(basename($src), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $target === null ? $text : '<a href="' . htmlspecialchars($this->urlFor($target, 'a')) . '">' . $text . '</a>';
        }, $html) ?? self::failed();
    }

    private const LINK_ELEMENTS = '/<(a|img|source|video|audio|track|iframe|embed|object|script|link)\b[^>]*>/i';
    private const LINK_ATTRIBUTES = '/(\s)(href|src|poster|data|srcset)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';

    /** The relative URLs a page links to (without #fragment or ?query). @return list<string> */
    public static function linkTargets(string $html): array
    {
        $links = [];
        preg_match_all(self::LINK_ELEMENTS, $html, $tags);
        foreach ($tags[0] as $tag) {
            preg_match_all(self::LINK_ATTRIBUTES, $tag, $attributes, PREG_SET_ORDER);
            foreach ($attributes as $m) {
                $value = self::attributeValue($m);
                $values = strtolower($m[2]) === 'srcset' ? array_map(static fn (string $c): string => preg_split('/\s+/', trim($c))[0] ?? '', explode(',', $value)) : [$value];
                foreach ($values as $url) {
                    if ($url !== '' && !self::isAbsolute($url)) {
                        $links[] = explode('?', explode('#', $url, 2)[0], 2)[0];
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($links, static fn (string $l): bool => $l !== '')));
    }

    /**
     * Relative links to files become keys: pages to the front end's #/view/KEY, other
     * files (images, scripts, style sheets, srcset candidates) to get.php.  Links to
     * files the viewer may not see lose their target.
     */
    private function links(string $html, string $dir, Place $place): string
    {
        return preg_replace_callback(self::LINK_ELEMENTS, function (array $tag) use ($dir, $place): string {
            $element = strtolower($tag[1]);
            return preg_replace_callback(self::LINK_ATTRIBUTES, function (array $m) use ($dir, $place, $element): string {
                $value = self::attributeValue($m);
                if (strtolower($m[2]) === 'srcset') {
                    $kept = [];
                    foreach (explode(',', $value) as $candidate) {
                        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [''];
                        $url = $this->rewrite($parts[0], $dir, $place, $element);
                        if ($url !== null) {
                            $kept[] = trim($url . ' ' . ($parts[1] ?? ''));
                        }
                    }
                    return $m[1] . 'srcset="' . htmlspecialchars(implode(', ', $kept)) . '"';
                }
                $url = $this->rewrite($value, $dir, $place, $element);
                return $url === null ? $m[1] . 'data-cal6-hidden="1"' : ($url === $value ? $m[0] : $m[1] . $m[2] . '="' . htmlspecialchars($url) . '"');
            }, $tag[0]) ?? self::failed();
        }, $html) ?? self::failed();
    }

    /** The URL to use for a link: unchanged, a key URL, or null when it leads to a file the viewer may not see. */
    private function rewrite(string $value, string $dir, Place $place, string $element): ?string
    {
        if ($value === '' || self::isAbsolute($value)) {
            return $value;
        }
        $fragment = '';
        if (($hash = strpos($value, '#')) !== false) {
            $fragment = substr($value, $hash);
            $value = substr($value, 0, $hash);
        }
        $path = explode('?', $value, 2)[0];
        $target = $this->visiblePlace($place, $dir, $path);
        if ($target !== null && $target->file->kind() === 'page' && !in_array($element, ['a', 'iframe', 'embed', 'object'], true)) {
            $target = null; // a page can not be an image, script or style sheet: get.php never sends pages
        }
        if ($target !== null) {
            return $this->urlFor($target, $element) . $fragment;
        }
        return realpath($dir . '/' . rawurldecode($path)) !== false ? null : $value . $fragment;
    }

    private static function isAbsolute(string $url): bool
    {
        return (bool) preg_match('#^([a-z][a-z0-9+.-]*:|/|\#|\?)#i', $url);
    }

    /** @param array<int, string> $m a LINK_ATTRIBUTES match */
    private static function attributeValue(array $m): string
    {
        return html_entity_decode($m[4] !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : ($m[6] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function visiblePlace(Place $from, string $dir, string $link): ?Place
    {
        if ($link === '') {
            return null;
        }
        $target = $this->course->placeForPath($from, $dir, $link);
        if ($target === null || !$this->policy->decide($this->viewer, $target, $this->now)->visible) {
            return null;
        }
        return $target;
    }

    private function urlFor(Place $target, string $element): string
    {
        if ($target->file->kind() === 'page') {
            // pages are only ever served by html.php (C-1): links open them in the front end,
            // frames get the whole processed document
            return $element === 'a' && !$this->full
                ? '#/view/' . rawurlencode($target->key)
                : $this->baseUrl . 'calendar/php/html.php?file=' . rawurlencode($target->key) . '&full=1';
        }
        return $this->baseUrl . 'calendar/php/get.php?file=' . rawurlencode($target->key);
    }

    /** <a name="X"> gets id="X", so the front end can build the page's menu. */
    private function anchors(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*\bname\s*=\s*"([^"]*)"[^>]*>/i', static function (array $m): string {
            return preg_match('/\sid\s*=/i', $m[0]) ? $m[0] : substr($m[0], 0, 2) . ' id="' . $m[1] . '"' . substr($m[0], 2);
        }, $html) ?? self::failed();
    }

    /**
     * Replaces each <tag ...>inner</tag> block with $replace(inner, openingTag), without
     * regular expressions (H-4, M-13, L-29, L-34):
     *
     * - one linear pass finds every opening and closing tag; nested blocks of the same tag
     *   are matched by depth and the inner content is processed first, so
     *   <inst>A<inst>B</inst>C</inst> is one block;
     * - tags inside HTML comments, CDATA sections and <script>/<style> bodies are not tags,
     *   so a "</inst>" in a comment does not end the block early;
     * - a block that is never closed runs to the end of the page (fail closed);
     * - a closing tag with no opening one is left as text;
     * - any size and any depth of nesting works in linear time.
     *
     * @param callable(string, string): string $replace
     */
    public static function block(string $html, string $tag, callable $replace): string
    {
        $tokens = self::tagTokens($html, $tag, self::opaqueRanges($html));
        $match = [];
        $stack = [];
        foreach ($tokens as $i => [$kind]) {
            if ($kind === 'open') {
                $stack[] = $i;
            } elseif ($stack !== []) {
                $match[array_pop($stack)] = $i;
            }
        }
        return self::renderBlocks($html, $tokens, $match, 0, count($tokens), 0, strlen($html), $replace);
    }

    /**
     * @param list<array{0: string, 1: int, 2: int}> $tokens [kind, start, end]
     * @param array<int, int> $match opening token => closing token
     */
    private static function renderBlocks(string $html, array $tokens, array $match, int $from, int $to, int $start, int $end, callable $replace): string
    {
        $out = '';
        $pos = $start;
        $i = $from;
        while ($i < $to) {
            [$kind, $tokenStart, $tokenEnd] = $tokens[$i];
            if ($kind !== 'open') {
                $i++; // a closing tag with no opening one: left as text
                continue;
            }
            $out .= substr($html, $pos, $tokenStart - $pos);
            $opening = substr($html, $tokenStart, $tokenEnd - $tokenStart);
            $close = $match[$i] ?? null;
            [$innerEnd, $after, $innerTo] = $close === null ? [$end, $end, $to] : [$tokens[$close][1], $tokens[$close][2], $close];
            $inner = self::renderBlocks($html, $tokens, $match, $i + 1, $innerTo, $tokenEnd, $innerEnd, $replace);
            $out .= $replace($inner, $opening);
            $pos = $after;
            $i = $close === null ? $to : $close + 1;
        }
        return $out . substr($html, $pos, max(0, $end - $pos));
    }

    /**
     * Every "<tag ...>" and "</tag>" outside the opaque ranges, in order.
     *
     * @param list<array{0: int, 1: int}> $opaque
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function tagTokens(string $html, string $tag, array $opaque): array
    {
        $tokens = [];
        $length = strlen($html);
        $name = strlen($tag);
        $r = 0;
        $from = 0;
        while (($at = stripos($html, '<', $from)) !== false) {
            $from = $at + 1;
            while ($r < count($opaque) && $opaque[$r][1] <= $at) {
                $r++;
            }
            if ($r < count($opaque) && $opaque[$r][0] <= $at) {
                $from = $opaque[$r][1];
                continue; // inside a comment, CDATA or script/style body
            }
            $closing = ($html[$at + 1] ?? '') === '/';
            $nameAt = $at + ($closing ? 2 : 1);
            if (strncasecmp(substr($html, $nameAt, $name), $tag, $name) !== 0) {
                continue;
            }
            $next = $html[$nameAt + $name] ?? '';
            if ($closing) {
                $endAt = $nameAt + $name;
                while ($endAt < $length && ctype_space($html[$endAt])) {
                    $endAt++;
                }
                if (($html[$endAt] ?? '') === '>') {
                    $tokens[] = ['close', $at, $endAt + 1];
                    $from = $endAt + 1;
                }
            } elseif ($next === '>' || $next === '/' || ($next !== '' && ctype_space($next))) {
                $gt = strpos($html, '>', $nameAt);
                $tokens[] = ['open', $at, $gt === false ? $length : $gt + 1];
                $from = $gt === false ? $length : $gt + 1;
            }
        }
        return $tokens;
    }

    /** [start, end) of HTML comments, CDATA sections and <script>/<style> bodies. @return list<array{0: int, 1: int}> */
    private static function opaqueRanges(string $html): array
    {
        $ranges = [];
        $from = 0;
        $length = strlen($html);
        while (($at = strpos($html, '<', $from)) !== false) {
            if (substr_compare($html, '<!--', $at, 4) === 0) {
                $end = strpos($html, '-->', $at + 4);
                $stop = $end === false ? $length : $end + 3;
            } elseif (substr_compare($html, '<![CDATA[', $at, 9) === 0) {
                $end = strpos($html, ']]>', $at + 9);
                $stop = $end === false ? $length : $end + 3;
            } elseif (preg_match('/\G<(script|style)\b[^>]*>/Ai', $html, $m, 0, $at)) {
                $body = $at + strlen($m[0]);
                $end = stripos($html, '</' . $m[1], $body);
                $ranges[] = [$body, $end === false ? $length : $end];
                $from = $end === false ? $length : $end + 2;
                continue;
            } else {
                $from = $at + 1;
                continue;
            }
            $ranges[] = [$at, $stop];
            $from = $stop;
        }
        return $ranges;
    }

    /** A pattern that failed (backtracking or another PCRE limit): never send the page half processed. */
    private static function failed(): never
    {
        throw new \RuntimeException('the page could not be processed: ' . preg_last_error_msg());
    }

    public static function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
            return html_entity_decode($m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }
}
