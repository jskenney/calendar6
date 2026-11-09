<?php

declare(strict_types=1);

namespace Cal6\Course;

/**
 * The course directory is the boundary (DESIGN.md): nothing outside it is served
 * or run, and inside it config/, calendar/, names starting with a dot, .php and
 * .ini files are never served.  Paths are resolved with realpath(), so symbolic
 * links are followed and must still end inside the course.
 */
final class Boundary
{
    private string $real;
    /** @var list<string> real paths that are never served: config/ and calendar/ */
    private array $closed = [];

    public function __construct(string $courseDir)
    {
        $this->real = rtrim((string) realpath($courseDir), '/');
        foreach (['config', 'calendar'] as $name) {
            $closed = realpath($this->real . '/' . $name);
            if ($closed !== false) {
                $this->closed[] = $closed;
            }
        }
    }

    public function root(): string
    {
        return $this->real;
    }

    /** The real path of $path when it is inside the course and may be read, else null. */
    public function resolve(string $path): ?string
    {
        $real = self::realpath($path);
        if ($real === false || !str_starts_with($real, $this->real . '/')) {
            return null;
        }
        $relative = substr($real, strlen($this->real) + 1);
        $first = strtolower(explode('/', $relative)[0]);
        if ($first === 'config' || $first === 'calendar') {
            return null; // also Config/ on case-insensitive file systems (L-11)
        }
        foreach ($this->closed as $closed) {
            if ($real === $closed || str_starts_with($real, $closed . '/')) {
                return null;
            }
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part[0] === '.') {
                return null;
            }
        }
        return $real;
    }

    /** realpath() that treats a path with a NUL byte (a "%00" in a link) as missing instead of throwing (L-35). */
    public static function realpath(string $path): string|false
    {
        return str_contains($path, "\0") ? false : realpath($path);
    }

    /** A course-relative path from an .ini file: no absolute paths, no "..". */
    public function resolveRelative(string $relative): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || $relative[0] === '/' || preg_match('#(^|/)\.\.?(/|$)#', $relative)) {
            return null;
        }
        return $this->resolve($this->real . '/' . $relative);
    }

    public function relative(string $real): string
    {
        return substr($real, strlen($this->real) + 1);
    }

    /**
     * Files that are never sent: server-side scripts (also under other names and as backup
     * copies: .phps, .pht, .inc, grades.php~, db.php.bak, L-28) and configuration.
     */
    public static function neverServed(string $name): bool
    {
        return (bool) preg_match('/\.(php\d?|phtml|phar|phps|pht|inc|ini|htaccess|htpasswd)$/i', $name)
            || (bool) preg_match('/\.(php\d?|phtml|phps|inc|ini)[.~][^\/]*$/i', $name)
            || self::isPageBackup($name);
    }

    /**
     * An editor's backup of a page (index.html~, notes.md.bak, x.html.orig, #index.html#): raw
     * page source, so never served by either endpoint (M-22).
     */
    public static function isPageBackup(string $name): bool
    {
        $base = basename($name);
        return (bool) preg_match('/\.(html?|md)(~|\.(bak|orig|old|save|backup|tmp|swp|rej|copy|\d+))$/i', $base)
            || (bool) preg_match('/^#.+#$/', $base);
    }
}
