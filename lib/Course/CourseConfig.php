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

    /** @var list<string> [dropdown] show: the event types a front end offers as menu-bar drop-downs */
    public array $dropdown = [];
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
    /** @var array<string, array{file: string, label: string}> pages.ini pages */
    public array $pages = [];

    /** language.ini [text]: the words html.php writes into pages (DOC-49) */
    public const TEXT_DEFAULTS = ['lock_prompt' => 'Enter the code to see this section', 'lock_button' => 'Unlock', 'show_answer' => 'Show answer'];

    /** @var array<string, string> */
    public array $text = self::TEXT_DEFAULTS;

    /** @var list<array{id: string, text: string, show: Window}> announcements.ini (DOC-50) */
    public array $announcements = [];

    /** pages.ini [start]: the page (by name) the site opens with, or "calendar" */
    public string $startPage = 'calendar';
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
            if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $line->key) || in_array(strtolower($line->key), ['config', 'calendar', 'web', 'page'], true)) {
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
        // [notes] names are notes, never boxes: "notes", or a name [notes] uses, is ignored in
        // [boxes] (Decided, H-7)
        $notesNames = array_map(static fn ($line): string => mb_strtolower($line->key, 'UTF-8'), $ini->section('notes'));
        $notesNames[] = 'notes';
        foreach ($ini->section('boxes') as $line) {
            if (in_array(mb_strtolower($line->key, 'UTF-8'), $notesNames, true)) {
                $config->problems[] = new Problem("[boxes] $line->key: \"$line->key\" is a [notes] name, not a box (the line is ignored)", 'config.ini', $line->line);
                continue;
            }
            [$files, $label] = array_pad(explode('|', $line->value, 2), 2, '');
            $names = array_values(array_filter(array_map('trim', explode(',', $files)), static fn (string $f): bool => $f !== ''));
            $config->boxes[] = ['box' => $line->key, 'files' => $names, 'label' => trim($label) !== '' ? trim($label) : $line->key, 'notes' => false];
        }
        // [notes]: files whose text calendar.php returns itself (short remarks such as "the class
        // meets in the auditorium today"); matched and released like a box (lab/notes = always)
        foreach ($ini->section('notes') as $line) {
            [$files] = explode('|', $line->value, 2);
            $names = array_values(array_filter(array_map('trim', explode(',', $files)), static fn (string $f): bool => $f !== ''));
            $config->boxes[] = ['box' => $line->key, 'files' => $names, 'label' => $line->key, 'notes' => true];
        }

        // [dropdown] show = class, lab: only event types of [types], in the order given, once each
        foreach ($ini->section('dropdown') as $line) {
            if (strtolower($line->key) !== 'show') {
                $config->problems[] = new Problem("[dropdown] $line->key: unknown key (only \"show\")", 'config.ini', $line->line);
                continue;
            }
            $config->dropdown = [];
            foreach (array_filter(array_map('trim', explode(',', $line->value)), static fn (string $t): bool => $t !== '') as $name) {
                $type = $config->typeName($name);
                if ($type === null) {
                    $config->problems[] = new Problem("[dropdown] show: \"$name\" is not an event type in [types] (left out)", 'config.ini', $line->line);
                } elseif (!in_array($type, $config->dropdown, true)) {
                    $config->dropdown[] = $type;
                }
            }
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
            if (preg_match('/^(\d+)\s+per\s+(\d+)\s*([mh])$/i', $attempts, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 1000 && (int) $m[2] >= 1) {
                $config->loginAttempts = [(int) $m[1], (int) $m[2] * (strtolower($m[3]) === 'h' ? 3600 : 60)];
            } else {
                $config->problems[] = new Problem("[users] login_attempts: \"$attempts\" is not like \"5 per 15m\" with 1 to 1000 attempts and at least 1 minute (the default is kept)", 'config.ini');
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

        // pages.ini (it was paths.ini: an old file is reported, not read)
        if (!is_file($config->configDir() . '/pages.ini') && is_file($config->configDir() . '/paths.ini')) {
            $config->problems[] = new Problem('paths.ini is now called pages.ini: rename it (it is not read)', 'paths.ini');
        }
        $pagesIni = $config->ini('pages.ini');
        array_push($config->problems, ...$pagesIni->problems);
        foreach ($pagesIni->section('pages') as $line) {
            if (strtolower($line->key) === 'calendar') {
                $config->problems[] = new Problem('[pages] calendar: "calendar" names the calendar itself in [start], not a page (the line is ignored)', 'pages.ini', $line->line);
                continue;
            }
            [$file, $label] = array_pad(explode('|', $line->value, 2), 2, '');
            $config->pages[strtolower($line->key)] = ['file' => trim($file), 'label' => trim($label) !== '' ? trim($label) : ucfirst($line->key)];
        }
        // [start] start = a page's name or menu label, or calendar: where the site opens
        $start = trim((string) $pagesIni->get('start', 'start', ''));
        if ($start !== '' && strtolower($start) !== 'calendar') {
            $found = null;
            foreach ($config->pages as $name => $page) {
                if (strcasecmp((string) $name, $start) === 0) {
                    $found = (string) $name;
                    break;
                }
            }
            foreach ($found === null ? $config->pages : [] as $name => $page) {
                if (strcasecmp($page['label'], $start) === 0) {
                    $found = (string) $name;
                    break;
                }
            }
            if ($found === null) {
                $config->problems[] = new Problem("[start] start: \"$start\" is neither a page's name or label nor calendar (the site starts in the calendar)", 'pages.ini');
            }
            $config->startPage = $found ?? 'calendar';
        }

        // language.ini [text]: the words calendar6 writes into pages; unknown keys are problems
        $language = $config->ini('language.ini');
        array_push($config->problems, ...$language->problems);
        foreach ($language->section('text') as $line) {
            if (!isset(self::TEXT_DEFAULTS[strtolower($line->key)])) {
                $config->problems[] = new Problem("[text] $line->key: unknown key (" . implode(', ', array_keys(self::TEXT_DEFAULTS)) . ')', 'language.ini', $line->line);
            } elseif (trim($line->value) !== '') {
                $config->text[strtolower($line->key)] = trim($line->value);
            }
        }

        // announcements.ini: one section per announcement, its name the identifier
        $announcements = $config->ini('announcements.ini');
        array_push($config->problems, ...$announcements->problems);
        foreach ($announcements->sections() as $id) {
            if ($id === '') {
                continue; // lines before the first section
            }
            $text = trim((string) $announcements->get($id, 'text', ''));
            if ($text === '') {
                $config->problems[] = new Problem("[$id]: an announcement needs text (it is not shown)", 'announcements.ini');
                continue;
            }
            $show = trim((string) $announcements->get($id, 'show', 'always'));
            try {
                $window = Window::parse($show === '' ? 'always' : $show, false);
            } catch (InvalidArgumentException $e) {
                $config->problems[] = new Problem("[$id] show: " . $e->getMessage() . ' (it is not shown)', 'announcements.ini');
                continue;
            }
            foreach ($announcements->section($id) as $line) {
                if (!in_array(strtolower($line->key), ['text', 'show'], true)) {
                    $config->problems[] = new Problem("[$id] $line->key: unknown key (text, show)", 'announcements.ini', $line->line);
                }
            }
            $config->announcements[] = ['id' => $id, 'text' => $text, 'show' => $window];
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
