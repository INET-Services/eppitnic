<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\DomainService;
use Eppitnic\Service\Notifier;
use Eppitnic\Service\PollProcessor;
use Eppitnic\Service\PowerDnsZones;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeHttpClient;
use Eppitnic\Tests\Support\FakeMailer;
use RedBeanPHP\R;

/**
 * verifyTransfer() acts on our own pending transfer-ins only: another
 * registrar's request for one of our domains stays in the queue, and a
 * cancelled request is a record nothing reconciles.
 */
final class PollProcessorTransferTest extends EppTestCase
{
    private const UPDATE_REFUSED_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2304"><msg lang="en">Object status prohibits operation</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    private FakeHttpClient $http;

    protected function tearDown(): void {
        Notifier::useMailerFactory(null);
        PowerDnsZones::useHttpClient(null);
        parent::tearDown();
    }

    protected function setUp(): void {
        parent::setUp();

        $this->http = new FakeHttpClient();
        PowerDnsZones::useHttpClient($this->http);
        FakeMailer::reset();
        Notifier::useMailerFactory(fn() => new FakeMailer(true));
        $this->configure(false);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['messages', 'transfers', 'contacts', 'domains', 'history', 'settings', 'tasks', 'users'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        // SQLite has no NOW()
        @R::getPDO()->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'), 0);

        R::exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, type TEXT, domain TEXT, ac_id TEXT, archived_time TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT, registrant TEXT,
                 authinfo TEXT, kind TEXT NOT NULL DEFAULT 'transfer', techc TEXT, dns TEXT, status TEXT NOT NULL DEFAULT 'pending',
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT, time TEXT)");
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER, email TEXT, active INTEGER DEFAULT 1,
                 notify_enabled INTEGER DEFAULT 1, notify_message_types TEXT, notify_fulltext TEXT)');
        R::exec("INSERT INTO users (reseller_id, email) VALUES (2, 'reseller@example.it')");
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, name TEXT, org TEXT, street TEXT, street2 TEXT, street3 TEXT, city TEXT, province TEXT,
                 postalcode TEXT, countrycode TEXT, voice TEXT, fax TEXT, email TEXT, authinfo TEXT,
                 consentforpublishing INTEGER, nationalitycode TEXT, entitytype INTEGER, regcode TEXT, schoolcode TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, object TEXT,
                 action TEXT, active INTEGER DEFAULT 1, exit_code INTEGER, exit_message TEXT)');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 2)");
    
    }

    private function configure(bool $pdns): void {
        Config::loadForTesting(static::SETTINGS + [
            'smtp' => [
                'enabled' => true, 'host' => 'localhost', 'port' => null, 'sender' => '',
                'recipient_mode' => 'both', 'recipient' => 'admin@example.it', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
            'pdns' => [
                'enabled'     => $pdns,
                'apis'        => [['protocol' => 'http', 'host' => 'pdns1', 'port' => 8081, 'api_key' => 'k1']],
                'nameservers' => ['ns1.example.it'],
                'ttl'         => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null,
            ],
        ]);
    }

    private function message(int $id, string $type, string $domain, string $acId = 'OTHER-REG'): void {
        R::exec('INSERT INTO messages (id, type, domain, ac_id) VALUES (?, ?, ?, ?)', [$id, $type, $domain, $acId]);
    }

    private function transfer(string $domain, string $status = 'pending', string $dns = 'a:0:{}',
                              int $attempts = 0, ?string $attemptedAgo = null,
                              ?string $registrant = 'REGI1234REGI5678'): void {
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, kind, techc, dns, status, attempts, attempted_at)
                 VALUES (2, ?, ?, ?, 'a:0:{}', ?, ?, ?, ?)",
            [$domain, $registrant, $registrant === null ? 'transfer' : 'trade', $dns, $status, $attempts,
             $attemptedAgo === null ? null : date('Y-m-d H:i:s', strtotime("-{$attemptedAgo}"))]);
    }

    private function archived(int $id): bool {
        return R::getCell('SELECT archived_time FROM messages WHERE id = ?', [$id]) !== null;
    }

    private function verify(): array {
        return (new PollProcessor($this->nic))->verifyTransfer();
    }

    public function testATransferOutRequestStaysInTheQueue(): void {
        $this->message(1, 'pendingTransfer', 'ours.it');

        $this->verify();

        $this->assertFalse($this->archived(1));
    }

    public function testATransferOutCancelsAScheduledDeletion(): void {
        R::exec("INSERT INTO domains (domain, reseller_id, ns, status) VALUES ('leaving.it', 2, 'a:0:{}', 'a:0:{}')");
        R::exec("INSERT INTO tasks (domain, date, notice, object, action) VALUES ('leaving.it', '2027-05-01', 'scheduled deletion', 'registry', 'delete')");
        $id = (int) R::getInsertID();
        $tag = (string) ($this->nic->EPPCfg->registrar_tag ?: $this->nic->EPPCfg->username);
        $this->message(1, 'serverApprovedTransfer', 'leaving.it', $tag);

        $log = $this->verify();

        $this->assertSame(0, (int) R::getCell("SELECT active FROM domains WHERE domain = 'leaving.it'"));
        $this->assertSame(0, (int) R::getCell('SELECT active FROM tasks WHERE id = ?', [$id]));
        $this->assertContains("  scheduled deletion cancelled (task {$id})", $log);
        $data = json_decode(R::getCell("SELECT data FROM history WHERE object = 'domains' AND action = 'delete'"), true);
        $this->assertSame(['scheduled_deletion' => 'cancelled', 'task_id' => $id], array_intersect_key($data, ['scheduled_deletion' => 0, 'task_id' => 0]));
    }

    public function testAPendingNoticeForOurOwnTransferInIsArchived(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'pendingTransfer', 'example-one.it');
        $this->message(2, 'pendingTransfer', 'ours.it');
        $this->transport->queue(CommandCatalog::TRANSFER_QUERY_RESPONSE);

        $this->verify();

        $this->assertTrue($this->archived(1));
        $this->assertFalse($this->archived(2));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'), 'still pending at the registry');
    }

    /** @return array<string, array{0: string}> */
    public static function cancellations(): array {
        return [
            'by us'        => ['clientCancelledTransfer'],
            'by the registry' => ['serverCancelledTransfer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cancellations')]
    public function testACancelledTransferRemovesItsNote(string $type): void {
        $this->transfer('example-one.it');
        $this->message(1, $type, 'example-one.it');

        $log = $this->verify();

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertStringContainsString('removing transfer note', implode("\n", $log));
        $this->assertSame([], $this->transport->requests);
    }

    public function testACancelledRowIsLeftAlone(): void {
        $this->transfer('example-one.it', 'cancelled');
        $this->message(1, 'pendingTransfer', 'example-one.it');

        $this->verify();

        $this->assertSame('cancelled', R::getCell('SELECT status FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertSame([], $this->transport->requests);
    }

    public function testACompletedTransferIsStoredAndItsNoteRemoved(): void {
        $this->transfer('example-one.it', 'pending', serialize([['name' => 'ns1.example-one.it', 'ip' => ['192.0.2.9']]]));
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->verify();

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.9</domain:hostAddr>', implode('', $this->transport->requests));
    }

    public function testATransferThatCannotBeStoredKeepsItsRow(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        R::exec("CREATE TRIGGER refuse_domains BEFORE INSERT ON domains BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $log = $this->verify();

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertStringContainsString("couldn't store domain locally", implode("\n", $log));
    }

    /** the registry's copy of the registrant, as it gives it to the gaining registrar */
    private function domainInfoWithCopiedRegistrant(): string {
        return str_replace(
            '<domain:registrant>REGI1234REGI5678</domain:registrant>',
            '<domain:registrant>DUP532973256</domain:registrant>',
            CommandCatalog::DOMAIN_INFO_RESPONSE
        );
    }

    public function testACopiedRegistrantIsFetchedAndStoredForTheTransfersReseller(): void {
        $this->transfer('example-one.it');
        R::exec('UPDATE transfers SET reseller_id = 3');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue($this->domainInfoWithCopiedRegistrant());
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);

        $this->verify();

        $contact = R::getRow("SELECT reseller_id, name FROM contacts WHERE handle = 'DUP532973256'");
        $this->assertSame(3, (int) $contact['reseller_id']);
        $this->assertSame('Mario Rossi', $contact['name']);
        $domain = R::getRow("SELECT active, registrant, reseller_id FROM domains WHERE domain = 'example-one.it'");
        $this->assertSame(['DUP532973256', 1, 3], [$domain['registrant'], (int) $domain['active'], (int) $domain['reseller_id']]);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertStringContainsString('<contact:id>DUP532973256</contact:id>', implode('', $this->transport->requests));
        $this->assertSame([null], R::getCol("SELECT user_id FROM history WHERE object = 'contacts' AND action = 'create'"));
    }

    public function testAServerApprovedPlainTransferCompletesWithItsCopiedRegistrant(): void {
        // approved by the registry when the losing registrar did not act
        $this->transfer('example-one.it', registrant: null);
        R::exec('UPDATE transfers SET reseller_id = 3');
        $this->message(1, 'serverApprovedTransfer', 'example-one.it', 'LOSING-REG');
        $this->transport->queue($this->domainInfoWithCopiedRegistrant());
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);

        $this->verify();

        $domain = R::getRow("SELECT active, registrant, reseller_id FROM domains WHERE domain = 'example-one.it'");
        $this->assertSame(['DUP532973256', 1, 3], [$domain['registrant'], (int) $domain['active'], (int) $domain['reseller_id']]);
        $this->assertSame(3, (int) R::getCell("SELECT reseller_id FROM contacts WHERE handle = 'DUP532973256'"));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
    }

    public function testAPlainTransferWithoutARegistrantIsReconciled(): void {
        $this->transfer('example-one.it', registrant: null);
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue($this->domainInfoWithCopiedRegistrant());
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);

        $log = $this->verify();

        $this->assertStringContainsString("verifying 'example-one.it'", implode("\n", $log));
        $domain = R::getRow("SELECT active, registrant, reseller_id FROM domains WHERE domain = 'example-one.it'");
        $this->assertSame(['DUP532973256', 1, 2], [$domain['registrant'], (int) $domain['active'], (int) $domain['reseller_id']]);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
    }

    public function testATradeCompletesWithItsRegistrantAlreadyStored(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);

        $this->verify();

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM contacts'), 'no copy: the registrant is ours');
        $this->assertSame('REGI1234REGI5678', R::getCell("SELECT registrant FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testACopiedRegistrantThatCannotBeFetchedKeepsTheTransferForARetry(): void {
        $this->transfer('example-one.it', 'pending', $this->requestedNs());
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue($this->domainInfoWithCopiedRegistrant());
        $this->transport->queue(self::UPDATE_REFUSED_RESPONSE);

        $log = $this->verify();

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM domains'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM contacts'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame(0, (int) R::getCell('SELECT attempts FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertCount(2, $this->transport->requests, 'no nameserver update before the registrant is stored');
        $this->assertStringContainsString(
            "couldn't store registrant 'DUP532973256' locally, will retry next run",
            implode("\n", $log)
        );
    }

    public function testACopiedRegistrantThatCannotBeStoredKeepsTheTransferForARetry(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue($this->domainInfoWithCopiedRegistrant());
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);
        R::exec("CREATE TRIGGER refuse_contacts BEFORE INSERT ON contacts BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $log = $this->verify();

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM domains'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertStringContainsString("couldn't store registrant 'DUP532973256' locally", implode("\n", $log));
    }

    public function testAStoredRegistrantIsNotFetchedFirstAndKeepsItsReseller(): void {
        $this->transfer('example-one.it');
        R::exec('UPDATE transfers SET reseller_id = 3');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);
        $this->transport->queue(CommandCatalog::CONTACT_INFO_RESPONSE);

        $this->verify();

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM contacts'));
        $this->assertSame(2, (int) R::getCell("SELECT reseller_id FROM contacts WHERE handle = 'REGI1234REGI5678'"));
        $this->assertSame(2, (int) R::getCell("SELECT reseller_id FROM domains WHERE domain = 'example-one.it'"));
        $this->assertCount(3, $this->transport->requests, 'domain info, update, then only the linked-state refresh');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    private function refusedUpdate(): void {
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(self::UPDATE_REFUSED_RESPONSE);
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
    }

    private function requestedNs(): string {
        return serialize([['name' => 'ns1.example.it']]);
    }

    public function testARowThatStillNamesTechContactsDoesNotApplyThem(): void {
        $this->transfer('example-one.it', 'pending', serialize([['name' => 'ns1.example-one.it', 'ip' => ['192.0.2.9']]]));
        R::exec("UPDATE transfers SET techc = ?", [serialize(['TECH-NEW'])]);
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->verify();

        $sent = implode('', $this->transport->requests);
        $this->assertStringContainsString('192.0.2.9', $sent);
        $this->assertStringNotContainsString('domain:contact', $sent);
        $this->assertStringNotContainsString('TECH-NEW', $sent);
    }

    public function testARefusedUpdateStoresTheRegistrysDataAndKeepsTheNote(): void {
        $this->transfer('example-one.it', 'pending', $this->requestedNs());
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->refusedUpdate();

        $this->verify();

        $this->assertStringContainsString('ns2.example.net', (string) R::getCell("SELECT ns FROM domains WHERE domain = 'example-one.it'"));
        $this->assertStringNotContainsString('ns1.example.it', (string) R::getCell("SELECT ns FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(1, (int) R::getCell('SELECT attempts FROM transfers'));
        $this->assertNotNull(R::getCell('SELECT attempted_at FROM transfers'));
        $this->assertFalse($this->archived(1));
        $this->assertSame([], FakeMailer::$sent);
    }

    public function testARetryWithinTwentyMinutesSendsNothing(): void {
        $this->transfer('example-one.it', 'pending', $this->requestedNs(), 1, '10 minutes');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');

        $log = $this->verify();

        $this->assertSame([], $this->transport->requests);
        $this->assertStringContainsString('retry due at', implode("\n", $log));
        $this->assertSame(1, (int) R::getCell('SELECT attempts FROM transfers'));
    }

    public function testARetryAfterTwentyMinutesThatSucceedsCompletesTheTransfer(): void {
        $this->transfer('example-one.it', 'pending', $this->requestedNs(), 2, '21 minutes');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->verify();

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertSame([], FakeMailer::$sent);
    }

    public function testTheFourthFailureSendsTheEmailAndGivesUp(): void {
        $this->transfer('example-one.it', 'pending', $this->requestedNs(), 3, '21 minutes');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->refusedUpdate();

        $log = $this->verify();

        $this->assertSame(['admin@example.it', 'reseller@example.it'], array_column(FakeMailer::$sent, 'to'));
        $this->assertSame('[eppitnic] transfer_update_failed — example-one.it', FakeMailer::$sent[0]['subject']);
        $body = FakeMailer::$sent[0]['body'];
        $this->assertStringContainsString('Object status prohibits operation', $body);
        $this->assertStringContainsString('Requested nameservers: ns1.example.it', $body);
        $this->assertStringNotContainsString('tech contacts', $body);
        $this->assertStringContainsString('ns1.example-one.it (192.0.2.1), ns2.example.net', $body);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertTrue($this->archived(1));
        $this->assertStringContainsString('gave up', implode("\n", $log));
    }

    public function testAFailedFetchIsNotCounted(): void {
        $this->transfer('example-one.it');
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(self::UPDATE_REFUSED_RESPONSE);

        $this->verify();

        $this->assertSame(0, (int) R::getCell('SELECT attempts FROM transfers'));
        $this->assertFalse($this->archived(1));
    }

    public function testOurNameserversGetTheirZoneBeforeTheUpdateAndLoseItOnFailure(): void {
        $this->configure(true);
        $this->transfer('example-one.it', 'pending', $this->requestedNs());
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->refusedUpdate();

        $this->verify();

        $methods = array_column($this->http->calls(), 'method');
        $this->assertSame('GET', $methods[0]);
        $this->assertContains('POST', $methods);
        $this->assertSame('DELETE', end($methods));
    }

    public function testAZoneIsPreparedOnlyForOurNameserversAndKeptOnSuccess(): void {
        $this->configure(true);
        $this->transfer('example-one.it', 'pending', $this->requestedNs());
        $this->message(1, 'clientApprovedTransfer', 'example-one.it');
        $this->transport->queue(CommandCatalog::DOMAIN_INFO_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $this->verify();

        $this->assertNotContains('DELETE', array_column($this->http->calls(), 'method'));
        $this->assertContains('POST', array_column($this->http->calls(), 'method'));
    }

    public function testAReplacedTransferRequestStartsOverWithItsAttempts(): void {
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, status, attempts, attempted_at)
                 VALUES (2, 'example-one.it', 'REGI1234REGI5678', 'cancelled', 3, '2026-01-01 00:00:00')");

        DomainService::recordTransferRequest('example-one.it', 'REGI1234REGI5678', 2);

        $this->assertSame(0, (int) R::getCell('SELECT attempts FROM transfers'));
        $this->assertNull(R::getCell('SELECT attempted_at FROM transfers'));
    }
}
