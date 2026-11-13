<?php

declare(strict_types=1);

namespace Cal6\Http;

use Cal6\Course\ConfigError;

/**
 * Finds the course (DESIGN.md, "Finding the course"): SCRIPT_FILENAME keeps the path
 * the script was reached by (.../AB123/calendar/php/get.php, or in the web layout
 * .../AB123/web/calendar/php/get.php), so the course is two (or three) levels above
 * php/.  Before anything is read, the calendar directory must lead to the code that is
 * running and course/config/config.ini must exist.
 */
final class Locator
{
    /**
     * The course directory: the software is reached as course/calendar/ or, in the web layout,
     * as course/web/calendar/ (the front end's repositories beside it in web/). The directory
     * must be named calendar and lead to the running code; the course is whichever of the two
     * holds config/config.ini.
     */
    public static function courseDir(array $server): string
    {
        $script = (string) ($server['SCRIPT_FILENAME'] ?? '');
        $calendar = dirname($script, 2);
        $software = realpath(__DIR__ . '/../..');
        if ($script === '' || basename($calendar) !== 'calendar' || realpath($calendar) !== $software) {
            throw new ConfigError('the course directory can not be found: the calendar must be reached as course_directory/calendar/ or course_directory/web/calendar/');
        }
        $parent = dirname($calendar);
        if (is_file("$parent/config/config.ini")) {
            return $parent;
        }
        if (basename($parent) === 'web' && is_file(dirname($parent) . '/config/config.ini')) {
            return dirname($parent);
        }
        throw new ConfigError('course_directory/config/config.ini is missing');
    }

    /** The URL path of the course directory, for the session cookie: /~user/AB123/ */
    public static function coursePath(array $server): string
    {
        $levels = self::inWebLayout($server) ? 4 : 3;
        $path = dirname((string) ($server['SCRIPT_NAME'] ?? '/'), $levels);
        $path = str_replace('\\', '/', $path);
        return rtrim($path, '/') . '/';
    }

    /** Where the software is, from the course's URL: "calendar/" or "web/calendar/". */
    public static function softwarePath(array $server): string
    {
        return self::inWebLayout($server) ? 'web/calendar/' : 'calendar/';
    }

    /** Whether the software was reached as course/web/calendar/ (no config/ beside calendar/). */
    private static function inWebLayout(array $server): bool
    {
        $parent = dirname((string) ($server['SCRIPT_FILENAME'] ?? ''), 3);
        return basename($parent) === 'web' && !is_file("$parent/config/config.ini");
    }
}
