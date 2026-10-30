<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\Command\DomainSyncCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `domain sync` end to end: phase selection/wraparound against a real (in
 * memory) `domains` table, reconciliation against canned registry answers,
 * and the linked-contact refresh step. Follows DomainCommandTest's pattern
 * of driving the real argv-to-output path rather than its pieces.
 */
final class DomainSyncCommandTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'domains', 'contacts', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, authinfo TEXT, ns TEXT, registrant TEXT, admin TEXT, tech TEXT,
                 cr_date TEXT, ex_date TEXT, dnssec TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, name TEXT, org TEXT, street TEXT, street2 TEXT, street3 TEXT, city TEXT,
                 province TEXT, postalcode TEXT, countrycode TEXT, voice TEXT, fax TEXT, email TEXT,
                 authinfo TEXT, consentforpublishing INTEGER, nationalitycode TEXT, entitytype INTEGER,
                 regcode TEXT, schoolcode TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT,
                 acknowledged_time TEXT DEFAULT NULL, acknowledged_user_id INTEGER DEFAULT NULL)');
    }

    // ---------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------

    private function withSettings(array $domainSync): void {
        Config::loadForTesting(['domain_sync' => $domainSync] + static::SETTINGS);
    }

    /**
     * @return int the new row's id
     */
    private function seedDomain(string $name, array $fields = []): int {
        $fields += [
            'reseller_id' => 1, 'active' => 1, 'status' => serialize(['ok']),
            'authinfo'   => 'AUTHINFO12345678', 'ns' => serialize(['ns1.example.it' => [], 'ns2.example.it' => []]),
            'registrant' => 'REGI1234REGI5678', 'admin' => 'ADMIN123ADMIN456',
            'tech'       => serialize(['TECH1234TECH5678' => 'TECH1234TECH5678']),
            'cr_date'    => '2020-01-01', 'ex_date' => '2027-01-01', 'dnssec' => serialize([]),
        ];
        R::exec(
            'INSERT INTO domains (domain, reseller_id, active, status, authinfo, ns, registrant, admin, tech, cr_date, ex_date, dnssec)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name, $fields['reseller_id'], $fields['active'], $fields['status'], $fields['authinfo'],
                $fields['ns'], $fields['registrant'], $fields['admin'], $fields['tech'],
                $fields['cr_date'], $fields['ex_date'], $fields['dnssec'],
            ]
        );
        return (int) R::getCell('SELECT id FROM domains WHERE domain = ?', [$name]);
    }

    private function seedContact(string $handle, int $resellerId = 1): void {
        R::exec('INSERT INTO contacts (handle, reseller_id, status, name, email) VALUES (?, ?, ?, ?, ?)', [
            $handle, $resellerId, serialize(['ok']), 'Mario Rossi', 'old@example.it',
        ]);
    }

    /** a run that never reaches the registry */
    private function runOffline(array $args): string {
        $command = new DomainSyncCommand($args);
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $this->assertSame(0, $command->run());
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, bool> $availability name => available
     */
    private function checkResponse(array $availability): string {
        $cd = '';
        foreach ($availability as $name => $avail) {
            $av = $avail ? 'true' : 'false';
            $reason = $avail ? '' : '<domain:reason lang="en">Registered</domain:reason>';
            $cd .= "<domain:cd><domain:name avail=\"{$av}\">{$name}</domain:name>{$reason}</domain:cd>";
        }
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
          <response>
            <result code="1000"><msg lang="en">Command completed successfully</msg></result>
            <resData>
              <domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">{$cd}</domain:chkData>
            </resData>
            <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
          </response>
        </epp>
        XML;
    }

    private function infoResponse(string $name, array $f = []): string {
        $f += [
            'registrant' => 'REGI1234REGI5678', 'admin' => 'ADMIN123ADMIN456', 'tech' => 'TECH1234TECH5678',
            'ns1' => 'ns1.example.it', 'ns2' => 'ns2.example.it',
            'crDate' => '2020-01-01T00:00:00.000+01:00', 'exDate' => '2027-01-01T00:00:00.000+01:00',
            'authinfo' => 'AUTHINFO12345678', 'status' => 'ok',
        ];
        // an empty admin or tech leaves the element out, as the registry now does
        $adminLine = $f['admin'] === '' ? '' : "<domain:contact type=\"admin\">{$f['admin']}</domain:contact>";
        $techLine  = $f['tech'] === '' ? '' : "<domain:contact type=\"tech\">{$f['tech']}</domain:contact>";
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
          <response>
            <result code="1000"><msg lang="en">Command completed successfully</msg></result>
            <resData>
              <domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
                <domain:name>{$name}</domain:name>
                <domain:roid>ROID-ITNIC</domain:roid>
                <domain:status s="{$f['status']}"/>
                <domain:registrant>{$f['registrant']}</domain:registrant>
                {$adminLine}
                {$techLine}
                <domain:ns>
                  <domain:hostAttr><domain:hostName>{$f['ns1']}</domain:hostName></domain:hostAttr>
                  <domain:hostAttr><domain:hostName>{$f['ns2']}</domain:hostName></domain:hostAttr>
                </domain:ns>
                <domain:clID>TEST-REG</domain:clID>
                <domain:crDate>{$f['crDate']}</domain:crDate>
                <domain:exDate>{$f['exDate']}</domain:exDate>
                <domain:authInfo><domain:pw>{$f['authinfo']}</domain:pw></domain:authInfo>
              </domain:infData>
            </resData>
            <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
          </response>
        </epp>
        XML;
    }

    private function contactInfoResponse(string $handle, string $email = 'mario.rossi@example.it'): string {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="no"?>
        <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
          <response>
            <result code="1000"><msg lang="en">Command completed successfully</msg></result>
            <resData>
              <contact:infData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
                <contact:id>{$handle}</contact:id>
                <contact:roid>{$handle}-ITNIC</contact:roid>
                <contact:status s="ok"/>
                <contact:postalInfo type="loc">
                  <contact:name>Mario Rossi</contact:name>
                  <contact:addr>
                    <contact:street>Via Roma 1</contact:street>
                    <contact:city>Bolzano</contact:city>
                    <contact:sp>BZ</contact:sp>
                    <contact:pc>39100</contact:pc>
                    <contact:cc>IT</contact:cc>
                  </contact:addr>
                </contact:postalInfo>
                <contact:voice>+39.0471000000</contact:voice>
                <contact:email>{$email}</contact:email>
                <contact:clID>TEST-REG</contact:clID>
              </contact:infData>
            </resData>
            <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
          </response>
        </epp>
        XML;
    }

    /**
     * @param string[] $responses queued after login, before logout
     */
    private function withRegistry(Command $command, array $responses, callable $fn): string {
        $transport = new FakeTransport();
        $transport->queue(CommandCatalog::GREETING_RESPONSE);
        $transport->queue(CommandCatalog::OK_RESPONSE);
        foreach ($responses as $response) {
            $transport->queue($response);
        }
        $transport->queue(CommandCatalog::OK_RESPONSE); // logout

        $this->nic->setTransport($transport);
        $this->transport = $transport;
        $command->useClient($this->nic);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // off by default / no candidates / tuning
    // ---------------------------------------------------------------

    public function testOffByDefaultIsANoOp(): void {
        $this->withSettings(['enabled' => false, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('example-one.it');

        $this->assertStringContainsString('domain sync is off', $this->runOffline([]));
        $this->assertCount(0, $this->transport->requests, 'off must make no registry calls at all');
        $cfg = Config::get('domain_sync');
        $this->assertSame(0, $cfg['cursor_id']);
    }

    /**
     * --batch-size overrides this run only; `config domain-sync-set
     * batch_size` changes the stored default.
     */
    public function testBatchSizeOptionDoesNotPersist(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);

        $this->runOffline(['--batch-size=10']);

        $this->assertSame(25, Config::get('domain_sync')['batch_size'], 'the CLI override leaked into the stored setting');
    }

    public function testBatchSizeOptionHasNoEffectWhileOff(): void {
        $this->withSettings(['enabled' => false, 'batch_size' => 25, 'cursor_id' => 0]);

        $this->assertStringContainsString('domain sync is off', $this->runOffline(['--batch-size=10']));
        $this->assertCount(0, $this->transport->requests, 'off means off, regardless of --batch-size');
        $this->assertSame(25, Config::get('domain_sync')['batch_size']);
    }

    public function testRejectsAnOutOfRangeBatchSize(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);

        $this->expectException(\Eppitnic\Cli\UsageError::class);
        (new DomainSyncCommand(['--batch-size=0']))->run();
    }

    public function testNoActiveDomainsIsANoOp(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);

        $this->assertStringContainsString('no active domains', $this->runOffline([]));
        $this->assertCount(0, $this->transport->requests);
    }

    // ---------------------------------------------------------------
    // phase selection and wraparound (no registry detail needed --
    // every candidate checks as gone, so nothing beyond <domain:check> fires)
    // ---------------------------------------------------------------

    public function testPhaseSelectionCoversTheFirstBatch(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 5, 'cursor_id' => 0]);
        $names = [];
        for ($i = 1; $i <= 12; $i++) {
            $names[] = "domain{$i}.it";
            $this->seedDomain("domain{$i}.it");
        }

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [$this->checkResponse(array_fill_keys(array_slice($names, 0, 5), true))], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $this->assertSame(5, Config::get('domain_sync')['cursor_id']);
    }

    public function testPhaseWrapsAroundToTheStart(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 5, 'cursor_id' => 5]);
        $names = [];
        for ($i = 1; $i <= 8; $i++) {
            $names[] = "domain{$i}.it";
            $this->seedDomain("domain{$i}.it");
        }
        // ids 6,7,8 then wraps to 1,2
        $expected = ['domain6.it', 'domain7.it', 'domain8.it', 'domain1.it', 'domain2.it'];

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [$this->checkResponse(array_fill_keys($expected, true))], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $this->assertSame(2, Config::get('domain_sync')['cursor_id']);
    }

    public function testChunksMoreThanFiveDomainsIntoMultipleCheckCalls(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 7, 'cursor_id' => 0]);
        $names = [];
        for ($i = 1; $i <= 7; $i++) {
            $names[] = "domain{$i}.it";
            $this->seedDomain("domain{$i}.it");
        }

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [
            $this->checkResponse(array_fill_keys(array_slice($names, 0, 5), true)),
            $this->checkResponse(array_fill_keys(array_slice($names, 5, 2), true)),
        ], function () use ($command) {
            $command->run();
        });

        $checks = array_values(array_filter($this->transport->requests, fn($r) => str_contains($r, '<domain:check')));
        $this->assertCount(2, $checks, 'seven names must be split into two <domain:check> calls');
    }

    // ---------------------------------------------------------------
    // reconciliation
    // ---------------------------------------------------------------

    public function testADomainNoLongerAtTheRegistryIsReportedNotDeactivated(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('gone.it');

        $command = new DomainSyncCommand([]);
        $output = $this->withRegistry($command, [$this->checkResponse(['gone.it' => true])], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $this->assertStringContainsString('no longer at the registry', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['gone.it']));
    }

    public function testANameMissingFromTheAnswerIsAFailedCheckNotAGoneDomain(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('silent.it');

        $command = new DomainSyncCommand([]);
        $output = $this->withRegistry($command, [$this->checkResponse(['other.it' => false])], function () use ($command) {
            $this->assertSame(DOMAIN_FETCH_FAILED, $command->run());
        });

        $this->assertStringNotContainsString('no longer at the registry', $output);
        $this->assertStringContainsString('1 failure(s)', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM domains WHERE domain = ?', ['silent.it']));
    }

    public function testNamesMatchTheAnswerRegardlessOfCaseAndIdnForm(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain("caff\u{00e8}.it");
        $this->seedDomain('Mixed.IT');

        $command = new DomainSyncCommand([]);
        $answer = $this->checkResponse(['xn--caff-8oa.it' => false, 'mixed.it' => false]);
        $output = $this->withRegistry($command, [
            $answer, $this->infoResponse('xn--caff-8oa.it'), $this->infoResponse('mixed.it'),
            CommandCatalog::CONTACT_INFO_RESPONSE, CommandCatalog::CONTACT_INFO_RESPONSE,
            CommandCatalog::CONTACT_INFO_RESPONSE,
        ], function () use ($command) {
            $command->run();
        });

        $this->assertStringContainsString('0 gone', $output);
        $this->assertStringContainsString('0 failure(s)', $output);
    }

    public function testADriftedDomainIsReconciled(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('drifted.it'); // admin defaults to ADMIN123ADMIN456

        $command = new DomainSyncCommand([]);
        $output = $this->withRegistry($command, [
            $this->checkResponse(['drifted.it' => false]),
            $this->infoResponse('drifted.it', ['admin' => 'NEWADMIN12345678']),
            $this->contactInfoResponse('REGI1234REGI5678'),
            $this->contactInfoResponse('NEWADMIN12345678'),
            $this->contactInfoResponse('TECH1234TECH5678'),
        ], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $this->assertSame('NEWADMIN12345678', R::getCell('SELECT admin FROM domains WHERE domain = ?', ['drifted.it']));
        $history = R::getRow("SELECT * FROM history WHERE object = 'domains' AND action = 'update'");
        $this->assertNotEmpty($history, 'the reconciliation must be recorded');
        $this->assertStringNotContainsString('"ns"', $history['data'], 'nameservers did not move -- no spurious ns change');
        $this->assertStringContainsString('reconciled (admin)', $output);
    }

    public function testAnInfoWithoutAdminAndTechClearsTheStoredContacts(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('drifted.it');

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [
            $this->checkResponse(['drifted.it' => false]),
            $this->infoResponse('drifted.it', ['admin' => '', 'tech' => '']),
            $this->contactInfoResponse('REGI1234REGI5678'),
        ], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $row = R::getRow('SELECT admin, tech FROM domains WHERE domain = ?', ['drifted.it']);
        $this->assertSame('', (string) $row['admin']);
        $this->assertSame([], \Eppitnic\Persistence\SerializedColumn::toArray($row['tech']));
    }

    public function testExpiryOnlyDriftStillTriggersAWrite(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('renewed.it', ['ex_date' => '2027-01-01']); // local: stale expiry

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [
            $this->checkResponse(['renewed.it' => false]),
            $this->infoResponse('renewed.it', ['exDate' => '2028-01-01T00:00:00.000+01:00']), // registry: renewed
            $this->contactInfoResponse('REGI1234REGI5678'),
            $this->contactInfoResponse('ADMIN123ADMIN456'),
            $this->contactInfoResponse('TECH1234TECH5678'),
        ], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $this->assertStringContainsString(
            '2028-01-01',
            (string) R::getCell('SELECT ex_date FROM domains WHERE domain = ?', ['renewed.it']),
            'an expiry-only drift, with nothing else changed, must still be persisted'
        );
    }

    public function testReportOnlyWritesNothingAndDoesNotAdvanceTheCursor(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('drifted.it');

        $command = new DomainSyncCommand(['--report-only']);
        $output = $this->withRegistry($command, [
            $this->checkResponse(['drifted.it' => false]),
            $this->infoResponse('drifted.it', ['admin' => 'NEWADMIN12345678']),
            $this->contactInfoResponse('REGI1234REGI5678'),
            $this->contactInfoResponse('NEWADMIN12345678'),
            $this->contactInfoResponse('TECH1234TECH5678'),
        ], function () use ($command) {
            $this->assertSame(DATA_INCONSISTENT, $command->run());
        });

        $this->assertStringContainsString('would reconcile', $output);
        $this->assertSame('ADMIN123ADMIN456', R::getCell('SELECT admin FROM domains WHERE domain = ?', ['drifted.it']));
        $this->assertSame(0, Config::get('domain_sync')['cursor_id']);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM contacts"), 'nothing should be stored either');
    }

    // ---------------------------------------------------------------
    // linked contacts
    // ---------------------------------------------------------------

    public function testANewlyDiscoveredTechContactIsFetchedAndStored(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('example.it', ['reseller_id' => 3]);
        $this->seedContact('REGI1234REGI5678');
        $this->seedContact('ADMIN123ADMIN456');
        // TECH1234TECH5678 is not seeded -- it is the newly-discovered one

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [
            $this->checkResponse(['example.it' => false]),
            $this->infoResponse('example.it'),
            $this->contactInfoResponse('REGI1234REGI5678'),
            $this->contactInfoResponse('ADMIN123ADMIN456'),
            $this->contactInfoResponse('TECH1234TECH5678'),
        ], function () use ($command) {
            $command->run();
        });

        $row = R::getRow('SELECT * FROM contacts WHERE handle = ?', ['TECH1234TECH5678']);
        $this->assertNotEmpty($row, 'a newly-seen tech contact must be stored');
        $this->assertSame(3, (int) $row['reseller_id'], 'a new contact is owned by the reseller of the domain that named it');
    }

    public function testAnAlreadyKnownContactIsRefreshedWithoutClobberingItsOwner(): void {
        $this->withSettings(['enabled' => true, 'batch_size' => 25, 'cursor_id' => 0]);
        $this->seedDomain('example.it', ['reseller_id' => 1]);
        $this->seedContact('REGI1234REGI5678', 7);
        $this->seedContact('ADMIN123ADMIN456', 7);
        $this->seedContact('TECH1234TECH5678', 7); // pre-existing, owned by reseller 7

        $command = new DomainSyncCommand([]);
        $this->withRegistry($command, [
            $this->checkResponse(['example.it' => false]),
            $this->infoResponse('example.it'),
            $this->contactInfoResponse('REGI1234REGI5678'),
            $this->contactInfoResponse('ADMIN123ADMIN456'),
            $this->contactInfoResponse('TECH1234TECH5678', 'refreshed@example.it'),
        ], function () use ($command) {
            $command->run();
        });

        $row = R::getRow('SELECT * FROM contacts WHERE handle = ?', ['TECH1234TECH5678']);
        $this->assertSame(7, (int) $row['reseller_id'], 'an existing owner must not be clobbered');
        $this->assertSame('refreshed@example.it', $row['email'], 'but its data is still refreshed');
    }
}
