<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * An operation that changes which domains reference a contact re-reads it
 * from the registry, so its stored status says "linked" without a sync.
 */
final class ContactRefreshTest extends EppTestCase
{
    private string $contactInfo;

    protected function setUp(): void {
        parent::setUp();

        \Eppitnic\Config::loadForTesting(self::SETTINGS + [
            'pdns' => ['enabled' => false, 'apis' => [], 'nameservers' => []],
        ]);
        $this->nic = new \Eppitnic\Epp\Client();
        $this->nic->setTransport($this->transport);
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'history', 'resellers', 'tasks', 'settings'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 user_id INTEGER, registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, name TEXT, org TEXT, street TEXT, street2 TEXT, street3 TEXT, city TEXT, province TEXT,
                 postalcode TEXT, countrycode TEXT, voice TEXT, fax TEXT, email TEXT, authinfo TEXT,
                 consentforpublishing INTEGER, nationalitycode TEXT, entitytype INTEGER, regcode TEXT, schoolcode TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec('CREATE TABLE resellers (id INTEGER PRIMARY KEY, techc TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, object TEXT, action TEXT, active INTEGER)');
        R::exec("INSERT INTO contacts (handle, reseller_id, status) VALUES ('ABCD1234EFGH5678', 2, ?)", [serialize(['ok'])]);

        $this->contactInfo = str_replace(
            '<contact:status s="ok"/>',
            '<contact:status s="ok"/><contact:status s="linked"/>',
            CommandCatalog::CONTACT_INFO_RESPONSE
        );
    }

    private function registry(string $contactAnswer): void {
        for ($i = 0; $i < 6; $i++) {
            $this->transport->queueCallback(fn(string $xml) => match (true) {
                str_contains($xml, '<domain:check') => $this->freeName(),
                str_contains($xml, '<contact:info') => $contactAnswer,
                default => CommandCatalog::OK_RESPONSE,
            });
        }
    }

    /** the catalog's check answer with example-two.it left out */
    private function freeName(): string {
        return preg_replace('#<domain:cd><domain:name avail="false">.*?</domain:cd>#s', '', CommandCatalog::DOMAIN_CHECK_RESPONSE);
    }

    private function params(): array {
        return ['domain' => 'example-one.it', 'registrant' => 'ABCD1234EFGH5678', 'authinfo' => 'SECRET1234567890'];
    }

    private function storedStatus(): array {
        return unserialize(R::getCell("SELECT status FROM contacts WHERE handle = 'ABCD1234EFGH5678'"));
    }

    public function testACreatedDomainRefreshesItsRegistrantsStoredStatus(): void {
        $this->registry($this->contactInfo);

        $result = DomainService::createOrTransfer($this->nic, $this->params(), 5);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(['ok', 'linked'], $this->storedStatus());
    }

    public function testARefreshThatFailsDoesNotFailTheCreate(): void {
        $this->registry(CommandCatalog::loginRefusal('2303', 'Object does not exist', '', 'gone'));
        ini_set('error_log', '/dev/null');

        $result = DomainService::createOrTransfer($this->nic, $this->params(), 5);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(['ok'], $this->storedStatus());
    }

    public function testAContactNotStoredLocallyIsNotFetched(): void {
        $this->registry($this->contactInfo);
        R::exec('DELETE FROM contacts');

        \Eppitnic\Service\ContactRefresh::run($this->nic, ['ABCD1234EFGH5678', null], 5);

        $this->assertSame([], $this->transport->requests);
    }
}
