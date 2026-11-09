<?php

declare(strict_types=1);

namespace Cal6\Access;

use Cal6\Ini\IniFile;
use Cal6\Ini\IniLine;
use Cal6\Support\Problem;
use Cal6\Time\Window;
use InvalidArgumentException;

/**
 * A set of release rules, by target; the later line wins (v4).  Lines that a
 * later line overrides are remembered, so instructors can be told.
 */
final class Rules
{
    /** @var array<string, Rule> by target in lower case */
    public array $byTarget = [];
    /** @var list<Problem> */
    public array $problems = [];
    /** @var list<string> */
    public array $overridden = [];

    /**
     * A line whose target can be read but whose time can not is kept as "never" for
     * that target (fail closed: a typo must not release anything); a line whose target
     * can not be read is only a problem.
     *
     * @param list<IniLine> $lines
     */
    public static function fromLines(array $lines, string $label, bool $grant = false): self
    {
        $rules = new self();
        foreach ($lines as $line) {
            try {
                $targets = Target::expand($line->key);
            } catch (InvalidArgumentException $e) {
                $rules->problems[] = new Problem($e->getMessage(), $label, $line->line);
                continue;
            }
            $unreadable = null;
            try {
                $window = Window::parse($line->value);
            } catch (InvalidArgumentException $e) {
                $rules->problems[] = new Problem($e->getMessage() . ' (the target is hidden until this is fixed)', $label, $line->line);
                $window = Window::parse('never');
                $unreadable = $line->value;
            }
            foreach ($targets as $target) {
                $key = self::key($target);
                if (isset($rules->byTarget[$key])) {
                    $rules->overridden[] = sprintf('%s line %d (%s) is overridden by line %d', $label, $rules->byTarget[$key]->line, $target, $line->line);
                }
                $rules->byTarget[$key] = new Rule($target, $window, "$label:$line->line", $grant, $line->line, $unreadable);
            }
        }
        return $rules;
    }

    public static function fromIni(IniFile $file, string $section = ''): self
    {
        $rules = self::fromLines($file->section($section), $file->label);
        array_unshift($rules->problems, ...$file->problems);
        return $rules;
    }

    /** Targets are not case-sensitive (M-14): Class_1/Notes.html is class_1/notes.html. */
    public function get(string $target): ?Rule
    {
        return $this->byTarget[self::key($target)] ?? null;
    }

    public static function key(string $target): string
    {
        return mb_strtolower($target, 'UTF-8');
    }

    /** This set with $other laid over it: a rule of $other replaces the rule for the same target. */
    public function overlay(self $other): self
    {
        $merged = new self();
        // not array_merge: it renumbers targets made of digits ("2024"), losing them (L-26)
        $merged->byTarget = $this->byTarget;
        foreach ($other->byTarget as $target => $rule) {
            $merged->byTarget[$target] = $rule;
        }
        return $merged;
    }
}
