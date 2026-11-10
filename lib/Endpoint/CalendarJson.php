<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Course;
use Cal6\Course\Place;
use Cal6\Http\Context;

/** The body of calendar.php for one viewer at one moment (DESIGN.md, `calendar.php`). */
final class CalendarJson
{
    public ?int $nextChange = null;

    public function __construct(private readonly Course $course, private readonly Context $c)
    {
    }

    /** A notes file's text, as written (trimmed; at most 2000 bytes, cut on a character boundary). */
    private static function noteText(string $path): string
    {
        $text = trim((string) @file_get_contents($path, false, null, 0, 2004));
        if (strlen($text) > 2000) {
            $text = mb_strcut($text, 0, 2000, 'UTF-8') . '…';
        }
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * @param string|null $section a section of sections.ini: each event then carries that
     *        section's start, end, period, instructor and room on its day; otherwise no times
     * @return array<string, mixed>
     */
    public function build(Viewer $viewer, int $now, ?string $section = null): array
    {
        $policy = new Policy($this->course);
        $instructor = $viewer->instructor;
        // a logged-on viewer (an instructor, or a user with grants) learns when what students do
        // not see yet opens; anonymous viewers get no hint at all (Decided)
        $loggedOn = $viewer->user !== null;
        $names = ['', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $kind = static fn (Place $place): string => $place->file->kind() === 'page' ? 'html' : 'get';

        $pages = [];
        foreach ($this->course->pageKeys as $name => $key) {
            $name = (string) $name; // names made of digits come back from array keys as integers (M-17)
            $place = $this->course->places[$key];
            $decision = $policy->decide($viewer, $place, $now);
            $this->note($decision->nextChange);
            if ($decision->visible) {
                $pages[$name] = ['title' => $this->course->config->pages[$name]['label'], 'key' => $key,
                    'filename' => basename($place->file->name), 'kind' => $kind($place)];
            }
        }

        $notesBoxes = array_column(array_filter($this->course->config->boxes, static fn (array $b): bool => $b['notes'] ?? false), 'box');
        $boxOrder = array_flip(array_column($this->course->config->boxes, 'box'));
        $events = [];
        foreach ($this->course->events as $event) {
            if ($event->date === null && !$instructor) {
                continue;
            }
            $boxes = [];
            $notes = [];
            $opens = null;
            foreach ($event->files as $name => $file) {
                $name = (string) $name;
                if (!$file->topLevel || \Cal6\Course\Boundary::isUnlisted($name)) {
                    continue; // notes.md2 is reached only through a page's link (L-92)
                }
                $place = $this->course->places[$this->course->keysByEvent[$event->id][$name]];
                if ($place->box === null) {
                    // only what config.ini names for a box (or for notes) is listed: there is no
                    // list of other files; those are reached through a page's links (Decided)
                    continue;
                }
                $decision = $policy->decide($viewer, $place, $now);
                $this->note($decision->nextChange);
                if (!$decision->visible) {
                    continue;
                }
                $item = [];
                if ($loggedOn) {
                    $anonymous = $policy->decide(Viewer::anonymous(), $place, $now);
                    if (!$anonymous->visible) {
                        // not yet visible to students: when it opens (nothing for "never")
                        $item['visible'] = false;
                        if ($anonymous->opens !== null) {
                            $item['opens'] = $this->c->iso($anonymous->opens);
                            $opens = Policy::min($opens, $anonymous->opens);
                        }
                    }
                }
                if (in_array($place->box, $notesBoxes, true)) {
                    // a notes file: its text, not a key (short remarks; nothing to serve)
                    $notes[] = ['filename' => $name, 'text' => self::noteText($file->realPath)] + $item;
                    continue;
                }
                $boxes[] = ['box' => $place->box, 'filename' => $name, 'key' => $place->key, 'kind' => $kind($place)] + $item;
            }
            usort($boxes, static fn (array $a, array $b): int => ($boxOrder[$a['box']] ?? 0) <=> ($boxOrder[$b['box']] ?? 0));
            // the title is always shown, to everyone (Decided)
            $entry = ['type' => $event->type, 'number' => $event->number, 'title' => $event->title(), 'date' => $event->date];
            if ($section !== null && ($times = $this->sectionTimes($section, $event)) !== null) {
                $entry += $times;
            }
            $entry['boxes'] = $boxes;
            if ($notes !== []) {
                $entry['notes'] = $notes;
            }
            if ($opens !== null) {
                $entry['opens'] = $this->c->iso($opens); // logged-on viewers only (see above)
            }
            if ($event->copyOf !== null) {
                $entry['copy_of'] = $event->copyOf;
                $entry['mode'] = $event->mode;
            }
            if ($instructor) {
                $entry['directory'] = $event->folder !== null ? $event->type . '/' . $event->folder->dirName : null;
            }
            $events[$event->id] = $entry;
        }

        $days = [];
        foreach ($this->course->days as $day) {
            $item = ['date' => $day->key()];
            // "as": what the day is instead of its own weekday (holiday, none, or another weekday)
            if ($day->kind !== null) {
                $item['as'] = $day->kind;
            } elseif ($day->weekday !== (int) $day->date->format('N')) {
                $item['as'] = $names[$day->weekday];
            }
            if ($day->period !== $this->course->config->defaultPeriod) {
                $item['period'] = $day->period;
            }
            if ($day->label !== null) {
                $item['label'] = $day->label;
            }
            $item['events'] = $day->events;
            $shown = array_values(array_filter($day->events, static fn (string $id): bool => isset($events[$id])));
            if (count($shown) === 1) {
                $one = $events[$shown[0]];
                $item += ['type' => $one['type'], 'number' => $one['number'], 'title' => $one['title']];
            } elseif (count($shown) > 1) {
                $item['items'] = array_map(static fn (string $id): array => ['event' => $id, 'type' => $events[$id]['type'], 'number' => $events[$id]['number'], 'title' => $events[$id]['title']], $shown);
            }
            $days[] = $item;
        }

        $this->note($viewer->user !== null ? $viewer->validUntil($now, $this->course->config->zone) : null);
        $out = [
            'generated' => $this->c->iso($now),
            'next_change' => $this->c->iso($this->nextChange),
            'viewer' => ['user' => $viewer->user, 'instructor' => $instructor],
        ];
        if ($section !== null) {
            $out['section'] = $section;
        }
        $out += ['pages' => (object) $pages, 'days' => $days, 'events' => (object) $events];
        if ($instructor) {
            $out['problems'] = array_map(static fn ($p): array => $p->toArray(), $this->course->problems);
        }
        return $out;
    }

    /**
     * When and where a section meets for an event on its day: start, end, period, instructor,
     * room (from sections.ini and the day's period schedule); null when it does not meet then.
     *
     * @return array{start: ?string, end: ?string, period: string, instructor: string, room: string}|null
     */
    private function sectionTimes(string $section, \Cal6\Schedule\ScheduledEvent $event): ?array
    {
        $data = $this->course->config->sections->sections[$section] ?? null;
        $day = $event->date !== null ? ($this->course->days[$event->date] ?? null) : null;
        if ($data === null || $day === null) {
            return null;
        }
        foreach ($data['meetings'] as $meeting) {
            if ($meeting['type'] !== $event->type || !in_array($day->weekday, $meeting['days'], true)) {
                continue;
            }
            $times = $this->course->config->periods->meeting($day->period, $meeting['periods']);
            return [
                'start' => $times[0] ?? null,
                'end' => $times[1] ?? null,
                'period' => implode('', $meeting['periods']),
                'instructor' => implode(', ', $data['instructor']),
                'room' => $meeting['room'],
            ];
        }
        return null;
    }

    private function note(?int $moment): void
    {
        $this->nextChange = Policy::min($this->nextChange, $moment);
    }
}
