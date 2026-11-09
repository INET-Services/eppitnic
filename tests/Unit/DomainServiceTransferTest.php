<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Persistence\Scope;
use Eppitnic\Service\DomainService;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * What POST /v1/domains and `domain create` send is decided by the caller's
 * input, the registry's <check> only guards it: no AuthInfo registers, an
 * AuthInfo requests a transfer, and with a registrant a transfer-trade. A
 * transfer is recorded as a pending row in `transfers`, never an owned domain.
 */
final class DomainServiceTransferTest extends EppTestCase
{
    /** the user is reseller 7's; the registrant below is reseller 2's */
    private Scope $scope;

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
                 registrant TEXT, authinfo TEXT, kind TEXT NOT NULL DEFAULT 'transfer', techc TEXT, dns TEXT,
                 status TEXT NOT NULL DEFAULT 'pending', time TEXT,
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT)");
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 2)");

        $this->scope = new Scope(5, 7, 'user');
    }

    /** a transfer: the name is taken at the registry (example-two.it) */
    private function params(array $override = []): array {
        return $override + [
            'domain'   => 'example-two.it',
            'tech'     => ['TECH1234TECH5678'],
            'ns'       => [['name' => 'ns1.example-two.it', 'ip' => ['192.0.2.1']], 'ns2.example.it'],
            'authinfo' => 'SECRET1234567890',
        ];
    }

    /** the registry's check answer for one name: example-one.it is free, example-two.it taken */
    private function checked(string $name): string {
        $other = $name === 'example-one.it' ? 'example-two.it' : 'example-one.it';
        return preg_replace('#<domain:cd><domain:name avail="(?:true|false)">' . $other . '</domain:name>.*?</domain:cd>#s', '', CommandCatalog::DOMAIN_CHECK_RESPONSE);
    }

    public function testAnAuthinfoWithoutARegistrantRequestsAPlainTransfer(): void {
        $this->transport->queue($this->checked('example-two.it'));
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer($this->nic, $this->params(), $this->scope);

        $this->assertTrue($result['ok']);
        $this->assertSame('transfer-requested', $result['action']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM domains'));

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame('example-two.it', $row['domain']);
        $this->assertNull($row['registrant']);
        $this->assertSame('transfer', $row['kind']);
        $this->assertSame(7, (int) $row['reseller_id'], 'the requester\'s reseller');
        $this->assertSame('pending', $row['status']);
        $this->assertSame([], unserialize($row['techc']), 'tech contacts are ignored');
        $this->assertSame($this->params()['ns'], unserialize($row['dns']));
        $this->assertSame('SECRET1234567890', $row['authinfo'], 'the code the request was sent with');

        $request = $this->transport->lastRequest();
        $this->assertStringContainsString('<transfer op="request">', $request);
        $this->assertStringNotContainsString('newRegistrant', $request);
    }

    public function testAnAuthinfoWithARegistrantRequestsATransferTrade(): void {
        $this->transport->queue($this->checked('example-two.it'));
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer(
            $this->nic, $this->params(['registrant' => 'REGI1234REGI5678']), $this->scope
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('trade-requested', $result['action']);

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame('REGI1234REGI5678', $row['registrant']);
        $this->assertSame('trade', $row['kind']);
        $this->assertSame(2, (int) $row['reseller_id'], 'the registrant\'s reseller');
        $this->assertSame('SECRET1234567890', $row['authinfo']);

        $request = $this->transport->lastRequest();
        $this->assertStringContainsString('<extdom:newRegistrant>REGI1234REGI5678</extdom:newRegistrant>', $request);
        $this->assertStringContainsString('<domain:pw>SECRET1234567890</domain:pw>', $request);
        $this->assertSame(1, preg_match('#<extdom:newAuthInfo>\s*<extdom:pw>([^<]{16})</extdom:pw>#', $request, $m), 'a new code is generated');
        $this->assertNotSame('SECRET1234567890', $m[1]);
    }

    public function testAnUnknownRegistrantIsRefusedBeforeTheRegistryHearsOfIt(): void {
        $this->transport->queue($this->checked('example-two.it'));

        $result = DomainService::createOrTransfer(
            $this->nic, $this->params(['registrant' => 'NOSUCH12NOSUCH34']), $this->scope
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
        $this->assertCount(1, $this->transport->requests, 'only the check was sent');
    }

    public function testNeitherAuthinfoNorRegistrantSendsNothing(): void {
        $result = DomainService::createOrTransfer($this->nic, $this->params(['authinfo' => '']), $this->scope);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('registrant is required', $result['error']);
        $this->assertSame([], $this->transport->requests);
    }

    public function testAnAuthinfoForANameThatIsAvailableIsRefused(): void {
        $this->transport->queue($this->checked('example-one.it'));

        $result = DomainService::createOrTransfer($this->nic, $this->params(['domain' => 'example-one.it', 'ns' => ['ns1.example.it']]), $this->scope);

        $this->assertFalse($result['ok']);
        $this->assertSame("'example-one.it' is not registered; leave the AuthInfo code empty to register it", $result['error']);
        $this->assertCount(1, $this->transport->requests, 'only the check was sent');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testNoAuthinfoForATakenNameIsRefused(): void {
        $this->transport->queue($this->checked('example-two.it'));

        $result = DomainService::createOrTransfer(
            $this->nic, $this->params(['authinfo' => '', 'registrant' => 'REGI1234REGI5678']), $this->scope
        );

        $this->assertFalse($result['ok']);
        $this->assertSame("'example-two.it' is already registered; give its AuthInfo code to transfer it", $result['error']);
        $this->assertCount(1, $this->transport->requests, 'only the check was sent');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testAnUnansweredCheckIsAnError(): void {
        $this->transport->queue(CommandCatalog::loginRefusal('2400', 'Command failed', '5000', 'try later'));

        $result = DomainService::createOrTransfer($this->nic, $this->params(), $this->scope);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $this->transport->requests);
    }

    public function testRegisteringOnlyReadsTheAuthinfoAsTheNewDomainsCode(): void {
        $this->transport->queue($this->checked('example-one.it'));
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer(
            $this->nic,
            $this->params(['domain' => 'example-one.it', 'ns' => ['ns1.example.it'], 'registrant' => 'REGI1234REGI5678']),
            $this->scope,
            false,
            true
        );

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('created', $result['action']);
        $request = $this->transport->lastRequest();
        $this->assertStringContainsString('<domain:create', $request);
        $this->assertStringContainsString('<domain:pw>SECRET1234567890</domain:pw>', $request);
        $this->assertStringNotContainsString('<transfer', $request);
    }

    public function testRegisteringOnlyRefusesATakenNameAndPointsToTheTransfer(): void {
        $this->transport->queue($this->checked('example-two.it'));

        $result = DomainService::createOrTransfer(
            $this->nic, $this->params(['registrant' => 'REGI1234REGI5678']), $this->scope, true, true
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            "'example-two.it' is already registered; use `domain transfer request` to transfer it",
            $result['error']
        );
        $this->assertCount(1, $this->transport->requests, 'only the check was sent');
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testRegisteringOnlyNeedsARegistrantEvenWithAnAuthinfo(): void {
        $result = DomainService::createOrTransfer($this->nic, $this->params(), $this->scope, true, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('registrant is required', $result['error']);
        $this->assertSame([], $this->transport->requests);
    }

    public function testADryRunRecordsNothing(): void {
        $this->transport->queue($this->checked('example-two.it'));
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer($this->nic, $this->params(), $this->scope, false);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testADryRunTrustsNoCheckAnswerSoItPreviewsTheTransfer(): void {
        // a dry run reads every name as available
        $this->transport->queue($this->checked('example-one.it'));
        $this->transport->queue(CommandCatalog::OK_RESPONSE);

        $result = DomainService::createOrTransfer($this->nic, $this->params(['domain' => 'example-one.it', 'ns' => ['ns1.example.it']]), $this->scope, false);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('transfer-requested', $result['action']);
        $this->assertStringContainsString('<transfer op="request">', $this->transport->lastRequest());
    }

    public function testARequestReplacesACancelledRowOfTheSameName(): void {
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, status) VALUES (9, 'example-two.it', 'OLD', 'cancelled')");

        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', 7, ['ns1.example.it']);

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame(['pending', 'REGI1234REGI5678', 2], [$row['status'], $row['registrant'], (int) $row['reseller_id']]);
        $this->assertSame([], unserialize($row['techc']));
        $this->assertSame(['ns1.example.it'], unserialize($row['dns']));
    }

    public function testAReRequestReplacesTheKindRegistrantAndOwner(): void {
        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', 7);
        $this->assertSame(['trade', 'REGI1234REGI5678', 2], $this->kindRegistrantReseller());

        R::exec("UPDATE transfers SET status = 'cancelled'");
        DomainService::recordTransferRequest('example-two.it', null, 7);

        $row = R::getRow('SELECT kind, registrant, reseller_id, status FROM transfers');
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame(['transfer', null, 7, 'pending'], [$row['kind'], $row['registrant'], (int) $row['reseller_id'], $row['status']]);

        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', 7);
        $this->assertSame(['trade', 'REGI1234REGI5678', 2], $this->kindRegistrantReseller());
    }

    /** @return array{0: string, 1: ?string, 2: int} */
    private function kindRegistrantReseller(): array {
        $row = R::getRow('SELECT kind, registrant, reseller_id FROM transfers');
        return [$row['kind'], $row['registrant'], (int) $row['reseller_id']];
    }

    public function testARequestStoresItsAuthinfoAndAReRequestReplacesIt(): void {
        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', 7, [], 'FIRSTCODE1234567');
        $this->assertSame('FIRSTCODE1234567', R::getCell('SELECT authinfo FROM transfers'));

        R::exec("UPDATE transfers SET status = 'cancelled'");
        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', 7, [], 'SECONDCODE123456');

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame('SECONDCODE123456', R::getCell('SELECT authinfo FROM transfers'));
    }

    public function testARequestWithoutAuthinfoStoresNull(): void {
        DomainService::recordTransferRequest('example-two.it', 'REGI1234REGI5678', 7);

        $this->assertNull(R::getCell('SELECT authinfo FROM transfers'));
    }
}
