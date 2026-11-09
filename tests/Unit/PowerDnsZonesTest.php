<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\PowerDnsZones;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeHttpClient;

/**
 * Preparing a zone before the registry is asked to delegate to it, and taking
 * that back when the registry refuses.
 */
final class PowerDnsZonesTest extends EppTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void {
        parent::setUp();
        $this->http = new FakeHttpClient();
        PowerDnsZones::useHttpClient($this->http);
        $this->pdns([]);
    }

    protected function tearDown(): void {
        PowerDnsZones::useHttpClient(null);
        parent::tearDown();
    }

    private function pdns(array $overrides): void {
        Config::loadForTesting(static::SETTINGS + ['pdns' => $overrides + [
            'enabled'     => true,
            'apis'        => [
                ['protocol' => 'http', 'host' => 'pdns1', 'port' => 8081, 'api_key' => 'k1'],
                ['protocol' => 'http', 'host' => 'pdns2', 'port' => 8081, 'api_key' => 'k2'],
            ],
            'nameservers' => ['ns1.example.it', 'ns2.example.it'],
            'ttl'         => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null,
        ]]);
    }

    /** @return string[] "METHOD host" per call, for compact assertions */
    private function calls(): array {
        return array_map(
            static fn(array $c) => $c['method'] . ' ' . parse_url($c['url'], PHP_URL_HOST),
            $this->http->calls()
        );
    }

    /** the fake shares its zones between hosts, so pdns2 finds pdns1's */
    public function testEveryApiGetsTheZoneAndItsNsSet(): void {
        $zone = PowerDnsZones::provision('example-one.it', ['ns1.example.it', 'ns9.other.it']);

        $this->assertNull($zone['warning']);
        $this->assertSame(['GET pdns1', 'POST pdns1', 'PATCH pdns1', 'GET pdns2', 'PATCH pdns2'], $this->calls());
    }

    /** PowerDNS holds zones in punycode; our own database keeps Unicode */
    public function testAnIdnZoneAndItsNameserversReachPowerDnsInPunycode(): void {
        PowerDnsZones::provision('sesamstraße.it', ['ns1.example.it', 'ns1.sesamstraße.it']);

        $requests = $this->http->calls();
        $this->assertStringEndsWith('/zones/xn--sesamstrae-e4a.it.', $requests[0]['url']);
        $this->assertSame('POST', $requests[1]['method']);
        $this->assertSame(
            ['name' => 'xn--sesamstrae-e4a.it.', 'kind' => 'Native', 'nameservers' => ['ns1.example.it.', 'ns1.xn--sesamstrae-e4a.it.']],
            json_decode((string) $requests[1]['body'], true)
        );
        $this->assertStringEndsWith('/zones/xn--sesamstrae-e4a.it.', $requests[2]['url']);
        $this->assertStringContainsString('"ns1.xn--sesamstrae-e4a.it."', (string) $requests[2]['body']);
        foreach ($requests as $request) {
            $this->assertStringNotContainsString('ß', $request['url'] . $request['body']);
        }
    }

    public function testUndoDeletesAnIdnZoneByItsPunycodeId(): void {
        $zone = PowerDnsZones::provision('città.it', ['ns1.example.it']);
        PowerDnsZones::undo('città.it', $zone, []);

        $last = $this->http->calls()[array_key_last($this->http->calls())];
        $this->assertSame('DELETE', $last['method']);
        $this->assertStringEndsWith('/zones/xn--citt-3na.it.', $last['url']);
    }

    public function testNothingHappensWithoutAListedNameserver(): void {
        $zone = PowerDnsZones::provision('example-one.it', ['ns9.other.it']);

        $this->assertSame([], $zone['servers']);
        $this->assertSame([], $this->calls());
    }

    public function testNothingHappensWhileSyncIsOff(): void {
        $this->pdns(['enabled' => false]);

        PowerDnsZones::provision('example-one.it', ['ns1.example.it']);

        $this->assertSame([], $this->calls());
    }

    /** matched after normalizing: case and a trailing dot don't matter */
    public function testNameserversAreComparedNormalized(): void {
        PowerDnsZones::provision('example-one.it', ['NS1.Example.IT.']);

        $this->assertNotSame([], $this->calls());
    }

    public function testAFailureBecomesAWarningNotAnException(): void {
        $this->http->failHost('pdns2', 401, '{"error":"Unauthorized"}');
        $log = tempnam(sys_get_temp_dir(), 'eppitnic-pdns-');
        $previous = ini_set('error_log', $log);

        try {
            $zone = PowerDnsZones::provision('example-one.it', ['ns1.example.it']);
        } finally {
            ini_set('error_log', (string) $previous);
            $logged = (string) file_get_contents($log);
            unlink($log);
        }

        $this->assertStringContainsString('http://pdns2:8081: 401 Unauthorized', (string) $zone['warning']);
        $this->assertStringContainsString('could not be prepared', $logged);
    }

    /** only where it was created: pdns2 found the zone already there */
    public function testUndoDeletesTheZoneWhereItCreatedIt(): void {
        $zone = PowerDnsZones::provision('example-one.it', ['ns1.example.it']);
        $before = count($this->calls());
        PowerDnsZones::undo('example-one.it', $zone, []);

        $this->assertSame(['DELETE pdns1'], array_slice($this->calls(), $before));
    }

    public function testUndoRestoresTheNsSetOfAZoneThatExisted(): void {
        $this->http = new FakeHttpClient(['example-one.it.']);
        PowerDnsZones::useHttpClient($this->http);

        $zone = PowerDnsZones::provision('example-one.it', ['ns1.example.it', 'ns2.example.it']);
        PowerDnsZones::undo('example-one.it', $zone, ['ns1.example.it', 'ns3.example.it']);

        $last = $this->http->calls()[array_key_last($this->http->calls())];
        $this->assertSame('PATCH', $last['method']);
        $this->assertStringContainsString('ns3.example.it.', (string) $last['body']);
        $this->assertNotContains('DELETE pdns1', $this->calls());
    }
}
