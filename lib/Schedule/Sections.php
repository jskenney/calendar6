<?php

declare(strict_types=1);

namespace Cal6\Schedule;

use Cal6\Ini\IniFile;
use Cal6\Support\Problem;

/**
 * sections.ini: when and where each section meets.  Information only: there is
 * always one calendar (DESIGN.md, "Sections, periods and period schedules").
 *
 *     meets = MF4, R34          days M T W R F S, then periods (X, 10, then digits)
 *     room  = HP132, HP301      one room, or one per entry of meets
 */
final class Sections
{
    private const DAYS = ['M' => 1, 'T' => 2, 'W' => 3, 'R' => 4, 'F' => 5, 'S' => 6, 'U' => 7];

    /** @var array<string, array{instructor: list<string>, meetings: list<array{meets: string, days: list<int>, periods: list<string>, type: string, room: string}>}> */
    public array $sections = [];
    /** @var list<Problem> */
    public array $problems = [];

    /** @param array<string, string> $types */
    public static function fromIni(IniFile $ini, array $types): self
    {
        $sections = new self();
        $sections->problems = $ini->problems;
        $defaultType = array_key_first($types) ?? 'class';
        foreach ($ini->sections() as $name) {
            if ($name === '') {
                $sections->problems[] = new Problem('settings outside a [section] heading', $ini->label);
                continue;
            }
            $meets = $ini->get($name, 'meets', '');
            $meetings = [];
            $line = 0;
            foreach ($ini->section($name) as $l) {
                if (strcasecmp($l->key, 'meets') === 0) {
                    $line = $l->line;
                }
            }
            foreach (array_filter(array_map('trim', explode(',', (string) $meets))) as $entry) {
                $meeting = self::meeting($entry, $types, $defaultType);
                if ($meeting === null) {
                    $sections->problems[] = new Problem("[$name] not a meeting (days, then periods, then an optional type): $entry", $ini->label, $line);
                    continue;
                }
                $meetings[] = $meeting;
            }
            $rooms = array_values(array_filter(array_map('trim', explode(',', (string) $ini->get($name, 'room', ''))), static fn (string $r): bool => $r !== ''));
            if (count($rooms) > 1 && count($rooms) !== count($meetings)) {
                $sections->problems[] = new Problem("[$name] room: give one room, or one per entry of meets", $ini->label);
            }
            foreach ($meetings as $i => $meeting) {
                $meetings[$i]['room'] = count($rooms) === count($meetings) ? $rooms[$i] : ($rooms[0] ?? '');
            }
            $instructors = array_values(array_filter(array_map('trim', explode(',', (string) $ini->get($name, 'instructor', ''))), static fn (string $i): bool => $i !== ''));
            $sections->sections[$name] = ['instructor' => $instructors, 'meetings' => $meetings];
        }
        return $sections;
    }

    /**
     * @param array<string, string> $types
     * @return array{meets: string, days: list<int>, periods: list<string>, type: string, room: string}|null
     */
    public static function meeting(string $entry, array $types, string $defaultType): ?array
    {
        if (!preg_match('/^([MTWRFSU]+)\s*([X0-9]+)(?:\s+([A-Za-z][A-Za-z0-9-]*))?$/i', $entry, $m)) {
            return null;
        }
        $type = $m[3] ?? $defaultType;
        if (!isset($types[$type]) && isset($m[3])) {
            return null;
        }
        $days = [];
        foreach (str_split(strtoupper($m[1])) as $letter) {
            $days[] = self::DAYS[$letter];
        }
        $periods = [];
        $rest = strtoupper($m[2]);
        while ($rest !== '') {
            if (str_starts_with($rest, '10')) {
                $periods[] = '10';
                $rest = substr($rest, 2);
            } elseif ($rest[0] === '0') {
                return null; // there is no period 0
            } else {
                $periods[] = $rest[0];
                $rest = substr($rest, 1);
            }
        }
        return ['meets' => strtoupper($m[1]) . strtoupper($m[2]), 'days' => array_values(array_unique($days)), 'periods' => $periods, 'type' => $type, 'room' => ''];
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    /** @return list<array{section: string, meetings: list<array{meets: string, type: string, room: string}>, instructor: string}> for config.php */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->sections as $name => $section) {
            $out[] = [
                'section' => (string) $name,
                'meetings' => array_map(static fn (array $m): array => ['meets' => $m['meets'], 'type' => $m['type'], 'room' => $m['room']], $section['meetings']),
                'instructor' => implode(', ', $section['instructor']),
            ];
        }
        return $out;
    }
}
