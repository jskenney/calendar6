<?php

declare(strict_types=1);

namespace Cal6\Access;

use Cal6\Course\CourseConfig;
use Cal6\Ini\IniFile;
use Cal6\Time\Window;
use InvalidArgumentException;

/**
 * Who is asking: anonymous (every student), or an account from
 * config/auth/<username>.ini (instructors and staff).  The account file is read
 * on every request, so removing it or changing it takes effect at once.
 */
final class Viewer
{
    private function __construct(
        public readonly ?string $user,
        public readonly bool $instructor,
        public readonly ?Window $valid,
        public readonly Rules $grants,
        public readonly string $passwordHash = '',
        public readonly int $accountMtime = 0,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null, false, null, new Rules());
    }

    public static function validUsername(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name);
    }

    /** The account, or null when there is no such usable account. */
    /** Keys of the account itself, never grants. */
    private const OWN_KEYS = ['valid', 'instructor', 'password_hash'];

    public static function account(CourseConfig $config, string $name): ?self
    {
        if (!self::validUsername($name)) {
            return null;
        }
        $dir = realpath($config->configDir() . '/auth');
        $path = $dir === false ? false : realpath("$dir/$name.ini");
        if ($path === false || dirname($path) !== $dir || !is_file($path)) {
            return null;
        }
        $ini = IniFile::load($path, "auth/$name.ini");
        if (!$ini->readable) {
            return null;
        }
        // valid, instructor and password_hash are read wherever they are written: one placed
        // after [grants] is still the account's own (not a grant on a target named "valid", M-33)
        $own = static function (string $key, ?string $default = null) use ($ini): ?string {
            $value = $default;
            foreach ($ini->lines as $line) {
                if (strcasecmp($line->key, $key) === 0 && ($line->section === '' || strcasecmp($line->section, 'grants') === 0)) {
                    $value = $line->value;
                }
            }
            return $value;
        };
        $valid = $config->usersValid;
        $text = $own('valid');
        if ($text !== null && $text !== '') {
            try {
                $valid = Window::parse($text, false);
            } catch (InvalidArgumentException) {
                return null; // an account whose window can not be read can not log in
            }
        }
        $instructor = in_array(strtolower((string) $own('instructor', 'false')), ['true', 'yes', '1', 'on'], true);
        $grants = array_values(array_filter($ini->section('grants'), static fn ($line): bool => !in_array(strtolower($line->key), self::OWN_KEYS, true)));
        return new self($name, $instructor, $valid, Rules::fromLines($grants, "auth/$name.ini", true),
            (string) $own('password_hash', ''), (int) filemtime($path));
    }

    public function isValidAt(int $now, \DateTimeZone $zone): bool
    {
        return $this->user !== null && ($this->valid === null || $this->valid->isOpen($now, $zone, null));
    }

    /** The end of the account's window after $now, if any. */
    public function validUntil(int $now, \DateTimeZone $zone): ?int
    {
        return $this->valid?->bounds($zone, null)[1];
    }

    public function cacheName(): string
    {
        return $this->user === null ? 'anonymous' : 'user-' . $this->user;
    }
}
