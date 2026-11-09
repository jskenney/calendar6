<?php

declare(strict_types=1);

namespace Cal6\Schedule;

use Cal6\Content\ContentFile;
use Cal6\Content\EventFolder;
use Cal6\Time\EventTime;

/** An event on the calendar (or, for instructors, one on no calendar day). */
final class ScheduledEvent
{
    /** @var array<string, ContentFile> the folder's files plus virtual.ini files, by name */
    public array $files = [];

    public function __construct(
        public readonly string $id,          // class_6, or class_5@2026-11-11 for a "same" repeat
        public readonly string $type,
        public readonly int $number,
        public readonly ?EventFolder $folder,
        public readonly ?string $date,       // Y-m-d, null when on no calendar day
        public readonly ?EventTime $time,
        public readonly ?string $copyOf = null,
        public readonly ?string $mode = null,
    ) {
        $this->files = $folder?->files ?? [];
    }

    /** The name rules and grants use: type_number. */
    public function target(): string
    {
        return $this->type . '_' . $this->number;
    }

    public function title(): string
    {
        return $this->folder?->title ?? '';
    }
}
