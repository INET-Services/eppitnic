<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * POST /v1/domains and `domain create` on a name somebody else holds request
 * a transfer, recorded like `domain transfer request`: a pending row in
 * `transfers`, never an owned domain.
 */
final class DomainServiceTransferTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['transfers', 'contacts', 'domains'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT UNIQUE,
                 registrant TEXT NOT NULL, techc TEXT, dns TEXT, status TEXT NOT NULL DEFAULT 'pending', time TEXT,
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT)");
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 2)");
    }

    private function params(): array {
        return [
            'domain'     => 'example-two.it',
            'registrant' => 'REGI1234REGI5678',
            'tech'       => ['TECH1234TECH5678'],
            'ns'         => [['name' => 'ns1.example-two.it', 'ip' => ['192.0.2.1']], 'ns2.example.it'],
            'authinfo'   => 'SECRET1234567890',
        ];
    }

    public function testATakenNameIsRecordedAsAPendingTransfer(): void {
        $this->transport->queue(CommandCatalog::DOMAIN_CHECK_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer($this->nic, $this->params(), 5);

        $this->assertTrue($result['ok']);
        $this->assertSame('transfer-requested', $result['action']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM domains'));

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame('example-two.it', $row['domain']);
        $this->assertSame('REGI1234REGI5678', $row['registrant']);
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame([], unserialize($row['techc']), 'tech contacts are ignored');
        $this->assertSame($this->params()['ns'], unserialize($row['dns']));
    }

    public function testAnUnknownRegistrantIsRefusedBeforeTheRegistryHearsOfIt(): void {
        $this->transport->queue(CommandCatalog::DOMAIN_CHECK_RESPONSE);

        $result = DomainService::createOrTransfer($this->nic, ['registrant' => 'NOSUCH12NOSUCH34'] + $this->params(), 5);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
        $this->assertCount(1, $this->transport->requests, 'only the check was sent');
    }

    public function testADryRunRecordsNothing(): void {
        $this->transport->queue(CommandCatalog::DOMAIN_CHECK_RESPONSE);
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer($this->nic, $this->params(), 5, false);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testARequestReplacesACancelledRowOfTheSameName(): void {
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, status) VALUES (9, 'example-two.it', 'OLD', 'cancelled')");

        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', ['ns1.example.it']);

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame(['pending', 'REGI1234REGI5678', 2], [$row['status'], $row['registrant'], (int) $row['reseller_id']]);
        $this->assertSame([], unserialize($row['techc']));
        $this->assertSame(['ns1.example.it'], unserialize($row['dns']));
    }
}
