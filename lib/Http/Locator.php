<?php

declare(strict_types=1);

namespace Cal6\Http;

use Cal6\Course\ConfigError;
use Cal6\Ini\IniFile;

/**
 * Finds the course (DESIGN.md, "Finding the course"). The software is reached through a web
 * directory (…/public_html/AB123/calendar/php/get.php: SCRIPT_FILENAME keeps the path the
 * script was reached by). The course itself lives anywhere, outside the web tree: course.ini,
 * next to the software (…/AB123/course.ini) or inside it (…/AB123/calendar/course.ini), says
 * where its root is ([course] root = /home/faculty/bob/AB123), and that root must hold
 * config/config.ini. Before anything is read, the software's directory must lead to the code
 * that is running.
 */
final class Locator
{
    public static function courseDir(array $server): string
    {
        $script = (string) ($server['SCRIPT_FILENAME'] ?? '');
        $software = dirname($script, 2);
        if ($script === '' || realpath($software) !== realpath(__DIR__ . '/../..')) {
            throw new ConfigError('the course can not be found: the script was not reached through the calendar software (with PHP-FPM, keep the linked path in SCRIPT_FILENAME)');
        }
        $ini = self::courseIni($software);
        if ($ini === null) {
            throw new ConfigError('course.ini is missing: put it next to the calendar directory (' . basename(dirname($software)) . '/course.ini) or inside it, with [course] root = the course directory');
        }
        $file = IniFile::load($ini, 'course.ini');
        if (!$file->readable) {
            throw new ConfigError('course.ini can not be read'); // L-150
        }
        $root = trim((string) $file->get('course', 'root', ''));
        if ($root === '') {
            throw new ConfigError('course.ini: [course] root is missing');
        }
        if ($root[0] !== '/') {
            // relative to course.ini's own directory (as the file system resolves it: after links)
            $root = dirname($ini) . '/' . $root;
        }
        $course = realpath($root);
        if ($course === false || !is_file("$course/config/config.ini")) {
            throw new ConfigError('course.ini: [course] root must be a directory that holds config/config.ini');
        }
        // anywhere at all (Decided): only config/config.ini is required. A course inside the web
        // tree is the instructor's choice (the web server then needs its own rules for it)
        return $course;
    }

    /**
     * course.ini next to the software's directory (in the web directory), else inside it, but
     * only when the software is a real directory (a clone for one course): a shared copy reached
     * through a link must never hand its course.ini to every web directory without one (L-147).
     */
    private static function courseIni(string $software): ?string
    {
        if (is_file(dirname($software) . '/course.ini')) {
            return dirname($software) . '/course.ini';
        }
        if (!is_link($software) && is_file($software . '/course.ini')) {
            return $software . '/course.ini';
        }
        return null;
    }

    /** A URL path with each segment percent-encoded, as browsers send and compare it (L-149). */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** The URL path of the web directory (the software's parent), for the cookies: /~bob/AB123/ */
    public static function coursePath(array $server): string
    {
        $path = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/'), 3));
        return self::encodePath(rtrim($path, '/')) . '/';
    }

    /** The software's directory under the web directory's URL, as requested: usually "calendar/". */
    public static function softwarePath(array $server): string
    {
        return rawurlencode(basename(dirname((string) ($server['SCRIPT_NAME'] ?? '/calendar/php/x.php'), 2))) . '/';
    }
}
