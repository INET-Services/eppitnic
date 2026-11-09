<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DomainTransferCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `domain transfer request` is a plain transfer, or with a registrant a
 * transfer-trade to it; it stores the registrant it was given, and refuses
 * before contacting the registry when that is not one it may use.
 */
final class DomainTransferRequestTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['transfers', 'contacts', 'users', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER)');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT,
                 registrant TEXT, authinfo TEXT, kind TEXT NOT NULL DEFAULT \'transfer\', techc TEXT, dns TEXT,
                 status TEXT NOT NULL DEFAULT \'pending\', time TEXT,
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('INSERT INTO users (id, reseller_id) VALUES (5, 2)');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('MINE1234MINE5678', 2), ('THEIR234THEIR678', 3)");
    }

    private function request(array $args): int {
        $command = new DomainTransferCommand(['--yes', '--user=5', ...$args]);
        $command->useErrorStream(fopen('php://memory', 'w+'));

        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // login()
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // transfer
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // logout()
        $this->nic->setTransport($this->sent);
        $command->useClient($this->nic);

        ob_start();
        try {
            return $command->run();
        } finally {
            ob_end_clean();
        }
    }

    public function testTheRegistrantIsStoredWithItsResellersId(): void {
        $this->request(['--authinfo=SECRET1234567890', '--registrant=MINE1234MINE5678', 'request', 'example-one.it']);

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame('MINE1234MINE5678', $row['registrant']);
        $this->assertSame('trade', $row['kind']);
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertStringContainsString(
            '<extdom:newRegistrant>MINE1234MINE5678</extdom:newRegistrant>',
            implode('', $this->sent->requests)
        );
    }

    public function testWithoutARegistrantItIsAPlainTransferOfTheUsersReseller(): void {
        $this->assertSame(0, $this->request(['--authinfo=SECRET1234567890', 'request', 'example-one.it']));

        $row = R::getRow('SELECT * FROM transfers');
        $this->assertNull($row['registrant']);
        $this->assertSame('transfer', $row['kind']);
        $this->assertSame(2, (int) $row['reseller_id'], "--user's reseller");
        $this->assertSame('SECRET1234567890', $row['authinfo']);
        $this->assertStringContainsString('<transfer op="request">', implode('', $this->sent->requests));
        $this->assertStringNotContainsString('newRegistrant', implode('', $this->sent->requests));
    }

    public function testAFileRowCarriesNoRegistrantForAPlainTransfer(): void {
        $this->request(['request', 'example-one.it;SECRET1234567890']);

        $this->assertSame(['transfer', null], [R::getCell('SELECT kind FROM transfers'), R::getCell('SELECT registrant FROM transfers')]);
    }

    public function testTheAuthinfoIsStoredAndAReRequestReplacesIt(): void {
        $this->request(['--authinfo=SECRET1234567890', '--registrant=MINE1234MINE5678', 'request', 'example-one.it']);
        $this->assertSame('SECRET1234567890', R::getCell('SELECT authinfo FROM transfers'));

        $this->request(['--registrant=MINE1234MINE5678', 'request', 'example-one.it;OTHERCODE1234567']);

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame('OTHERCODE1234567', R::getCell('SELECT authinfo FROM transfers'));
    }

    public function testAFileRowCarriesItsOwnRegistrant(): void {
        $this->request(['--registrant=THEIR234THEIR678', 'request', 'example-one.it;SECRET1234567890;MINE1234MINE5678']);

        $this->assertSame('MINE1234MINE5678', R::getCell('SELECT registrant FROM transfers'));
    }

    public function testCancelKeepsTheRowAsCancelledAndANewRequestReplacesIt(): void {
        $this->request(['--authinfo=SECRET1234567890', '--registrant=MINE1234MINE5678', 'request', 'example-one.it']);
        $this->request(['--authinfo=SECRET1234567890', 'cancel', 'example-one.it']);

        $this->assertSame('cancelled', R::getCell('SELECT status FROM transfers'));

        $this->request(['--authinfo=SECRET1234567890', '--registrant=MINE1234MINE5678', 'request', 'example-one.it']);

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame('pending', R::getCell('SELECT status FROM transfers'));
    }

    /** @return array<string, array{0: string[], 1: string}> args => message */
    public static function refusals(): array {
        return [
            'not a stored contact' => [['--registrant=NOSUCH12NOSUCH34'], 'not a stored contact'],
            'another reseller'    => [['--registrant=THEIR234THEIR678'], 'not yours'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARegistrantItCannotUseSendsNothing(array $args, string $message): void {
        try {
            $this->request([...$args, '--authinfo=SECRET1234567890', 'request', 'example-one.it']);
            $this->fail('expected a UsageError');
        } catch (UsageError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame([], $this->sent->requests);
            $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        }
    }
}
