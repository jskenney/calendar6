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
    /**
     * extension => [content type, inline]. Inline: types a browser shows safely (text, images,
     * audio, video, PDF, fonts, style sheets and scripts as used by pages). Attachments: documents,
     * archives, and anything a browser could run as a page of the site (HTML, XHTML, SVG, XML,
     * XSLT), whatever it is asked.
     */
    private const TYPES = [
        'txt' => ['text/plain', true], 'text' => ['text/plain', true], 'log' => ['text/plain', true],
        'md' => ['text/plain', true], 'markdown' => ['text/plain', true], 'rst' => ['text/plain', true],
        'tex' => ['text/plain', true], 'bib' => ['text/plain', true], 'sty' => ['text/plain', true],
        'cls' => ['text/plain', true], 'java' => ['text/plain', true], 'py' => ['text/plain', true],
        'c' => ['text/plain', true], 'h' => ['text/plain', true], 'cpp' => ['text/plain', true],
        'cc' => ['text/plain', true], 'cxx' => ['text/plain', true], 'hpp' => ['text/plain', true],
        'hh' => ['text/plain', true], 'cs' => ['text/plain', true], 'go' => ['text/plain', true],
        'rs' => ['text/plain', true], 'rb' => ['text/plain', true], 'pl' => ['text/plain', true],
        'pm' => ['text/plain', true], 'lua' => ['text/plain', true], 'r' => ['text/plain', true],
        'm' => ['text/plain', true], 'jl' => ['text/plain', true], 'kt' => ['text/plain', true],
        'kts' => ['text/plain', true], 'swift' => ['text/plain', true], 'scala' => ['text/plain', true],
        'hs' => ['text/plain', true], 'ml' => ['text/plain', true], 'clj' => ['text/plain', true],
        'ex' => ['text/plain', true], 'exs' => ['text/plain', true], 'erl' => ['text/plain', true],
        'dart' => ['text/plain', true], 'ts' => ['text/plain', true], 'tsx' => ['text/plain', true],
        'jsx' => ['text/plain', true], 'vue' => ['text/plain', true], 'sh' => ['text/plain', true],
        'bash' => ['text/plain', true], 'zsh' => ['text/plain', true], 'bat' => ['text/plain', true],
        'ps1' => ['text/plain', true], 'asm' => ['text/plain', true], 's' => ['text/plain', true],
        'v' => ['text/plain', true], 'vhd' => ['text/plain', true], 'sv' => ['text/plain', true],
        'f90' => ['text/plain', true], 'f' => ['text/plain', true], 'pas' => ['text/plain', true],
        'sql' => ['text/plain', true], 'toml' => ['text/plain', true], 'yaml' => ['text/plain', true],
        'yml' => ['text/plain', true], 'cfg' => ['text/plain', true], 'conf' => ['text/plain', true],
        'properties' => ['text/plain', true], 'gradle' => ['text/plain', true],
        'make' => ['text/plain', true], 'mk' => ['text/plain', true], 'cmake' => ['text/plain', true],
        'dockerfile' => ['text/plain', true], 'diff' => ['text/plain', true],
        'patch' => ['text/plain', true], 'srt' => ['text/plain', true], 'sage' => ['text/plain', true],
        'ipynb' => ['application/x-ipynb+json', true], 'csv' => ['text/csv', true],
        'tsv' => ['text/tab-separated-values', true], 'vtt' => ['text/vtt', true],
        'ics' => ['text/calendar', true], 'vcf' => ['text/vcard', true], 'css' => ['text/css', true],
        'js' => ['text/javascript', true], 'mjs' => ['text/javascript', true],
        'cjs' => ['text/javascript', true], 'map' => ['application/json', true],
        'json' => ['application/json', true], 'jsonl' => ['application/x-ndjson', true],
        'geojson' => ['application/geo+json', true], 'wasm' => ['application/wasm', true],
        'png' => ['image/png', true], 'gif' => ['image/gif', true], 'jpg' => ['image/jpeg', true],
        'jpeg' => ['image/jpeg', true], 'jpe' => ['image/jpeg', true], 'webp' => ['image/webp', true],
        'avif' => ['image/avif', true], 'bmp' => ['image/bmp', true], 'ico' => ['image/x-icon', true],
        'tif' => ['image/tiff', true], 'tiff' => ['image/tiff', true], 'heic' => ['image/heic', true],
        'heif' => ['image/heif', true], 'mp3' => ['audio/mpeg', true], 'm4a' => ['audio/mp4', true],
        'aac' => ['audio/aac', true], 'ogg' => ['audio/ogg', true], 'oga' => ['audio/ogg', true],
        'opus' => ['audio/opus', true], 'wav' => ['audio/wav', true], 'flac' => ['audio/flac', true],
        'weba' => ['audio/webm', true], 'mid' => ['audio/midi', true], 'midi' => ['audio/midi', true],
        'mp4' => ['video/mp4', true], 'm4v' => ['video/mp4', true], 'webm' => ['video/webm', true],
        'ogv' => ['video/ogg', true], 'mov' => ['video/quicktime', true],
        'mkv' => ['video/x-matroska', true], 'avi' => ['video/x-msvideo', true],
        'mpeg' => ['video/mpeg', true], 'mpg' => ['video/mpeg', true], '3gp' => ['video/3gpp', true],
        'woff' => ['font/woff', true], 'woff2' => ['font/woff2', true], 'ttf' => ['font/ttf', true],
        'otf' => ['font/otf', true], 'eot' => ['application/vnd.ms-fontobject', true],
        'pdf' => ['application/pdf', true],
        'htm' => ['text/html', false], 'html' => ['text/html', false],
        'xhtml' => ['application/xhtml+xml', false], 'xht' => ['application/xhtml+xml', false],
        'svg' => ['image/svg+xml', false], 'svgz' => ['image/svg+xml', false],
        'xml' => ['application/xml', false], 'xsl' => ['application/xml', false],
        'xslt' => ['application/xslt+xml', false], 'rss' => ['application/rss+xml', false],
        'atom' => ['application/atom+xml', false], 'mht' => ['message/rfc822', false],
        'mhtml' => ['message/rfc822', false], 'epub' => ['application/epub+zip', false],
        'mobi' => ['application/x-mobipocket-ebook', false], 'rtf' => ['application/rtf', false],
        'ps' => ['application/postscript', false], 'eps' => ['application/postscript', false],
        'doc' => ['application/msword', false],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'dot' => ['application/msword', false],
        'dotx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.template', false],
        'xls' => ['application/vnd.ms-excel', false],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'xlsm' => ['application/vnd.ms-excel.sheet.macroEnabled.12', false],
        'ppt' => ['application/vnd.ms-powerpoint', false],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
        'pps' => ['application/vnd.ms-powerpoint', false],
        'ppsx' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', false],
        'odt' => ['application/vnd.oasis.opendocument.text', false],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', false],
        'odp' => ['application/vnd.oasis.opendocument.presentation', false],
        'odg' => ['application/vnd.oasis.opendocument.graphics', false],
        'pages' => ['application/vnd.apple.pages', false],
        'numbers' => ['application/vnd.apple.numbers', false],
        'key' => ['application/vnd.apple.keynote', false], 'zip' => ['application/zip', false],
        'gz' => ['application/gzip', false], 'tgz' => ['application/gzip', false],
        'bz2' => ['application/x-bzip2', false], 'xz' => ['application/x-xz', false],
        '7z' => ['application/x-7z-compressed', false], 'rar' => ['application/vnd.rar', false],
        'tar' => ['application/x-tar', false], 'jar' => ['application/java-archive', false],
        'war' => ['application/java-archive', false],
        'apk' => ['application/vnd.android.package-archive', false],
        'dmg' => ['application/x-apple-diskimage', false], 'iso' => ['application/x-iso9660-image', false],
        'psd' => ['image/vnd.adobe.photoshop', false], 'ai' => ['application/postscript', false],
        'dwg' => ['image/vnd.dwg', false], 'dxf' => ['image/vnd.dxf', false], 'stl' => ['model/stl', false],
        'obj' => ['model/obj', false], 'glb' => ['model/gltf-binary', false],
        'gltf' => ['model/gltf+json', false], 'sqlite' => ['application/vnd.sqlite3', false],
        'db' => ['application/octet-stream', false], 'mat' => ['application/octet-stream', false],
        'npy' => ['application/octet-stream', false], 'pkl' => ['application/octet-stream', false],
        'bin' => ['application/octet-stream', false], 'exe' => ['application/octet-stream', false],
        'dll' => ['application/octet-stream', false], 'class' => ['application/java-vm', false],
        'pcap' => ['application/vnd.tcpdump.pcap', false],
    ];


    /** A file type that is served as such (not an unknown extension such as .bak or .orig). */
    public static function isKnownType(string $extension): bool
    {
        return isset(self::TYPES[strtolower($extension)]);
    }

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
