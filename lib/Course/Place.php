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
        public readonly bool $asset = false,      // a file a paths.ini page links to (decided by the page's rule)
        public readonly ?string $assetOf = null,  // the key of the page whose rules decide this file (virtual files' links)
    ) {
    }

    /** "class_6/notes.html", "class_5@2026-11-11/notes.html", "page/policy", "page/policy/pages/logo.png" */
    public function id(): string
    {
        if ($this->event !== null) {
            return $this->event->id . '/' . $this->file->name;
        }
        return 'page/' . $this->page . ($this->asset ? '/' . $this->file->name : '');
    }
}
