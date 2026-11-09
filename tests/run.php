<?php

declare(strict_types=1);

/*
 * Calendar 6 test runner (no dependencies).
 *
 *   php tests/run.php           every test
 *   php tests/run.php unit      only tests/unit
 *   php tests/run.php http      only tests/http
 *   php tests/run.php Policy    only test files whose name contains "Policy"
 *
 * tests/<suite>/FooTest.php defines Cal6\Tests\<Suite>\FooTest extending
 * Cal6\Tests\TestCase, with public test*() methods.  A PHP warning or notice
 * inside a test is a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/../lib/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Cal6\\Tests\\')) {
        $file = __DIR__ . '/lib/' . substr($class, 11) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$filter = $argv[1] ?? '';
$suites = ['unit', 'http'];
if (in_array($filter, $suites, true)) {
    $suites = [$filter];
    $filter = '';
}
$passed = $failed = $assertions = 0;
$start = microtime(true);
foreach ($suites as $suite) {
    foreach (glob(__DIR__ . "/$suite/*Test.php") ?: [] as $file) {
        if ($filter !== '' && stripos(basename($file), $filter) === false) {
            continue;
        }
        require_once $file;
        $class = 'Cal6\\Tests\\' . ucfirst($suite) . '\\' . basename($file, '.php');
        foreach (array_filter(get_class_methods($class), static fn (string $m): bool => str_starts_with($m, 'test')) as $method) {
            /** @var \Cal6\Tests\TestCase $test */
            $test = new $class();
            $errors = [];
            try {
                $test->setUp();
                try {
                    $test->$method();
                } finally {
                    $test->tearDown();
                }
            } catch (Throwable $e) {
                $errors[] = $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            $errors = array_merge($test->failures, $errors);
            if ($errors === [] && $test->assertions === 0) {
                $errors[] = 'no assertions';
            }
            $assertions += $test->assertions;
            $name = basename($file, '.php') . '::' . $method;
            if ($errors === []) {
                $passed++;
                echo "ok   $name\n";
            } else {
                $failed++;
                echo "FAIL $name\n";
                foreach ($errors as $error) {
                    echo "     $error\n";
                }
            }
        }
    }
}
printf("\n%d passed, %d failed, %d assertions, %.1fs\n", $passed, $failed, $assertions, microtime(true) - $start);
exit($failed === 0 && $passed > 0 ? 0 : 1);
