<?php

declare(strict_types=1);

namespace Cal6\Time;

use DateTimeImmutable;

/**
 * What relative times are measured from: the event's calendar date (00:00 in the
 * course time zone) and, when sections or the schedule give times, the first
 * section's start and the last section's end that day.
 */
final class EventTime
{
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly ?DateTimeImmutable $start = null,
        public readonly ?DateTimeImmutable $end = null,
    ) {
    }

    public function start(): DateTimeImmutable
    {
        return $this->start ?? $this->date;
    }

    /** The last section's end, or the end of the day (the next day at 00:00) without times. */
    public function end(): DateTimeImmutable
    {
        return $this->end ?? $this->date->modify('+1 day');
    }
}
