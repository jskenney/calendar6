<?php

declare(strict_types=1);

// Loads Cal6\Foo\Bar from lib/Foo/Bar.php.  Library files only declare classes.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Cal6\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
