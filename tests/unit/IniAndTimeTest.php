<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Access\Target;
use Cal6\Course\CourseConfig;
use Cal6\Tests\Fixture;
use Cal6\Ini\IniFile;
use Cal6\Schedule\Sections;
use Cal6\Tests\TestCase;
use Cal6\Time\EventTime;
use Cal6\Time\When;
use Cal6\Time\Window;
use DateTimeImmutable;
use DateTimeZone;

final class IniAndTimeTest extends TestCase
{
    private function zone(): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }

    private function ts(string $time): int
    {
        return (new DateTimeImmutable($time, $this->zone()))->getTimestamp();
    }

    public function testAMeetingMustEndAfterItStarts(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $dir = Fixture::course(['config/config.ini' => str_replace("mon = class\n", "mon = class 22:00-00:30\n", $template)]);
        $config = CourseConfig::load($dir);
        Fixture::remove($dir);
        $this->assertSame(1, count(array_filter($config->problems, static fn ($p): bool => str_contains($p->message, 'must be after'))), 'a reversed meeting is a problem (L-53)');
        $monday = array_values(array_filter($config->schedule, static fn (array $d): bool => $d['type'] === 'class'))[0] ?? [];
        $this->assertSame(['type' => 'class', 'start' => null, 'end' => null], $monday, 'the day keeps its type without the times');
        $periods = \Cal6\Schedule\Periods::fromIni(IniFile::parse("[normal]\n1 = 10:00-09:00\n2 = 25:00-26:00\n3 = 08:00-08:50\n", 'periods.ini'));
        $this->assertSame(2, count($periods->problems), 'reversed and impossible periods are problems');
        $this->assertSame(['3'], array_map('strval', array_keys($periods->schedules['normal']['periods'])));
    }

    public function testTrustedProxiesTakeRangesAndAnySpelling(): void
    {
        $this->assertTrue(\Cal6\Http\Context::isTrusted('10.1.2.3', ['10.0.0.0/8']), 'a CIDR range (L-76)');
        $this->assertTrue(\Cal6\Http\Context::isTrusted('2001:db8:0:0::1', ['2001:db8::1']), 'another IPv6 spelling');
        $this->assertFalse(\Cal6\Http\Context::isTrusted('11.0.0.1', ['10.0.0.0/8']));
        $this->assertTrue(\Cal6\Http\Context::isTrusted('::ffff:10.0.0.5', ['10.0.0.0/8']), 'an IPv4-mapped address (L-81)');
        $this->assertTrue(\Cal6\Http\Context::isTrusted('10.0.0.5', ['::ffff:10.0.0.5']));
        $template = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        $dir = Fixture::course(['config/config.ini' => $template . "\n[server]\ntrusted_proxies = 10.0.0.0/8, proxy.example\n"]);
        $config = CourseConfig::load($dir);
        Fixture::remove($dir);
        $this->assertSame(['10.0.0.0/8'], $config->trustedProxies);
        $this->assertSame(1, count(array_filter($config->problems, static fn ($p): bool => str_contains($p->message, 'proxy.example'))), 'a host name is a problem');
    }

    public function testOddLoginAttemptsKeepTheDefault(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../template/config/config.ini');
        foreach (['5 per 0m' => [5, 900], '0 per 15m' => [5, 900], 'lots' => [5, 900], '3 per 2h' => [3, 7200]] as $value => $expected) {
            $dir = Fixture::course(['config/config.ini' => str_replace('login_attempts = 5 per 15m', "login_attempts = $value", $template)]);
            $config = CourseConfig::load($dir);
            Fixture::remove($dir);
            $this->assertSame($expected, $config->loginAttempts, "login_attempts = $value (L-46)");
            $listed = array_filter($config->problems, static fn ($p): bool => str_contains($p->message, 'login_attempts'));
            $this->assertSame($expected === [5, 900] ? 1 : 0, count($listed), "a problem for $value");
        }
    }

    public function testIniReader(): void
    {
        $ini = IniFile::parse("; comment\n# also a comment\n[Course]\nnumber = AB123 ; trailing\nname = A; B\n\nclass_20..27/notes = never\nclass_20..27/notes = always\nbroken line\nhash = \$2y\$12\$abc;def\n", 'x.ini');
        $this->assertSame('AB123', $ini->get('course', 'number'), 'sections are case-insensitive; "; " starts a comment');
        $this->assertSame('A; B', $ini->get('course', 'name'), 'a ";" right after text is not a comment... unless after a space');
        $this->assertSame('always', $ini->get('course', 'class_20..27/notes'), 'the later line wins');
        $this->assertSame(2, count(array_filter($ini->lines, static fn ($l): bool => $l->key === 'class_20..27/notes')), 'every line is kept');
        $this->assertSame(1, count($ini->problems), 'a line without "=" is a problem');
        $this->assertSame(9, $ini->problems[0]->line);
        $this->assertSame('$2y$12$abc;def', $ini->get('course', 'hash'), 'values are never interpreted');
        $this->assertSame(['course'], $ini->sections());
        $numeric = IniFile::parse("[1121]\nmeets = MF1\n");
        $this->assertSame(['1121'], $numeric->sections(), 'numeric section names stay strings');
    }

    public function testAbsoluteWindows(): void
    {
        $zone = $this->zone();
        $w = Window::parse('2026-10-15 13:30 until 2026-10-22');
        $this->assertFalse($w->isOpen($this->ts('2026-10-15 13:29'), $zone, null));
        $this->assertTrue($w->isOpen($this->ts('2026-10-15 13:30'), $zone, null), 'open at the minute');
        $this->assertTrue($w->isOpen($this->ts('2026-10-22 23:59'), $zone, null), 'a closing date without a time includes that day');
        $this->assertFalse($w->isOpen($this->ts('2026-10-23 00:00'), $zone, null));
        $this->assertSame($this->ts('2026-10-23 00:00'), $w->nextChange($this->ts('2026-10-20'), $zone, null));
        $this->assertTrue(Window::parse('always')->isOpen(0, $zone, null));
        $this->assertFalse(Window::parse('never')->isOpen(PHP_INT_MAX - 1, $zone, null));
        $this->assertTrue(Window::parse('2026-01-01')->isOpen($this->ts('2026-01-01 00:00'), $zone, null), 'an opening date is 00:00');
        $this->assertThrows(fn () => Window::parse('+7', false), 'relative times are not allowed where there is no event');
        $this->assertThrows(fn () => Window::parse('2026-02-30'), 'not a date');
        $this->assertThrows(fn () => Window::parse('2026-02-03 25:00'), 'not a time');
        $this->assertThrows(fn () => Window::parse('12 15 2020'), 'v4 dates are not read');
        $this->assertThrows(fn () => Window::parse('always until never'));
    }

    public function testRelativeWindows(): void
    {
        $zone = $this->zone();
        $wednesday = new DateTimeImmutable('2026-10-14 00:00', $zone);
        $event = new EventTime($wednesday, $wednesday->setTime(7, 45), $wednesday->setTime(15, 30));
        $at = fn (string $text): int => (int) When::parse($text)->resolve($zone, $event);
        $this->assertSame($this->ts('2026-10-21 17:00'), $at('+7 17:00'));
        $this->assertSame($this->ts('2026-10-14 00:00'), $at('-0'));
        $this->assertSame($this->ts('2026-10-12 00:00'), $at('-monday'), 'the Monday on or before');
        $this->assertSame($this->ts('2026-10-14 08:00'), $at('-wednesday 08:00'), 'on the day itself');
        $this->assertSame($this->ts('2026-10-21 00:00'), $at('+wed'), 'strictly after');
        $this->assertSame($this->ts('2026-10-19 00:00'), $at('+monday'));
        $this->assertSame($this->ts('2026-10-14 07:30'), $at('start-15m'), 'the first section of the day');
        $this->assertSame($this->ts('2026-10-14 16:30'), $at('end+1h'), 'the last section');
        $w = Window::parse('-monday 08:00 until +14');
        $this->assertTrue($w->isOpen($this->ts('2026-10-28 23:59'), $zone, $event), 'a relative until without a time is the end of that day');
        $this->assertFalse($w->isOpen($this->ts('2026-10-29 00:00'), $zone, $event));
        $this->assertFalse(Window::parse('+1')->isOpen(PHP_INT_MAX - 1, $zone, null), 'relative rules without an event never open');
        $noTimes = new EventTime($wednesday);
        $this->assertSame($this->ts('2026-10-15 00:00'), When::parse('end')->resolve($zone, $noTimes), 'end without times: the end of the day');
    }

    public function testDaylightSavingTime(): void
    {
        $zone = $this->zone();
        $friday = new DateTimeImmutable('2026-10-30 00:00', $zone);
        $this->assertSame($this->ts('2026-11-06 17:00'), When::parse('+7 17:00')->resolve($zone, new EventTime($friday)), 'calendar days across the change keep the clock time');
    }

    public function testTargets(): void
    {
        $this->assertSame(['class_20/notes', 'class_21/notes', 'class_22/notes'], Target::expand('class_20..22/notes'));
        $this->assertSame(['class_6', 'class_8', 'class_22'], Target::expand('class_6,8,22'));
        $this->assertSame(['lab_1', 'lab_2', 'lab_3', 'lab_7'], Target::expand('lab_1..3,7'));
        $this->assertSame(['lab_6/notes.html'], Target::expand('lab_6/notes.html'), 'one event');
        $this->assertSame(['homework_1.html'], Target::expand('homework_1.html'), 'a file name is not a range');
        $this->assertSame(['page/policy'], Target::expand('page/policy'));
        $this->assertThrows(fn () => Target::expand('class_9..3'));
    }

    public function testSectionMeetings(): void
    {
        $types = ['class' => 'Class', 'lab' => 'Lab'];
        $m = Sections::meeting('T910', $types, 'class');
        $this->assertSame(['9', '10'], $m['periods'] ?? null, 'periods are read greedily: 10 before 1');
        $this->assertSame(['X', '1'], Sections::meeting('MWX1', $types, 'class')['periods'] ?? null);
        $this->assertSame([1, 3], Sections::meeting('MWX1', $types, 'class')['days'] ?? null);
        $this->assertSame('lab', Sections::meeting('T12 lab', $types, 'class')['type'] ?? null);
        $this->assertSame(null, Sections::meeting('T0', $types, 'class'), 'there is no period 0');
        $this->assertSame(null, Sections::meeting('Q1', $types, 'class'));
        $sections = Sections::fromIni(IniFile::parse("[4345]\nmeets = MF4, R34\ninstructor = JONES\nroom = HP132, HP301\n[1]\nmeets = M1, T1, W1\nroom = A, B\n"), $types);
        $this->assertSame('HP132', $sections->sections['4345']['meetings'][0]['room']);
        $this->assertSame('HP301', $sections->sections['4345']['meetings'][1]['room'], 'one room per entry of meets');
        $this->assertSame(1, count($sections->problems), 'a room count that matches neither');
    }
}
