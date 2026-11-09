<?php

declare(strict_types=1);

namespace Cal6\Course;

use Cal6\Access\Rules;
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

        $default = $course->config->ini('default.ini');
        // no usable release rules: anonymous viewers see nothing (fail closed), also when the
        // file is missing or misnamed (M-19)
        $course->rulesUnreadable = !$default->exists || !$default->readable;
        if (!$default->exists) {
            $course->problems[] = new Problem('config/default.ini is missing: nothing is shown to anonymous viewers until it exists', 'default.ini');
        }
        $course->rules = Rules::fromIni($default);
        array_push($course->problems, ...$course->rules->problems);
        foreach ($course->rules->overridden as $note) {
            $course->problems[] = new Problem($note, 'default.ini');
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
            [$boxes, $assets] = $this->boxesOf($event);
            foreach ($event->files as $name => $file) {
                $name = (string) $name; // names made of digits come back from array keys as integers (M-17)
                $place = new Place(Keys::make($secret, $event->id . '/' . $name), $file, $event, null,
                    $boxes[$name] ?? $assets[$name] ?? null, isset($assets[$name]));
                $this->places[$place->key] = $place;
                $this->keysByEvent[$event->id][$name] = $place->key;
            }
        }
        foreach ($this->config->pages as $name => $page) {
            $name = (string) $name;
            $real = $this->boundary->resolveRelative($page['file']);
            if ($real === null || !is_file($real) || !ContentFile::isPage($real)) {
                $this->problems[] = new Problem("page \"$name\": missing, outside the course, or not .html/.md: $page[file]", 'paths.ini');
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
            if ($this->places[$key]->assetOf === null) {
                return true;
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
    private function addLinkedAssets(Place $page, string $htmlReal): void
    {
        $html = (string) @file_get_contents($htmlReal);
        if (preg_match('/\.md$/i', $htmlReal)) {
            $html = (new Markdown())->text($html);
        }
        foreach (PageRenderer::linkTargets($html) as $link) {
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
     * Files a paths.ini page links to (images, style sheets, other pages next to it)
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
            foreach (PageRenderer::linkTargets($html) as $link) {
                $target = $this->boundary->resolve(dirname($real) . '/' . rawurldecode($link));
                if ($target === null || isset($seen[$target]) || !is_file($target) || Boundary::neverServed($target)) {
                    continue;
                }
                $seen[$target] = true;
                if (isset($this->byRealPath[$target])) {
                    continue; // an event's file or a page of its own
                }
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
     * Which files fill which box (the first candidate found fills each box), and the
     * box assets (M-3): a file whose name starts with a box file's name without its
     * extension, followed by "-", "_", "." or "/" (lab-solution-figure.png,
     * lab-solution/fig.png), follows that box's rule; the longest such name wins.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} file => box, asset => box
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
        $stems = [];
        foreach ($boxes as $file => $box) {
            $stems[] = [mb_strtolower((string) preg_replace('/\.[^.\/]+$/', '', (string) $file), 'UTF-8'), $box];
        }
        usort($stems, static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        $assets = [];
        foreach (array_map('strval', array_keys($event->files)) as $name) {
            if (isset($boxes[$name])) {
                continue;
            }
            $lower = mb_strtolower($name, 'UTF-8');
            foreach ($stems as [$stem, $box]) {
                if (strlen($lower) > strlen($stem) && str_starts_with($lower, $stem) && in_array($lower[strlen($stem)], ['-', '_', '.', '/'], true)) {
                    $assets[$name] = $box;
                    break;
                }
            }
        }
        return [$boxes, $assets];
    }

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
