<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Api\Access;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * Contacts, domains and transfers belong to a reseller: who may reach them
 * (Api\Access), where a stored domain lands, and the reseller's daily quota.
 */
final class ResellerScopingTest extends EppTestCase
{
    private Scope $alice;   // user of reseller 2
    private Scope $carol;   // Alice's colleague, same reseller
    private Scope $bob;     // user of reseller 3
    private Scope $admin;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'domains', 'contacts', 'transfers', 'history', 'settings', 'tasks'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE resellers (id INTEGER PRIMARY KEY, name TEXT, max_operations INTEGER DEFAULT 0, active INTEGER DEFAULT 1)');
        R::exec("INSERT INTO resellers (id, name, max_operations) VALUES (1, 'Registrar (self)', 0), (2, 'Two', 2), (3, 'Three', 0)");
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER, role TEXT)');
        R::exec("INSERT INTO users (id, reseller_id, role) VALUES (1, 1, 'admin'), (2, 2, 'user'), (3, 3, 'user'), (4, 2, 'user')");
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, reseller_id INTEGER, active INTEGER DEFAULT 1, status TEXT,
                 domain TEXT UNIQUE, authinfo TEXT, ns TEXT, registrant TEXT, admin TEXT, tech TEXT, cr_date TEXT,
                 ex_date TEXT, dnssec TEXT, last_invoice TEXT DEFAULT CURRENT_TIMESTAMP)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER, handle TEXT UNIQUE, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT, registrant TEXT, status TEXT NOT NULL DEFAULT \'pending\')');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, object TEXT, action TEXT, active INTEGER DEFAULT 1)');

        R::exec("INSERT INTO contacts (reseller_id, handle) VALUES (2, 'REG-TWO'), (3, 'REG-THREE')");
        R::exec("INSERT INTO domains (reseller_id, domain, registrant, admin, tech) VALUES
                 (2, 'two.it', 'REG-TWO', 'ADM-X', ?), (3, 'three.it', 'REG-THREE', NULL, NULL)", [serialize(['TECH-SHARED' => 'TECH-SHARED'])]);
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant) VALUES (2, 'pending.it', 'REG-TWO')");

        $this->alice = new Scope(2, 2, 'user');
        $this->carol = new Scope(4, 2, 'user');
        $this->bob   = new Scope(3, 3, 'user');
        $this->admin = new Scope(1, 1, 'admin');
    }

    // ---------------------------------------------------------------
    // domains
    // ---------------------------------------------------------------

    public function testAColleagueReachesTheResellersDomains(): void {
        $this->assertTrue(Access::canAccessDomain('two.it', $this->alice));
        $this->assertTrue(Access::canAccessDomain('two.it', $this->carol));
    }

    public function testAnotherResellerDoesNot(): void {
        $this->assertFalse(Access::canAccessDomain('two.it', $this->bob));
        $this->assertTrue(Access::canAccessDomain('two.it', $this->admin));
    }

    public function testAPendingTransferIsTheRequestingResellers(): void {
        $this->assertFalse(Access::canAccessDomain('pending.it', $this->alice), 'not a domain yet');
        $this->assertTrue(Access::canAccessDomain('pending.it', $this->alice, true));
        $this->assertFalse(Access::canAccessDomain('pending.it', $this->bob, true));
    }

    public function testACancelledTransferIsOnlyARecord(): void {
        R::exec("UPDATE transfers SET status = 'cancelled' WHERE domain = 'pending.it'");

        $this->assertFalse(Access::canAccessDomain('pending.it', $this->alice, true));
        $this->assertTrue(Access::canAccessDomain('pending.it', $this->admin, true));
    }

    public function testAClaimOnAnotherResellersDomainIsBlocked(): void {
        $this->assertTrue(Access::domainHeldByAnotherReseller('three.it', $this->alice));
        $this->assertFalse(Access::domainHeldByAnotherReseller('two.it', $this->carol));
        $this->assertFalse(Access::domainHeldByAnotherReseller('three.it', $this->admin));
    }

    public function testOnlyTheResellersOwnContactsMayBeRegistrants(): void {
        $this->assertTrue(Access::canUseAsRegistrant('REG-TWO', $this->carol));
        $this->assertFalse(Access::canUseAsRegistrant('REG-THREE', $this->alice));
        $this->assertTrue(Access::canUseAsRegistrant('REG-THREE', $this->admin));
    }

    // ---------------------------------------------------------------
    // contacts
    // ---------------------------------------------------------------

    public function testAContactIsItsResellers(): void {
        $this->assertTrue(Access::ownsContact('REG-TWO', $this->carol));
        $this->assertFalse(Access::ownsContact('REG-TWO', $this->bob));
        $this->assertTrue(Access::ownsContact('REG-TWO', $this->admin));
    }

    public function testAContactOnOneOfTheResellersDomainsIsReadableButNotOwned(): void {
        $this->assertTrue(Access::canAccessContact('TECH-SHARED', $this->alice));
        $this->assertFalse(Access::ownsContact('TECH-SHARED', $this->alice));
        $this->assertFalse(Access::canAccessContact('TECH-SHARED', $this->bob));
    }

    // ---------------------------------------------------------------
    // storing a domain
    // ---------------------------------------------------------------

    public function testAStoredDomainLandsInItsRegistrantsReseller(): void {
        $domain = new Domain($this->nic);
        $domain->set('domain', 'new.it');
        $domain->set('registrant', 'REG-THREE');

        $this->assertTrue($domain->storeDB(2, false));

        $this->assertSame(3, (int) R::getCell("SELECT reseller_id FROM domains WHERE domain = 'new.it'"), 'the registrant decides, not the actor');
        $this->assertSame(2, (int) R::getCell("SELECT user_id FROM history WHERE object = 'domains' AND action = 'create'"), 'history names the actor');
    }

    // ---------------------------------------------------------------
    // importing
    // ---------------------------------------------------------------

    private static function response(string $name): string {
        return file_get_contents(__DIR__ . "/../fixtures/responses/{$name}.xml");
    }

    public function testAnImportLeavesAnotherResellersDomainAlone(): void {
        $results = DomainService::import($this->nic, ['three.it'], $this->alice);

        $this->assertSame('held by another reseller', $results['three.it']['domain']);
        $this->assertSame([], $this->transport->requests, 'the registry is never asked');
        $this->assertSame(1, (int) R::getCell("SELECT active FROM domains WHERE domain = 'three.it'"));
    }

    public function testAnImportDeactivatesOnlyTheResellersOwnMissingDomain(): void {
        $this->transport->queue(self::response('domain-info-error'));
        DomainService::import($this->nic, ['two.it'], $this->alice);
        $this->assertSame(0, (int) R::getCell("SELECT active FROM domains WHERE domain = 'two.it'"));

        $this->transport->queue(self::response('domain-info-error'));
        DomainService::import($this->nic, ['three.it'], $this->admin);
        $this->assertSame(0, (int) R::getCell("SELECT active FROM domains WHERE domain = 'three.it'"), 'an admin reconciles any');
    }

    public function testAnImportOfAMissingDomainCancelsItsScheduledDeletion(): void {
        R::exec("INSERT INTO tasks (domain, date, notice, object, action) VALUES ('two.it', '2027-05-01', 'scheduled deletion', 'registry', 'delete')");
        $this->transport->queue(self::response('domain-info-error'));

        DomainService::import($this->nic, ['two.it'], $this->alice);

        $this->assertSame(0, (int) R::getCell("SELECT active FROM tasks WHERE domain = 'two.it'"));
        $data = json_decode(R::getCell("SELECT data FROM history WHERE object = 'domains' AND action = 'delete'"), true);
        $this->assertSame('cancelled', $data['scheduled_deletion']);
    }

    public function testAnImportLeavesAnotherResellersScheduledDeletion(): void {
        R::exec("INSERT INTO tasks (domain, date, notice, object, action) VALUES ('three.it', '2027-05-01', 'scheduled deletion', 'registry', 'delete')");

        DomainService::import($this->nic, ['three.it'], $this->alice);

        $this->assertSame(1, (int) R::getCell("SELECT active FROM tasks WHERE domain = 'three.it'"));
    }

    public function testAnImportWillNotFollowARegistrantIntoAnotherReseller(): void {
        R::exec("INSERT INTO contacts (reseller_id, handle) VALUES (3, 'TESTHANDLE000001')");
        $this->transport->queue(self::response('domain-info-ok'))->queue(self::response('contact-info-ok'));

        $results = DomainService::import($this->nic, ['example-1.it'], $this->alice);

        $this->assertSame('held by another reseller', $results['example-1.it']['registrant']);
        $this->assertSame('skipped', $results['example-1.it']['domain_stored']);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = 'example-1.it'"));
    }

    // ---------------------------------------------------------------
    // the daily quota (reseller 2: max 2)
    // ---------------------------------------------------------------

    public function testTheQuotaCountsEveryUserOfTheReseller(): void {
        Access::recordRequest('two.it', 'register', $this->alice);
        $this->assertTrue(Access::withinQuota($this->carol));

        Access::recordRequest('pending.it', 'transfer', $this->carol);
        $this->assertFalse(Access::withinQuota($this->alice), 'a transfer-in request counts too');
        $this->assertTrue(Access::withinQuota($this->bob), 'another reseller is unaffected');
    }

    public function testYesterdaysRequestsDoNotCount(): void {
        R::exec("INSERT INTO history (timestamp, user_id, object, object_id, action, data)
                 VALUES (DATETIME('now', '-1 day'), 2, 'domains', 0, 'request', '{}'), (DATETIME('now', '-1 day'), 4, 'domains', 0, 'request', '{}')");

        $this->assertTrue(Access::withinQuota($this->alice));
    }

    public function testOnlyRequestsCountNotLocalBookkeeping(): void {
        R::exec("INSERT INTO history (user_id, object, object_id, action, data)
                 VALUES (2, 'domains', 1, 'create', '{}'), (2, 'domains', 1, 'create', '{}'), (2, 'domains', 1, 'create', '{}')");

        $this->assertTrue(Access::withinQuota($this->alice), 'imports, sync and completions write create, not request');
    }

    public function testAnAdminAndAnUnlimitedResellerAreNeverOverQuota(): void {
        foreach (range(1, 3) as $i) {
            Access::recordRequest("x{$i}.it", 'register', $this->admin);
            Access::recordRequest("y{$i}.it", 'register', $this->bob);
        }

        $this->assertTrue(Access::withinQuota($this->admin));
        $this->assertTrue(Access::withinQuota($this->bob), 'reseller 3 has max_operations 0');
    }

    public function testARequestIsRecordedForTheActingUser(): void {
        Access::recordRequest('two.it', 'transfer', $this->carol);

        $row = R::getRow("SELECT * FROM history WHERE action = 'request'");
        $this->assertSame(4, (int) $row['user_id']);
        $this->assertSame('domains', $row['object']);
        $this->assertSame(['domain' => 'two.it', 'kind' => 'transfer'], json_decode($row['data'], true));
    }
}
