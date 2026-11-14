<?php

declare(strict_types=1);

namespace Cal6\Course;

use Cal6\Access\Rules;
use Cal6\Ini\IniLine;
use Cal6\Access\Target;
use Cal6\Content\ContentFile;
use Cal6\Content\Scanner;
use Cal6\Render\Markdown;
use Cal6\Render\PageRenderer;
use Cal6\Schedule\CalendarBuilder;
use Cal6\Schedule\Day;
use Cal6\Schedule\ScheduledEvent;
use Cal6\Support\Problem;
use InvalidArgumentException;

/**
 * The course model: configuration, the calendar, every event with its files and
 * boxes, the pages, the release rules, and the key of every file.
 */
final class Course
{
    public CourseConfig $config;
    public Boundary $boundary;
    /** @var array<string, Day> */
    public array $days = [];
    /** @var array<string, ScheduledEvent> */
    public array $events = [];
    /** @var array<string, Place> by key */
    public array $places = [];
    /** @var array<string, array<string, string>> event id => file name => key */
    public array $keysByEvent = [];
    /** @var array<string, string> page name => key */
    public array $pageKeys = [];
    /** @var array<string, list<string>> real path => keys of the places that serve it */
    public array $byRealPath = [];
    public Rules $rules;
    public bool $rulesUnreadable = false;
    /** @var list<Problem> */
    public array $problems = [];

    public static function load(string $courseDir, ?CourseConfig $config = null): self
    {
        $course = new self();
        $course->config = $config ?? CourseConfig::load($courseDir);
        $course->boundary = new Boundary($courseDir);
        $scanner = new Scanner($course->config, $course->boundary);
        $folders = $scanner->scan();
        $calendar = (new CalendarBuilder($course->config, $folders))->build();
        $course->days = $calendar->days;
        $course->events = $calendar->events;
        $course->problems = [...$course->config->problems, ...$scanner->problems, ...$calendar->problems];
        $course->addVirtualFiles();
        $course->indexPlaces();

        $default = $course->config->ini('security.ini');
        // no usable release rules: anonymous viewers see nothing (fail closed), also when the
        // file is missing or misnamed (M-19)
        $course->rulesUnreadable = !$default->exists || !$default->readable;
        if (!$default->exists) {
            // it was default.ini: an old file is reported, not read (fail closed)
            $course->problems[] = is_file($course->config->configDir() . '/' . 'default' . '.ini')
                ? new Problem('default.ini is now called security.ini: rename it (it is not read; nothing is shown to anonymous viewers until then)', 'default' . '.ini')
                : new Problem('config/security.ini is missing: nothing is shown to anonymous viewers until it exists', 'security.ini');
        }
        // security.ini has no sections: a line under a header such as [rules] is still a rule
        // (dropping it would publish what it hides), in file order, and the header is a
        // problem (M-33)
        // an old-style line without "=" (lab 99 99 9999, exam never) hides its first word's
        // target, as an unreadable time does (H-2): dropping it would publish what it hides (L-74)
        $lines = $default->lines;
        foreach ($default->unreadable as $bad) {
            // Calendar 4 separated with tabs, so a path may hold spaces (L-80)
            $target = trim(str_contains($bad->key, "\t") ? explode("\t", $bad->key, 2)[0] : (preg_split('/\s+/', $bad->key, 2)[0] ?? ''));
            if ($target !== '') {
                $lines[] = new IniLine($bad->section, $target, 'never', $bad->line);
                $course->problems[] = new Problem("$target is hidden until line $bad->line is written as \"target = when\"", 'security.ini', $bad->line);
            }
        }
        usort($lines, static fn (IniLine $a, IniLine $b): int => $a->line <=> $b->line);
        $course->rules = Rules::fromLines($lines, $default->label);
        array_unshift($course->rules->problems, ...$default->problems);
        foreach ($default->sections() as $section) {
            if ($section !== '') {
                $course->problems[] = new Problem("[$section]: security.ini has no sections; the lines under it are read as rules all the same", 'security.ini');
            }
        }
        array_push($course->problems, ...$course->rules->problems);
        // a rule for an event that does not exist (class_40 in a course of 30) does nothing:
        // say so, as it is most likely a typo (M-35)
        foreach ($course->rules->byTarget as $rule) {
            if (preg_match('/^([a-z][a-z0-9-]*)_(\d+)(?:\/|$)/i', $rule->target, $m) && $course->config->typeName($m[1]) !== null
                && !isset($course->events[$course->config->typeName($m[1]) . '_' . (int) $m[2]])) {
                $course->problems[] = new Problem("$rule->target: there is no {$m[1]} {$m[2]}, so this rule does nothing", 'security.ini', $rule->line);
            }
        }
        // a rule (or grant) whose target matches no file, event, box or page does nothing:
        // a typo such as labs = never must not pass silently (M-45)
        // (every type, box and event the course has counts, also with no file yet; only a rule
        // naming a file needs that file)
        $known = ['*' => true];
        foreach (array_keys($course->config->types) as $type) {
            foreach ([$type, "$type/*"] as $target) {
                $known[Rules::key($target)] = true;
            }
            foreach ($course->config->boxes as $box) {
                $known[Rules::key("$type/{$box['box']}")] = true;
                $known[Rules::key("*/{$box['box']}")] = true;
            }
        }
        foreach ($course->events as $event) {
            foreach (["{$event->type}_{$event->number}", "{$event->type}_{$event->number}/all", "{$event->type}_{$event->number}/*"] as $target) {
                $known[Rules::key($target)] = true;
            }
            foreach ($course->config->boxes as $box) {
                $known[Rules::key("{$event->type}_{$event->number}/{$box['box']}")] = true;
            }
        }
        foreach (array_keys($course->config->pages) as $page) {
            $known[Rules::key("page/$page")] = true;
        }
        $policy = new \Cal6\Access\Policy($course);
        foreach ($course->places as $place) {
            foreach ($policy->candidateLists($place) as [$targets]) {
                foreach ($targets as $target) {
                    $known[Rules::key($target)] = true;
                }
            }
        }
        $unmatched = static function (Rules $rules, string $label) use ($known, $course): void {
            foreach ($rules->byTarget as $key => $rule) {
                if (isset($known[(string) $key])) {
                    continue;
                }
                if (preg_match('/^([a-z][a-z0-9-]*)_(\d+)(?:\/|$)/i', $rule->target, $m) && ($type = $course->config->typeName($m[1])) !== null
                    && !isset($course->events[$type . '_' . (int) $m[2]])) {
                    if ($label !== 'security.ini') { // security.ini's are listed above; a grant's here (L-119)
                        $course->problems[] = new Problem("$rule->target: there is no {$m[1]} {$m[2]}, so this grant does nothing", $label, $rule->line);
                    }
                    continue;
                }
                if (preg_match('/^([a-z][a-z0-9-]*)_0\d*(?:\/|$)/i', $rule->target, $m) && $course->config->typeName($m[1]) !== null) {
                    continue; // class_01: the name as written, kept beside class_1 (L-65), which is checked
                }
                $course->problems[] = new Problem("$rule->target matches no file, event, box or page, so this line does nothing (a typo?)", $label, $rule->line);
            }
        };
        $unmatched($course->rules, 'security.ini');
        foreach (glob($course->config->configDir() . '/auth/*.ini') ?: [] as $file) {
            $account = \Cal6\Access\Viewer::account($course->config, basename($file, '.ini'));
            if ($account !== null) {
                $unmatched($account->grants, 'auth/' . basename($file));
            }
        }
        foreach ($course->rules->overridden as $note) {
            $course->problems[] = new Problem($note, 'security.ini');
        }
        return $course;
    }

    private function addVirtualFiles(): void
    {
        $ini = $this->config->ini('virtual.ini');
        array_push($this->problems, ...$ini->problems);
        foreach ($ini->lines as $line) {
            try {
                $real = $this->boundary->resolveRelative($line->value);
                if ($real === null || !is_file($real) || Boundary::neverServed($real)) {
                    throw new InvalidArgumentException("the real file is missing or outside the course: $line->value");
                }
                $slash = strpos($line->key, '/');
                if ($slash === false) {
                    throw new InvalidArgumentException('expected "event/name = real file"');
                }
                $name = substr($line->key, $slash + 1);
                if ($name === '' || str_contains($name, '..') || $name[0] === '.' || $name[0] === '/') {
                    throw new InvalidArgumentException("not a file name: $name");
                }
                foreach (Target::expand(substr($line->key, 0, $slash)) as $target) {
                    foreach ($this->events as $event) {
                        // the event, its "same" repeats, and its numbered copies (L-33)
                        if (strcasecmp($event->target(), $target) === 0 || ($event->copyOf !== null && strcasecmp($event->copyOf, $target) === 0)) {
                            $event->files[$name] = new ContentFile($name, $real, (int) filesize($real), (int) filemtime($real),
                                !str_contains($name, '/'), $this->boundary->relative($real));
                        }
                    }
                }
            } catch (InvalidArgumentException $e) {
                $this->problems[] = new Problem($e->getMessage(), 'virtual.ini', $line->line);
            }
        }
    }

    private function indexPlaces(): void
    {
        $secret = $this->config->secret;
        foreach ($this->events as $event) {
            ksort($event->files, SORT_STRING);
            $boxes = $this->boxesOf($event);
            foreach ($event->files as $name => $file) {
                $name = (string) $name; // names made of digits come back from array keys as integers (M-17)
                $place = new Place(Keys::make($secret, $event->id . '/' . $name), $file, $event, null, $boxes[$name] ?? null);
                $this->places[$place->key] = $place;
                $this->keysByEvent[$event->id][$name] = $place->key;
            }
        }
        foreach ($this->config->pages as $name => $page) {
            $name = (string) $name;
            $real = $this->boundary->resolveRelative($page['file']);
            if ($real === null || !is_file($real) || !ContentFile::isPage($real)) {
                $this->problems[] = new Problem("page \"$name\": missing, outside the course, or not .html/.md: $page[file]", 'pages.ini');
                continue;
            }
            $file = new ContentFile(basename($real), $real, (int) filesize($real), (int) filemtime($real));
            $place = new Place(Keys::make($secret, "page/$name"), $file, null, $name);
            $this->places[$place->key] = $place;
            $this->pageKeys[$name] = $place->key;
        }
        foreach ($this->places as $key => $place) {
            $this->byRealPath[$place->file->realPath][] = $key;
        }
        foreach ($this->pageKeys as $name => $key) {
            $name = (string) $name;
            $this->addPageAssets($name, $this->places[$key]->file->realPath);
        }
        foreach (array_values($this->places) as $place) {
            // event pages, box-asset pages included (L-32), but not linked assets
            if ($place->event === null || $place->assetOf !== null || $place->file->kind() !== 'page') {
                continue;
            }
            if ($place->file->virtualOf !== null) {
                $this->addLinkedAssets($place, $place->file->realPath); // L-20
            }
            foreach ($this->injectedFiles($place->file->realPath, 0) as $injected) {
                $this->addLinkedAssets($place, $injected); // L-21
            }
        }
    }

    /** Whether a file already has a place of its own (an event's file or a page), not just a linked one. */
    private function hasOwnPlace(string $real): bool
    {
        foreach ($this->byRealPath[$real] ?? [] as $key) {
            $place = $this->places[$key];
            if ($place->assetOf === null && !($place->asset && $place->event === null) && $place->file->virtualOf === null) {
                return true; // not a page's asset either: those are per page too; nor a virtual name (L-49)
            }
        }
        return false;
    }

    /** The pages a page injects (<inject src>, a few levels deep), as real paths. @return list<string> */
    private function injectedFiles(string $real, int $depth): array
    {
        $found = [];
        preg_match_all('/<inject\b[^>]*>/i', (string) @file_get_contents($real), $m);
        foreach ($m[0] as $tag) {
            $src = PageRenderer::attribute($tag, 'src');
            $target = $src === null ? null : $this->boundary->resolve(dirname($real) . '/' . $src);
            if ($target !== null && is_file($target) && ContentFile::isPage($target) && dirname($target) !== dirname($real)) {
                $found[] = $target;
                if ($depth < 3) {
                    array_push($found, ...$this->injectedFiles($target, $depth + 1));
                }
            }
        }
        return $found;
    }

    /**
     * Files that $htmlReal (a virtual page's real file, or a file a page injects) links
     * to, relative to its own directory, get keys decided by $page's rules; files that
     * already have a key keep it.
     */
    /**
     * <replace> values of the course itself filled in, so a link such as
     * src="logo-<replace value=course>.png" gets a key (L-90); values that depend on the event
     * (type, event, title) are left, and such links get none.
     */
    private function withReplaceValues(string $html): string
    {
        if (stripos($html, '<replace') === false) {
            return $html;
        }
        $values = $this->config->replace + ['course' => $this->config->number, 'coursename' => $this->config->name];
        return (string) (preg_replace_callback('/<replace\b[^>]*>/i', static function (array $m) use ($values): string {
            $name = (string) PageRenderer::attribute($m[0], 'value');
            return isset($values[$name]) ? htmlspecialchars((string) $values[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $m[0];
        }, $html) ?? $html);
    }

    private function addLinkedAssets(Place $page, string $htmlReal): void
    {
        $html = (string) @file_get_contents($htmlReal);
        if (preg_match('/\.md$/i', $htmlReal)) {
            $html = (new Markdown())->text($html);
        }
        foreach (PageRenderer::linkTargets($this->withReplaceValues($html)) as $link) {
            $target = $this->boundary->resolve(dirname($htmlReal) . '/' . rawurldecode($link));
            if ($target === null || !is_file($target) || Boundary::neverServed($target) || $this->hasOwnPlace($target)) {
                continue;
            }
            foreach ($this->byRealPath[$target] ?? [] as $existing) {
                if ($this->places[$existing]->assetOf === $page->key) {
                    continue 2; // already linked from this page
                }
            }
            $relative = $this->boundary->relative($target);
            $file = new ContentFile($relative, $target, (int) filesize($target), (int) filemtime($target), false);
            $place = new Place(Keys::make($this->config->secret, $page->id() . '|' . $relative), $file, $page->event, null, null, true, $page->key);
            $this->places[$place->key] = $place;
            $this->byRealPath[$target][] = $place->key;
        }
    }

    /**
     * Files a pages.ini page links to (images, style sheets, other pages next to it)
     * get keys of their own, decided by that page's rule (M-5).  Linked pages are
     * followed a few levels; files that already belong to an event keep the event's key.
     */
    private function addPageAssets(string $name, string $pageReal): void
    {
        $queue = [[$pageReal, 0]];
        $seen = [$pageReal => true];
        while ($queue !== []) {
            [$real, $depth] = array_shift($queue);
            $html = (string) @file_get_contents($real);
            if (preg_match('/\.md$/i', $real)) {
                $html = (new Markdown())->text($html);
            }
            foreach (PageRenderer::linkTargets($this->withReplaceValues($html)) as $link) {
                $target = $this->boundary->resolve(dirname($real) . '/' . rawurldecode($link));
                if ($target === null || isset($seen[$target]) || !is_file($target) || Boundary::neverServed($target)) {
                    continue;
                }
                $seen[$target] = true;
                if ($this->hasOwnPlace($target)) {
                    continue; // an event's file or a page of its own
                }
                // one key per linking page, so each page's own rule decides it (L-38)
                $relative = $this->boundary->relative($target);
                $file = new ContentFile($relative, $target, (int) filesize($target), (int) filemtime($target), false);
                $place = new Place(Keys::make($this->config->secret, "page/$name/$relative"), $file, null, $name, null, true);
                $this->places[$place->key] = $place;
                $this->byRealPath[$target][] = $place->key;
                if ($depth < 3 && ContentFile::isPage($target)) {
                    $queue[] = [$target, $depth + 1];
                }
            }
            foreach ($this->injectedFiles($real, 2) as $injected) { // what it injects links to files too (L-32)
                if (!isset($seen[$injected])) {
                    $seen[$injected] = true;
                    $queue[] = [$injected, $depth + 1];
                }
            }
        }
    }

    /**
     * Which files fill which box: the first of the names config.ini lists for a box that is
     * there. A box holds exactly those files (Decided: names are never guessed; a file with a
     * similar name, lab-solution.pdf, is not part of the box; it is listed as a problem).
     * @return array<string, string> file => box
     */
    public function boxesOf(ScheduledEvent $event): array
    {
        // box files and asset names are matched without case, like rules (M-25)
        $byLower = [];
        foreach (array_map('strval', array_keys($event->files)) as $name) {
            $byLower[mb_strtolower($name, 'UTF-8')] ??= $name;
        }
        $boxes = [];
        foreach ($this->config->boxes as $box) {
            foreach ($box['files'] as $candidate) {
                $name = $byLower[mb_strtolower($candidate, 'UTF-8')] ?? null;
                if ($name !== null && !isset($boxes[$name])) {
                    $boxes[$name] = $box['box'];
                    break;
                }
            }
        }
        // a box holds exactly the files config.ini names for it, never a file with a similar name
        // (lab-solution.pdf is not a lab-solution): names are never guessed (Decided, E-8). Such a
        // file follows the event's rule, so instructors are told (information only, L-131)
        foreach ($event->files as $name => $file) {
            $name = (string) $name;
            if ($event->copyOf !== null || isset($boxes[$name]) || !$file->topLevel || Boundary::isUnlisted($name)) {
                continue;
            }
            $lower = mb_strtolower($name, 'UTF-8');
            $stem = explode('.', $lower, 2)[0];
            $extension = str_contains($lower, '.') ? substr($lower, strrpos($lower, '.') + 1) : '';
            $where = $event->type . '/' . ($event->folder->dirName ?? '');
            foreach ($this->config->boxes as $box) {
                $what = $box['notes'] ? 'notes' : "box $box[box]";
                $listed = array_map(static fn (string $f): string => mb_strtolower($f, 'UTF-8'), $box['files']);
                if (in_array($lower, $listed, true)) {
                    // a later choice of a box whose earlier choice is there (index.md next to index.html)
                    $this->problems[] = new Problem("$event->id/$name is listed for $what, but an earlier name fills it (" . implode(', ', $box['files']) . "): it follows the event's rule", $where);
                    break;
                }
                // a page's own assets (index.css, title.png) are not reported (L-134)
                if ($stem !== '' && !in_array($extension, self::ASSET_EXTENSIONS, true)
                    && ($stem === mb_strtolower($box['box'], 'UTF-8') || in_array($stem, array_map(static fn (string $f): string => explode('.', $f, 2)[0], $listed), true))) {
                    $this->problems[] = new Problem("$event->id/$name is named like $what but is not the file config.ini lists for it (" . implode(', ', $box['files']) . "): it follows the event's rule", $where);
                    break;
                }
            }
        }
        return $boxes;
    }

    /** Files a page uses (style, script, image, font): never reported as named like a box (L-134). */
    private const ASSET_EXTENSIONS = ['css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'woff', 'woff2', 'ttf', 'otf', 'eot'];

    public function place(string $key): ?Place
    {
        return Keys::looksValid($key) ? ($this->places[$key] ?? null) : null;
    }

    /**
     * The place a page links to: a file of the same event (by name), else another
     * known file with that real path (preferring the linking page's own assets, then
     * pages, then events that are not copies).
     */
    public function placeForPath(Place $from, string $fromDir, string $link): ?Place
    {
        $link = rawurldecode($link);
        $event = $from->event;
        $base = $event?->folder?->path;
        // by name only from inside the event's own directory; a virtual file's links are
        // relative to its real file (L-20)
        if ($event !== null && $base !== null && ($fromDir === $base || str_starts_with($fromDir, $base . '/'))) {
            $name = $fromDir === $base ? $link : substr($fromDir, strlen($base) + 1) . '/' . $link;
            $name = self::normalize($name);
            if ($name !== null && isset($this->keysByEvent[$event->id][$name])) {
                return $this->places[$this->keysByEvent[$event->id][$name]];
            }
        }
        $real = Boundary::realpath($fromDir . '/' . $link);
        if ($real === false) {
            return null;
        }
        $best = null;
        $bestScore = -1;
        foreach ($this->byRealPath[$real] ?? [] as $key) {
            $place = $this->places[$key];
            $score = match (true) {
                $place->assetOf !== null && $place->assetOf === $from->key => 5,
                $place->asset && $place->event === null && $place->page === $from->page && $place->assetOf === null => 4,
                $place->page !== null && !$place->asset => 3,
                $place->event !== null && $place->event->mode === null => 2,
                default => 1,
            };
            if ($score > $bestScore) {
                [$best, $bestScore] = [$place, $score];
            }
        }
        return $best;
    }

    private static function normalize(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** The day an event is on. */
    public function dayOf(ScheduledEvent $event): ?Day
    {
        return $event->date === null ? null : ($this->days[$event->date] ?? null);
    }
}
