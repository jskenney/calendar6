<?php

declare(strict_types=1);

namespace Cal6\Course;

use RuntimeException;

/** The course can not be served: no course directory, or config.ini is unusable. */
final class ConfigError extends RuntimeException
{
}
