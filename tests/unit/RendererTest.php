<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Access\Policy;
use Cal6\Access\Viewer;
use Cal6\Course\Course;
use Cal6\Render\PageRenderer;
use Cal6\Tests\Fixture;
use Cal6\Tests\TestCase;

final class RendererTest extends TestCase
{
    private string $dir = '';

    public function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    /** @param array<string, string|null> $files */
    private function course(array $files): Course
    {
        $this->dir = Fixture::course($files);
        return Course::load($this->dir);
    }

    private function render(Course $course, string $place, array $hostNames = [], ?Viewer $viewer = null, ?int $now = null): string
    {
        $renderer = new PageRenderer($course, new Policy($course), $viewer ?? Viewer::anonymous(), $now ?? time(), '/', hostNames: $hostNames);
        foreach ($course->places as $p) {
            if ($p->id() === $place) {
                return $renderer->render($p);
            }
        }
        throw new \RuntimeException("no place $place");
    }

    public function testHostMatchesOnlyServerNamesAndAddresses(): void
    {
        $course = $this->course(['class/01.Introduction/index.html' => '<host server="other, intranet">ONLY-INTRANET</host><host server="10.0.0.5">BY-ADDRESS</host>']);
        $this->assertNotContains('ONLY-INTRANET', $this->render($course, 'class_1/index.html', ['www']));
        $this->assertContains('ONLY-INTRANET', $this->render($course, 'class_1/index.html', ['Intranet']), 'a list, not case sensitive');
        $this->assertContains('BY-ADDRESS', $this->render($course, 'class_1/index.html', ['www', '10.0.0.5']), 'the server address (v4)');
        $_SERVER['SERVER_NAME'] = 'intranet';
        $this->assertNotContains('ONLY-INTRANET', $this->render($course, 'class_1/index.html', PageRenderer::serverNames(['SERVER_ADDR' => '127.0.0.1'])), 'never the Host header (H-1)');
        unset($_SERVER['SERVER_NAME']);
    }
}
