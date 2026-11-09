<?php

declare(strict_types=1);

namespace Cal6\Content;

/** One file of an event (or a page): its name in the event and its real file. */
final class ContentFile
{
    public function __construct(
        public readonly string $name,          // relative to the event directory: "notes.html", "img/a.png"
        public readonly string $realPath,
        public readonly int $size,
        public readonly int $mtime,
        public readonly bool $topLevel = true,
        public readonly ?string $virtualOf = null, // virtual.ini: the real file, relative to the course
    ) {
    }

    /**
     * "page" (served only by html.php, processed) or "file" (served only by get.php).
     * A page is .html, .htm or .md, by its name and by its real file: a link named
     * notes.txt that leads to notes.html is a page too.
     */
    public function kind(): string
    {
        return self::isPage($this->name) || self::isPage($this->realPath) ? 'page' : 'file';
    }

    public static function isPage(string $name): bool
    {
        return (bool) preg_match('/\.(html?|md)$/i', $name);
    }
}
