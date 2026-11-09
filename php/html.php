<?php

declare(strict_types=1);

// html.php: see DESIGN.md, "The PHP endpoints".
require __DIR__ . '/../lib/autoload.php';

Cal6\App::run([Cal6\Endpoint\HtmlEndpoint::class, 'handle']);
