<?php

declare(strict_types=1);

namespace Cal6\Http;

/**
 * Attempts (logins, lock codes) per key, kept outside the course in the private
 * temporary directory.  Each check-and-record holds a lock on the counter file, so
 * parallel requests can not slip past the limit.  Without storage the limiter is
 * unavailable, and callers refuse the attempt (fail closed).
 */
final class RateLimiter
{
    public function __construct(private readonly ?string $dir, private readonly int $seconds)
    {
    }

    public static function forCourse(string $courseDir, int $seconds): self
    {
        return new self(TempDir::for($courseDir, 'limits'), $seconds);
    }

    public function available(): bool
    {
        return $this->dir !== null;
    }

    /**
     * Records an attempt for $key unless $limit attempts were made in the window.
     * Returns 0 when recorded, else the seconds until another attempt is allowed.
     */
    public function hit(string $key, int $now, int $limit): int
    {
        if ($this->dir === null) {
            return $this->seconds;
        }
        return $this->locked($key, function (array $times) use ($now, $limit): array {
            $times = array_values(array_filter($times, fn (int $t): bool => $t > $now - $this->seconds));
            if (count($times) >= $limit) {
                return [$times, max(1, $times[count($times) - $limit] + $this->seconds - $now)];
            }
            $times[] = $now;
            return [$times, 0];
        });
    }

    /** Takes back the latest attempt of $key (a login that succeeded does not count). */
    public function forgive(string $key): void
    {
        if ($this->dir !== null) {
            $this->locked($key, static function (array $times): array {
                array_pop($times);
                return [$times, 0];
            });
        }
    }

    /** @param callable(list<int>): array{0: list<int>, 1: int} $change */
    private function locked(string $key, callable $change): int
    {
        $handle = @fopen($this->dir . '/' . sha1($key) . '.json', 'c+');
        if ($handle === false) {
            return $this->seconds;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return $this->seconds;
            }
            $raw = stream_get_contents($handle);
            $times = is_string($raw) ? json_decode($raw, true) : [];
            $times = is_array($times) ? array_values(array_filter($times, 'is_int')) : [];
            sort($times);
            [$times, $result] = $change($times);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(array_slice($times, -200)));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }
}
