<?php

namespace Eppitnic\PowerDns;

use Eppitnic\Service\PowerDnsApis;
use Eppitnic\Support\Idn;

/**
 * The four PowerDNS authoritative HTTP API calls `pdns sync` needs, for one
 * server (`server_id` is always `localhost`, PowerDNS's only value for the
 * authoritative server). Idempotent throughout: a 409 on create or a 404 on
 * delete both count as success.
 *
 * @category    Net
 * @package     Eppitnic\PowerDns\Api
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Api
{
    private string $base;

    /** @param array{protocol: string, host: string, port: int, api_key: string} $server */
    public function __construct(
        private HttpClient $http,
        private array $server
    ) {
        $this->base = PowerDnsApis::baseUrl($server) . '/api/v1/servers/localhost';
    }

    /** @return array{ok: bool, exists: bool, message: string} */
    public function zoneExists(string $zone): array {
        $response = $this->send('GET', '/zones/' . rawurlencode($this->zoneId($zone)), null);

        if ($response['status'] === 200) {
            return ['ok' => true, 'exists' => true, 'message' => ''];
        }
        if ($response['status'] === 404) {
            return ['ok' => true, 'exists' => false, 'message' => ''];
        }
        return ['ok' => false, 'exists' => false, 'message' => $this->errorMessage($response)];
    }

    /**
     * @param string[] $nameservers
     * @return array{ok: bool, message: string}
     */
    public function createZone(string $zone, array $nameservers): array {
        $body = [
            'name'        => $this->zoneId($zone),
            'kind'        => 'Native',
            'nameservers' => array_map([$this, 'fqdn'], $nameservers),
        ];
        $response = $this->send('POST', '/zones', $body);

        if ($response['status'] === 201) {
            return ['ok' => true, 'message' => 'zone created'];
        }
        if ($response['status'] === 409) {
            return ['ok' => true, 'message' => 'zone already exists'];
        }
        return ['ok' => false, 'message' => 'create zone failed: ' . $this->errorMessage($response)];
    }

    /**
     * @param string[] $nameservers
     * @return array{ok: bool, message: string}
     */
    public function replaceApexNs(string $zone, array $nameservers, int $ttl): array {
        $zoneId = $this->zoneId($zone);
        $body = [
            'rrsets' => [[
                'name'       => $zoneId,
                'type'       => 'NS',
                'ttl'        => $ttl,
                'changetype' => 'REPLACE',
                'records'    => array_map(
                    fn(string $ns) => ['content' => $this->fqdn($ns), 'disabled' => false],
                    $nameservers
                ),
            ]],
        ];
        $response = $this->send('PATCH', '/zones/' . rawurlencode($zoneId), $body);

        if ($response['status'] === 204) {
            return ['ok' => true, 'message' => 'apex NS records set'];
        }
        return ['ok' => false, 'message' => 'set apex NS records failed: ' . $this->errorMessage($response)];
    }

    /** @return array{ok: bool, message: string} */
    public function deleteZone(string $zone): array {
        $response = $this->send('DELETE', '/zones/' . rawurlencode($this->zoneId($zone)), null);

        if ($response['status'] === 204) {
            return ['ok' => true, 'message' => 'zone deleted'];
        }
        if ($response['status'] === 404) {
            return ['ok' => true, 'message' => 'zone already gone'];
        }
        return ['ok' => false, 'message' => 'delete zone failed: ' . $this->errorMessage($response)];
    }

    /**
     * Look the zone up, create it if missing, then replace its apex NS set --
     * what `pdns sync` does for a create or an update, on this one server.
     *
     * @param string[] $nameservers
     * @return array{ok: bool, created: bool, message: string}
     */
    public function syncZone(string $zone, array $nameservers, int $ttl): array {
        $exists = $this->zoneExists($zone);
        if ( ! $exists['ok']) {
            return ['ok' => false, 'created' => false, 'message' => $exists['message']];
        }

        $created = false;
        if ( ! $exists['exists']) {
            $outcome = $this->createZone($zone, $nameservers);
            if ( ! $outcome['ok']) {
                return ['ok' => false, 'created' => false, 'message' => $outcome['message']];
            }
            $created = true;
        }

        return ['created' => $created] + $this->replaceApexNs($zone, $nameservers, $ttl);
    }

    private function zoneId(string $zone): string {
        return $this->fqdn($zone);
    }

    /** the only place a name reaches PowerDNS, which holds zones in punycode */
    private function fqdn(string $name): string {
        return Idn::ace(rtrim($name, '.')) . '.';
    }

    /** @return array{status: int, body: string, error: string} */
    private function send(string $method, string $path, ?array $jsonBody): array {
        $headers = ['X-API-Key: ' . $this->server['api_key']];
        $body = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        }
        return $this->http->request($method, $this->base . $path, $headers, $body);
    }

    /** @param array{status: int, body: string, error: string} $response */
    private function errorMessage(array $response): string {
        if ($response['status'] === 0) {
            return $response['error'] !== '' ? $response['error'] : 'connection failed';
        }

        $decoded = json_decode($response['body'], true);
        $detail = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : trim($response['body']);
        $detail = $detail !== '' ? $detail : 'no further detail';

        return "{$response['status']} {$detail}";
    }
}
