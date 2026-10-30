<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Epp\Domain;
use Eppitnic\Support\Validate;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * Registry data is written to the database without going through request
 * validation, so the write itself has to refuse a value its column cannot hold.
 */
final class StoreLengthLimitTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'history', 'settings', 'tasks'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        $columns = implode(', ', array_map(fn($f) => "{$f} TEXT", Contact::FIELDS));
        R::exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER, status TEXT, handle TEXT, {$columns})");
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, object TEXT,
                 action TEXT, active INTEGER DEFAULT 1, exit_code INTEGER, exit_message TEXT)');
    }

    private function fetchedContact(string $email): Contact {
        $this->transport->queue(str_replace(
            'mario.rossi@example.it',
            $email,
            CommandCatalog::CONTACT_INFO_RESPONSE
        ));
        $contact = new Contact($this->nic);
        $this->assertTrue($contact->fetch('ABCD1234EFGH5678'));

        return $contact;
    }

    private function email(int $length): string {
        return str_repeat('a', $length - 11) . '@example.it';
    }

    public function testAnOversizedEmailIsRefusedAndNothingIsWritten(): void {
        $contact = $this->fetchedContact($this->email(257));

        $this->assertFalse($contact->storeDB(1, 1));

        $this->assertSame(
            'contact ABCD1234EFGH5678: email is 257 characters, the database holds at most 256',
            $contact->getError()
        );
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM contacts'));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testAnEightyCharacterEmailIsStored(): void {
        $email = $this->email(80);

        $this->assertTrue($this->fetchedContact($email)->storeDB(1, 1));

        $this->assertSame($email, R::getCell("SELECT email FROM contacts WHERE handle = 'ABCD1234EFGH5678'"));
    }

    public function testAnOversizedValueIsRefusedOnUpdateToo(): void {
        $this->assertTrue($this->fetchedContact('mario.rossi@example.it')->storeDB(1, 1));

        $contact = $this->fetchedContact($this->email(300));
        $this->assertFalse($contact->storeDB(1, 1));

        $this->assertSame(
            'mario.rossi@example.it',
            R::getCell("SELECT email FROM contacts WHERE handle = 'ABCD1234EFGH5678'")
        );
    }

    public function testAnOversizedDomainFieldIsRefused(): void {
        $domain = new Domain($this->nic);
        $domain->set('domain', 'example.it');
        $domain->set('registrant', str_repeat('R', 33));

        $this->assertFalse($domain->storeDB(1, false));

        $this->assertSame(
            'domain example.it: registrant is 33 characters, the database holds at most 32',
            $domain->getError()
        );
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM domains'));
    }

    /** @return array<string, array{string, int}> */
    public static function contactFieldLimits(): array {
        $cases = [];
        foreach (Validate::CONTACT_FIELD_MAX_LENGTHS as $field => $max) {
            $cases[$field] = [$field, $max];
        }
        return $cases;
    }

    #[DataProvider('contactFieldLimits')]
    public function testEveryContactFieldIsCheckedAgainstItsLimit(string $field, int $max): void {
        $contact = new Contact($this->nic);
        $contact->set('handle', 'ABC123');
        $contact->set($field, str_repeat('x', $max + 1));

        $this->assertFalse($contact->storeDB(1, 1));
        $this->assertStringContainsString("{$field} is " . ($max + 1) . " characters", $contact->getError());
    }
}
