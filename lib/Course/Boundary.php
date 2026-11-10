<?php

declare(strict_types=1);

namespace Cal6\Course;

use Cal6\Http\Download;

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

    /** Server-side script extensions, one list for the scripts and their backups (M-29). */
    private const SCRIPT = 'php\d*|phtml?|phar|phps|pht|inc|ini';

    /**
     * Files that are never sent: server-side scripts (also under other names and as backup
     * copies: .phps, .pht, .inc, grades.php~, db.php.bak, x.phar_old, L-28, M-29) and configuration.
     */
    public static function neverServed(string $name): bool
    {
        // an include fragment written as a page (menu.inc.html, part.inc.md) is a page (L-57)
        $base = (string) preg_replace('/\.inc(?=\.(?:html?|md)$)/i', '', basename($name));
        return (bool) preg_match('/\.(' . self::SCRIPT . '|htaccess|htpasswd)$/i', $base)
            || (bool) preg_match('/\.(' . self::SCRIPT . ')[^a-z0-9]/i', $base)
            || self::isPageBackup($name);
    }

    /**
     * An editor's or version tool's copy of a page (index.html~, notes.md.bak, x.html.orig,
     * index.html.~1~, x.html_bak, x.html,v, #index.html#): raw page source, so never served by
     * either endpoint (M-22, M-29).  Any name with .html, .htm or .md followed by something that
     * is not a letter or digit, unless it ends in a file type that is served as such
     * (report.html.pdf, slides.md.zip, notes.html.md).
     */
    public static function isPageBackup(string $name): bool
    {
        $base = basename($name);
        if (preg_match('/^#.+#$/', $base)) {
            return true;
        }
        // hand-made copies: index.html2, notes.mdold, x.htmlbak (L-78); not x.md5 (a checksum)
        if (preg_match('/\.(?:html?\d+|(?:html?|md)(?:old|bak|backup|orig|save|copy|prev|new|tmp|swp|swo))$/i', $base)) {
            return true;
        }
        if (!preg_match('/\.(html?|md)[^a-z0-9]/i', $base)) {
            return false;
        }
        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        // a name that ends in a page or another served type is that (x.html.md is a page)
        return !Download::isKnownType($extension);
    }
}
