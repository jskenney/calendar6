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
        $course->rulesUnreadable = $default->exists && !$default->readable;
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
                        if ($event->target() === $target) {
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
                $place = new Place(Keys::make($secret, $event->id . '/' . $name), $file, $event, null, $boxes[$name] ?? null);
                $this->places[$place->key] = $place;
                $this->keysByEvent[$event->id][$name] = $place->key;
            }
        }
        foreach ($this->config->pages as $name => $page) {
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
            $this->addPageAssets($name, $this->places[$key]->file->realPath);
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
        }
    }

    /** @return array<string, string> file name => box: the first candidate found fills each box */
    public function boxesOf(ScheduledEvent $event): array
    {
        $boxes = [];
        foreach ($this->config->boxes as $box) {
            foreach ($box['files'] as $candidate) {
                if (isset($event->files[$candidate]) && !isset($boxes[$candidate])) {
                    $boxes[$candidate] = $box['box'];
                    break;
                }
            }
        }
        return $boxes;
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
        if ($event !== null) {
            $base = $event->folder?->path;
            $name = $link;
            if ($base !== null && $fromDir !== $base && str_starts_with($fromDir, $base . '/')) {
                $name = substr($fromDir, strlen($base) + 1) . '/' . $link;
            }
            $name = self::normalize($name);
            if ($name !== null && isset($this->keysByEvent[$event->id][$name])) {
                return $this->places[$this->keysByEvent[$event->id][$name]];
            }
        }
        $real = realpath($fromDir . '/' . $link);
        if ($real === false) {
            return null;
        }
        $best = null;
        $bestScore = -1;
        foreach ($this->byRealPath[$real] ?? [] as $key) {
            $place = $this->places[$key];
            $score = match (true) {
                $place->asset && $place->page === $from->page => 4,
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
