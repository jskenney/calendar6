<?php

declare(strict_types=1);

namespace Cal6\Http;

/** Failed logins (and lock codes) per address and per username, kept outside the course. */
final class RateLimiter
{
    public function __construct(private readonly ?string $dir, private readonly int $limit, private readonly int $seconds)
    {
    }

    public static function forCourse(string $courseDir, int $limit, int $seconds): self
    {
        return new self(TempDir::for($courseDir, 'limits'), $limit, $seconds);
    }

    /** Seconds until another attempt is allowed, 0 when allowed now. */
    public function wait(string $key, int $now): int
    {
        $times = $this->times($key, $now);
        if (count($times) < $this->limit) {
            return 0;
        }
        return max(1, $times[count($times) - $this->limit] + $this->seconds - $now);
    }

    public function fail(string $key, int $now): void
    {
        if ($this->dir === null) {
            return;
        }
        $times = $this->times($key, $now);
        $times[] = $now;
        TempDir::write($this->path($key), (string) json_encode(array_slice($times, -100)));
    }

    public function clear(string $key): void
    {
        if ($this->dir !== null) {
            @unlink($this->path($key));
        }
    }

    /** @return list<int> */
    private function times(string $key, int $now): array
    {
        if ($this->dir === null) {
            return [];
        }
        $raw = @file_get_contents($this->path($key));
        $times = is_string($raw) ? json_decode($raw, true) : [];
        $times = is_array($times) ? array_values(array_filter($times, fn ($t): bool => is_int($t) && $t > $now - $this->seconds)) : [];
        sort($times);
        return $times;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.json';
    }
}
