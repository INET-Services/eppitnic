<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\Scope;
use Eppitnic\Tests\Support\EppTestCase;
use RedBeanPHP\R;

/**
 * The domain list is a local read that carries each domain's status flags, so
 * a client can show them without asking the registry once per row.
 */
final class DomainListTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS resellers');
        R::exec('CREATE TABLE resellers (id INTEGER PRIMARY KEY, name TEXT)');
        R::exec("INSERT INTO resellers (id, name) VALUES (1, 'Registrar (self)')");
        R::exec('DROP TABLE IF EXISTS domains');
        R::exec('DROP TABLE IF EXISTS transfers');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT,
                 status TEXT, reseller_id INTEGER, ex_date TEXT, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, reseller_id INTEGER, status TEXT NOT NULL DEFAULT \'pending\')');
    }

    private function insert(string $domain, ?string $status, int $active = 1): void {
        R::exec('INSERT INTO domains (domain, registrant, status, reseller_id, active) VALUES (?, ?, ?, 1, ?)',
            [$domain, 'REG1', $status, $active]);
    }

    /**
     * @return array<string, string[]> domain => its status flags
     */
    private function statuses(bool $activeOnly = true): array {
        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1), null, $activeOnly);
        return array_column($rows, 'status', 'domain');
    }

    public function testCurrentCodeStoresItPlain(): void {
        $this->insert('a.it', serialize(['ok']));

        $this->assertSame(['ok'], $this->statuses()['a.it']);
    }

    /**
     * 6.x base64'd the column behind a marker, and one table holds both shapes.
     */
    public function testSixPointXWrappedItInAnEnvelope(): void {
        $this->insert('b.it', '__SERIALIZED:' . base64_encode(serialize(['ok', 'clientHold'])));

        $this->assertSame(['ok', 'clientHold'], $this->statuses()['b.it']);
    }

    public function testAnEmptyOrUnreadableValueIsNoFlagsRatherThanAnError(): void {
        $this->insert('c.it', null);
        $this->insert('d.it', '0');
        $this->insert('e.it', '__SERIALIZED:%%%not base64%%%');

        $this->assertSame(['c.it' => [], 'd.it' => [], 'e.it' => []], $this->statuses());
    }

    public function testTheInactiveStayOutOfTheDefaultList(): void {
        $this->insert('f.it', serialize(['ok']), 0);

        $this->assertArrayNotHasKey('f.it', $this->statuses());
        $this->assertArrayHasKey('f.it', $this->statuses(false));
    }

    /**
     * Not a domain EPP has confirmed exists locally yet, so it carries no
     * status of its own -- but the field is still there for a client that
     * expects every row to have one.
     */
    public function testAPendingTransferInHasNoStatusOfItsOwn(): void {
        R::exec('INSERT INTO transfers (domain, registrant, reseller_id) VALUES (?, ?, 1)', ['g.it', 'REG1']);

        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1), null, true);
        $transferIn = current(array_filter($rows, fn($row) => $row['domain'] === 'g.it (transfer-in)'));

        $this->assertSame([], $transferIn['status']);
    }

    public function testACancelledTransferInSaysSo(): void {
        R::exec("INSERT INTO transfers (domain, registrant, reseller_id, status) VALUES ('h.it', 'REG1', 1, 'cancelled')");

        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1), null, true);

        $this->assertSame(['h.it (transfer-in cancelled)'], array_column($rows, 'domain'));
    }

    public function testEachRowCarriesTheExpiryDate(): void {
        $this->insert('a.it', serialize([]));
        R::exec("UPDATE domains SET ex_date = '2027-03-01' WHERE domain = 'a.it'");
        R::exec("INSERT INTO transfers (domain, registrant, reseller_id) VALUES ('b.it', 'REG1', 1)");

        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1));

        $this->assertSame([null, '2027-03-01'], array_column($rows, 'ex_date'));
    }

    public function testEachRowNamesItsReseller(): void {
        $this->insert('a.it', serialize([]));
        R::exec("INSERT INTO transfers (domain, registrant, reseller_id) VALUES ('b.it', 'REG1', 1)");

        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1));

        $this->assertSame(['Registrar (self)', 'Registrar (self)'], array_column($rows, 'reseller_name'));
    }

    public function testAStoredDomainIsNotListedAgainAsATransferIn(): void {
        $this->insert('a.it', serialize([]));
        R::exec("INSERT INTO transfers (domain, registrant, reseller_id) VALUES ('a.it', 'REG1', 1)");
        R::exec("INSERT INTO transfers (domain, registrant, reseller_id) VALUES ('b.it', 'REG1', 1)");

        $rows = (new Domain($this->nic))->listDomains(Scope::operator(1));

        $this->assertSame(['b.it (transfer-in)', 'a.it'], array_column($rows, 'domain'));
    }
}
