<?php

declare(strict_types=1);

namespace Cal6\Http;

use Cal6\Course\Course;

/**
 * The built course model, shared by every endpoint while the course is unchanged
 * (same Fingerprint), so get.php and html.php do not walk the whole course on every
 * request (M-9).  The file is signed with the course secret and read back only when
 * the signature matches.
 */
final class ModelCache
{
    public function __construct(private readonly ?string $dir, private readonly string $secret)
    {
    }

    public static function forCourse(string $courseDir, string $secret): self
    {
        return new self(TempDir::for($courseDir, 'model'), $secret);
    }

    public function get(string $fingerprint): ?Course
    {
        if ($this->dir === null) {
            return null;
        }
        $raw = @file_get_contents($this->dir . '/model');
        if (!is_string($raw) || strlen($raw) < 130) {
            return null;
        }
        $signature = substr($raw, 0, 64);
        $body = substr($raw, 65);
        if (!hash_equals(hash_hmac('sha256', $body, $this->secret), $signature) || !str_starts_with($body, $fingerprint . "\n")) {
            return null;
        }
        $course = @unserialize(substr($body, strlen($fingerprint) + 1));
        return $course instanceof Course ? $course : null;
    }

    public function put(string $fingerprint, Course $course): void
    {
        if ($this->dir !== null) {
            $body = $fingerprint . "\n" . serialize($course);
            TempDir::write($this->dir . '/model', hash_hmac('sha256', $body, $this->secret) . "\n" . $body);
        }
    }
}
