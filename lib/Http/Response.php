<?php

declare(strict_types=1);

namespace Cal6\Http;

/** Headers and bodies every endpoint sends (API.md, "Conventions for every endpoint"). */
final class Response
{
    public static function common(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200, string $cache = 'private, no-store'): void
    {
        self::common();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: ' . $cache);
        echo self::encode($data);
    }

    /** @param array<mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function error(int $status, string $message, array $headers = []): void
    {
        foreach ($headers as $header) {
            header($header);
        }
        self::json(['error' => $message], $status);
    }

    /** A JSON body with an ETag; answers 304 when the browser already has it. */
    public static function jsonWithEtag(string $body, array $server): void
    {
        self::common();
        $etag = '"' . substr(sha1($body), 0, 20) . '"';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-cache');
        header('ETag: ' . $etag);
        $match = (string) ($server['HTTP_IF_NONE_MATCH'] ?? '');
        if ($match !== '' && in_array($etag, array_map('trim', explode(',', $match)), true)) {
            http_response_code(304);
            return;
        }
        http_response_code(200);
        echo $body;
    }
}
