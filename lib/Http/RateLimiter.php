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
        if (random_int(1, 100) === 1) {
            $this->sweep($now);
        }
        return $this->locked($key, max(200, $limit), function (array $times) use ($now, $limit): array {
            $times = array_values(array_filter($times, fn (int $t): bool => $t > $now - $this->seconds));
            if (count($times) >= $limit) {
                return [$times, max(1, $times[count($times) - $limit] + $this->seconds - $now)];
            }
            $times[] = $now;
            return [$times, 0];
        });
    }

    private const MAX_FILES = 20000;

    /**
     * Removes counters with no attempt inside the window, and the oldest ones beyond
     * MAX_FILES, so trying many addresses or usernames can not fill the disk (L-16).
     */
    public function sweep(int $now): void
    {
        if ($this->dir === null) {
            return;
        }
        clearstatcache(); // mtimes read earlier in this request (an earlier sweep) may be stale
        $files = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime < $now - $this->seconds) {
                @unlink($file);
            } else {
                $files[$file] = $mtime;
            }
        }
        if (count($files) > self::MAX_FILES) {
            asort($files);
            foreach (array_slice(array_keys($files), 0, count($files) - self::MAX_FILES) as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * One attempt per $interval seconds for $key: 0 when this attempt may go ahead (and
     * the next one must wait), else the seconds to wait.  Nothing sleeps (M-18).
     */
    public function gate(string $key, int $now, int $interval): int
    {
        if ($this->dir === null) {
            return $this->seconds;
        }
        return $this->locked('gate:' . $key, 200, static function (array $times) use ($now, $interval): array {
            $next = $times === [] ? 0 : max($times);
            if ($now < $next) {
                return [$times, $next - $now];
            }
            return [[$now + $interval], 0];
        });
    }

    /**
     * The key for a client address: IPv6 addresses count per /64 network (M-18); an
     * IPv4-mapped address (::ffff:1.2.3.4) counts as its IPv4 address (M-26).
     */
    public static function addressKey(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($address);
            if ($packed !== false) {
                if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                    return (string) inet_ntop(substr($packed, 12));
                }
                return bin2hex(substr($packed, 0, 8)) . '::/64';
            }
        }
        return $address;
    }

    /** Takes back the latest attempt of $key (a login that succeeded does not count). */
    public function forgive(string $key): void
    {
        if ($this->dir !== null) {
            $this->locked($key, PHP_INT_MAX, static function (array $times): array {
                array_pop($times);
                return [$times, 0];
            });
        }
    }

    /** @param callable(list<int>): array{0: list<int>, 1: int} $change */
    /** $keep: how many attempts to keep, at least the limit, so any limit can trigger (L-125). */
    private function locked(string $key, int $keep, callable $change): int
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
            fwrite($handle, (string) json_encode($keep >= count($times) ? $times : array_slice($times, -$keep)));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }
}
