<?php

declare(strict_types=1);

namespace Cal6\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One moment, as written in the .ini files (DESIGN.md, "Dates, times and windows"):
 *
 *     always | never | YYYY-MM-DD [HH:MM] | (+|-)N [HH:MM] | (+|-)weekday [HH:MM]
 *     | start [(+|-)N(m|h)] | end [(+|-)N(m|h)]
 *
 * Without HH:MM an opening time is 00:00 and a closing time is the end of that day.
 */
final class When
{
    public const ALWAYS = 'always';
    public const NEVER = 'never';
    public const DATE = 'date';
    public const DAYS = 'days';
    public const WEEKDAY = 'weekday';
    public const START = 'start';
    public const END = 'end';

    private const WEEKDAYS = [
        'mon' => 1, 'monday' => 1, 'tue' => 2, 'tues' => 2, 'tuesday' => 2, 'wed' => 3, 'wednesday' => 3,
        'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4, 'fri' => 5, 'friday' => 5,
        'sat' => 6, 'saturday' => 6, 'sun' => 7, 'sunday' => 7,
    ];

    private function __construct(
        public readonly string $kind,
        public readonly string $text,
        private readonly ?string $date = null,   // YYYY-MM-DD
        private readonly int $sign = 1,
        private readonly int $amount = 0,        // days, a weekday (1-7), or minutes for start/end
        private readonly ?string $time = null,   // HH:MM
    ) {
    }

    public static function parse(string $text, bool $allowRelative = true): self
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $lower = strtolower($text);
        if ($lower === 'always' || $lower === 'never') {
            return new self($lower, $lower);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{1,2}:\d{2}))?$/', $text, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidArgumentException("not a date: $text");
            }
            return new self(self::DATE, $text, "$m[1]-$m[2]-$m[3]", time: self::time($m[4] ?? null, $text));
        }
        if (!$allowRelative) {
            throw new InvalidArgumentException("expected always, never or YYYY-MM-DD [HH:MM]: $text");
        }
        if (preg_match('/^([+-])(\d{1,4})(?: (\d{1,2}:\d{2}))?$/', $text, $m)) {
            return new self(self::DAYS, $text, null, $m[1] === '-' ? -1 : 1, (int) $m[2], self::time($m[3] ?? null, $text));
        }
        if (preg_match('/^([+-])([a-z]+)(?: (\d{1,2}:\d{2}))?$/i', $text, $m) && isset(self::WEEKDAYS[strtolower($m[2])])) {
            return new self(self::WEEKDAY, $text, null, $m[1] === '-' ? -1 : 1, self::WEEKDAYS[strtolower($m[2])], self::time($m[3] ?? null, $text));
        }
        if (preg_match('/^(start|end)(?: ?([+-]) ?(\d{1,4})(m|h))?$/i', $text, $m)) {
            $minutes = isset($m[3]) ? (int) $m[3] * (strtolower($m[4]) === 'h' ? 60 : 1) : 0;
            return new self(strtolower($m[1]), $text, null, ($m[2] ?? '+') === '-' ? -1 : 1, $minutes);
        }
        throw new InvalidArgumentException("not a time: $text");
    }

    private static function time(?string $time, string $text): ?string
    {
        if ($time === null) {
            return null;
        }
        [$h, $i] = array_map('intval', explode(':', $time));
        if ($h > 23 || $i > 59) {
            throw new InvalidArgumentException("not a time of day: $text");
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    public function isRelative(): bool
    {
        return in_array($this->kind, [self::DAYS, self::WEEKDAY, self::START, self::END], true);
    }

    /**
     * The moment as a timestamp: null for always (as an opening) / never; a closing
     * date without a time is the end of that day.
     */
    public function resolve(DateTimeZone $zone, ?EventTime $event, bool $closing = false): ?int
    {
        switch ($this->kind) {
            case self::ALWAYS:
            case self::NEVER:
                return null;
            case self::START:
                return $event === null ? null : $event->start()->getTimestamp() + $this->sign * $this->amount * 60;
            case self::END:
                return $event === null ? null : $event->end()->getTimestamp() + $this->sign * $this->amount * 60;
        }
        if ($this->kind === self::DATE) {
            $day = new DateTimeImmutable($this->date . ' 00:00', $zone);
        } elseif ($event === null) {
            return null;
        } elseif ($this->kind === self::DAYS) {
            $day = $event->date->modify(($this->sign < 0 ? '-' : '+') . $this->amount . ' days');
        } else {
            $current = (int) $event->date->format('N');
            if ($this->sign < 0) { // on or before
                $day = $event->date->modify('-' . (($current - $this->amount + 7) % 7) . ' days');
            } else {               // strictly after
                $ahead = ($this->amount - $current + 7) % 7;
                $day = $event->date->modify('+' . ($ahead === 0 ? 7 : $ahead) . ' days');
            }
        }
        $day = $day->setTimezone($zone);
        if ($this->time !== null) {
            [$h, $i] = array_map('intval', explode(':', $this->time));
            return $day->setTime($h, $i)->getTimestamp();
        }
        return ($closing ? $day->setTime(0, 0)->modify('+1 day') : $day->setTime(0, 0))->getTimestamp();
    }
}
