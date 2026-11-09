<?php

declare(strict_types=1);

namespace Cal6\Tests;

/**
 * Test courses: a copy of template/ with a secret, the `calendar` link to this
 * repository, and any files a test adds or replaces.
 */
final class Fixture
{
    public const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    public const PASSWORD = 'test-password-1';

    /** @param array<string, string|null> $files path => content (null removes) */
    public static function course(array $files = []): string
    {
        $repo = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/cal6-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        self::copy("$repo/template", $dir);
        symlink($repo, "$dir/calendar");
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        file_put_contents("$dir/config/auth/prof.ini", "password_hash = $hash\ninstructor = true\nvalid = always\n");
        file_put_contents("$dir/config/auth/ta.ini", "password_hash = $hash\nvalid = always\n[grants]\nlab_1 = always\nexam_1/solution.html = always\n");
        foreach ($files as $path => $content) {
            $full = "$dir/$path";
            if ($content === null) {
                self::removeTree($full);
                continue;
            }
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
        }
        $config = (string) file_get_contents("$dir/config/config.ini");
        file_put_contents("$dir/config/config.ini", (string) preg_replace('/^secret\s*=.*REPLACE.*$/m', 'secret   = ' . self::SECRET, $config));
        return $dir;
    }

    private static function copy(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_dir("$from/$name")) {
                mkdir("$to/$name");
                self::copy("$from/$name", "$to/$name");
            } else {
                copy("$from/$name", "$to/$name");
            }
        }
    }

    public static function remove(string $dir): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $real = realpath($dir);
        if ($real !== false) {
            self::removeTree(sys_get_temp_dir() . "/cal6-$uid-" . substr(sha1($real), 0, 16));
        }
        self::removeTree($dir);
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree("$dir/$name");
            }
        }
        rmdir($dir);
    }
}
