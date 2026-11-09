<?php

declare(strict_types=1);

namespace Cal6\Schedule;

use Cal6\Ini\IniFile;
use Cal6\Support\Problem;

/**
 * periods.ini: the school's periods for each period schedule.  A period that is
 * not listed does not exist in that schedule; `cancelled` exists but does not meet.
 */
final class Periods
{
    /** @var array<string, array{label: string, periods: array<string, array{0: string, 1: string}|null>}> null = cancelled */
    public array $schedules = [];
    /** @var list<Problem> */
    public array $problems = [];

    public static function fromIni(IniFile $ini): self
    {
        $periods = new self();
        $periods->problems = $ini->problems;
        foreach ($ini->lines as $line) {
            if ($line->section === '') {
                $periods->problems[] = new Problem('a period outside a [period schedule] section', $ini->label, $line->line);
                continue;
            }
            $schedule = &$periods->schedules[$line->section];
            $schedule ??= ['label' => ucwords(str_replace('_', ' ', $line->section)), 'periods' => []];
            if (strtolower($line->key) === 'label') {
                $schedule['label'] = $line->value;
            } elseif (!preg_match('/^(X|\d{1,2})$/i', $line->key)) {
                $periods->problems[] = new Problem("not a period name: $line->key", $ini->label, $line->line);
            } elseif (strtolower($line->value) === 'cancelled') {
                $schedule['periods'][strtoupper($line->key)] = null;
            } elseif (preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $line->value, $m)) {
                $schedule['periods'][strtoupper($line->key)] = [sprintf('%02d:%s', $m[1], $m[2]), sprintf('%02d:%s', $m[3], $m[4])];
            } else {
                $periods->problems[] = new Problem("expected HH:MM-HH:MM or cancelled: $line->key = $line->value", $ini->label, $line->line);
            }
            unset($schedule);
        }
        return $periods;
    }

    /**
     * One meeting made of consecutive periods in a schedule: [start, end], or null
     * when a period is cancelled or does not exist in that schedule.
     *
     * @param list<string> $names
     * @return array{0: string, 1: string}|null
     */
    public function meeting(string $schedule, array $names): ?array
    {
        $start = null;
        $end = null;
        foreach ($names as $name) {
            $times = $this->schedules[$schedule]['periods'][$name] ?? null;
            if ($times === null) {
                return null;
            }
            $start = $start === null || $times[0] < $start ? $times[0] : $start;
            $end = $end === null || $times[1] > $end ? $times[1] : $end;
        }
        return $start === null ? null : [$start, (string) $end];
    }

    /** @return array<string, array{label: string, times: array<string, string>}> for config.php */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->schedules as $name => $schedule) {
            $times = [];
            foreach ($schedule['periods'] as $period => $t) {
                $times[$period] = $t === null ? 'cancelled' : "$t[0]-$t[1]";
            }
            $out[$name] = ['label' => $schedule['label'], 'times' => $times];
        }
        return $out;
    }
}
