<?php

declare(strict_types=1);

namespace Cal6\Http;

/**
 * A hash of the names, sizes and modification times of everything in the course
 * directory that the calendar reads (calendar/ and names starting with a dot are
 * skipped).  When it is unchanged, a cached answer can be reused.
 */
final class Fingerprint
{
    private const MAX_DEPTH = 7;

    public static function of(string $courseDir): string
    {
        $context = hash_init('sha1');
        $root = (string) realpath($courseDir);
        self::walk(rtrim($courseDir, '/'), '', 0, $context, $root);
        return hash_final($context);
    }

    private static function walk(string $dir, string $prefix, int $depth, \HashContext $context, string $root): void
    {
        $entries = @scandir($dir) ?: [];
        foreach ($entries as $name) {
            if ($name[0] === '.' || ($depth === 0 && $name === 'calendar')) {
                continue;
            }
            $path = "$dir/$name";
            if (is_link($path)) {
                // links are followed only while they stay inside the course (M-10)
                $real = realpath($path);
                if ($real === false || !str_starts_with($real, $root . '/')) {
                    hash_update($context, "$prefix$name\0link\n");
                    continue;
                }
            }
            $stat = @stat($path);
            if ($stat === false) {
                continue;
            }
            hash_update($context, "$prefix$name\0{$stat['size']}\0{$stat['mtime']}\n");
            if (is_dir($path) && $depth < self::MAX_DEPTH) {
                self::walk($path, "$prefix$name/", $depth + 1, $context, $root);
            }
        }
    }
}
