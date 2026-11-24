<?php

namespace Eppitnic\PowerDns;

/**
 * The real HttpClient, over curl (already a hard dependency -- see
 * composer.json). TLS is verified; both timeouts are short, since a
 * `pdns sync` run must not hang on an unreachable server. A redirect is
 * followed (https only) just where the caller asks, and a body is cut off at
 * `$maxBytes` where it sets one. `$interface` binds the outgoing connection
 * to that local address.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private int $connectTimeout = 5,
        private int $timeout = 15,
        private bool $followRedirects = false,
        private int $maxBytes = 0,
        private ?string $interface = null
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);
        if ($this->followRedirects) {
            // the _STR form needs PHP 8.3
            defined('CURLOPT_REDIR_PROTOCOLS_STR')
                ? curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https')
                : curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($this->interface !== null && $this->interface !== '') {
            curl_setopt($ch, CURLOPT_INTERFACE, $this->interface);
        }

        // the last response's headers: a redirect starts the list over
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($handle, string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) {
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        });

        $received = '';
        if ($this->maxBytes > 0) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk) use (&$received): int {
                $received .= $chunk;
                return strlen($received) > $this->maxBytes ? 0 : strlen($chunk);
            });
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            return ['status' => 0, 'body' => '', 'error' => $error, 'headers' => []];
        }
        if ($this->maxBytes > 0) {
            $response = $received;
        }

        // no curl_close(): a no-op since PHP 8.0, deprecated in 8.5
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => $status, 'body' => (string) $response, 'error' => '', 'headers' => $responseHeaders];
    }
}
