<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * Contact::storeDB() re-stores what the registry says (domain sync, imports,
 * refreshes): only a real change is written and recorded in the history.
 */
final class ContactStoreHistoryTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['contacts', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, name TEXT, org TEXT, street TEXT, street2 TEXT, street3 TEXT, city TEXT, province TEXT,
                 postalcode TEXT, countrycode TEXT, voice TEXT, fax TEXT, email TEXT, authinfo TEXT,
                 consentforpublishing INTEGER, nationalitycode TEXT, entitytype INTEGER, regcode TEXT, schoolcode TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
    }

    private function fetched(string $answer = CommandCatalog::CONTACT_INFO_RESPONSE): Contact {
        $this->transport->queue($answer);
        $contact = new Contact($this->nic);
        $this->assertTrue($contact->fetch('ABCD1234EFGH5678'));
        return $contact;
    }

    /** @return array<int, array{action: string, data: array}> */
    private function history(): array {
        return array_map(
            fn($row) => ['action' => $row['action'], 'data' => json_decode($row['data'], true)],
            R::getAll("SELECT action, data FROM history WHERE object = 'contacts' ORDER BY id")
        );
    }

    public function testANewContactIsACreate(): void {
        $this->assertTrue($this->fetched()->storeDB(2, 1));

        $this->assertSame([['action' => 'create', 'data' => ['handle' => 'ABCD1234EFGH5678']]], $this->history());
    }

    public function testStoringTheSameContactAgainWritesNothing(): void {
        $this->fetched()->storeDB(2, 1);
        R::exec("UPDATE contacts SET active = 0");

        $this->assertTrue($this->fetched()->storeDB(2, 1));

        $this->assertCount(1, $this->history(), 'only the create');
        $this->assertSame(0, (int) R::getCell('SELECT active FROM contacts'), 'the row was not rewritten');
    }

    public function testARefreshKeepsTheStoredAuthinfo(): void {
        $this->fetched()->storeDB(2, 1);
        R::exec("UPDATE contacts SET authinfo = 'Kept-Auth-1234'");

        $this->fetched()->storeDB(2, 1);

        $this->assertSame('Kept-Auth-1234', R::getCell('SELECT authinfo FROM contacts'), 'contact:info has none to replace it');
    }

    public function testARealChangeIsAnUpdateWithJustTheChangedColumns(): void {
        $this->fetched()->storeDB(2, 1);
        $changed = str_replace('<contact:city>', '<contact:city>Neu-', CommandCatalog::CONTACT_INFO_RESPONSE);

        $this->assertTrue($this->fetched($changed)->storeDB(3, 1));

        $update = $this->history()[1];
        $this->assertSame('update', $update['action']);
        $this->assertSame(['handle', 'city'], array_keys($update['data']));
        $this->assertStringStartsWith('Neu-', R::getCell('SELECT city FROM contacts'));
        $this->assertSame(2, (int) R::getCell('SELECT reseller_id FROM contacts'), 'an existing row keeps its reseller');
    }
}
