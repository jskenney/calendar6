<?php

declare(strict_types=1);

namespace Cal6\Tests;

final class HttpResponse
{
    /** @param array<string, string> $headers lower-case names */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body)
    {
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
