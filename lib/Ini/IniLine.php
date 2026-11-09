<?php

declare(strict_types=1);

namespace Cal6\Ini;

/** One `key = value` line of an .ini file. */
final class IniLine
{
    public function __construct(
        public readonly string $section,
        public readonly string $key,
        public readonly string $value,
        public readonly int $line,
    ) {
    }
}
