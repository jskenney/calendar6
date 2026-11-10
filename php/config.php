<?php

declare(strict_types=1);

// config.php: inputs and outputs in API.md; design notes in DESIGN.md, "The PHP endpoints".
require __DIR__ . '/../lib/autoload.php';

Cal6\App::run([Cal6\Endpoint\ConfigEndpoint::class, 'handle']);
