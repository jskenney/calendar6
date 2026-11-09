<?php

declare(strict_types=1);

namespace Cal6\Tests;

/** A browser with a cookie jar, talking to a test Server. */
final class HttpClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(private readonly Server $server)
    {
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = [], string $method = 'GET'): HttpResponse
    {
        return $this->request($method, $path, null, $headers);
    }

    /** @param array<string, string> $fields */
    public function post(string $path, array $fields, array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, http_build_query($fields), $headers + ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, ?string $body, array $headers = []): HttpResponse
    {
        $curl = curl_init($this->server->url($path));
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        if ($this->cookies !== []) {
            $lines[] = 'Cookie: ' . implode('; ', array_map(static fn (string $k, string $v): string => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    if ($name === 'set-cookie') {
                        [$pair] = explode(';', $value, 2);
                        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                        if ($v === '' || $v === 'deleted') {
                            unset($this->cookies[$k]);
                        } else {
                            $this->cookies[$k] = $v;
                        }
                        $responseHeaders['set-cookie'] = ($responseHeaders['set-cookie'] ?? '') . $value . "\n";
                    } else {
                        $responseHeaders[$name] = $value;
                    }
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return new HttpResponse($status, $responseHeaders, is_string($result) ? $result : '');
    }
}
