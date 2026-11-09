<?php

declare(strict_types=1);

namespace Cal6\Schedule;

use DateTimeImmutable;

/** One calendar day after config.ini [schedule] and dates.ini. */
final class Day
{
    /** @var list<string> event types, in order */
    public array $types = [];
    /** @var list<string> ids of the events placed on this day */
    public array $events = [];
    public ?string $kind = null;      // holiday
    public ?string $label = null;
    public bool $explicit = false;    // dates.ini chose the events
    /** @var list<array{0: int, 1: int}> dates.ini holiday/none windows with times */
    public array $cancel = [];

    public function __construct(
        public readonly DateTimeImmutable $date,
        public int $weekday,              // whose schedule the day follows (1-7)
        public string $period,
    ) {
    }

    public function key(): string
    {
        return $this->date->format('Y-m-d');
    }
}
