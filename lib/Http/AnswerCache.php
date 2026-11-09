<?php

declare(strict_types=1);

namespace Cal6\Http;

/**
 * calendar.php's answers, one per viewer (all anonymous viewers share one).  An
 * answer is reused while the course is unchanged (Fingerprint) and until its
 * next_change, the earliest moment at which a rule, grant, window or the lockdown
 * changes what that viewer sees.  Only a speed-up: deleting it is always safe.
 */
final class AnswerCache
{
    public function __construct(private readonly ?string $dir)
    {
    }

    public static function forCourse(string $courseDir): self
    {
        return new self(TempDir::for($courseDir, 'answers'));
    }

    public function get(string $name, string $fingerprint, int $now): ?string
    {
        if ($this->dir === null) {
            return null;
        }
        $raw = @file_get_contents($this->path($name));
        $entry = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($entry) || ($entry['fingerprint'] ?? null) !== $fingerprint || !is_string($entry['body'] ?? null)) {
            return null;
        }
        if (isset($entry['until']) && is_int($entry['until']) && $now >= $entry['until']) {
            return null;
        }
        return $entry['body'];
    }

    public function put(string $name, string $fingerprint, ?int $until, string $body): void
    {
        if ($this->dir !== null) {
            TempDir::write($this->path($name), (string) json_encode(['fingerprint' => $fingerprint, 'until' => $until, 'body' => $body]));
        }
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . sha1($name) . '.json';
    }
}
