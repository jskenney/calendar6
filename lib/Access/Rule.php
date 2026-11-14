<?php

declare(strict_types=1);

namespace Cal6\Access;

use Cal6\Time\Window;

/** One line of security.ini or of a user's [grants]: target = window. */
final class Rule
{
    public function __construct(
        public readonly string $target,
        public readonly Window $window,
        public readonly string $source,   // "security.ini:12" or "auth/jdoe.ini:8"
        public readonly bool $grant = false,
        public readonly int $line = 0,
        public readonly ?string $unreadable = null,   // the text that could not be read (the rule is then "never")
    ) {
    }

    public function describe(): string
    {
        return $this->unreadable !== null
            ? $this->target . ' = ' . $this->unreadable . ' (can not be read: hidden)'
            : $this->target . ' = ' . $this->window->text;
    }
}
