<?php

declare(strict_types=1);

namespace Cal6\Http;

/**
 * Sends a file: its MIME type, Content-Length, single-range support (video, large
 * PDFs), ETag / Last-Modified with 304, inline or attachment by type.  HTML and
 * SVG are always attachments, so they can not run as pages of the site.
 * (The table comes from Calendar 4's provide_file(), by way of Calendar 5.)
 */
final class Download
{
    /** extension => [content type, inline] */
    private const TYPES = [
        'bz2' => ['application/x-bzip2', false], 'css' => ['text/css', true], 'gz' => ['application/gzip', false],
        'tgz' => ['application/gzip', false], 'zip' => ['application/zip', false], 'java' => ['text/plain', true],
        'py' => ['text/plain', true], 'c' => ['text/plain', true], 'h' => ['text/plain', true], 'cpp' => ['text/plain', true],
        'js' => ['text/javascript', true], 'mjs' => ['text/javascript', true], 'json' => ['application/json', true], 'csv' => ['text/csv', true],
        'sql' => ['text/plain', true], 'txt' => ['text/plain', true], 'md' => ['text/plain', true], 'tex' => ['text/plain', true],
        'pdf' => ['application/pdf', true],
        'doc' => ['application/msword', false],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'xls' => ['application/vnd.ms-excel', false],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'ppt' => ['application/vnd.ms-powerpoint', false],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
        'svg' => ['image/svg+xml', false], 'gif' => ['image/gif', true], 'png' => ['image/png', true],
        'jpg' => ['image/jpeg', true], 'jpeg' => ['image/jpeg', true], 'jpe' => ['image/jpeg', true],
        'webp' => ['image/webp', true], 'ico' => ['image/x-icon', true],
        'htm' => ['text/html', false], 'html' => ['text/html', false],
        'mp4' => ['video/mp4', true], 'm4v' => ['video/mp4', true], 'webm' => ['video/webm', true], 'ogv' => ['video/ogg', true],
        'mp3' => ['audio/mpeg', true], 'm4a' => ['audio/mp4', true], 'ogg' => ['audio/ogg', true], 'wav' => ['audio/wav', true],
        'vtt' => ['text/vtt', true],
    ];

    /** @return array{0: string, 1: bool} content type and whether it is shown inline */
    public static function typeOf(string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            return ['text/plain', true];
        }
        return self::TYPES[$extension] ?? ['application/octet-stream', false];
    }

    /** @param array<string, mixed> $server */
    public static function send(string $path, string $name, array $server, bool $forceDownload): void
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if ($size === false || $mtime === false || !is_readable($path)) {
            Response::error(404, 'not found');
            return;
        }
        [$type, $inline] = self::typeOf($name);
        if (str_starts_with($type, 'text/') && $type !== 'text/html') {
            $type .= '; charset=utf-8';
        }
        $etag = '"' . substr(sha1($path . "\0" . $size . "\0" . $mtime), 0, 20) . '"';
        Response::common();
        header('Cache-Control: private, no-cache');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Accept-Ranges: bytes');

        $match = (string) ($server['HTTP_IF_NONE_MATCH'] ?? '');
        $since = isset($server['HTTP_IF_MODIFIED_SINCE']) ? strtotime((string) $server['HTTP_IF_MODIFIED_SINCE']) : false;
        if (($match !== '' && in_array($etag, array_map('trim', explode(',', $match)), true))
            || ($match === '' && $since !== false && $since >= $mtime)) {
            http_response_code(304);
            return;
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = $server['HTTP_RANGE'] ?? null;
        if (is_string($range) && $size > 0) {
            $parsed = self::range($range, $size);
            if ($parsed === null) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            [$start, $end] = $parsed;
            $status = 206;
        }
        http_response_code($status);
        header('Content-Type: ' . $type);
        $base = basename($name);
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '', $base));
        header('Content-Disposition: ' . ($inline && !$forceDownload ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($base)); // L-8
        header('Content-Length: ' . ($size === 0 ? 0 : $end - $start + 1));
        if ($status === 206) {
            header("Content-Range: bytes $start-$end/$size");
        }
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD' || $size === 0) {
            return;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        fseek($handle, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($handle)) {
            $chunk = fread($handle, (int) min(1 << 16, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($handle);
    }

    /** One range: "bytes=a-b", "bytes=a-" or "bytes=-n"; null when it can not be satisfied. @return array{0: int, 1: int}|null */
    public static function range(string $header, int $size): ?array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            $length = (int) $m[2];
            return $length === 0 ? null : [max(0, $size - $length), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        return $start >= $size || $start > $end ? null : [$start, $end];
    }
}
