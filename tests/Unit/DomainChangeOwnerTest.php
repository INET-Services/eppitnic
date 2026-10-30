<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

final class DomainChangeOwnerTest extends EppTestCase
{
    private string $contactInfo = CommandCatalog::CONTACT_INFO_RESPONSE;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'history', 'resellers', 'tasks', 'settings'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 user_id INTEGER, registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, status TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec('CREATE TABLE resellers (id INTEGER PRIMARY KEY, techc TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec("INSERT INTO resellers (id, techc) VALUES (2, NULL)");
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('example-one.it', 1, 'REGI1234REGI5678')");

        for ($i = 0; $i < 20; $i++) {
            $this->transport->queueCallback(fn(string $xml) => match (true) {
                str_contains($xml, '<domain:info') => CommandCatalog::DOMAIN_INFO_RESPONSE,
                str_contains($xml, '<contact:check') => self::checkResponse($xml),
                str_contains($xml, '<contact:info') => $this->contactInfo,
                default => CommandCatalog::OK_RESPONSE,
            });
        }
    }

    /** every handle asked about is free */
    private static function checkResponse(string $xml): string {
        preg_match('#<contact:id>([^<]+)</contact:id>#', $xml, $m);
        return preg_replace(
            '#<contact:cd><contact:id avail="false">.*?</contact:cd>#s',
            '',
            str_replace('ABCD1234EFGH5678', $m[1], CommandCatalog::CONTACT_CHECK_RESPONSE)
        );
    }

    public function testChangeOwnerCompletesAndMovesTheLocalRow(): void {
        $result = DomainService::changeOwner($this->nic, 'example-one.it', 2, 1);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $row = R::getRow("SELECT reseller_id, registrant FROM domains WHERE domain = 'example-one.it'");
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertNotSame('REGI1234REGI5678', $row['registrant']);
        $this->assertSame($result['domain']->get('registrant'), $row['registrant']);

        $sent = implode("\n", $this->transport->requests);
        $this->assertStringNotContainsString('type="admin"', $sent);
        $this->assertStringNotContainsString('type="tech"', $sent);
        $this->assertStringNotContainsString('contact:fax', $sent);
        $this->assertSame(1, substr_count($sent, '<domain:update'), 'only the registrant change is sent');
    }

    public function testTheDuplicateCarriesEqualNameAndOrg(): void {
        DomainService::changeOwner($this->nic, 'example-one.it', 2, 1);

        $creates = array_values(array_filter($this->transport->requests, fn($r) => str_contains($r, '<contact:create')));
        $this->assertCount(1, $creates);
        $this->assertStringContainsString('<contact:name>Rossi &amp; Figli S.r.l.</contact:name>', $creates[0]);
        $this->assertStringContainsString('<contact:org>Rossi &amp; Figli S.r.l.</contact:org>', $creates[0]);
    }

    public function testANaturalPersonDuplicatesWithOrgAsNameAndOrg(): void {
        $this->contactInfo = str_replace(
            ['<contact:org>Rossi &amp; Figli S.r.l.</contact:org>', '<extcon:entityType>2'],
            ['<contact:org>Rossi Srl</contact:org>', '<extcon:entityType>1'],
            CommandCatalog::CONTACT_INFO_RESPONSE
        );

        DomainService::changeOwner($this->nic, 'example-one.it', 2, 1);

        $create = $this->createRequest();
        $this->assertStringContainsString('<contact:name>Rossi Srl</contact:name>', $create);
        $this->assertStringContainsString('<contact:org>Rossi Srl</contact:org>', $create);
    }

    public function testAnEmptyOrgDuplicatesWithTheNameAsNameAndOrg(): void {
        $this->contactInfo = str_replace(
            '<contact:org>Rossi &amp; Figli S.r.l.</contact:org>',
            '',
            CommandCatalog::CONTACT_INFO_RESPONSE
        );

        DomainService::changeOwner($this->nic, 'example-one.it', 2, 1);

        $create = $this->createRequest();
        $this->assertStringContainsString('<contact:name>Mario Rossi</contact:name>', $create);
        $this->assertStringContainsString('<contact:org>Mario Rossi</contact:org>', $create);
    }

    private function createRequest(): string {
        $creates = array_values(array_filter($this->transport->requests, fn($r) => str_contains($r, '<contact:create')));
        $this->assertCount(1, $creates);
        return $creates[0];
    }
}
