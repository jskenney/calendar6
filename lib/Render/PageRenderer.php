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
    /** Marks a value filled in by <url> until links are rewritten (a private-use character). */
    private const URL_MARK = "\u{E000}";
    /** Deeper blocks of one tag are dropped with their content, so kept nesting stays linear (L-67). */
    private const MAX_BLOCK_DEPTH = 256;

    private int $injectedBytes = 0;

    /** @var list<string> <url> values that were used, to remember in the session */
    public array $usedUrlValues = [];
    /** @var array<string, string> placeholder => <calphp> output */
    private array $outputs = [];
    private ?string $outputKey = null;

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

    /**
     * The page as this viewer may see it before locks: injected, with <postmeeting>, <inst>,
     * <host> and comments handled, so lock codes are taken only from <lock>s this viewer can
     * reach (a code inside a hidden block is not accepted, L-91).
     */
    public function visibleSource(Place $place): string
    {
        $html = $this->expandedSource($place);
        for ($round = 0; ; $round++) {
            $before = $html;
            $html = $this->postmeeting($html, $place);
            $html = self::block($html, 'inst', fn (string $inner): string => $this->viewer->instructor ? $inner : '');
            $html = $this->host($html);
            $html = self::stripComments($html);
            if ($html === $before) {
                return $html;
            }
            if ($round >= 8) {
                self::failed();
            }
        }
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
        // hiding runs until nothing changes: removing a block or a comment can join the text
        // around it into a new comment or block (A<<inst>x</inst>!-- ... -->), L-61
        for ($round = 0; ; $round++) {
            $before = $html;
            $html = $this->postmeeting($html, $place);
            $html = self::block($html, 'inst', fn (string $inner): string => $this->viewer->instructor ? $inner : '');
            $html = $this->host($html);
            $html = $this->lock($html);
            $html = self::stripComments($html);
            if ($html === $before) {
                break;
            }
            if ($round >= 8) {
                self::failed();
            }
        }
        $html = $this->hideInAttributes($html, $place);
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
        $html = str_replace(self::URL_MARK, '', $html);
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
        $range = [-1, -1];
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] === 'bounded') {
                // only these show their content as text, and not inside <svg>/<math> (L-89)
                $range = in_array($token[3], ['textarea', 'xmp', 'title'], true) && empty($token[4]) ? [$token[1], $token[2]] : [-1, -1];
                continue;
            }
            if ($token[0] === 'comment') {
                // inside a textarea, xmp, title, ... a comment is text the reader sees (starter
                // code): it stays, unless it holds a block tag (L-82)
                if ($token[1] >= $range[0] && $token[2] <= $range[1]
                    && !preg_match('/<\/?(?:inst|lock|postmeeting|host|student)(?=[\s>\/]|$)/i', substr($html, $token[1], $token[2] - $token[1]))) {
                    continue;
                }
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
                    $source = $this->hideForSource($source, $owner, $real);
                }
                return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $inner = $this->postmeeting($this->load($real), $owner);
            // hidden blocks in its attribute values follow its own place too (L-87)
            $inner = $this->hideInAttributes($inner, $owner);
            $inner = $depth < self::MAX_INJECT_DEPTH ? $this->injects($inner, dirname($real), $depth + 1, $owner, [...$ancestors, $real]) : $inner;
            if (dirname($real) === $dir) {
                return $inner;
            }
            // its links, <calphp> and <showlink> are relative to the injected file, not the page
            // (L-21); <replace> is filled in first, so src="logo-<replace ...>.png" is rebased as
            // its value makes it (L-90); <url> waits for the whole page, so its context (an
            // unclosed script, say) is the page's (L-97), and rebase keeps its tags
            return self::rebase($this->replace($inner, $place), dirname($real), $dir);
        });
    }

    /** A page's source with the blocks this viewer may not see removed (for <codeinject>). */
    private function hideForSource(string $source, Place $place, string $path): string
    {
        // Markdown code is set aside first, so a code sample can not hide what follows (L-69)
        $codes = [];
        if (preg_match('/\.md$/i', $path)) {
            [$source, $codes] = Markdown::maskCode($source);
        }
        // until nothing changes, as render() does (L-61, L-69); the code set aside is hidden in
        // too, since the page's own reading of it may differ (a list item's indented line is
        // text there): fail closed (L-102)
        $source = $this->hideForSourceToFixedPoint($source, $place);
        foreach ($codes as $key => $code) {
            $codes[$key] = $this->hideForSourceToFixedPoint($code, $place);
        }
        return strtr($source, $codes);
    }

    private function hideForSourceToFixedPoint(string $source, Place $place): string
    {
        for ($round = 0; ; $round++) {
            $before = $source;
            $source = $this->hideForSourceOnce($source, $place);
            if ($source === $before) {
                return $source;
            }
            if ($round >= 8) {
                self::failed();
            }
        }
    }

    private function hideForSourceOnce(string $source, Place $place): string
    {
        $source = self::stripComments($source); // a commented-out block is still hidden content
        $source = $this->hideInAttributes($source, $place); // and so is one in an attribute (L-86)
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
        // outside every event directory, compared by real path, so a type directory that is a
        // link to elsewhere in the course still counts (L-83)
        $first = explode('/', $this->course->boundary->relative($real))[0];
        if (isset($this->course->config->types[$first])) {
            return false;
        }
        foreach (array_keys($this->course->config->types) as $type) {
            $typeDir = Boundary::realpath($this->course->boundary->root() . '/' . $type);
            if ($typeDir !== false && str_starts_with($real, $typeDir . '/')) {
                return false;
            }
        }
        return true;
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
            // a value from <url> keeps its mark where it was, path or fragment (link rewriting
            // needs it, M-36)
            $cut = strcspn($url, '?#');
            [$path, $suffix] = [substr($url, 0, $cut), substr($url, $cut)];
            if (stripos($path, '<url') !== false) {
                return $url; // a path from <url> is relative to the page (it is filled in later)
            }
            $mark = str_contains($path, self::URL_MARK) ? self::URL_MARK : '';
            $path = str_replace(self::URL_MARK, '', $path);
            if ($path === '' || self::isAbsolute($path . str_replace(self::URL_MARK, '', $suffix))) {
                return $url;
            }
            return $mark . self::relativePath($toDir, $fromDir . '/' . $path) . $suffix;
        };
        $html = self::replaceTags($html, self::LINK_ELEMENTS, static function (string $element, string $tag) use ($fix): string {
            return self::editLinks($tag, static function (string $attribute, string $value) use ($fix): string {
                if ($attribute === 'srcset') {
                    return implode(', ', array_map(static function (string $candidate) use ($fix): string {
                        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [''];
                        return trim($fix($parts[0]) . ' ' . ($parts[1] ?? ''));
                    }, explode(',', $value)));
                }
                return $fix($value);
            }, true);
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
            // a placeholder no page text or <url> value can contain: random for each render (L-60)
            $this->outputKey ??= bin2hex(random_bytes(12));
            $placeholder = "\x00cal6-calphp-" . $this->outputKey . '-' . count($this->outputs) . "\x00";
            $this->outputs[$placeholder] = $output;
            return $placeholder;
        });
    }

    /** <postmeeting>: once the last section's meeting that day is over (instructors always). */
    /**
     * Hidden blocks written inside an attribute value (a tooltip: title="Hint: <inst>42</inst>")
     * are removed for viewers who may not see them, as Markdown does for alt text (L-79); the
     * tags themselves go in any case, so a value never carries a block tag.
     */
    private function hideInAttributes(string $html, Place $place): string
    {
        if (!preg_match('/<\/?(?:inst|lock|postmeeting|host)(?=[\s>\/])/i', $html)) {
            return $html;
        }
        $out = '';
        $pos = 0;
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] !== 'tag' || $token[4]) {
                continue;
            }
            $tag = substr($html, $token[1], $token[2] - $token[1]);
            if (!preg_match('/<\/?(?:inst|lock|postmeeting|host)(?=[\s>\/])/i', substr($tag, 1))) {
                continue;
            }
            $new = '';
            $at = 0;
            foreach (HtmlLexer::attributes($tag) as [, $start, $end]) {
                if ($start < 0) {
                    continue;
                }
                $value = substr($tag, $start, $end - $start);
                $hidden = $this->postmeeting($value, $place);
                $hidden = self::block($hidden, 'inst', fn (string $inner): string => $this->viewer->instructor ? $inner : '');
                $hidden = $this->host($hidden);
                $hidden = self::block($hidden, 'lock', function (string $inner, string $opening): string {
                    $codes = array_values(array_filter(array_map('trim', explode(',', (string) self::attribute($opening, 'code'))), static fn (string $c): bool => $c !== ''));
                    foreach ($this->unlocked as $entered) {
                        if (self::codeMatches($entered, $codes)) {
                            return $inner;
                        }
                    }
                    return $this->viewer->instructor ? $inner : '';
                });
                $new .= substr($tag, $at, $start - $at) . $hidden;
                $at = $end;
            }
            $out .= substr($html, $pos, $token[1] - $pos) . $new . substr($tag, $at);
            $pos = $token[2];
        }
        return $out . substr($html, $pos);
    }

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
            // marked, so link rewriting knows the visitor chose this value (M-36); the marks
            // are removed once links are rewritten
            return self::URL_MARK . htmlspecialchars(str_replace(["\0", self::URL_MARK], '', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        $out = '';
        $pos = 0;
        foreach (self::findValueTags($html, $name) as [$start, $end, $closing]) {
            $out .= substr($html, $pos, $start - $pos) . ($closing ? '' : $fn(substr($html, $start, $end - $start)));
            $pos = $end;
        }
        return $out . substr($html, $pos);
    }

    /**
     * The <name ...> and </name> tags of a value tag, each ending where a browser would end it
     * (a ">" inside a quoted value does not end it, L-73). @return list<array{0: int, 1: int, 2: bool}>
     */
    private static function findValueTags(string $html, string $name): array
    {
        if (preg_match_all('/<\/?' . $name . '(?=[\t\n\f\r \/>]|$)/i', $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            self::failed();
        }
        $found = [];
        $after = 0;
        foreach ($matches[0] as [$text, $start]) {
            if ($start < $after) {
                continue; // inside the previous tag
            }
            $end = HtmlLexer::tagEnd($html, $start);
            $found[] = [$start, $end, $text[1] === '/'];
            $after = $end;
        }
        return $found;
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
        $tags = [];
        $marker = 'cal6url' . bin2hex(random_bytes(6)) . 'n';
        $marked = '';
        $pos = 0;
        foreach (self::findValueTags($html, 'url') as [$start, $end, $closing]) {
            $marked .= substr($html, $pos, $start - $pos);
            if (!$closing) {
                $marked .= $marker . count($tags) . 'x';
                $tags[] = substr($html, $start, $end - $start);
            }
            $pos = $end;
        }
        $marked .= substr($html, $pos);
        if ($tags === []) {
            return $marked;
        }
        $allowed = [];
        $pos = 0;
        // the body of a textarea, title, noscript, ... is text in one reading and markup in the
        // other: no values there (M-37)
        $bounded = [];
        $tokens = [];
        $all = HtmlLexer::scan($marked);
        foreach ($all as $token) {
            if ($token[0] === 'bounded') {
                $bounded[] = [$token[1], $token[2]];
            } else {
                $tokens[] = $token;
            }
        }
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
                        if (preg_match_all('/' . $marker . '(\d+)x/', $value, $found) === false) {
                            self::failed();
                        }
                        if (in_array($attribute, ['href', 'src'], true)) {
                            // the whole value as it will be sent decides the scheme, so two
                            // values side by side can not spell javascript: (M-34)
                            $final = preg_replace_callback('/' . $marker . '(\d+)x/', static fn (array $m): string => str_replace(self::URL_MARK, '', $fn($tags[(int) $m[1]])), $value) ?? self::failed();
                            if (self::hasUnsafeScheme($final)) {
                                continue;
                            }
                        }
                        foreach ($found[1] as $number) {
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
        foreach ($bounded as [$from, $to]) {
            if (preg_match_all('/' . $marker . '(\d+)x/', substr($marked, $from, $to - $from), $inside) !== false) {
                foreach ($inside[1] as $number) {
                    unset($allowed[(int) $number]);
                }
            }
        }
        // a script or style that is never closed runs to the end in a browser: nothing after it
        // (a script or style inside a textarea or noscript body, or one read as bounded inside an
        // svg, is not one: L-103)
        $boundedEnd = -1; // the end of the bounded bodies seen so far (tokens come in order, L-108)
        foreach ($all as $t => $token) {
            if ($token[0] === 'bounded') {
                $boundedEnd = max($boundedEnd, $token[2]);
                continue;
            }
            if ($token[0] !== 'tag' || $token[4] || !in_array($token[3], ['script', 'style'], true)
                || in_array($all[$t + 1][0] ?? '', ['raw', 'bounded'], true)) {
                continue;
            }
            if ($token[1] >= $boundedEnd) {
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
    private const LINK_ATTRIBUTES = ['href', 'src', 'poster', 'data', 'srcset'];

    /**
     * The link attributes of a tag, read by HtmlLexer::attributes() as the browser reads them
     * (H-6: never a pattern over the tag text, which would find " src=" inside a title).
     * $fn gets the lower-case name and the decoded value and returns the new value, or null to
     * remove the attribute (data-cal6-hidden="1" is added once in its place).
     *
     * @param callable(string, string): ?string $fn
     */
    private static function editLinks(string $tag, callable $fn, bool $keepUrlTags = false): string
    {
        $out = '';
        $pos = 0;
        $hidden = false;
        foreach (HtmlLexer::attributes($tag) as [$name, $start, $end, , $nameStart, $attributeEnd]) {
            if ($start < 0 || !in_array($name, self::LINK_ATTRIBUTES, true)) {
                continue;
            }
            $value = html_entity_decode(substr($tag, $start, $end - $start), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $new = $fn($name, $value);
            if ($new === $value) {
                continue;
            }
            $out .= substr($tag, $pos, $nameStart - $pos);
            if ($new === null) {
                $out .= $hidden ? '' : 'data-cal6-hidden="1"';
                $hidden = true;
            } else {
                $escaped = htmlspecialchars($new, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                // when rebasing an injected file (the author's text, before <url> runs), a <url>
                // tag waiting in the value stays a tag; never later, where a visitor's value is in it
                if ($keepUrlTags) {
                    $escaped = preg_replace_callback('/&lt;\/?url\b.*?&gt;/i', static fn (array $m): string => html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $escaped) ?? $escaped;
                }
                $out .= $name . '="' . $escaped . '"';
            }
            $pos = $attributeEnd;
        }
        return $out . substr($tag, $pos);
    }

    /** The link attributes of a tag, decoded. @return list<array{0: string, 1: string}> */
    private static function linkValues(string $tag): array
    {
        $values = [];
        foreach (HtmlLexer::attributes($tag) as [$name, $start, $end]) {
            if ($start >= 0 && in_array($name, self::LINK_ATTRIBUTES, true)) {
                $values[] = [$name, html_entity_decode(substr($tag, $start, $end - $start), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            }
        }
        return $values;
    }

    /** The relative URLs a page links to (without #fragment or ?query). @return list<string> */
    public static function linkTargets(string $html): array
    {
        $links = [];
        $tags = [];
        foreach (self::LINK_ELEMENTS as $element) {
            array_push($tags, ...self::tagsNamed($html, $element));
        }
        foreach ($tags as $tag) {
            foreach (self::linkValues($tag) as [$attribute, $value]) {
                $values = $attribute === 'srcset' ? array_map(static fn (string $c): string => preg_split('/\s+/', trim($c))[0] ?? '', explode(',', $value)) : [$value];
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
            return self::editLinks($tag, function (string $attribute, string $value) use ($dir, $place, $element): ?string {
                if ($attribute === 'srcset') {
                    $kept = [];
                    foreach (explode(',', $value) as $candidate) {
                        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [''];
                        $url = $this->rewrite($parts[0], $dir, $place, $element);
                        if ($url !== null) {
                            $kept[] = trim($url . ' ' . ($parts[1] ?? ''));
                        }
                    }
                    return implode(', ', $kept);
                }
                return $this->rewrite($value, $dir, $place, $element);
            });
        });
    }

    /** The URL to use for a link: unchanged, a key URL, or null when it leads to a file the viewer may not see. */
    private function rewrite(string $value, string $dir, Place $place, string $element): ?string
    {
        // a value filled in by <url> carries the marker: the visitor chose this target (M-36)
        // (only a value in the path counts: page.html#<url ...> still links to page.html)
        $fromUrl = str_contains(substr($value, 0, strcspn($value, '?#')), self::URL_MARK);
        $value = str_replace(self::URL_MARK, '', $value);
        if ($value === '' || self::isAbsolute($value)) {
            return $value;
        }
        $fragment = '';
        if (($hash = strpos($value, '#')) !== false) {
            $fragment = substr($value, $hash);
            $value = substr($value, 0, $hash);
        }
        [$path, $query] = array_pad(explode('?', $value, 2), 2, '');
        $target = $path === '' ? null : $this->course->placeForPath($place, $dir, $path);
        if ($target !== null && $fromUrl && !$this->hasOwnRule($target)) {
            // a target chosen through <url> may only be a file with a rule of its own, as
            // calendar.php lists them: never one that borrows a page's rule (M-36)
            return null;
        }
        if ($target !== null && !$this->policy->decide($this->viewer, $target, $this->now)->visible) {
            return null;
        }
        if ($target !== null && $target->file->kind() === 'page' && !in_array($element, ['a', 'iframe', 'embed', 'object'], true)) {
            return null; // a page can not be an image, script or style sheet: get.php never sends pages
        }
        if ($target !== null) {
            return $this->urlFor($target, $element, $query) . $fragment;
        }
        if ($fromUrl) {
            return null; // missing, outside the course or never served: all look alike (L-51)
        }
        // a link the author wrote: a file of the course without a key (one never served) loses
        // its target; a missing file, or a path outside the course (or in config/ or calendar/),
        // is left as written, as in Calendar 4, and says nothing about the disk outside it
        $inside = $this->course->boundary->resolve($dir . '/' . rawurldecode($path));
        return $inside !== null ? null : $value . $fragment;
    }

    /** An event's own file or a paths.ini page: the places calendar.php lists. */
    private function hasOwnRule(Place $target): bool
    {
        if ($target->event !== null) {
            return $target->file->topLevel && $target->assetOf === null && !Boundary::isUnlisted($target->file->name);
        }
        return $target->page !== null && !$target->asset && $target->assetOf === null
            && ($this->course->pageKeys[$target->page] ?? null) === $target->key;
    }

    private static function isAbsolute(string $url): bool
    {
        return (bool) preg_match('#^([a-z][a-z0-9+.-]*:|/|\#|\?)#i', $url);
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

    private function urlFor(Place $target, string $element, string $query = ''): string
    {
        if ($target->file->kind() === 'page') {
            // pages are only ever served by html.php (C-1): links open them in the front end,
            // frames get the whole processed document. A link's query string goes along, so
            // notes.html?week=3 fills <url value="week"> on that page (L-75); html.php's own
            // parameters can not be set this way
            $kept = [];
            foreach ($query === '' ? [] : explode('&', $query) as $pair) {
                // the name as PHP will read it: cut at NUL, "name[...]" is "name", leading spaces
                // go and space or "." become "_" (file%00x=, file[]=, L-104)
                $name = explode("\0", urldecode(explode('=', $pair, 2)[0]), 2)[0];
                $name = strtolower(strtr(explode('[', ltrim($name, ' '), 2)[0], ' .', '__'));
                if ($pair !== '' && !in_array($name, ['file', 'full', 'lock', 'csrf'], true)) {
                    $kept[] = $pair;
                }
            }
            $query = implode('&', $kept);
            return $element === 'a' && !$this->full
                ? '#/view/' . rawurlencode($target->key) . ($query !== '' ? '?' . $query : '')
                : $this->baseUrl . 'calendar/php/html.php?file=' . rawurlencode($target->key) . '&full=1' . ($query !== '' ? '&' . $query : '');
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
    /**
     * Where "<$tag" stands in a tag's attribute name or unquoted value (not inside quotes), as an
     * offset in $tagText, or null.
     */
    private static function blockInAttributes(string $tagText, string $tag): ?int
    {
        if (stripos($tagText, '<' . $tag, 1) === false) {
            return null;
        }
        foreach (HtmlLexer::attributes($tagText) as [, $start, $end, $quoted, $nameStart, $attributeEnd]) {
            $spans = [[$nameStart, $start < 0 ? $attributeEnd : $start]];
            if ($start >= 0 && !$quoted) {
                $spans[] = [$start, $end];
            }
            foreach ($spans as [$from, $to]) {
                if (preg_match('/<' . preg_quote($tag, '/') . '(?=[\t\n\f\r \/>]|$)/i', substr($tagText, $from, $to - $from) . ' ', $m, PREG_OFFSET_CAPTURE)) {
                    return $from + $m[0][1];
                }
            }
        }
        return null;
    }

    public static function block(string $html, string $tag, callable $replace): string
    {
        $tag = strtolower($tag);
        $buffers = [''];
        $openings = [];
        $pos = 0;
        $skip = 0; // blocks nested deeper than MAX_BLOCK_DEPTH are dropped with their content (L-67)
        $range = [-1, -1, -1];   // the bounded element (textarea, title, ...) being read: id, start, end
        $ranges = [];            // for each open block, the bounded element it was opened in (-1: none)
        $rangeCount = 0;
        // a closing tag counts only where both readings see it: with bounded elements and
        // without them (a <title> inside <svg> is markup, M-39)
        $plainClosings = [];
        foreach (HtmlLexer::scan($html, false) as $token) {
            if ($token[0] === 'tag' && $token[4] && $token[3] === $tag) {
                $plainClosings[$token[1]] = true;
            }
        }
        foreach (HtmlLexer::scan($html) as $token) {
            if ($token[0] === 'bounded') {
                $range = [$rangeCount++, $token[1], $token[2]];
                continue;
            }
            if ($token[0] === 'tag' && !$token[4] && $token[3] !== $tag && $skip === 0
                && ($at = self::blockInAttributes(substr($html, $token[1], $token[2] - $token[1]), $tag)) !== null) {
                // a block tag typed where attributes are (a missing ">": <div class="x"<inst>S</inst>)
                // opens a block all the same, as Calendar 4's patterns did (fail closed, L-110)
                $start = $token[1] + $at;
                $buffers[count($buffers) - 1] .= substr($html, $pos, $start - $pos);
                $openings[] = '<' . $tag . '>';
                $ranges[] = -1;
                $buffers[] = '';
                $pos = $start + strlen($tag) + 1;
                continue;
            }
            if ($token[0] !== 'tag' || $token[3] !== $tag) {
                continue;
            }
            [, $start, $end, , $closing] = $token;
            $in = $start >= $range[1] && $start < $range[2] ? $range[0] : -1;
            // a closing tag inside a bounded element is text to a browser: it closes only a block
            // opened inside the same element, never one opened outside it (M-39)
            if ($closing && (!isset($plainClosings[$start]) || ($in >= 0 && $skip === 0 && (end($ranges) === false || end($ranges) !== $in)))) {
                continue;
            }
            if ($skip > 0) {
                $skip += $closing ? -1 : 1;
                $pos = $end;
                continue;
            }
            $buffers[count($buffers) - 1] .= substr($html, $pos, $start - $pos);
            if (!$closing && count($openings) >= self::MAX_BLOCK_DEPTH) {
                $skip = 1;
                $pos = $end;
                continue;
            }
            if (!$closing) {
                $openings[] = substr($html, $start, $end - $start);
                $ranges[] = $in;
                $buffers[] = '';
            } elseif ($openings !== []) {
                $inner = array_pop($buffers);
                array_pop($ranges);
                $buffers[count($buffers) - 1] .= $replace($inner, array_pop($openings));
            } else {
                $buffers[count($buffers) - 1] .= substr($html, $start, $end - $start);
            }
            $pos = $end;
        }
        if ($skip === 0) {
            $buffers[count($buffers) - 1] .= substr($html, $pos);
        }
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
        // read as a browser reads the tag: " src=" inside another attribute's value is not src (H-6)
        $name = strtolower($name);
        foreach (HtmlLexer::attributes($tag) as [$attribute, $start, $end]) {
            if ($attribute === $name) {
                return $start < 0 ? '' : html_entity_decode(substr($tag, $start, $end - $start), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return null;
    }
}
