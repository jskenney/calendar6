<?php

declare(strict_types=1);

namespace Cal6\Http;

use Cal6\Course\ConfigError;

/**
 * Finds the course (DESIGN.md, "Finding the course"): SCRIPT_FILENAME keeps the path
 * the script was reached by (.../AB123/calendar/php/get.php), so the course is two
 * levels above php/.  Before anything is read, course/calendar must lead to the
 * code that is running and course/config/config.ini must exist.
 */
final class Locator
{
    public static function courseDir(array $server): string
    {
        $script = (string) ($server['SCRIPT_FILENAME'] ?? '');
        $calendar = dirname($script, 2);
        $course = dirname($calendar);
        $software = realpath(__DIR__ . '/../..');
        if ($script === '' || basename($calendar) !== 'calendar' || realpath($calendar) !== $software) {
            throw new ConfigError('the course directory can not be found: the calendar must be reached as course_directory/calendar/');
        }
        if (!is_file("$course/config/config.ini")) {
            throw new ConfigError('course_directory/config/config.ini is missing');
        }
        return $course;
    }

    /** The URL path of the course directory, for the session cookie: /~user/AB123/ */
    public static function coursePath(array $server): string
    {
        $path = dirname((string) ($server['SCRIPT_NAME'] ?? '/'), 3);
        $path = str_replace('\\', '/', $path);
        return rtrim($path, '/') . '/';
    }
}
