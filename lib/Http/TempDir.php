<?php

declare(strict_types=1);

namespace Cal6\Http;

/**
 * A private directory per course outside the web root, for the answer cache and
 * the login rate limiter: the system temporary directory, in a folder named by a
 * hash of the course path.  It must belong to us and be closed to others, or it
 * is not used (another account could have created it first).
 */
final class TempDir
{
    public static function for(string $courseDir, string $sub): ?string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $base = rtrim(sys_get_temp_dir(), '/') . '/cal6-' . $uid . '-' . substr(sha1((string) realpath($courseDir)), 0, 16);
        $dir = "$base/$sub";
        foreach ([$base, $dir] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
                return null;
            }
            clearstatcache(true, $path);
            if (is_link($path) || (fileperms($path) & 0077) !== 0 || fileowner($path) !== $uid) {
                return null;
            }
        }
        return $dir;
    }

    /** Write a file atomically (readers never see half of it). */
    public static function write(string $path, string $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $data, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $path);
        }
        @unlink($tmp);
    }
}
