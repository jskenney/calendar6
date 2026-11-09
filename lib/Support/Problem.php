<?php

declare(strict_types=1);

namespace Cal6\Support;

/** Something wrong in a course's files, shown to instructors only. */
final class Problem
{
    public function __construct(
        public readonly string $message,
        public readonly string $file = '',
        public readonly int $line = 0,
    ) {
    }

    /** @return array{message: string, file: string, line: int} */
    public function toArray(): array
    {
        return ['message' => $this->message, 'file' => $this->file, 'line' => $this->line];
    }
}
