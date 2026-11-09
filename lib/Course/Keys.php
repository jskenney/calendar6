<?php

declare(strict_types=1);

namespace Cal6\Course;

/**
 * Keys name a file in a place; they are not capabilities (access is checked on
 * every request).  HMAC-SHA256(secret, place), shortened: stable, and hidden
 * names can not be guessed.
 */
final class Keys
{
    public static function make(string $secret, string $place): string
    {
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $place, $secret, true)), '+/', '-_'), '='), 0, 22);
    }

    public static function looksValid(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{22}$/', $key);
    }
}
