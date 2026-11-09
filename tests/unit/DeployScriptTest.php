<?php

declare(strict_types=1);

namespace Cal6\Tests\Unit;

use Cal6\Tests\TestCase;

final class DeployScriptTest extends TestCase
{
    public function testSetupRefusesWithoutBeingAsked(): void
    {
        $script = dirname(__DIR__) . '/deploy/course-setup.sh';
        $output = [];
        exec('env -u CI bash ' . escapeshellarg($script) . ' 2>&1', $output, $status);
        $this->assertSame(2, $status, 'no --yes, no CI: it does nothing (L-19)');
        $this->assertContains('--yes', implode("\n", $output));
        $this->assertContains('never touches a directory it did not make', (string) file_get_contents($script));
    }
}
