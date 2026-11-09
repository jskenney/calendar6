<?php

declare(strict_types=1);

namespace Cal6\Tests;

/** PHP's built-in web server, serving a test course as the document root. */
final class Server
{
    /** @var resource */
    private $process;
    public readonly int $port;

    public readonly string $log;

    /** @param string|null $clock a file holding the time the server should use (a Unix timestamp) */
    public function __construct(public readonly string $docRoot, public readonly ?string $clock = null)
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'cal6-log-');
        for ($try = 0; $try < 20; $try++) {
            $port = random_int(20000, 45000);
            $env = getenv();
            if ($clock !== null) {
                $env['CAL6_TEST_CLOCK'] = $clock;
            }
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $this->log, '-d', 'error_reporting=-1', '-S', "127.0.0.1:$port", '-t', $docRoot], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
            if (!is_resource($process)) {
                continue;
            }
            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $this->process = $process;
                    $this->port = $port;
                    return;
                }
                usleep(50000);
                if (!(proc_get_status($process)['running'] ?? false)) {
                    break;
                }
            }
            proc_terminate($process);
        }
        throw new \RuntimeException('could not start the PHP server');
    }

    public function url(string $path): string
    {
        return "http://127.0.0.1:$this->port/" . ltrim($path, '/');
    }

    /** PHP errors, warnings and notices the server printed. @return list<string> */
    public function phpErrors(): array
    {
        $lines = preg_split('/\R/', (string) @file_get_contents($this->log)) ?: [];
        return array_values(array_filter($lines, static fn (string $l): bool => (bool) preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|calendar6:)/', $l)));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
    }
}
