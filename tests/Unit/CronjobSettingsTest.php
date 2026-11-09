<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\CronjobSettings;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The single place every `config *-set` CLI command and
 * `PATCH /v1/cronjobs/{job}` share for validating, persisting and auditing
 * a scheduled job's settings.
 */
final class CronjobSettingsTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        Config::loadForTesting(static::SETTINGS + [
            'pdns' => ['enabled' => false, 'apis' => [], 'nameservers' => [], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
            'domain_sync' => ['enabled' => false, 'batch_size' => 25, 'cursor_id' => 7, 'frequency_minutes' => 5, 'last_run_at' => null],
            'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
            'poll_process' => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
            'passkey_challenge_purge' => ['enabled' => true, 'frequency_minutes' => 1440, 'last_run_at' => null],
            'passkey_metadata' => ['enabled' => true, 'frequency_minutes' => 1440, 'last_run_at' => null],
        ]);
    }

    public function testJobsListsEveryRegisteredJob(): void {
        $this->assertSame(
            ['pdns', 'domain_sync', 'domain_reap_deletions', 'poll_process', 'passkey_challenge_purge', 'passkey_metadata', 'keepalive'],
            CronjobSettings::jobs()
        );
    }

    public function testFieldsListsAJobsOwnFieldsOnly(): void {
        $this->assertSame(['enabled', 'frequency_minutes'], CronjobSettings::fields('poll_process'));
    }

    public function testFieldsIncludesApisAndNameserversForPdns(): void {
        $this->assertSame(
            ['enabled', 'apis', 'nameservers', 'ttl', 'delay_hours', 'frequency_minutes'],
            CronjobSettings::fields('pdns')
        );
    }

    /** apis/nameservers are lists, not a bare value -- each has its own subcommand */
    public function testScalarFieldsExcludesApisAndNameservers(): void {
        $this->assertSame(['enabled', 'ttl', 'delay_hours', 'frequency_minutes'], CronjobSettings::scalarFields('pdns'));
    }

    public function testListFieldCommandPointsAtConfigPdnsApi(): void {
        $this->assertSame('config pdns-api', CronjobSettings::listFieldCommand('pdns', 'apis'));
    }

    public function testListFieldCommandPointsAtConfigPdnsNameserver(): void {
        $this->assertSame('config pdns-nameserver', CronjobSettings::listFieldCommand('pdns', 'nameservers'));
    }

    public function testListFieldCommandIsNullForAScalarField(): void {
        $this->assertNull(CronjobSettings::listFieldCommand('pdns', 'ttl'));
    }

    public function testGetReturnsTheStoredSettings(): void {
        $this->assertSame(3600, CronjobSettings::get('pdns')['ttl']);
    }

    public function testGetWrapsKeepaliveAsAUniformShape(): void {
        $this->assertSame(['enabled' => false], CronjobSettings::get('keepalive'));
    }

    public function testGetRejectsAnUnknownJob(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::get('bogus');
    }

    public function testSetCoercesAStringBoolAndInt(): void {
        $result = CronjobSettings::set('pdns', ['enabled' => 'true', 'ttl' => '7200'], 1);
        $this->assertTrue($result['enabled']);
        $this->assertSame(7200, $result['ttl']);
        $this->assertTrue(Config::get('pdns')['enabled']);
        $this->assertSame(7200, Config::get('pdns')['ttl']);
    }

    public function testSetPreservesFieldsNotBeingChanged(): void {
        CronjobSettings::set('domain_sync', ['batch_size' => 50], 1);
        $stored = Config::get('domain_sync');
        $this->assertSame(50, $stored['batch_size']);
        $this->assertSame(7, $stored['cursor_id'], 'job state was clobbered by an unrelated field change');
    }

    public function testSetWithNullStoresTheDefault(): void {
        CronjobSettings::set('pdns', ['apis' => [self::validApi()], 'ttl' => 60, 'delay_hours' => 1], 1);
        $result = CronjobSettings::set('pdns', ['apis' => null, 'ttl' => null, 'delay_hours' => null], 1);

        $this->assertSame([], Config::get('pdns')['apis']);
        $this->assertSame(3600, Config::get('pdns')['ttl']);
        $this->assertSame(12, Config::get('pdns')['delay_hours']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testNullDefaultsFollowTheSchemaSeeds(): void {
        CronjobSettings::set('domain_sync', ['batch_size' => 50, 'frequency_minutes' => 1, 'enabled' => false], 1);
        CronjobSettings::set('domain_sync', ['batch_size' => null, 'frequency_minutes' => null, 'enabled' => null], 1);
        $this->assertEquals(['enabled' => true, 'batch_size' => 25, 'frequency_minutes' => 5], array_intersect_key(
            Config::get('domain_sync'), ['enabled' => 1, 'batch_size' => 1, 'frequency_minutes' => 1]
        ));

        CronjobSettings::set('poll_process', ['frequency_minutes' => null], 1);
        $this->assertSame(5, Config::get('poll_process')['frequency_minutes']);
        CronjobSettings::set('domain_reap_deletions', ['frequency_minutes' => null], 1);
        $this->assertSame(15, Config::get('domain_reap_deletions')['frequency_minutes']);
        CronjobSettings::set('passkey_challenge_purge', ['frequency_minutes' => 60], 1);
        CronjobSettings::set('passkey_challenge_purge', ['frequency_minutes' => null], 1);
        $this->assertSame(1440, Config::get('passkey_challenge_purge')['frequency_minutes']);
        CronjobSettings::set('passkey_metadata', ['frequency_minutes' => 60], 1);
        CronjobSettings::set('passkey_metadata', ['frequency_minutes' => null], 1);
        $this->assertSame(1440, Config::get('passkey_metadata')['frequency_minutes']);
        CronjobSettings::set('keepalive', ['enabled' => true], 1);
        $this->assertSame(['enabled' => false], CronjobSettings::set('keepalive', ['enabled' => null], 1));
    }

    public function testGetShowsTheDefaultForAFieldStoredAsNullOrMissing(): void {
        Config::set('domain_sync', ['enabled' => true, 'batch_size' => null, 'cursor_id' => 0, 'last_run_at' => null]);

        $settings = CronjobSettings::get('domain_sync');

        $this->assertSame(25, $settings['batch_size']);
        $this->assertSame(5, $settings['frequency_minutes']);
    }

    public function testSetRejectsAnUnknownField(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('pdns', ['bogus' => 1], 1);
    }

    public function testSetRejectsAnUnknownJob(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('bogus', ['enabled' => true], 1);
    }

    public function testPollProcessEnabledCanBeToggled(): void {
        $result = CronjobSettings::set('poll_process', ['enabled' => false], 1);
        $this->assertFalse($result['enabled']);
        $this->assertFalse(Config::get('poll_process')['enabled']);
    }

    public function testSetRejectsAnInvalidApisEntry(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('pdns', ['apis' => [['protocol' => 'ftp', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'k']]], 1);
    }

    public function testSetAcceptsAValidApisEntry(): void {
        $result = CronjobSettings::set('pdns', ['apis' => [self::validApi()]], 1);
        $this->assertSame('ns1', $result['apis'][0]['host']);
    }

    public function testSetKeepsTheStoredApiKeyWhenBlank(): void {
        CronjobSettings::set('pdns', ['apis' => [self::validApi()]], 1);

        $result = CronjobSettings::set('pdns', ['apis' => [['protocol' => 'https', 'host' => 'ns1', 'port' => 8081, 'api_key' => '']]], 1);

        $this->assertSame('stored-key', $result['apis'][0]['api_key']);
    }

    /** @return array{protocol: string, host: string, port: int, api_key: string} */
    private static function validApi(): array {
        return ['protocol' => 'https', 'host' => 'ns1', 'port' => 8081, 'api_key' => 'stored-key'];
    }

    public function testSetNormalizesNameservers(): void {
        $result = CronjobSettings::set('pdns', ['nameservers' => ['NS1.Example.IT.']], 1);
        $this->assertSame(['ns1.example.it'], $result['nameservers']);
    }

    public function testSetRejectsAnIpAddressAsANameserver(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('pdns', ['nameservers' => ['192.0.2.1']], 1);
    }

    public function testSetRejectsAFrequencyOutOfRange(): void {
        $this->expectException(\InvalidArgumentException::class);
        CronjobSettings::set('domain_sync', ['frequency_minutes' => 1441], 1);
    }

    public function testPreviewDoesNotWrite(): void {
        [, $preview] = CronjobSettings::preview('pdns', ['ttl' => '7200']);
        $this->assertSame(7200, $preview['ttl']);
        $this->assertSame(3600, Config::get('pdns')['ttl'], 'preview() wrote a change');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testSetRecordsOneHistoryRowPerChange(): void {
        CronjobSettings::set('pdns', ['ttl' => '7200'], 42);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertNotEmpty($row);
        $this->assertSame('42', (string) $row['user_id']);
        $this->assertSame('update', $row['action']);
        $this->assertStringContainsString('pdns', $row['data']);
        $this->assertStringContainsString('7200', $row['data']);
    }

    public function testHistoryRedactsApiKeys(): void {
        CronjobSettings::set('pdns', ['apis' => [self::validApi()]], 42);

        $row = R::getRow("SELECT * FROM history WHERE object = 'cronjobs'");
        $this->assertStringNotContainsString('stored-key', $row['data']);
        $this->assertStringContainsString('api_key_set', $row['data']);
    }

    public function testPublicViewRedactsApis(): void {
        CronjobSettings::set('pdns', ['apis' => [self::validApi()]], 1);

        $view = CronjobSettings::publicView('pdns');

        $this->assertTrue($view['apis'][0]['api_key_set']);
        $this->assertArrayNotHasKey('api_key', $view['apis'][0]);
    }

    public function testPublicViewOfAJobWithoutApisIsUnaffected(): void {
        $this->assertSame(CronjobSettings::get('poll_process'), CronjobSettings::publicView('poll_process'));
    }

    public function testKeepaliveSetGoesThroughTheSameHistoryTrail(): void {
        CronjobSettings::set('keepalive', ['enabled' => true], 1);
        $this->assertTrue(Config::get('keepalive'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testMarkRunWritesLastRunAtOnly(): void {
        CronjobSettings::markRun('domain_reap_deletions');

        $stored = Config::get('domain_reap_deletions');
        $this->assertNotNull($stored['last_run_at']);
        $this->assertTrue($stored['enabled'], 'markRun() touched an unrelated field');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'), 'bookkeeping must not be audited');
    }

    public function testMarkRunOnKeepaliveIsANoOp(): void {
        CronjobSettings::markRun('keepalive');
        $this->assertFalse(Config::get('keepalive'), 'keepalive has no last_run_at of its own');
    }
}
