<?php

declare(strict_types=1);

namespace Cal6\Access;

/** Whether a viewer may see a file now, and why. */
final class Decision
{
    public function __construct(
        public readonly bool $visible,
        public readonly ?Rule $rule,          // the rule that decides it for anonymous viewers (null: no rule)
        public readonly ?int $opens = null,   // the next time it becomes visible, when known
        public readonly ?int $nextChange = null,
    ) {
    }
}
