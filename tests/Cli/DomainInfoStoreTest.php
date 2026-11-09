<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\DomainInfoCommand;
use Eppitnic\Config;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use RedBeanPHP\R;

/**
 * `domain info --store` writes the domain locally; domains.registrant is a
 * foreign key, so a registrant not stored yet is fetched and stored first,
 * for --user's reseller.
 */
final class DomainInfoStoreTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();

        Config::loadForTesting(self::SETTINGS + [
            'pdns' => ['enabled' => false, 'apis' => [], 'nameservers' => []],
        ]);
        $this->nic = new \Eppitnic\Epp\Client();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'history', 'users', 'tasks', 'settings'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, name TEXT, org TEXT, street TEXT, street2 TEXT, street3 TEXT, city TEXT, province TEXT,
                 postalcode TEXT, countrycode TEXT, voice TEXT, fax TEXT, email TEXT, authinfo TEXT,
                 consentforpublishing INTEGER, nationalitycode TEXT, entitytype INTEGER, regcode TEXT, schoolcode TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, object TEXT, action TEXT, active INTEGER)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('INSERT INTO users (id, reseller_id) VALUES (5, 3)');
    }

    /**
     * @param string[] $answers what the registry answers after login
     * @return array{0: int, 1: string} exit code, warnings
     */
    private function store(array $answers): array {
        $command = new DomainInfoCommand(['--store', '--user=5', 'example-one.it']);
        $errors = fopen('php://memory', 'w+');
        $command->useErrorStream($errors);

        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // login
        foreach ($answers as $answer) {
            $this->sent->queue($answer);
        }
        $this->sent->queue(CommandCatalog::OK_RESPONSE); // logout
        $this->nic->setTransport($this->sent);
        $command->useClient($this->nic);

        ob_start();
        try {
            $status = $command->run();
        } finally {
            ob_end_clean();
        }
        rewind($errors);
        return [$status, (string) stream_get_contents($errors)];
    }

    private function storedDomains(): int {
        return (int) R::getCell('SELECT COUNT(*) FROM domains');
    }

    public function testAnUnknownRegistrantIsStoredForTheUsersResellerWithTheDomain(): void {
        [$status] = $this->store([CommandCatalog::DOMAIN_INFO_RESPONSE, CommandCatalog::CONTACT_INFO_RESPONSE]);

        $this->assertSame(0, $status);
        $contact = R::getRow("SELECT reseller_id, name FROM contacts WHERE handle = 'REGI1234REGI5678'");
        $this->assertSame([3, 'Mario Rossi'], [(int) $contact['reseller_id'], $contact['name']]);
        $this->assertSame('REGI1234REGI5678', R::getCell("SELECT registrant FROM domains WHERE domain = 'example-one.it'"));
        $this->assertSame(5, (int) R::getCell("SELECT user_id FROM history WHERE object = 'contacts' AND action = 'create'"));
        $this->assertStringContainsString('<contact:id>REGI1234REGI5678</contact:id>', implode('', $this->sent->requests));
    }

    public function testARegistrantThatCannotBeFetchedWarnsAndStoresNothing(): void {
        $refused = CommandCatalog::loginRefusal('2303', 'Object does not exist', '', 'gone');

        [$status, $errors] = $this->store([CommandCatalog::DOMAIN_INFO_RESPONSE, $refused]);

        $this->assertSame(DOMAIN_FETCH_FAILED, $status);
        $this->assertStringContainsString("example-one.it: not stored, registrant 'REGI1234REGI5678'", $errors);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM contacts'));
        $this->assertSame(0, $this->storedDomains());
    }

    public function testAKnownRegistrantIsNotFetchedAgainAndStaysAsItIs(): void {
        R::exec("INSERT INTO contacts (handle, reseller_id, name) VALUES ('REGI1234REGI5678', 2, 'Stored Name')");

        [$status] = $this->store([CommandCatalog::DOMAIN_INFO_RESPONSE]);

        $this->assertSame(0, $status);
        $this->assertStringNotContainsString('<contact:info', implode('', $this->sent->requests));
        $contact = R::getRow("SELECT reseller_id, name FROM contacts WHERE handle = 'REGI1234REGI5678'");
        $this->assertSame([2, 'Stored Name'], [(int) $contact['reseller_id'], $contact['name']]);
        $this->assertSame(1, $this->storedDomains());
    }
}
