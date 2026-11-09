<?php

declare(strict_types=1);

namespace Cal6\Content;

/** One event directory: <type>/<sort>.<Title>/ */
final class EventFolder
{
    /** @param array<string, ContentFile> $files by name */
    public function __construct(
        public readonly string $type,
        public readonly int $index,       // 1-based, in natural order
        public readonly string $dirName,
        public readonly string $title,
        public readonly string $path,     // real path
        public readonly array $files,
    ) {
    }

    public static function titleOf(string $dirName): string
    {
        $dot = strpos($dirName, '.');
        return trim($dot === false ? $dirName : substr($dirName, $dot + 1));
    }
}
