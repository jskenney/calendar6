<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Course\CourseConfig;
use Cal6\Http\Context;
use Cal6\Http\Session;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

final class SessionTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    public function tearDown(): void
    {
        (new \ReflectionProperty(Session::class, 'open'))->setValue(null, false);
        $_SESSION = [];
        foreach ($this->dirs as $dir) {
            Fixture::remove($dir);
        }
    }

    private function context(): Context
    {
        $dir = $this->dirs[] = Fixture::course();
        return new Context($dir, CourseConfig::load($dir), [], [], [], time());
    }

    public function testASessionBelongsToOneCourse(): void
    {
        $open = new \ReflectionProperty(Session::class, 'open');
        $first = $this->context();
        $_SESSION = [];
        Session::start($first, true);
        Session::set('user', 'prof');
        $this->assertSame('prof', Session::get('user'));

        $open->setValue(null, false); // the same session data, presented to another course
        $second = $this->context();
        Session::start($second, true);
        $this->assertSame(null, Session::get('user'), 'another course ignores it (former M-16)');
        $this->assertSame((string) realpath($second->courseDir), Session::get('course'));

        Session::set('user', 'ann');
        Session::clear();
        $this->assertSame((string) realpath($second->courseDir), Session::get('course'), 'logging out keeps the binding');
        $this->assertSame(null, Session::get('user'));
    }
}
