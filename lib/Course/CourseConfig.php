<?php

declare(strict_types=1);

namespace Cal6\Course;

use Cal6\Ini\IniFile;
use Cal6\Schedule\Periods;
use Cal6\Schedule\Sections;
use Cal6\Support\Problem;
use Cal6\Time\Window;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Everything in course_directory/config/ except the release rules and accounts
 * (DESIGN.md, "Samples of every .ini file").  Paths are relative to the course.
 */
final class CourseConfig
{
    public const WEEKDAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    public string $number = '';
    public string $name = '';
    public DateTimeZone $zone;
    public string $secret = '';
    public string $defaultPeriod = 'normal';
    public DateTimeImmutable $start;
    public DateTimeImmutable $end;
    public bool $weekends = false;
    /** @var array<int, array{type: string, start: ?string, end: ?string}> weekday (1-7) => entry */
    public array $schedule = [];
    /** @var array<string, string> type directory => label, in menu order */
    public array $types = [];
    /** @var list<array{box: string, files: list<string>, label: string}> */
    public array $boxes = [];
    public ?Window $lockdown = null;
    public ?Window $usersValid = null;
    public int $sessionSeconds = 8 * 3600;
    /** @var array{0: int, 1: int} failures allowed per seconds */
    public array $loginAttempts = [5, 900];
    /** @var array<string, string> */
    public array $replace = [];
    /** @var list<string> [server] trusted_proxies: addresses whose X-Forwarded-For / -Proto are believed */
    public array $trustedProxies = [];
    /** @var array<string, array{file: string, label: string}> paths.ini pages */
    public array $pages = [];
    public Periods $periods;
    public Sections $sections;
    /** @var list<Problem> */
    public array $problems = [];
    /** @var array<string, IniFile> */
    public array $files = [];

    public function __construct(public readonly string $courseDir)
    {
        $this->zone = new DateTimeZone('UTC');
        $this->start = new DateTimeImmutable('today');
        $this->end = $this->start;
    }

    public function configDir(): string
    {
        return $this->courseDir . '/config';
    }

    public function ini(string $name): IniFile
    {
        return $this->files[$name] ??= IniFile::load($this->configDir() . '/' . $name, $name);
    }

    public static function load(string $courseDir): self
    {
        $config = new self($courseDir);
        $ini = $config->ini('config.ini');
        if (!$ini->exists || !$ini->readable) {
            throw new ConfigError('config/config.ini is missing or can not be read');
        }
        $config->problems = $ini->problems;

        $config->number = (string) $ini->get('course', 'number', '');
        $config->name = (string) $ini->get('course', 'name', '');
        try {
            $config->zone = new DateTimeZone((string) $ini->get('course', 'timezone', date_default_timezone_get()));
        } catch (Exception) {
            throw new ConfigError('config.ini: [course] timezone is not a time zone');
        }
        $config->secret = (string) $ini->get('course', 'secret', '');
        if (strlen($config->secret) < 32 || str_contains($config->secret, 'REPLACE')) {
            throw new ConfigError('config.ini: [course] secret must be set to a long random value');
        }
        $config->defaultPeriod = strtolower((string) $ini->get('course', 'period', 'normal'));

        foreach (['start', 'end'] as $key) {
            $value = (string) $ini->get('semester', $key, '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $config->zone);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new ConfigError("config.ini: [semester] $key must be a date (YYYY-MM-DD)");
            }
            $config->$key = $date;
        }
        if ($config->end < $config->start) {
            throw new ConfigError('config.ini: [semester] end is before start');
        }
        $config->weekends = in_array(strtolower((string) $ini->get('semester', 'weekends', 'no')), ['yes', 'true', '1', 'on'], true);

        foreach ($ini->section('types') as $line) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $line->key) || in_array(strtolower($line->key), ['config', 'calendar', 'page'], true)) {
                $config->problems[] = new Problem("not a usable type directory name: $line->key", 'config.ini', $line->line);
                continue;
            }
            $config->types[$line->key] = $line->value !== '' ? $line->value : ucfirst($line->key);
        }
        foreach ($ini->section('schedule') as $line) {
            $day = self::WEEKDAYS[strtolower(substr($line->key, 0, 3))] ?? null;
            if ($day === null || !preg_match('/^([A-Za-z][A-Za-z0-9-]*)(?:\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2}))?$/', $line->value, $m)) {
                $config->problems[] = new Problem("expected \"weekday = type [HH:MM-HH:MM]\" (one type per day): $line->key = $line->value", 'config.ini', $line->line);
                continue;
            }
            $type = $config->typeName($m[1]);
            if ($type === null) {
                $config->problems[] = new Problem("unknown event type: $m[1]", 'config.ini', $line->line);
                continue;
            }
            try {
                [$start, $end] = isset($m[2]) ? self::timeRange($m[2], $m[3]) : [null, null];
                $config->schedule[$day] = ['type' => $type, 'start' => $start, 'end' => $end];
            } catch (InvalidArgumentException $e) {
                // the day keeps its type; only the times are dropped
                $config->problems[] = new Problem($e->getMessage() . ' (the times are ignored)', 'config.ini', $line->line);
                $config->schedule[$day] = ['type' => $type, 'start' => null, 'end' => null];
            }
        }
        foreach ($ini->section('boxes') as $line) {
            [$files, $label] = array_pad(explode('|', $line->value, 2), 2, '');
            $names = array_values(array_filter(array_map('trim', explode(',', $files)), static fn (string $f): bool => $f !== ''));
            $config->boxes[] = ['box' => $line->key, 'files' => $names, 'label' => trim($label) !== '' ? trim($label) : $line->key];
        }

        foreach (['lockdown' => ['access', 'lockdown'], 'usersValid' => ['users', 'valid']] as $property => [$section, $key]) {
            $value = $ini->get($section, $key);
            if ($value !== null && $value !== '') {
                try {
                    $config->$property = Window::parse($value, false);
                } catch (InvalidArgumentException $e) {
                    // fail closed (L-10): an unreadable lockdown locks now; an unreadable
                    // default window lets no account without its own window log in
                    $config->$property = Window::parse($property === 'lockdown' ? 'always' : 'never');
                    $config->problems[] = new Problem("[$section] $key: " . $e->getMessage() . ($property === 'lockdown' ? ' (locked until this is fixed)' : ' (accounts without their own window can not log in)'), 'config.ini');
                }
            }
        }
        $session = $ini->get('users', 'session');
        if ($session !== null) {
            if (preg_match('/^(\d+)\s*([mhd])$/i', $session, $m) && (int) $m[1] > 0) {
                $config->sessionSeconds = (int) $m[1] * ['m' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
            } else {
                $config->problems[] = new Problem("[users] session: \"$session\" is not like \"8h\" (the default is kept)", 'config.ini');
            }
        }
        // at least one attempt per minute or longer: "0 per 15m" would block every login and
        // "5 per 0m" would count nothing (L-46); otherwise the default stays and it is a problem
        $attempts = $ini->get('users', 'login_attempts');
        if ($attempts !== null) {
            if (preg_match('/^(\d+)\s+per\s+(\d+)\s*([mh])$/i', $attempts, $m) && (int) $m[1] >= 1 && (int) $m[2] >= 1) {
                $config->loginAttempts = [(int) $m[1], (int) $m[2] * (strtolower($m[3]) === 'h' ? 3600 : 60)];
            } else {
                $config->problems[] = new Problem("[users] login_attempts: \"$attempts\" is not like \"5 per 15m\" with at least 1 attempt and 1 minute (the default is kept)", 'config.ini');
            }
        }
        // addresses or CIDR ranges; anything else never matches, so it is a problem (L-76)
        foreach (array_filter(array_map('trim', explode(',', (string) $ini->get('server', 'trusted_proxies', ''))), static fn (string $p): bool => $p !== '') as $proxy) {
            if (\Cal6\Http\Context::validProxy($proxy)) {
                $config->trustedProxies[] = $proxy;
            } else {
                $config->problems[] = new Problem("[server] trusted_proxies: \"$proxy\" is not an IP address or CIDR range (ignored)", 'config.ini');
            }
        }
        foreach ($ini->section('replace') as $line) {
            $config->replace[$line->key] = $line->value;
        }

        $paths = $config->ini('paths.ini');
        array_push($config->problems, ...$paths->problems);
        foreach ($paths->section('pages') as $line) {
            [$file, $label] = array_pad(explode('|', $line->value, 2), 2, '');
            $config->pages[strtolower($line->key)] = ['file' => trim($file), 'label' => trim($label) !== '' ? trim($label) : ucfirst($line->key)];
        }

        $config->periods = Periods::fromIni($config->ini('periods.ini'));
        $config->sections = Sections::fromIni($config->ini('sections.ini'), $config->types);
        array_push($config->problems, ...$config->periods->problems, ...$config->sections->problems);
        if ($config->periods->schedules !== [] && !isset($config->periods->schedules[$config->defaultPeriod])) {
            $config->problems[] = new Problem("[course] period: no such period schedule in periods.ini: $config->defaultPeriod", 'config.ini');
        }
        return $config;
    }

    /** The type as written in [types], for a name in any case (M-14), or null. */
    public function typeName(string $name): ?string
    {
        foreach (array_keys($this->types) as $type) {
            if (strcasecmp($type, $name) === 0) {
                return $type;
            }
        }
        return null;
    }

    public static function hhmm(string $time): string
    {
        [$h, $i] = array_map('intval', explode(':', $time));
        if ($h > 24 || $i > 59 || ($h === 24 && $i > 0)) {
            throw new InvalidArgumentException("not a time of day: $time");
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    /**
     * A meeting's start and end, "HH:MM" each: the end must be after the start on the same day
     * (24:00 at most), or <postmeeting> would open before the meeting (L-53).
     *
     * @return array{0: string, 1: string}
     */
    public static function timeRange(string $start, string $end): array
    {
        [$start, $end] = [self::hhmm($start), self::hhmm($end)];
        if ($end <= $start) {
            throw new InvalidArgumentException("the end ($end) must be after the start ($start) on the same day");
        }
        return [$start, $end];
    }
}
