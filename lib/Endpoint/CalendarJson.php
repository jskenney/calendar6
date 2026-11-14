<?php

declare(strict_types=1);

namespace Cal6\Endpoint;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Course;
use Cal6\Course\Place;
use Cal6\Http\Context;

/** The body of calendar.php for one viewer at one moment (API.md, `calendar.php`). */
final class CalendarJson
{
    public ?int $nextChange = null;

    public function __construct(private readonly Course $course, private readonly Context $c)
    {
    }

    /**
     * A notes file's text, as written (Decided, H-7: any file [notes] names, HTML too): trimmed,
     * at most 2000 bytes, a longer text cut on a character boundary and ending in "…" (L-128).
     */
    private static function noteText(string $path): string
    {
        // leading whitespace does not count against the limit (L-137): skip it, then read enough
        $handle = @fopen($path, 'rb');
        $text = '';
        if ($handle !== false) {
            $skipped = 0;
            while (!feof($handle) && $skipped < 1048576 && ($chunk = fread($handle, 8192)) !== false && $chunk !== '') {
                $trimmed = ltrim($chunk);
                if ($trimmed !== '') {
                    $text = $trimmed . (string) fread($handle, 8192);
                    break;
                }
                $skipped += strlen($chunk);
            }
            fclose($handle);
        }
        $text = mb_convert_encoding(trim($text), 'UTF-8', 'UTF-8');
        if (strlen($text) > 2000) {
            $text = rtrim(mb_strcut($text, 0, 2000 - strlen('…'), 'UTF-8')) . '…';
        }
        return $text;
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
        // which script serves a key: md (md.php, or html.php) for Markdown pages, html for other
        // pages, get for every other file
        $kind = static fn (Place $place): string => $place->file->kind() !== 'page' ? 'get'
            : (\Cal6\Render\PageRenderer::isMarkdown($place->file->realPath) ? 'md' : 'html');

        $pages = [];
        foreach ($this->course->pageKeys as $name => $key) {
            $name = (string) $name; // names made of digits come back from array keys as integers (M-17)
            $place = $this->course->places[$key];
            $decision = $policy->decide($viewer, $place, $now);
            $this->note($decision->nextChange);
            // the title is always shown, to everyone (Decided); the rest only when visible
            $page = ['title' => $this->course->config->pages[$name]['label']];
            if ($decision->visible) {
                $page += ['key' => $key, 'filename' => basename($place->file->name), 'kind' => $kind($place)] + $this->hint($policy, $place, $now, $loggedOn);
            }
            $pages[$name] = $page;
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
                $item = $this->hint($policy, $place, $now, $loggedOn);
                if (isset($item['opens'])) {
                    $opens = Policy::min($opens, $policy->decide(Viewer::anonymous(), $place, $now)->opens);
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
                $entry += $times + ['section' => $section];
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

        // every day of the semester, by date: a marker for what dates.ini says about the day (a
        // holiday, another weekday, a label, another period schedule), then its shown events
        $days = [];
        foreach ($this->course->days as $day) {
            $items = [];
            $marker = [];
            if ($day->kind !== null) {
                $marker['title'] = $day->kind;
            } elseif ($day->weekday !== (int) $day->date->format('N')) {
                $marker['title'] = $names[$day->weekday];
            }
            if ($day->label !== null) {
                $marker['label'] = $day->label;
            }
            if ($day->period !== $this->course->config->defaultPeriod) {
                $marker['schedule'] = $day->period;
            }
            if ($marker !== [] || $day->explicit) {
                // every day dates.ini changes is marked, a type-only line without a label too (L-130)
                $items[] = ['event' => '', 'title' => $marker['title'] ?? ''] + $marker;
            }
            foreach ($day->events as $id) {
                if (!isset($events[$id])) {
                    continue;
                }
                $one = $events[$id];
                $item = ['event' => $id, 'type' => $one['type'], 'number' => $one['number'], 'title' => $one['title']];
                foreach (['start', 'end', 'period', 'instructor', 'room', 'cancelled'] as $field) {
                    if (array_key_exists($field, $one)) {
                        $item[$field] = $one[$field];
                    }
                }
                $items[] = $item;
            }
            $days[$day->key()] = $items;
        }

        $this->note($viewer->user !== null ? $viewer->validUntil($now, $this->course->config->zone) : null);
        $out = [
            // no next_change: the answer may change before it (an instructor edits a file), E-10
            'generated' => $this->c->iso($now),
            // valid_until belongs to the session, not to the cached answer: calendar.php fills it in
            'viewer' => ['user' => $viewer->user, 'instructor' => $instructor, 'valid_until' => null],
        ];
        if ($section !== null) {
            $out['section'] = $section;
        }
        // announcements shown now (to everyone), with their identifiers (DOC-50)
        $announcements = [];
        foreach ($this->course->config->announcements as $announcement) {
            $this->note($announcement['show']->nextChange($now, $this->course->config->zone, null));
            if ($announcement['show']->isOpen($now, $this->course->config->zone, null)) {
                $announcements[] = ['id' => $announcement['id'], 'text' => $announcement['text']];
            }
        }
        $out += ['announcements' => $announcements, 'dropdown' => $this->course->config->dropdown, 'pages' => (object) $pages, 'days' => (object) $days, 'events' => (object) $events];
        if ($instructor) {
            $out['problems'] = array_map(static fn ($p): array => $p->toArray(), $this->course->problems);
        }
        return $out;
    }

    /**
     * When and where a section meets for an event on its day: start, end, period, instructor,
     * room (from sections.ini and the day's period schedule); null when it does not meet then.
     * A meeting cancelled by the period schedule (no start or end then) or starting inside a
     * dates.ini holiday/none window with times carries "cancelled": true (L-129).
     *
     * @return array{start?: string, end?: string, period: string, instructor: string, room: string, cancelled?: true}|null
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
            $out = $times !== null ? ['start' => $times[0], 'end' => $times[1]] : [];
            $out += [
                'period' => implode('', $meeting['periods']),
                'instructor' => implode(', ', $data['instructor']),
                'room' => $meeting['room'],
            ];
            if ($times === null || $this->inCancelWindow($day, $times[0])) {
                $out['cancelled'] = true;
            }
            return $out;
        }
        return null;
    }

    /**
     * For a logged-on viewer, on what students may not see yet: "visible": false, and when it
     * opens (nothing for "never"). Anonymous viewers get no hints at all (Decided).
     *
     * @return array{visible?: false, opens?: string}
     */
    private function hint(Policy $policy, Place $place, int $now, bool $loggedOn): array
    {
        if (!$loggedOn) {
            return [];
        }
        $anonymous = $policy->decide(Viewer::anonymous(), $place, $now);
        if ($anonymous->visible) {
            return [];
        }
        return ['visible' => false] + ($anonymous->opens !== null ? ['opens' => $this->c->iso($anonymous->opens)] : []);
    }

    /** Whether a meeting starting at $start (HH:MM) on $day starts inside a dates.ini cancel window. */
    private function inCancelWindow(\Cal6\Schedule\Day $day, string $start): bool
    {
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day->key() . ' ' . $start, $this->course->config->zone);
        if ($moment === false) {
            return false;
        }
        $at = $moment->getTimestamp();
        foreach ($day->cancel as [$from, $to]) {
            if ($at >= $from && $at < $to) {
                return true;
            }
        }
        return false;
    }

    private function note(?int $moment): void
    {
        $this->nextChange = Policy::min($this->nextChange, $moment);
    }
}
