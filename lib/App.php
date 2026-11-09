<?php

declare(strict_types=1);

namespace Cal6;

use Cal6\Course\ConfigError;
use Cal6\Http\Context;
use Cal6\Http\Response;
use Throwable;

/** Runs one endpoint: finds the course, loads config.ini, and answers errors as JSON. */
final class App
{
    /** @param callable(Context): void $endpoint */
    public static function run(callable $endpoint): void
    {
        try {
            $context = Context::fromGlobals();
            date_default_timezone_set($context->config->zone->getName());
            $endpoint($context);
        } catch (ConfigError $e) {
            Response::error(500, $e->getMessage());
        } catch (Throwable $e) {
            error_log('calendar6: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                Response::error(500, 'internal error');
            }
        }
    }
}
