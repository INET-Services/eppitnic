<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\PowerDns\HttpClient;

/**
 * One fixed answer to every request, and a record of what was asked.
 */
final class StaticHttpClient implements HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array}> */
    public array $calls = [];

    public function __construct(
        private int $status = 200,
        private string $body = '',
        private string $error = ''
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body): array {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        return ['status' => $this->status, 'body' => $this->body, 'error' => $this->error];
    }
}
