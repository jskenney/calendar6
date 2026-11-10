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
    private const MAX_INJECT_BYTES = 4_000_000;

    private int $injectedBytes = 0;

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
        // removed (v4 search links), as real tags only: script text stays as written (L-54)
        $html = self::replaceTags($html, ['keyword', 'search'], static fn (): string => '', ['keyword', 'search']);
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
        return self::stripComments($text);
    }

    /**
     * HTML comments are removed before anything else (outside <script>/<style> bodies),
     * so a commented-out <inst>, <lock> or <inject> never reaches the browser (M-23).
     */
    public static function stripComments(string $html): string
    {
        $out = '';
        $pos = 0;
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] === 'comment') {
                $out .= substr($html, $pos, $token[1] - $pos);
                $pos = $token[2];
            }
        }
        return $out . substr($html, $pos);
    }

    /**
     * Replaces the real tags named $names: only tags HtmlLexer sees, so a tag written inside a
     * quoted attribute value or a <script>/<style> body is left alone (M-28, L-47), in one
     * linear pass (L-44).  $fn gets the lower-case name and the tag's text; closing tags of the
     * names in $dropClosing (</url>, </replace>) are removed.
     *
     * @param list<string> $names
     * @param callable(string, string): string $fn
     * @param list<string> $dropClosing
     */
    private static function replaceTags(string $html, array $names, callable $fn, array $dropClosing = []): string
    {
        $out = '';
        $pos = 0;
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] !== 'tag') {
                continue;
            }
            [, $start, $end, $name, $closing] = $token;
            if ($closing ? !in_array($name, $dropClosing, true) : !in_array($name, $names, true)) {
                continue;
            }
            $out .= substr($html, $pos, $start - $pos);
            if (!$closing) {
                $out .= $fn($name, substr($html, $start, $end - $start));
            }
            $pos = $end;
        }
        return $out . substr($html, $pos);
    }

    /** The real tags named $name, as their text (for reading attributes). @return list<string> */
    private static function tagsNamed(string $html, string $name): array
    {
        $tags = [];
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] === 'tag' && !$token[4] && $token[3] === $name) {
                $tags[] = substr($html, $token[1], $token[2] - $token[1]);
            }
        }
        return $tags;
    }

    /** <inject src="file"> and <codeinject src="file">: relative to the page, inside the course. */
    private function injects(string $html, string $dir, int $depth, Place $place, array $ancestors = []): string
    {
        $ancestors[] = $place->file->realPath;
        return self::replaceTags($html, ['inject', 'codeinject'], function (string $name, string $tag) use ($dir, $depth, $place, $ancestors): string {
            $code = $name === 'codeinject';
            $m = [$tag];
            $src = self::attribute($m[0], 'src');
            $real = $src === null ? null : $this->course->boundary->resolve($dir . '/' . $src);
            if ($real === null || !is_file($real) || Boundary::neverServed($real) || !$this->mayInject($real)) {
                return '';
            }
            // no cycles (a page injecting itself or an ancestor), and at most MAX_INJECT_BYTES
            // of injected text per page, so injects can not multiply a page without end (L-37)
            // (the size is checked before the file is read, so one huge file is never read, L-48)
            $size = (int) filesize($real);
            if (in_array($real, $ancestors, true) || $this->injectedBytes + $size > self::MAX_INJECT_BYTES) {
                return '';
            }
            $this->injectedBytes += $size;
            // the injected file's own place decides its <postmeeting> (M-24); a file without
            // a key (shared/) belongs to the page that injects it
            $owner = $this->injectOwner($real) ?? $place;
            if ($code) {
                $source = (string) file_get_contents($real);
                if (ContentFile::isPage($real)) {
                    // the source of a page, shown as code, still loses what this viewer may
                    // not see: raw page source never leaves the server (L-30, C-1)
                    $source = $this->hideForSource($source, $owner);
                }
                return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $inner = $this->postmeeting($this->load($real), $owner);
            $inner = $depth < self::MAX_INJECT_DEPTH ? $this->injects($inner, dirname($real), $depth + 1, $owner, [...$ancestors, $real]) : $inner;
            // its links, <calphp> and <showlink> are relative to the injected file, not the page (L-21)
            return dirname($real) === $dir ? $inner : self::rebase($inner, dirname($real), $dir);
        });
    }

    /** A page's source with the blocks this viewer may not see removed (for <codeinject>). */
    private function hideForSource(string $source, Place $place): string
    {
        $source = self::stripComments($source); // a commented-out block is still hidden content
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

    /** The visible place an injected file belongs to (an event's file before a page), or null. */
    private function injectOwner(string $real): ?Place
    {
        $owner = null;
        foreach ($this->course->byRealPath[$real] ?? [] as $key) {
            $candidate = $this->course->places[$key];
            if ($candidate->assetOf === null && $this->policy->decide($this->viewer, $candidate, $this->now)->visible
                && ($owner === null || ($owner->event === null && $candidate->event !== null))) {
                $owner = $candidate;
            }
        }
        return $owner;
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
        $html = self::replaceTags($html, self::LINK_ELEMENTS, static function (string $element, string $tag) use ($fix): string {
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
            }, $tag) ?? self::failed();
        });
        return self::replaceTags($html, ['calphp', 'showlink'], static function (string $name, string $tag) use ($fix): string {
            $src = self::attribute($tag, 'src');
            return $src === null ? $tag : '<' . $name . ' src="' . htmlspecialchars($fix($src)) . '">';
        });
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
        return self::replaceTags($html, ['calphp'], function (string $name, string $tag) use ($dir, $place): string {
            $m = [$tag];
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
        });
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
        $codes = [];
        foreach (self::tagsNamed($html, 'lock') as $tag) { // read with the lexer, so a code may contain ">" (L-45)
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
        // anywhere, also inside attribute values and scripts, as v4: the values are the course's own
        return self::valueTags($html, 'replace', static function (string $tag) use ($values): string {
            return htmlspecialchars($values[(string) self::attribute($tag, 'value')] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        });
    }

    /**
     * <url value="name" default="x">: from the page's URL (remembered in an existing session),
     * HTML-escaped.  In text and in quoted values of a few attributes (`href="<url ...>"`, as
     * v4), never where HTML escaping does not protect (M-28, M-32): see urlTags().
     */
    private function url(string $html): string
    {
        return self::urlTags($html, function (string $tag): string {
            $name = (string) self::attribute($tag, 'value');
            if (isset($this->urlValues[$name]) && !self::hasUnsafeScheme($this->urlValues[$name])) {
                $this->usedUrlValues[] = $name;
                $value = $this->urlValues[$name];
            } else {
                $value = (string) self::attribute($tag, 'default');
            }
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        });
    }

    /**
     * <replace> is written inside other tags' attribute values in v4 pages (`title="<replace
     * value=course>"`), where the lexer does not see it, so it is found by pattern, anywhere
     * (its values are the course's own); closing tags are removed.
     *
     * @param callable(string): string $fn
     */
    private static function valueTags(string $html, string $name, callable $fn): string
    {
        $out = preg_replace_callback('/<' . $name . '\b[^>]*>|<\/' . $name . '\s*>/i', static fn (array $m): string => $m[0][1] === '/' ? '' : $fn($m[0]), $html);
        return $out ?? self::failed();
    }

    /** Attributes where a value from a link may go (quoted only); href and src also check the scheme. */
    private const URL_ATTRIBUTES = ['href', 'src', 'title', 'alt', 'value', 'class', 'id', 'name', 'placeholder', 'label'];

    /**
     * Where each <url> tag may be replaced (M-32): every <url> is first swapped for a marker made
     * of letters and digits, so the tags around it lex as the browser will read them; then a
     * marker counts only in text, or in a quoted value of an attribute in URL_ATTRIBUTES (or
     * data-*), and in href or src only when the value before it has no scheme or http(s). A
     * marker in a script or style body (with the browser's escaped states, L-52), an unquoted
     * value, an event handler, style, srcdoc or anywhere else keeps the tag as written.
     *
     * @param callable(string): string $fn
     */
    private static function urlTags(string $html, callable $fn): string
    {
        if (preg_match_all('/<url\b[^>]*>/i', $html, $matches) === false) {
            self::failed();
        }
        if ($matches[0] === []) {
            return preg_replace('/<\/url\s*>/i', '', $html) ?? self::failed();
        }
        $tags = $matches[0];
        $marker = 'cal6url' . bin2hex(random_bytes(6)) . 'n';
        $counter = 0;
        $marked = preg_replace_callback('/<url\b[^>]*>/i', static function () use ($marker, &$counter): string {
            return $marker . ($counter++) . 'x';
        }, $html) ?? self::failed();
        $marked = preg_replace('/<\/url\s*>/i', '', $marked) ?? self::failed();
        $allowed = [];
        $pos = 0;
        $tokens = HtmlLexer::scan($marked);
        $count = count($tokens);
        foreach ($tokens as $t => $token) {
            if ($token[0] === 'tag' && !$token[4]) {
                $tag = substr($marked, $token[1], $token[2] - $token[1]);
                if (str_contains($tag, $marker)) {
                    foreach (HtmlLexer::attributes($tag) as [$attribute, $start, $end, $quoted]) {
                        if ($start < 0 || !$quoted || !(in_array($attribute, self::URL_ATTRIBUTES, true) || str_starts_with($attribute, 'data-'))) {
                            continue;
                        }
                        $value = substr($tag, $start, $end - $start);
                        if (preg_match_all('/' . $marker . '(\d+)x/', $value, $found, PREG_OFFSET_CAPTURE) === false) {
                            self::failed();
                        }
                        foreach ($found[1] as $k => [$number, $offset]) {
                            $before = substr($value, 0, $found[0][$k][1]);
                            if (in_array($attribute, ['href', 'src'], true) && self::hasUnsafeScheme(preg_replace('/' . $marker . '\d+x/', '', $before) . 'x')) {
                                continue;
                            }
                            $allowed[(int) $number] = true;
                        }
                    }
                }
            }
            // text between tokens (not a script/style body or a comment)
            if ($token[0] !== 'raw') {
                $textEnd = $token[1];
                self::allowMarkersIn(substr($marked, $pos, $textEnd - $pos), $marker, $allowed);
            }
            $pos = $token[2];
            if ($t === $count - 1) {
                self::allowMarkersIn(substr($marked, $pos), $marker, $allowed);
            }
        }
        if ($tokens === []) {
            self::allowMarkersIn($marked, $marker, $allowed);
        }
        // a script or style that is never closed runs to the end in a browser: nothing after it
        foreach ($tokens as $t => $token) {
            if ($token[0] === 'tag' && !$token[4] && in_array($token[3], ['script', 'style'], true) && ($tokens[$t + 1][0] ?? '') !== 'raw') {
                if (preg_match_all('/' . $marker . '(\d+)x/', substr($marked, $token[2]), $after) !== false) {
                    foreach ($after[1] as $number) {
                        unset($allowed[(int) $number]);
                    }
                }
                break;
            }
        }
        return preg_replace_callback('/' . $marker . '(\d+)x/', static function (array $m) use ($tags, $allowed, $fn): string {
            $n = (int) $m[1];
            return isset($allowed[$n]) ? $fn($tags[$n]) : $tags[$n];
        }, $marked) ?? self::failed();
    }

    /** @param array<int, true> $allowed */
    private static function allowMarkersIn(string $text, string $marker, array &$allowed): void
    {
        if ($text !== '' && preg_match_all('/' . $marker . '(\d+)x/', $text, $found) > 0) {
            foreach ($found[1] as $number) {
                $allowed[(int) $number] = true;
            }
        }
    }

    /** A value that would make a link run script (javascript:, data:, vbscript:, ...): only http(s) and plain text pass (L-39). */
    private static function hasUnsafeScheme(string $value): bool
    {
        $clean = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return (bool) preg_match('/^[a-z][a-z0-9+.-]*:/', $clean) && !preg_match('/^https?:/', $clean);
    }

    private function showlinks(string $html, string $dir, Place $place): string
    {
        return self::replaceTags($html, ['showlink'], function (string $name, string $tag) use ($dir, $place): string {
            $src = (string) self::attribute($tag, 'src');
            $target = $this->visiblePlace($place, $dir, $src);
            $text = htmlspecialchars(basename($src), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $target === null ? $text : '<a href="' . htmlspecialchars($this->urlFor($target, 'a')) . '">' . $text . '</a>';
        });
    }

    private const LINK_ELEMENTS = ['a', 'img', 'source', 'video', 'audio', 'track', 'iframe', 'embed', 'object', 'script', 'link'];
    private const LINK_ATTRIBUTES = '/(\s)(href|src|poster|data|srcset)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';

    /** The relative URLs a page links to (without #fragment or ?query). @return list<string> */
    public static function linkTargets(string $html): array
    {
        $links = [];
        $tags = [];
        foreach (self::LINK_ELEMENTS as $element) {
            array_push($tags, ...self::tagsNamed($html, $element));
        }
        foreach ($tags as $tag) {
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
        return self::replaceTags($html, self::LINK_ELEMENTS, function (string $element, string $tag) use ($dir, $place): string {
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
            }, $tag) ?? self::failed();
        });
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
        // a file this viewer may not see and a file that does not exist get the same answer, so
        // links (also ones filled in by <url>) can not tell whether a path exists (L-51)
        return null;
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
        // real <a> tags only, read by the lexer (L-54)
        return self::replaceTags($html, ['a'], static function (string $name, string $tag): string {
            $attributes = [];
            foreach (HtmlLexer::attributes($tag) as [$attribute, $start, $end]) {
                $attributes[$attribute] ??= $start < 0 ? '' : substr($tag, $start, $end - $start);
            }
            if (!isset($attributes['name']) || isset($attributes['id'])) {
                return $tag;
            }
            $id = htmlspecialchars(html_entity_decode($attributes['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<a id="' . $id . '"' . substr($tag, 2);
        });
    }

    /**
     * Replaces each <tag ...>inner</tag> block with $replace(inner, openingTag), without
     * regular expressions (H-4, M-13, L-29, L-34, L-36, L-37):
     *
     * - tags come from HtmlLexer, so tags inside comments, quoted attribute values and
     *   <script>/<style> bodies are not tags, as in a browser;
     * - nested blocks of the same tag are matched with a stack and the inner content is
     *   processed first, so <inst>A<inst>B</inst>C</inst> is one block;
     * - a block that is never closed runs to the end of the page (fail closed); a closing
     *   tag with no opening one is left as text;
     * - linear time and no recursion, at any size and depth.
     *
     * @param callable(string, string): string $replace
     */
    public static function block(string $html, string $tag, callable $replace): string
    {
        $tag = strtolower($tag);
        $buffers = [''];
        $openings = [];
        $pos = 0;
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] !== 'tag' || $token[3] !== $tag) {
                continue;
            }
            [, $start, $end, , $closing] = $token;
            $buffers[count($buffers) - 1] .= substr($html, $pos, $start - $pos);
            if (!$closing) {
                $openings[] = substr($html, $start, $end - $start);
                $buffers[] = '';
            } elseif ($openings !== []) {
                $inner = array_pop($buffers);
                $buffers[count($buffers) - 1] .= $replace($inner, array_pop($openings));
            } else {
                $buffers[count($buffers) - 1] .= substr($html, $start, $end - $start);
            }
            $pos = $end;
        }
        $buffers[count($buffers) - 1] .= substr($html, $pos);
        while ($openings !== []) { // never closed: to the end of the page
            $inner = array_pop($buffers);
            $buffers[count($buffers) - 1] .= $replace($inner, array_pop($openings));
        }
        return $buffers[0];
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
