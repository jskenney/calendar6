<?php

declare(strict_types=1);

namespace Cal6\Access;

use Cal6\Time\Window;

/** One line of default.ini or of a user's [grants]: target = window. */
final class Rule
{
    public function __construct(
        public readonly string $target,
        public readonly Window $window,
        public readonly string $source,   // "default.ini:12" or "auth/jdoe.ini:8"
        public readonly bool $grant = false,
    ) {
    }

    public function describe(): string
    {
        return $this->target . ' = ' . $this->window->text;
    }
}
