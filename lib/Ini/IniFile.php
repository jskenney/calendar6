<?php

declare(strict_types=1);

namespace Cal6\Ini;

use Cal6\Support\Problem;

/**
 * The .ini files of a course (DESIGN.md, "Samples of every .ini file").
 *
 * Our own reader, not parse_ini_file(): every line keeps its number for messages,
 * keys may contain spaces, dots, slashes, commas and "..", values are never
 * interpreted (no "yes" => "1"), and a key may appear more than once (the
 * later line wins where that matters, but every line is kept in order).
 *
 * Syntax: `[section]`, `key = value`; whole-line comments start with `;` or `#`;
 * `;` after a space starts a comment at the end of a line.
 */
final class IniFile
{
    /** @var list<IniLine> */
    public array $lines = [];
    /** @var list<Problem> */
    public array $problems = [];
    public bool $exists = true;
    public bool $readable = true;

    public function __construct(public readonly string $label = '')
    {
    }

    public static function load(string $path, string $label): self
    {
        if (!file_exists($path)) {
            $file = new self($label);
            $file->exists = false;
            return $file;
        }
        $text = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            $file = new self($label);
            $file->readable = false;
            $file->problems[] = new Problem('the file can not be read', $label);
            return $file;
        }
        return self::parse($text, $label);
    }

    public static function parse(string $text, string $label = ''): self
    {
        $file = new self($label);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $section = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $raw) {
            $number = $index + 1;
            $line = self::stripComment($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[\s*([^\]]*?)\s*\]$/', $line, $m)) {
                $section = strtolower($m[1]);
                continue;
            }
            $equals = strpos($line, '=');
            if ($equals === false || trim(substr($line, 0, $equals)) === '') {
                $file->problems[] = new Problem('expected "key = value": ' . $line, $label, $number);
                continue;
            }
            $file->lines[] = new IniLine($section, trim(substr($line, 0, $equals)), trim(substr($line, $equals + 1)), $number);
        }
        return $file;
    }

    private static function stripComment(string $raw): string
    {
        $line = trim($raw);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            return '';
        }
        if (preg_match('/\s;/', $line, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr($line, 0, $m[0][1]);
        }
        return trim($line);
    }

    /** The value of the last `key` in `section`, or $default. */
    public function get(string $section, string $key, ?string $default = null): ?string
    {
        $value = $default;
        foreach ($this->lines as $line) {
            if ($line->section === $section && strcasecmp($line->key, $key) === 0) {
                $value = $line->value;
            }
        }
        return $value;
    }

    /** @return list<IniLine> the lines of one section, in order */
    public function section(string $section): array
    {
        return array_values(array_filter($this->lines, static fn (IniLine $l): bool => $l->section === $section));
    }

    /** @return list<string> section names, in order of first appearance */
    public function sections(): array
    {
        $names = [];
        foreach ($this->lines as $line) {
            $names[$line->section] = true;
        }
        return array_map('strval', array_keys($names));
    }

    public function problem(string $message, int $line = 0): void
    {
        $this->problems[] = new Problem($message, $this->label, $line);
    }
}
