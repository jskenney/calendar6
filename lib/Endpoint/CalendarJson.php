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

    /** @return array<string, mixed> */
    public function build(Viewer $viewer, int $now): array
    {
        $policy = new Policy($this->course);
        $instructor = $viewer->instructor;
        $names = ['', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        $pages = [];
        foreach ($this->course->pageKeys as $name => $key) {
            $name = (string) $name; // names made of digits come back from array keys as integers (M-17)
            $place = $this->course->places[$key];
            $decision = $policy->decide($viewer, $place, $now);
            $this->note($decision->nextChange);
            if ($decision->visible) {
                $pages[] = ['name' => $name, 'title' => $this->course->config->pages[$name]['label'], 'key' => $key];
            }
        }

        $events = [];
        foreach ($this->course->events as $event) {
            if ($event->date === null && !$instructor) {
                continue;
            }
            $boxes = [];
            $files = [];
            $anyVisible = false;
            $opens = null;
            foreach ($event->files as $name => $file) {
                $name = (string) $name;
                if (!$file->topLevel || \Cal6\Course\Boundary::isUnlisted($name)) {
                    continue; // notes.md2 is reached only through a page's link (L-92)
                }
                $place = $this->course->places[$this->course->keysByEvent[$event->id][$name]];
                $decision = $policy->decide($viewer, $place, $now);
                $this->note($decision->nextChange);
                $anonymousView = $instructor ? $policy->decide(Viewer::anonymous(), $place, $now) : $decision;
                if (!$decision->visible) {
                    if ($decision->opens !== null) {
                        $opens = Policy::min($opens, $decision->opens);
                    }
                    continue;
                }
                $anyVisible = true;
                $item = ['name' => $name, 'key' => $place->key, 'kind' => $file->kind(), 'size' => $file->size];
                if ($instructor && !$anonymousView->visible) {
                    $item['visible'] = false;
                    $item['rule'] = $anonymousView->rule?->describe() ?? ($event->date === null ? 'on no calendar day' : '');
                    if ($anonymousView->opens !== null) {
                        $item['opens'] = $this->c->iso($anonymousView->opens);
                    }
                }
                if ($place->box !== null && !$place->asset) {
                    $boxes[$place->box][] = $item;
                } else {
                    if ($place->asset) {
                        $item['box'] = $place->box; // a box asset: it follows that box's rule
                    }
                    $files[] = $item;
                }
            }
            $ordered = [];
            foreach ($this->course->config->boxes as $box) {
                if (isset($boxes[$box['box']])) {
                    $ordered[] = ['box' => $box['box'], 'files' => $boxes[$box['box']]];
                }
            }
            $entry = [
                'type' => $event->type,
                'number' => $event->number,
                'title' => $anyVisible || $instructor ? $event->title() : '',
                'date' => $event->date,
            ];
            if ($event->time?->start !== null) {
                $entry['start'] = $event->time->start->format('H:i');
                $entry['end'] = $event->time->end?->format('H:i');
            }
            $entry['boxes'] = $ordered;
            $entry['files'] = $files;
            if ($opens !== null) {
                $entry['opens'] = $this->c->iso($opens);
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
            if ($day->weekday !== (int) $day->date->format('N')) {
                $item['as'] = $names[$day->weekday];
            }
            if ($day->period !== $this->course->config->defaultPeriod) {
                $item['period'] = $day->period;
            }
            if ($day->kind !== null) {
                $item['kind'] = $day->kind;
            }
            if ($day->label !== null) {
                $item['label'] = $day->label;
            }
            $item['events'] = $day->events;
            $days[] = $item;
        }

        $this->note($viewer->user !== null ? $viewer->validUntil($now, $this->course->config->zone) : null);
        $out = [
            'generated' => $this->c->iso($now),
            'next_change' => $this->c->iso($this->nextChange),
            'viewer' => ['user' => $viewer->user, 'instructor' => $instructor],
            'pages' => $pages,
            'days' => $days,
            'events' => (object) $events,
        ];
        if ($instructor) {
            $out['problems'] = array_map(static fn ($p): array => $p->toArray(), $this->course->problems);
        }
        return $out;
    }

    private function note(?int $moment): void
    {
        $this->nextChange = Policy::min($this->nextChange, $moment);
    }
}
