<?php

declare(strict_types=1);

namespace Cal6\Access;

use InvalidArgumentException;

/**
 * Event targets with ranges and lists (DESIGN.md, `default.ini`):
 * `class_20..27/notes` is the same as `class_20/notes` ... `class_27/notes`, and
 * `class_6,8,22` / `class_1..3,7` work the same way.
 */
final class Target
{
    private const LIMIT = 2000;

    /** @return list<string> the targets one written target stands for, in order */
    public static function expand(string $target): array
    {
        $target = trim($target);
        if ($target === '') {
            throw new InvalidArgumentException('empty target');
        }
        if (!preg_match('/^([A-Za-z][A-Za-z0-9-]*)_([0-9][0-9.,]*)(\/.*)?$/', $target, $m)) {
            return [$target];
        }
        // one number is read as a number too, so class_01 is class_1 (M-35)
        $numbers = self::numbers($m[2]);
        return array_map(static fn (int $n): string => $m[1] . '_' . $n . ($m[3] ?? ''), $numbers);
    }

    /** @return list<int> "20..27", "6,8,22", "1..3,7" */
    public static function numbers(string $spec): array
    {
        $numbers = [];
        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^(\d+)\.\.(\d+)$/', $part, $r)) {
                [$from, $to] = [(int) $r[1], (int) $r[2]];
                if ($from > $to || $to - $from > self::LIMIT) {
                    throw new InvalidArgumentException("not a range: $part");
                }
                for ($n = $from; $n <= $to; $n++) {
                    $numbers[] = $n;
                }
            } elseif (preg_match('/^\d+$/', $part)) {
                $numbers[] = (int) $part;
            } else {
                throw new InvalidArgumentException("not a number, range or list: $spec");
            }
            if (count($numbers) > self::LIMIT) {
                throw new InvalidArgumentException("too many events: $spec");
            }
        }
        return $numbers;
    }
}
