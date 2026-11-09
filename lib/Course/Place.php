<?php

declare(strict_types=1);

namespace Cal6\Course;

use Cal6\Content\ContentFile;
use Cal6\Schedule\ScheduledEvent;

/** A file in a place: an event's file, or a page from paths.ini.  Each has its own key. */
final class Place
{
    public function __construct(
        public readonly string $key,
        public readonly ContentFile $file,
        public readonly ?ScheduledEvent $event,   // null for pages
        public readonly ?string $page = null,     // paths.ini name
        public readonly ?string $box = null,
    ) {
    }

    /** "class_6/notes.html", "class_5@2026-11-11/notes.html", "page/policy" */
    public function id(): string
    {
        return $this->event !== null ? $this->event->id . '/' . $this->file->name : 'page/' . $this->page;
    }
}
