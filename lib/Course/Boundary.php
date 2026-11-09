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

    public function __construct(string $courseDir)
    {
        $this->real = rtrim((string) realpath($courseDir), '/');
    }

    public function root(): string
    {
        return $this->real;
    }

    /** The real path of $path when it is inside the course and may be read, else null. */
    public function resolve(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, $this->real . '/')) {
            return null;
        }
        $relative = substr($real, strlen($this->real) + 1);
        $first = explode('/', $relative)[0];
        if ($first === 'config' || $first === 'calendar') {
            return null;
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part[0] === '.') {
                return null;
            }
        }
        return $real;
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

    /** Files that are never sent: server-side scripts and configuration. */
    public static function neverServed(string $name): bool
    {
        return (bool) preg_match('/\.(php\d?|phtml|phar|ini|htaccess|htpasswd)$/i', $name);
    }
}
