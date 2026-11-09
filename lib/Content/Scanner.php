<?php

declare(strict_types=1);

namespace Cal6\Content;

use Cal6\Course\Boundary;
use Cal6\Course\CourseConfig;
use Cal6\Support\Problem;

/**
 * Walks the material: one directory per event type, one directory per event
 * (`<sort>.<Title>`, natural order), and the files in it (subdirectories too, so
 * pages can link to img/pic.png).  Names starting with a dot are ignored.
 */
final class Scanner
{
    private const MAX_DEPTH = 4;

    /** @var list<Problem> */
    public array $problems = [];

    public function __construct(private readonly CourseConfig $config, private readonly Boundary $boundary)
    {
    }

    /** @return array<string, list<EventFolder>> type => events */
    public function scan(): array
    {
        $events = [];
        foreach (array_keys($this->config->types) as $type) {
            $events[$type] = [];
            $dir = $this->boundary->resolve($this->boundary->root() . '/' . $type);
            if ($dir === null || !is_dir($dir)) {
                continue;
            }
            $names = [];
            foreach (scandir($dir) ?: [] as $name) {
                if ($name[0] === '.' || !is_dir("$dir/$name")) {
                    continue;
                }
                if ($this->boundary->resolve("$dir/$name") === null) {
                    $this->problems[] = new Problem("$type/$name leads outside the course directory and is ignored");
                    continue;
                }
                $names[] = $name;
            }
            usort($names, 'strnatcmp');
            foreach ($names as $name) {
                $this->checkName("$type/$name");
            }
            $this->checkCase($type, $names);
            foreach ($names as $i => $name) {
                $path = (string) realpath("$dir/$name");
                $events[$type][] = new EventFolder($type, $i + 1, $name, EventFolder::titleOf($name), $path, $this->files($path, '', 0));
            }
        }
        return $events;
    }

    /** Names that are not UTF-8 are shown with a replacement character; tell instructors (M-1). */
    private function checkName(string $path): void
    {
        if (!mb_check_encoding($path, 'UTF-8')) {
            $this->problems[] = new Problem('this name is not UTF-8 and is shown with a replacement character; rename it: ' . mb_convert_encoding($path, 'UTF-8', 'ISO-8859-1'));
        }
    }

    /**
     * Rules are not case-sensitive, so two names in one place that differ only in case
     * (Notes.html and notes.html) would share every rule: tell instructors (M-14).
     *
     * @param list<string> $names
     */
    private function checkCase(string $where, array $names): void
    {
        $seen = [];
        foreach ($names as $name) {
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                $this->problems[] = new Problem("$where: \"$seen[$key]\" and \"$name\" differ only in case, so rules for one also apply to the other; rename one");
            }
            $seen[$key] = $name;
        }
    }

    /** @return array<string, ContentFile> */
    public function files(string $dir, string $prefix, int $depth): array
    {
        $files = [];
        $entries = scandir($dir) ?: [];
        sort($entries, SORT_STRING);
        $this->checkCase(basename($dir) . ($prefix !== '' ? '/' . rtrim($prefix, '/') : ''), array_values(array_filter($entries, static fn (string $e): bool => $e[0] !== '.')));
        foreach ($entries as $name) {
            if ($name[0] === '.') {
                continue;
            }
            $real = $this->boundary->resolve("$dir/$name");
            if ($real === null) {
                continue;
            }
            if (is_dir($real)) {
                if ($depth < self::MAX_DEPTH) {
                    $files += $this->files($real, "$prefix$name/", $depth + 1);
                }
                continue;
            }
            if (is_file($real) && Boundary::isPageBackup($name)) {
                $this->problems[] = new Problem(basename($dir) . "/$prefix$name looks like an editor's backup of a page: it is never served; remove it");
            }
            if (!is_file($real) || Boundary::neverServed($name) || Boundary::neverServed($real)) {
                continue;
            }
            $this->checkName(basename($dir) . "/$prefix$name");
            $files[$prefix . $name] = new ContentFile($prefix . $name, $real, (int) filesize($real), (int) filemtime($real), $prefix === '');
        }
        return $files;
    }
}
