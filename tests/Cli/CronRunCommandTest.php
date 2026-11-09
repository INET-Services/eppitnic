<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\CronRunCommand;
use Eppitnic\Config;
use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\StaticHttpClient;
use Eppitnic\Tests\Support\TestPki;
use RedBeanPHP\R;

/**
 * `cron run`'s due/not-due decisions and its `last_run_at` bookkeeping. Every
 * scenario here keeps `tasks`/`domains` empty and `keepalive` off, so a due
 * job's own command returns before ever opening a registry session --
 * `--dry-run` is used instead wherever a job would otherwise be due and
 * unconditionally reach the network (poll_process/keepalive have no local
 * table to stay empty against).
 */
final class CronRunCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'domains', 'tasks', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, user_id INTEGER,
                 active INTEGER DEFAULT 1, ex_date TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1, executed_time TEXT,
                 exit_code INTEGER, exit_message TEXT, created_time TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
    }

    private const DEFAULTS = [
        'pdns'                  => ['enabled' => false, 'apis' => [], 'nameservers' => [], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
        'domain_sync'           => ['enabled' => false, 'batch_size' => 25, 'cursor_id' => 0, 'frequency_minutes' => 5, 'last_run_at' => null],
        'domain_reap_deletions' => ['enabled' => true, 'frequency_minutes' => 15, 'last_run_at' => null],
        'poll_process'          => ['enabled' => true, 'frequency_minutes' => 5, 'last_run_at' => null],
        'passkey_challenge_purge' => ['enabled' => false, 'frequency_minutes' => 1440, 'last_run_at' => null],
        'passkey_metadata'      => ['enabled' => false, 'frequency_minutes' => 1440, 'last_run_at' => null],
    ];

    /**
     * @param array<string, array<string, mixed>> $overrides job => fields to override
     * @param bool $passkeys whether a relying party is set, which switches passkeys on
     */
    private function configure(array $overrides = [], bool $passkeys = true): void {
        $jobs = self::DEFAULTS;
        foreach ($overrides as $job => $fields) {
            $jobs[$job] = $fields + $jobs[$job];
        }
        $policy = ['passkeys' => [
            'rp_id' => $passkeys ? 'localhost' : '', 'origins' => ['http://localhost:5173'],
            'type' => 'any', 'models' => [], 'extra_aaguids' => [],
        ]];
        Config::loadForTesting($jobs + $policy + static::SETTINGS);
    }

    private static function minutesAgo(int $minutes): string {
        return date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
    }

    private function capture(array $args = []): array {
        $command = new CronRunCommand($args);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        return [$code, $output];
    }

    public function testDryRunReportsDueJobsWithoutRunningOrWritingLastRunAt(): void {
        $this->configure([
            'pdns'                  => ['enabled' => true, 'last_run_at' => null],
            'domain_sync'           => ['enabled' => false],
            'domain_reap_deletions' => ['enabled' => true, 'last_run_at' => null],
            'poll_process'          => ['last_run_at' => null],
        ]);

        [$code, $output] = $this->capture(['--dry-run']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('would run: pdns, domain_reap_deletions, poll_process, keepalive', $output);
        $this->assertStringNotContainsString('domain_sync', $output);

        $this->assertNull(Config::get('pdns')['last_run_at']);
        $this->assertNull(Config::get('domain_reap_deletions')['last_run_at']);
        $this->assertNull(Config::get('poll_process')['last_run_at']);
    }

    public function testDisabledAndNotYetDueJobsAreSkipped(): void {
        $this->configure([
            'pdns'                  => ['enabled' => false],
            'domain_sync'           => ['enabled' => false],
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
        ]);

        [$code, $output] = $this->capture(['--dry-run']);

        $this->assertSame(0, $code);
        // keepalive has no due-ness of its own -- always listed, even here
        $this->assertStringContainsString('would run: keepalive', $output);
        foreach (['pdns', 'domain_sync', 'domain_reap_deletions', 'poll_process'] as $job) {
            $this->assertStringNotContainsString($job, $output);
        }
    }

    public function testElapsedFrequencyDeterminesDueness(): void {
        $this->configure([
            'pdns'        => ['enabled' => true, 'last_run_at' => self::minutesAgo(20), 'frequency_minutes' => 15],
            'domain_sync' => ['enabled' => true, 'last_run_at' => self::minutesAgo(2), 'frequency_minutes' => 5],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringContainsString('pdns', $output);
        $this->assertStringNotContainsString('domain_sync', $output);
    }

    public function testPollProcessDueOnElapsedTimeWhenEnabled(): void {
        $this->configure([
            'poll_process' => ['enabled' => true, 'last_run_at' => self::minutesAgo(10), 'frequency_minutes' => 5],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringContainsString('poll_process', $output);
    }

    /**
     * Disabling it is a real foot-gun (the registry password stops
     * auto-rotating) -- see PollProcessCommand -- but `cron run` respects it
     * exactly like every other job's `enabled`, in both --dry-run and a real
     * run (which never opens a registry session to check, since the
     * disabled command returns before that point).
     */
    public function testPollProcessDisabledIsSkippedEvenWhenDue(): void {
        $this->configure([
            'poll_process' => ['enabled' => false, 'last_run_at' => null],
        ]);

        [, $dryRunOutput] = $this->capture(['--dry-run']);
        $this->assertStringNotContainsString('poll_process', $dryRunOutput);

        [$code, $output] = $this->capture();
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('poll_process', $output);
        $this->assertNull(Config::get('poll_process')['last_run_at'], 'a disabled job must not be run');
    }

    public function testARealRunUpdatesLastRunAtOnlyForJobsItRan(): void {
        $this->configure([
            'pdns'                  => ['enabled' => true, 'last_run_at' => null],
            'domain_sync'           => ['enabled' => false],
            'domain_reap_deletions' => ['enabled' => true, 'last_run_at' => null],
            // kept not-due, so PollProcessCommand (which always opens a
            // session) is never actually invoked in this test
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
        ]);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ran: pdns, domain_reap_deletions', $output);

        $this->assertNotNull(Config::get('pdns')['last_run_at']);
        $this->assertNotNull(Config::get('domain_reap_deletions')['last_run_at']);
        $this->assertNull(Config::get('domain_sync')['last_run_at'], 'a disabled job must not be run');
        $this->assertSame(
            self::minutesAgo(1),
            Config::get('poll_process')['last_run_at'],
            'a not-yet-due job must not be run'
        );
    }

    private function challengesTable(): void {
        R::exec('DROP TABLE IF EXISTS webauthn_challenges');
        R::exec('CREATE TABLE webauthn_challenges (id INTEGER PRIMARY KEY, purpose TEXT, user_id INTEGER,
                 challenge_hash TEXT, created_at TEXT, expires_at TEXT, used_at TEXT)');
    }

    public function testThePasskeyChallengePurgeRunsDailyAndPurges(): void {
        $this->challengesTable();
        R::exec("INSERT INTO webauthn_challenges (purpose, challenge_hash, created_at, expires_at)
                 VALUES ('login', 'old', '2020-01-01 00:00:00', '2020-01-01 00:05:00'),
                        ('login', 'live', '2020-01-01 00:00:00', ?)", [date('Y-m-d H:i:s', time() + 300)]);
        $this->configure([
            'domain_reap_deletions'   => ['enabled' => false],
            'poll_process'            => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
            'passkey_challenge_purge' => ['enabled' => true, 'last_run_at' => self::minutesAgo(1500)],
        ]);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ran: passkey_challenge_purge', $output);
        $this->assertSame(['live'], R::getCol('SELECT challenge_hash FROM webauthn_challenges'));
        $this->assertNotNull(Config::get('passkey_challenge_purge')['last_run_at']);
    }

    public function testThePasskeyChallengePurgeWaitsOutItsDay(): void {
        $this->configure([
            'passkey_challenge_purge' => ['enabled' => true, 'last_run_at' => self::minutesAgo(1400)],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringNotContainsString('passkey_challenge_purge', $output);
    }

    public function testADisabledPasskeyChallengePurgeIsSkipped(): void {
        $this->configure([
            'passkey_challenge_purge' => ['enabled' => false, 'last_run_at' => null],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringNotContainsString('passkey_challenge_purge', $output);
    }

    /** A BLOB signed under a root of this test's own, which the metadata code is told to trust. */
    private function fidoBlob(int $serial): string {
        $root = TestPki::root('Test FIDO Root');
        $signer = TestPki::signer($root);
        PasskeyMetadata::useRoot($root['pem']);
        return TestPki::jwt(TestPki::payload(['aaaaaaaa-bbbb-4ccc-8ddd-000000000001' => ['description' => 'Key One']], $serial), $signer, [$signer]);
    }

    private function metadataTable(): void {
        R::exec('DROP TABLE IF EXISTS authenticator_metadata');
        R::exec('CREATE TABLE authenticator_metadata (aaguid TEXT PRIMARY KEY, description TEXT, status TEXT, entry TEXT NOT NULL, updated_at TEXT NOT NULL)');
    }

    protected function tearDown(): void {
        PasskeyMetadata::useHttpClient(null);
        PasskeyMetadata::useRoot(null);
        parent::tearDown();
    }

    public function testThePasskeyMetadataJobFetchesDailyAndStoresWhatVerifies(): void {
        $this->metadataTable();
        $http = new StaticHttpClient(200, $this->fidoBlob(7));
        PasskeyMetadata::useHttpClient($http);
        $this->configure([
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
            'passkey_metadata'      => ['enabled' => true, 'last_run_at' => self::minutesAgo(1500)],
        ]);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ran: passkey_metadata', $output);
        $this->assertCount(1, $http->calls);
        $this->assertSame(7, AuthenticatorMetadata::blob()['serial']);
        $this->assertSame(1, AuthenticatorMetadata::count());
        $this->assertNotNull(Config::get('passkey_metadata')['last_run_at']);
        $this->assertGreaterThan(strtotime(self::minutesAgo(5)), strtotime(Config::get('passkey_metadata')['last_run_at']));
    }

    public function testAFailedMetadataFetchFailsTheRunButKeepsTheOldData(): void {
        $this->metadataTable();
        $this->configure([
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
            'passkey_metadata'      => ['enabled' => true, 'last_run_at' => self::minutesAgo(1500)],
        ]);
        PasskeyMetadata::import($this->fidoBlob(7));
        PasskeyMetadata::useHttpClient(new StaticHttpClient(0, '', 'timeout'));

        [$code] = $this->capture();

        $this->assertSame(CRON_RUN_FAILED, $code);
        $this->assertSame(7, AuthenticatorMetadata::blob()['serial']);
        $this->assertSame(1, AuthenticatorMetadata::count());
    }

    public function testThePasskeyMetadataJobSkipsWhilePasskeysAreOff(): void {
        $this->metadataTable();
        $http = new StaticHttpClient(200, $this->fidoBlob(7));
        PasskeyMetadata::useHttpClient($http);
        $this->configure([
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
            'passkey_metadata'      => ['enabled' => true, 'last_run_at' => self::minutesAgo(1500)],
        ], passkeys: false);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('passkey_metadata: passkeys are off', $output);
        $this->assertStringNotContainsString('ran: passkey_metadata', $output);
        $this->assertSame([], $http->calls);
        $this->assertNull(AuthenticatorMetadata::blob()['serial']);
        $this->assertGreaterThan(strtotime(self::minutesAgo(5)), strtotime(Config::get('passkey_metadata')['last_run_at']), 'it counts as run, so it is not tried every minute');
    }

    public function testADryRunDoesNotListTheMetadataJobWhilePasskeysAreOff(): void {
        $this->configure([
            'passkey_metadata' => ['enabled' => true, 'last_run_at' => null],
        ], passkeys: false);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringNotContainsString('passkey_metadata', $output);
        $this->assertNull(Config::get('passkey_metadata')['last_run_at']);
    }

    public function testThePasskeyMetadataJobWaitsOutItsDay(): void {
        $this->configure([
            'passkey_metadata' => ['enabled' => true, 'last_run_at' => self::minutesAgo(1400)],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringNotContainsString('passkey_metadata', $output);
    }

    public function testADueButDisabledPasskeyMetadataJobIsSkipped(): void {
        $this->configure([
            'passkey_metadata' => ['enabled' => false, 'last_run_at' => null],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringNotContainsString('passkey_metadata', $output);
    }

    public function testThePasskeyMetadataJobIsDueOnItsFirstRun(): void {
        $this->configure([
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
            'passkey_metadata'      => ['enabled' => true, 'last_run_at' => null],
        ]);

        [, $output] = $this->capture(['--dry-run']);

        $this->assertStringContainsString('would run: passkey_metadata', $output);
    }

    public function testNothingDueReportsSo(): void {
        $this->configure([
            'domain_reap_deletions' => ['enabled' => false],
            'poll_process'          => ['last_run_at' => self::minutesAgo(1), 'frequency_minutes' => 5],
        ]);

        [$code, $output] = $this->capture();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing due', $output);
    }
}
