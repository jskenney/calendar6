<?php

declare(strict_types=1);

namespace Cal6\Schedule;

use Cal6\Access\Target;
use Cal6\Content\EventFolder;
use Cal6\Course\CourseConfig;
use Cal6\Ini\IniFile;
use Cal6\Support\Problem;
use Cal6\Time\EventTime;
use Cal6\Time\Window;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The one course calendar (DESIGN.md, "Sections, periods and period schedules"):
 * config.ini [schedule] gives each weekday its event type, dates.ini changes single
 * days, and events are placed in order (class 1, 2, 3 ... on the class days), with
 * duplicate.ini's copies taking the numbers written (M-38).
 */
final class CalendarBuilder
{
    private const DAY_NAMES = [
        'mon' => 1, 'monday' => 1, 'tue' => 2, 'tuesday' => 2, 'wed' => 3, 'wednesday' => 3, 'thu' => 4,
        'thursday' => 4, 'fri' => 5, 'friday' => 5, 'sat' => 6, 'saturday' => 6, 'sun' => 7, 'sunday' => 7,
    ];

    /** @var array<string, Day> by Y-m-d */
    public array $days = [];
    /** @var array<string, ScheduledEvent> by id, in calendar order (unscheduled last) */
    public array $events = [];
    /** @var list<Problem> */
    public array $problems = [];

    /** @param array<string, list<EventFolder>> $folders */
    public function __construct(private readonly CourseConfig $config, private readonly array $folders)
    {
    }

    public function build(): self
    {
        $this->makeDays();
        $this->applyDates($this->config->ini('dates.ini'));
        $this->place($this->duplicates($this->config->ini('duplicate.ini')));
        return $this;
    }

    private function makeDays(): void
    {
        for ($date = $this->config->start; $date <= $this->config->end; $date = $date->modify('+1 day')) {
            $weekday = (int) $date->format('N');
            $day = new Day($date, $weekday, $this->config->defaultPeriod);
            if (isset($this->config->schedule[$weekday]) && ($this->config->weekends || $weekday < 6)) {
                $day->types = [$this->config->schedule[$weekday]['type']];
            }
            $this->days[$day->key()] = $day;
        }
    }

    private function applyDates(IniFile $ini): void
    {
        array_push($this->problems, ...$ini->problems);
        foreach ($ini->lines as $line) {
            try {
                $window = Window::parse($line->key, false);
                [$what, $label] = array_pad(explode('|', $line->value, 2), 2, null);
                $change = $this->parseWhat((string) $what);
            } catch (InvalidArgumentException $e) {
                $this->problems[] = new Problem($e->getMessage(), 'dates.ini', $line->line);
                continue;
            }
            [$from, $to] = $window->bounds($this->config->zone, null);
            if ($window->open->kind !== 'date') {
                $this->problems[] = new Problem('expected a date (YYYY-MM-DD [HH:MM] [until ...]): ' . $line->key, 'dates.ini', $line->line);
                continue;
            }
            if ($window->close === null) { // one day: until the end of the day it starts on
                $to = (new \DateTimeImmutable('@' . $from))->setTimezone($this->config->zone)->setTime(0, 0)->modify('+1 day')->getTimestamp();
            }
            $partial = str_contains($window->open->text, ':') || str_contains((string) $window->close?->text, ':');
            if ($partial && !in_array($change['events'], ['holiday', 'none'], true)) {
                // only holiday/none can cancel part of a day; anything else changes whole days (L-3)
                $this->problems[] = new Problem('times are only allowed with holiday or none (they cancel the meetings that start inside the window): ' . $line->key, 'dates.ini', $line->line);
                continue;
            }
            foreach ($this->days as $day) {
                $dayStart = $day->date->getTimestamp();
                $dayEnd = $day->date->modify('+1 day')->getTimestamp();
                if ($dayEnd <= $from || $dayStart >= $to) {
                    continue;
                }
                $this->applyChange($day, $change, $label === null ? null : trim($label), $partial ? [$from, $to] : null);
            }
        }
    }

    /**
     * @return array{events: ?string, types: list<string>, weekday: ?int, period: ?string}
     *         events: holiday | none | types | weekday | null (no change)
     */
    private function parseWhat(string $what): array
    {
        $change = ['events' => null, 'types' => [], 'weekday' => null, 'period' => null];
        foreach (array_filter(array_map('trim', explode(',', $what)), static fn (string $t): bool => $t !== '') as $token) {
            $lower = strtolower($token);
            if (preg_match('/^period\s+(\S+)$/i', $token, $m)) {
                $name = strtolower($m[1]);
                if ($this->config->periods->schedules !== [] && !isset($this->config->periods->schedules[$name])) {
                    throw new InvalidArgumentException("no such period schedule in periods.ini: $m[1]");
                }
                $change['period'] = $name;
            } elseif ($lower === 'holiday' || $lower === 'none') {
                $this->exclusive($change, $lower);
            } elseif (isset(self::DAY_NAMES[$lower])) {
                $this->exclusive($change, 'weekday');
                $change['weekday'] = self::DAY_NAMES[$lower];
            } elseif (($type = $this->config->typeName($token)) !== null) {
                if ($change['events'] !== null && $change['events'] !== 'types') {
                    throw new InvalidArgumentException("event types can not be combined with $change[events]: $what");
                }
                $change['events'] = 'types';
                $change['types'][] = $type;
            } else {
                throw new InvalidArgumentException("not holiday, none, an event type, a weekday or \"period <schedule>\": $token");
            }
        }
        if ($change['events'] === null && $change['period'] === null) {
            throw new InvalidArgumentException('nothing to change: ' . $what);
        }
        return $change;
    }

    /** @param array{events: ?string, types: list<string>, weekday: ?int, period: ?string} $change */
    private function exclusive(array &$change, string $events): void
    {
        if ($change['events'] !== null) {
            throw new InvalidArgumentException("$events can not be combined with $change[events]");
        }
        $change['events'] = $events;
    }

    /**
     * @param array{events: ?string, types: list<string>, weekday: ?int, period: ?string} $change
     * @param array{0: int, 1: int}|null $partial
     */
    private function applyChange(Day $day, array $change, ?string $label, ?array $partial): void
    {
        if ($change['period'] !== null) {
            $day->period = $change['period'];
        }
        if ($label !== null && $label !== '') {
            $day->label = $label;
        }
        switch ($change['events']) {
            case 'holiday':
            case 'none':
                if ($partial !== null) {
                    $day->cancel[] = $partial;
                    break;
                }
                $day->types = [];
                $day->kind = $change['events'] === 'holiday' ? 'holiday' : null;
                $day->explicit = true;
                break;
            case 'types':
                $day->types = $change['types'];
                $day->kind = null;
                $day->explicit = true;
                break;
            case 'weekday':
                $day->weekday = (int) $change['weekday'];
                $entry = $this->config->schedule[$day->weekday] ?? null;
                $day->types = $entry === null ? [] : [$entry['type']];
                $day->kind = null;
                $day->explicit = true;
                break;
        }
    }

    /** @return array<string, array<int, array{source: int, mode: string, line: int}>> type => number => copy */
    private function duplicates(IniFile $ini): array
    {
        array_push($this->problems, ...$ini->problems);
        $copies = [];
        foreach ($ini->lines as $line) {
            try {
                if (!preg_match('/^([A-Za-z][A-Za-z0-9-]*)_(\d+)(?:\s+(numbered|same))?$/i', $line->value, $m)) {
                    throw new InvalidArgumentException('expected "copies = type_N [numbered | same]"');
                }
                foreach (Target::expand($line->key) as $target) {
                    if (!preg_match('/^([A-Za-z][A-Za-z0-9-]*)_(\d+)$/', $target, $t)) {
                        throw new InvalidArgumentException("not an event: $target");
                    }
                    $type = $this->config->typeName($t[1]);
                    if ($type === null || strcasecmp($t[1], $m[1]) !== 0) {
                        throw new InvalidArgumentException("a copy must be the same type as its source: $target = $line->value");
                    }
                    $copies[$type][(int) $t[2]] = ['source' => (int) $m[2], 'mode' => strtolower($m[3] ?? 'numbered'), 'line' => $line->line];
                }
            } catch (InvalidArgumentException $e) {
                $this->problems[] = new Problem($e->getMessage(), 'duplicate.ini', $line->line);
            }
        }
        return $copies;
    }

    /** @param array<string, array<int, array{source: int, mode: string, line: int}>> $copies */
    private function place(array $copies): void
    {
        $nextDir = $nextNumber = $used = [];
        $dirOfNumber = []; // type => calendar number => directory (regular events only)
        $copyNumbers = []; // type => calendar numbers that are numbered copies
        foreach ($this->days as $day) {
            foreach ($day->types as $type) {
                $time = $this->eventTime($day, $type);
                if ($time->start !== null && $this->cancelled($day, $time->start->getTimestamp())) {
                    continue;
                }
                $folders = $this->folders[$type] ?? [];
                $nextDir[$type] ??= 1;
                $nextNumber[$type] ??= 1;
                // the left side of duplicate.ini names the number this meeting would carry (M-38):
                // class_6 = class_5 makes the event numbered 6 a copy of 5 (the folders move on
                // by one); with "same" that meeting shows class 5 again and the next meeting is
                // still number 6, so a line is used once
                $n = $nextNumber[$type];
                $copy = isset($used[$type][$n]) ? null : ($copies[$type][$n] ?? null);
                if ($copy !== null) {
                    $used[$type][$n] = true;
                    // the source is the event with that number on the calendar (M-15)
                    $source = $copy['source'];
                    if (isset($copyNumbers[$type][$source])) {
                        $this->problems[] = new Problem("{$type}_$n copies {$type}_$source, which is itself a copy; copy its source instead", 'duplicate.ini', $copy['line']);
                        continue;
                    }
                    $d = $dirOfNumber[$type][$source] ?? null;
                    if ($d === null) {
                        $this->problems[] = new Problem("{$type}_$n copies {$type}_$source, which is not on the calendar before it", 'duplicate.ini', $copy['line']);
                        continue;
                    }
                    $folder = $folders[$d - 1];
                    if ($copy['mode'] === 'same') {
                        $id = $this->uniqueId("{$type}_$source@" . $day->key());
                        $event = new ScheduledEvent($id, $type, $source, $folder, $day->key(), $time, "{$type}_$source", 'same');
                    } else {
                        $number = $nextNumber[$type]++;
                        $copyNumbers[$type][$number] = true;
                        $event = new ScheduledEvent("{$type}_$number", $type, $number, $folder, $day->key(), $time, "{$type}_$source", 'numbered');
                    }
                } else {
                    $d = $nextDir[$type];
                    if ($d > count($folders)) {
                        continue; // no more material of this type
                    }
                    $nextDir[$type]++;
                    $number = $nextNumber[$type]++;
                    $dirOfNumber[$type][$number] = $d;
                    $event = new ScheduledEvent("{$type}_$number", $type, $number, $folders[$d - 1], $day->key(), $time);
                }
                $this->events[$event->id] = $event;
                $day->events[] = $event->id;
            }
        }
        // a line whose number no meeting reaches (after the material runs out, or beyond the
        // semester) does nothing: say so (L-99)
        foreach ($copies as $type => $byNumber) {
            foreach ($byNumber as $number => $copy) {
                if (!isset($used[$type][$number])) {
                    $this->problems[] = new Problem("{$type}_$number = {$type}_{$copy['source']}: no meeting is numbered $number (the material or the calendar ends first), so this line does nothing", 'duplicate.ini', $copy['line']);
                }
            }
        }
        foreach ($this->folders as $type => $folders) {
            $nextNumber[$type] ??= 1;
            for ($d = $nextDir[$type] ?? 1; $d <= count($folders); $d++) {
                $number = $nextNumber[$type]++;
                $this->events["{$type}_$number"] = new ScheduledEvent("{$type}_$number", $type, $number, $folders[$d - 1], null, null);
            }
        }
    }

    private function uniqueId(string $id): string
    {
        $unique = $id;
        for ($n = 2; isset($this->events[$unique]); $n++) {
            $unique = "$id-$n";
        }
        return $unique;
    }

    private function cancelled(Day $day, int $start): bool
    {
        foreach ($day->cancel as [$from, $to]) {
            if ($start >= $from && $start < $to) {
                return true;
            }
        }
        return false;
    }

    /** The day's times for an event of $type: the first section's start and the last section's end. */
    public function eventTime(Day $day, string $type): EventTime
    {
        $start = $end = null;
        if (!$this->config->sections->isEmpty()) {
            foreach ($this->config->sections->sections as $section) {
                foreach ($section['meetings'] as $meeting) {
                    if ($meeting['type'] !== $type || !in_array($day->weekday, $meeting['days'], true)) {
                        continue;
                    }
                    $times = $this->config->periods->meeting($day->period, $meeting['periods']);
                    if ($times === null) {
                        continue;
                    }
                    $start = $start === null || $times[0] < $start ? $times[0] : $start;
                    $end = $end === null || $times[1] > $end ? $times[1] : $end;
                }
            }
        } else {
            $entry = $this->config->schedule[$day->weekday] ?? null;
            if ($entry !== null && $entry['type'] === $type && $entry['start'] !== null) {
                [$start, $end] = [$entry['start'], $entry['end']];
            }
        }
        return new EventTime($day->date, self::at($day->date, $start), self::at($day->date, $end));
    }

    private static function at(DateTimeImmutable $date, ?string $time): ?DateTimeImmutable
    {
        if ($time === null) {
            return null;
        }
        [$h, $i] = array_map('intval', explode(':', $time));
        return $date->setTime($h, $i);
    }
}
