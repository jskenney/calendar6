<?php

declare(strict_types=1);

namespace Cal6\Time;

use DateTimeZone;
use InvalidArgumentException;

/** `when [until when]`: visible while open <= now < close. */
final class Window
{
    private function __construct(
        public readonly When $open,
        public readonly ?When $close,
        public readonly string $text,
    ) {
    }

    public static function parse(string $text, bool $allowRelative = true): self
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            throw new InvalidArgumentException('expected a time');
        }
        $parts = preg_split('/ until /i', $text, 2) ?: [$text];
        $open = When::parse($parts[0], $allowRelative);
        $close = isset($parts[1]) ? When::parse($parts[1], $allowRelative) : null;
        if ($close !== null && in_array($close->kind, [When::ALWAYS, When::NEVER], true)) {
            throw new InvalidArgumentException('"until" needs a time, not always/never: ' . $text);
        }
        return new self($open, $close, $text);
    }

    public static function always(): self
    {
        return self::parse('always');
    }

    public function isAlways(): bool
    {
        return $this->open->kind === When::ALWAYS && $this->close === null;
    }

    public function isNever(): bool
    {
        return $this->open->kind === When::NEVER;
    }

    public function isRelative(): bool
    {
        return $this->open->isRelative() || ($this->close?->isRelative() ?? false);
    }

    /**
     * @return array{0: int|null, 1: int|null} open and close timestamps; open null = always,
     *         close null = no end; [PHP_INT_MAX, null] for never
     */
    public function bounds(DateTimeZone $zone, ?EventTime $event): array
    {
        if ($this->isNever() || ($this->isRelative() && $event === null)) {
            return [PHP_INT_MAX, null];
        }
        $open = $this->open->kind === When::ALWAYS ? null : $this->open->resolve($zone, $event);
        $close = $this->close?->resolve($zone, $event, true);
        return [$open, $close];
    }

    public function isOpen(int $now, DateTimeZone $zone, ?EventTime $event): bool
    {
        [$open, $close] = $this->bounds($zone, $event);
        return ($open === null || $open <= $now) && ($close === null || $now < $close);
    }

    /** The next moment after $now at which this window opens or closes, if any. */
    public function nextChange(int $now, DateTimeZone $zone, ?EventTime $event): ?int
    {
        $next = null;
        foreach ($this->bounds($zone, $event) as $moment) {
            if ($moment !== null && $moment !== PHP_INT_MAX && $moment > $now && ($next === null || $moment < $next)) {
                $next = $moment;
            }
        }
        return $next;
    }
}
