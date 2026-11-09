<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\PdnsSyncCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeHttpClient;
use RedBeanPHP\R;

/**
 * Applying DNS-sync events to PowerDNS over its HTTP API. A FakeHttpClient
 * stands in for every configured server, so what runs, in what order, and
 * what a non-2xx/404 response does is exercised with no real PowerDNS
 * anywhere.
 */
final class PdnsSyncTest extends EppTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS tasks');
        R::exec('DROP TABLE IF EXISTS domains');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, object TEXT, action TEXT,
                 active INTEGER DEFAULT 1, executed_time TEXT, exit_code INTEGER, exit_message TEXT, created_time TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, ns TEXT)');

        $this->http = new FakeHttpClient();

        Config::loadForTesting(self::SETTINGS + [
            'pdns' => [
                'enabled' => true,
                'apis' => [['protocol' => 'http', 'host' => 'ns1.test', 'port' => 8081, 'api_key' => 'k1']],
                'nameservers' => ['ns1.example.com'],
                'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null,
            ],
        ]);
    }

    private int $exitCode = 0;

    /**
     * @param string[] $args
     * @param bool $useFakeHttp false lets the command build its own client
     *             (the real dry-run one, under --dry-run) -- for the tests
     *             that are about that client's own behaviour
     */
    private function sync(array $args = [], bool $useFakeHttp = true): string {
        $command = new PdnsSyncCommand($args);
        if ($useFakeHttp) {
            $command->setHttpClient($this->http);
        }

        ob_start();
        $this->exitCode = $command->run();
        $command->flush();
        return (string) ob_get_clean();
    }

    private function addDomain(string $name, array $nameservers): void {
        $ns = [];
        foreach ($nameservers as $host) {
            $ns[$host] = $host;
        }
        R::exec('INSERT INTO domains (domain, ns) VALUES (?, ?)', [$name, serialize($ns)]);
    }

    /**
     * @param string $created relative to the *database's* clock, which is what
     *        production's CURRENT_TIMESTAMP default writes. PHP's clock is not
     *        the same one -- Client's constructor puts it on Europe/Rome while
     *        the test database answers in UTC -- and building the fixture from
     *        it would be testing against a shape production never produces.
     */
    private function addEvent(string $domain, string $action, string $created = 'now'): void {
        $dbNow = strtotime((string) R::getCell('SELECT CURRENT_TIMESTAMP'));

        R::exec('INSERT INTO tasks (domain, date, object, action, active, created_time) VALUES (?, CURRENT_DATE, \'pdns\', ?, 1, ?)',
            [$domain, $action, date('Y-m-d H:i:s', strtotime($created, $dbNow))]);
    }

    public function testNothingPendingIsNotAnError(): void {
        $output = $this->sync();

        $this->assertSame(0, $this->exitCode);
        $this->assertStringContainsString('no pending DNS-sync events', $output);
        $this->assertSame([], $this->http->calls());
    }

    /**
     * A zone that does not exist yet is created, then its apex NS set is
     * replaced wholesale -- which is what makes create and update the same
     * operation.
     */
    public function testACreateBuildsTheZoneAndItsNsRecords(): void {
        $this->addDomain('example-one.it', ['ns1.example.com', 'ns2.example.com']);
        $this->addEvent('example-one.it', 'create');

        $this->sync();

        $calls = $this->http->calls();
        $this->assertCount(3, $calls);
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertStringEndsWith('/zones/example-one.it.', $calls[0]['url']);

        $this->assertSame('POST', $calls[1]['method']);
        $this->assertStringEndsWith('/zones', $calls[1]['url']);
        $posted = json_decode($calls[1]['body'], true);
        $this->assertSame('example-one.it.', $posted['name']);
        $this->assertSame('Native', $posted['kind']);
        $this->assertSame(['ns1.example.com.', 'ns2.example.com.'], $posted['nameservers']);

        $this->assertSame('PATCH', $calls[2]['method']);
        $patched = json_decode($calls[2]['body'], true);
        $this->assertSame('REPLACE', $patched['rrsets'][0]['changetype']);
        $this->assertSame('NS', $patched['rrsets'][0]['type']);
        $this->assertSame(3600, $patched['rrsets'][0]['ttl']);
        $this->assertSame(
            ['ns1.example.com.', 'ns2.example.com.'],
            array_column($patched['rrsets'][0]['records'], 'content')
        );

        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(0, (int) $row['active'], 'the row was not archived');
        $this->assertSame(0, (int) $row['exit_code']);
        $this->assertStringContainsString('synced', $row['exit_message']);
    }

    /** the row says "sesamstraße.it", PowerDNS is told the punycode zone */
    public function testAnIdnDomainIsSyncedUnderItsPunycodeName(): void {
        $this->addDomain('sesamstraße.it', ['ns1.example.com', 'ns1.xn--sesamstrae-e4a.it']);
        $this->addEvent('sesamstraße.it', 'create');

        $this->sync();

        $calls = $this->http->calls();
        $this->assertStringEndsWith('/zones/xn--sesamstrae-e4a.it.', $calls[0]['url']);
        $this->assertSame('xn--sesamstrae-e4a.it.', json_decode($calls[1]['body'], true)['name']);
        $this->assertSame(
            ['ns1.example.com.', 'ns1.xn--sesamstrae-e4a.it.'],
            array_column(json_decode($calls[2]['body'], true)['rrsets'][0]['records'], 'content')
        );
        $this->assertSame(0, (int) R::getCell('SELECT active FROM tasks WHERE id = 1'));
    }

    public function testAnIdnZoneIsDeletedUnderItsPunycodeName(): void {
        $this->http = new FakeHttpClient(['xn--citt-3na.it.']);
        $this->addEvent('città.it', 'delete', '-13 hours');

        $this->sync();

        $call = $this->http->calls()[0];
        $this->assertSame('DELETE', $call['method']);
        $this->assertStringEndsWith('/zones/xn--citt-3na.it.', $call['url']);
    }

    /** a row still in 6.x's `__SERIALIZED:` envelope reads like a plain one */
    public function testNameserversInTheLegacyEnvelopeAreRead(): void {
        $ns = serialize(['ns1.example.com' => 'ns1.example.com', 'ns2.example.com' => 'ns2.example.com']);
        R::exec('INSERT INTO domains (domain, ns) VALUES (?, ?)', ['example-legacy.it', '__SERIALIZED:' . base64_encode($ns)]);
        $this->addEvent('example-legacy.it', 'create');

        $output = $this->sync();

        $this->assertSame(0, $this->exitCode);
        $this->assertStringContainsString('zone synced (2 NS records)', $output);
    }

    /**
     * An existing zone is not re-created; only its NS set is reconciled.
     */
    public function testAnUpdateSkipsTheZoneCreation(): void {
        $this->http = new FakeHttpClient(['example-one.it.']);
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'update');

        $this->sync();

        $methods = array_column($this->http->calls(), 'method');
        $this->assertSame(['GET', 'PATCH'], $methods);
    }

    public function testADeleteWaitsOutTheGracePeriod(): void {
        $this->addEvent('example-one.it', 'delete', '-1 hour');

        $output = $this->sync();

        $this->assertSame([], $this->http->calls(), 'the zone was torn down inside the grace period');
        $this->assertStringContainsString('not due yet', $output);
        $row = R::getRow('SELECT active, executed_time FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a deferred row was archived');
        $this->assertNull($row['executed_time'], 'a deferred row was never attempted, so it has no result yet');
    }

    public function testADeleteRunsOnceItIsDue(): void {
        $this->http = new FakeHttpClient(['example-one.it.']);
        $this->addEvent('example-one.it', 'delete', '-13 hours');

        $this->sync();

        $calls = $this->http->calls();
        $this->assertCount(1, $calls);
        $this->assertSame('DELETE', $calls[0]['method']);
        $this->assertSame(0, (int) R::getCell('SELECT active FROM tasks WHERE id = 1'));
    }

    public function testTheGracePeriodIsConfigurable(): void {
        $this->http = new FakeHttpClient(['example-one.it.']);
        $this->addEvent('example-one.it', 'delete', '-2 hours');

        $this->sync(['--delay-hours=1']);

        $this->assertSame('DELETE', $this->http->calls()[0]['method']);
    }

    /** deleting an already-gone zone (404) still counts as success */
    public function testADeleteOfAnAlreadyGoneZoneSucceeds(): void {
        $this->addEvent('example-one.it', 'delete', '-13 hours');

        $this->sync();

        $row = R::getRow('SELECT active, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(0, (int) $row['active']);
        $this->assertStringContainsString('deleted', $row['exit_message']);
    }

    /**
     * A row whose age cannot be read is not acted on: deleting a zone is the
     * one step here that cannot be undone, so an unknown age waits.
     */
    public function testADeleteWithNoTimestampIsNotApplied(): void {
        R::exec("INSERT INTO tasks (domain, date, object, action, active, created_time) VALUES ('example-one.it', CURRENT_DATE, 'pdns', 'delete', 1, NULL)");

        $output = $this->sync();

        $this->assertSame([], $this->http->calls());
        $this->assertStringContainsString('no usable created_time', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE id = 1'));
    }

    /**
     * A failing server leaves the row for the next run -- but the failure is
     * now recorded.
     */
    public function testAFailedCallLeavesTheRowForTheNextRun(): void {
        $this->http->failAll(500, '{"error":"stub failure"}');
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');

        $this->sync();

        $this->assertSame(DNS_SYNC_FAILED, $this->exitCode);
        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a failed row was archived');
        $this->assertSame(1, (int) $row['exit_code']);
        $this->assertStringContainsString('FAILED', $row['exit_message']);
        $this->assertStringContainsString('stub failure', $row['exit_message']);
    }

    /**
     * Every configured API gets every change; a row succeeds only when all of
     * them do, and the failing one's own message is in exit_message.
     */
    public function testAFailureOnOneOfSeveralApisKeepsTheRowActive(): void {
        Config::loadForTesting(self::SETTINGS + [
            'pdns' => [
                'enabled' => true,
                'apis' => [
                    ['protocol' => 'http', 'host' => 'ns1.test', 'port' => 8081, 'api_key' => 'k1'],
                    ['protocol' => 'http', 'host' => 'ns2.test', 'port' => 8081, 'api_key' => 'k2'],
                ],
                'nameservers' => ['ns1.example.com'],
                'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null,
            ],
        ]);
        $this->http->failHost('ns2.test', 422, '{"error":"bad NS content"}');
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');

        $this->sync();

        $row = R::getRow('SELECT active, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a partial failure was archived');
        $this->assertStringContainsString('ns2.test', $row['exit_message']);
        $this->assertStringContainsString('bad NS content', $row['exit_message']);

        // the succeeding server still got every call -- every API gets every change
        $ns1Calls = array_filter($this->http->calls(), fn($c) => str_contains($c['url'], 'ns1.test'));
        $this->assertSame(['GET', 'POST', 'PATCH'], array_column($ns1Calls, 'method'));
    }

    public function testADomainMissingLocallyIsSkipped(): void {
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync();

        $this->assertSame([], $this->http->calls());
        $this->assertStringContainsString('domain not found locally', $output);
        $row = R::getRow('SELECT active, exit_code, exit_message FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a skip stays active for the next run');
        $this->assertSame(1, (int) $row['exit_code']);
        $this->assertStringContainsString('SKIPPED', $row['exit_message']);
    }

    public function testADomainWithNoNameserversIsSkipped(): void {
        $this->addDomain('example-one.it', []);
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync();

        $this->assertSame([], $this->http->calls());
        $this->assertStringContainsString('no nameservers on record', $output);
    }

    public function testNoApisConfiguredIsNotAnError(): void {
        Config::loadForTesting(self::SETTINGS + [
            'pdns' => ['enabled' => true, 'apis' => [], 'nameservers' => ['ns1.example.com'], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
        ]);
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync();

        $this->assertSame(0, $this->exitCode);
        $this->assertStringContainsString('no PowerDNS API is configured', $output);
        $this->assertSame([], $this->http->calls());
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE id = 1'), 'nothing was touched');
    }

    /** `apis => null` (unset) must read exactly like `apis => []` */
    public function testApisSetToNullIsTreatedAsNoApisConfigured(): void {
        Config::loadForTesting(self::SETTINGS + [
            'pdns' => ['enabled' => true, 'apis' => null, 'nameservers' => ['ns1.example.com'], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
        ]);

        $output = $this->sync();

        $this->assertStringContainsString('no PowerDNS API is configured', $output);
        $this->assertSame([], $this->http->calls());
    }

    /**
     * A dry run runs nothing over the network and archives nothing -- the
     * events still stand. Uses the command's own dry-run client, not the
     * FakeHttpClient, since this is about that path.
     */
    public function testDryRunTouchesNeitherPowerDnsNorTheQueue(): void {
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');

        $output = $this->sync(['--dry-run'], useFakeHttp: false);

        $row = R::getRow('SELECT active, executed_time FROM tasks WHERE id = 1');
        $this->assertSame(1, (int) $row['active'], 'a row was archived under --dry-run');
        $this->assertNull($row['executed_time'], 'a result was recorded under --dry-run');

        $this->assertStringContainsString('POST', $output);
        $this->assertStringContainsString('/zones', $output);
        $this->assertStringContainsString('PATCH', $output);
        $this->assertStringContainsString('ns1.example.com.', $output);
        $this->assertStringNotContainsString('k1', $output, 'the API key leaked into the dry-run output');
    }

    /**
     * A row dated in the future is not due yet -- it must not be picked up
     * at all, regardless of what --delay-hours would otherwise allow.
     */
    public function testARowDatedInTheFutureIsNotPickedUp(): void {
        R::exec("INSERT INTO tasks (domain, date, object, action, active, created_time) VALUES ('example-one.it', ?, 'pdns', 'create', 1, ?)",
            [date('Y-m-d', strtotime('+1 day')), (string) R::getCell('SELECT CURRENT_TIMESTAMP')]);

        $output = $this->sync();

        $this->assertStringContainsString('no pending DNS-sync events', $output);
        $this->assertSame([], $this->http->calls());
    }

    /**
     * The preview must not claim a zone is missing after showing the POST
     * that would have created it -- two events for the same domain would
     * otherwise print two creates.
     */
    public function testDryRunRemembersZonesItWouldHaveCreated(): void {
        $this->addDomain('example-one.it', ['ns1.example.com']);
        $this->addEvent('example-one.it', 'create');
        $this->addEvent('example-one.it', 'update');

        $output = $this->sync(['--dry-run'], useFakeHttp: false);

        $this->assertSame(1, substr_count($output, 'POST'), 'the same zone was created twice in the preview');
    }
}
