<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Contact;
use Eppitnic\Persistence\Scope;
use Eppitnic\Tests\Support\EppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * The contact list is a local read that carries each contact's status flags,
 * so a client can show whether one is linked without asking the registry once
 * per row.
 */
final class ContactListTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS resellers');
        R::exec('CREATE TABLE resellers (id INTEGER PRIMARY KEY, name TEXT)');
        R::exec("INSERT INTO resellers (id, name) VALUES (1, 'Registrar (self)')");
        foreach (['domains', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, status TEXT, active INTEGER DEFAULT 1)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, status TEXT DEFAULT 'pending')");
        R::exec('DROP TABLE IF EXISTS contacts');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, org TEXT, name TEXT,
                 entitytype INTEGER, status TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1)');
    }

    private function insert(string $handle, ?string $status, int $active = 1): void {
        R::exec('INSERT INTO contacts (handle, org, name, entitytype, status, reseller_id, active) VALUES (?, ?, ?, 2, ?, 1, ?)',
            [$handle, $handle, $handle, $status, $active]);
    }

    /**
     * @return array<string, string[]> handle => its status flags
     */
    private function statuses(bool $activeOnly = true): array {
        $rows = (new Contact($this->nic))->listContacts(Scope::operator(1), $activeOnly);
        return array_column($rows, 'status', 'handle');
    }

    public function testCurrentCodeStoresItPlain(): void {
        $this->insert('A', serialize(['ok', 'linked']));

        $this->assertSame(['ok', 'linked'], $this->statuses()['A']);
    }

    /**
     * 6.x base64'd the column behind a marker, and one table holds both shapes.
     */
    public function testSixPointXWrappedItInAnEnvelope(): void {
        $this->insert('B', '__SERIALIZED:' . base64_encode(serialize(['ok', 'linked'])));

        $this->assertSame(['ok', 'linked'], $this->statuses()['B']);
    }

    public function testAnEmptyOrUnreadableValueIsNoFlagsRatherThanAnError(): void {
        $this->insert('C', null);
        $this->insert('D', '0');
        $this->insert('E', '__SERIALIZED:%%%not base64%%%');

        $this->assertSame(['C' => [], 'D' => [], 'E' => []], $this->statuses());
    }

    public function testTheInactiveStayOutOfTheDefaultList(): void {
        $this->insert('G', serialize(['ok']), 0);

        $this->assertArrayNotHasKey('G', $this->statuses());
        $this->assertArrayHasKey('G', $this->statuses(false));
    }

    public function testEachRowNamesItsReseller(): void {
        $this->insert('A', serialize([]));

        $rows = (new Contact($this->nic))->listContacts(Scope::operator(1));

        $this->assertSame('Registrar (self)', $rows[0]['reseller_name']);
    }

    private function linkedOf(string $handle): bool {
        $rows = (new Contact($this->nic))->listContacts(Scope::operator(1));
        return array_column($rows, 'linked', 'handle')[$handle];
    }

    public function testTheStoredStatusAloneMakesItLinked(): void {
        $this->insert('A', serialize(['ok', 'linked']));
        $this->insert('B', serialize(['ok']));

        $this->assertTrue($this->linkedOf('A'));
        $this->assertFalse($this->linkedOf('B'));
    }

    /**
     * @return array<string, array{0: string}> the domain's serialized status
     */
    public static function heldDomains(): array {
        return [
            'registered'    => [serialize(['ok'])],
            'inactive'      => [serialize(['inactive', 'dnsHold'])],
            'pendingDelete' => [serialize(['pendingDelete'])],
        ];
    }

    #[DataProvider('heldDomains')]
    public function testAHeldDomainLinksItWhateverTheStoredStatusSays(string $domainStatus): void {
        $this->insert('A', serialize(['ok']));
        R::exec('INSERT INTO domains (domain, registrant, active) VALUES (?, ?, 1)', ['a.it', 'A']);
        R::exec('UPDATE domains SET status = ?', [$domainStatus]);

        $this->assertTrue($this->linkedOf('A'));
    }

    public function testAPendingTransferLinksIt(): void {
        $this->insert('A', serialize(['ok']));
        R::exec("INSERT INTO transfers (domain, registrant, status) VALUES ('a.it', 'A', 'pending')");

        $this->assertTrue($this->linkedOf('A'));
    }

    public function testADeletedDomainOrACancelledTransferDoesNot(): void {
        $this->insert('A', serialize(['ok']));
        $this->insert('B', serialize(['ok']));
        R::exec('INSERT INTO domains (domain, registrant, active) VALUES (?, ?, 0)', ['a.it', 'A']);
        R::exec("INSERT INTO transfers (domain, registrant, status) VALUES ('b.it', 'B', 'cancelled')");

        $this->assertFalse($this->linkedOf('A'));
        $this->assertFalse($this->linkedOf('B'));
    }

    public function testASingleContactReportsTheSameBooleanFromTheStoredRow(): void {
        $this->insert('A', serialize(['ok']));
        R::exec('INSERT INTO domains (domain, registrant, active) VALUES (?, ?, 1)', ['a.it', 'A']);

        $contact = new Contact($this->nic);
        $contact->set('handle', 'A');

        $this->assertTrue($contact->isLinked());
        $this->assertSame(['a.it'], Contact::referencedBy('A'));
        $this->assertSame([], Contact::referencedBy('NOBODY'));
    }
}
