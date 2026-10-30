<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\ContactCleanupCommand;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * `contact cleanup`: admin/tech cleared locally, then the contacts no active
 * domain or pending transfer needs deleted at the registry.
 */
final class ContactCleanupCommandTest extends EppTestCase
{
    private $errors;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history', 'contacts', 'domains', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, name TEXT,
                 reseller_id INTEGER DEFAULT 1, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, admin TEXT, tech TEXT,
                 reseller_id INTEGER DEFAULT 1, active INTEGER DEFAULT 1)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, registrant TEXT, status TEXT DEFAULT 'pending')");
        TestAccounts::ensure(1, 'admin', 1, 'root');
        
        $this->errors = fopen('php://memory', 'w+');
    }

    private function contact(string $handle, int $reseller = 1, int $active = 1): void {
        R::exec('INSERT INTO contacts (handle, name, reseller_id, active) VALUES (?, ?, ?, ?)',
            [$handle, "Name {$handle}", $reseller, $active]);
    }

    private function domain(string $name, string $registrant, int $reseller = 1, int $active = 1): void {
        R::exec("INSERT INTO domains (domain, registrant, admin, tech, reseller_id, active)
                 VALUES (?, ?, 'ADM1', 'a:1:{i:0;s:4:\"TEC1\";}', ?, ?)", [$name, $registrant, $reseller, $active]);
    }

    private function isActive(string $handle): bool {
        return (int) R::getCell('SELECT active FROM contacts WHERE handle = ?', [$handle]) === 1;
    }

    /** @param string[] $answers one registry response per contact, in handle order */
    private function cleanup(array $argv, array $answers = []): array {
        $this->transport->queue(CommandCatalog::GREETING_RESPONSE);
        $this->transport->queue(CommandCatalog::LOGIN_RESPONSE);
        foreach ($answers as $answer) {
            $this->transport->queue($answer);
        }
        $this->transport->queue(CommandCatalog::LOGIN_RESPONSE);

        $command = new ContactCleanupCommand([...$argv, '--yes']);
        $command->useClient($this->nic);
        $command->useErrorStream($this->errors);
        ob_start();
        $code = $command->run();
        $command->flush();
        return [$code, (string) ob_get_clean()];
    }

    private function deletes(): array {
        return array_values(array_filter($this->transport->requests, fn($r) => str_contains($r, 'contact:delete')));
    }

    private function refusal(string $code, string $reasonCode = ''): string {
        return CommandCatalog::loginRefusal($code, 'refused', $reasonCode, 'reason');
    }

    public function testPhaseOneClearsAdminAndTechOnEveryDomain(): void {
        $this->contact('R1');
        $this->domain('a.it', 'R1');
        $this->domain('b.it', 'R1', 2, 0);

        [$code] = $this->cleanup(['--user=99'], []);

        $this->assertSame(0, $code);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(1) FROM domains WHERE admin IS NOT NULL OR tech IS NOT NULL'));
    }

    public function testOneSystemHistoryRowSummarisesTheRun(): void {
        $this->contact('A');
        $this->contact('B');
        $this->domain('a.it', 'USED');

        $this->cleanup([], [CommandCatalog::OK_RESPONSE, $this->refusal('2400')]);

        $rows = R::getAll('SELECT * FROM history');
        $this->assertCount(1, $rows);
        $this->assertSame('domains', $rows[0]['object']);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame(0, (int) $rows[0]['object_id']);
        $this->assertNull($rows[0]['user_id']);
        $this->assertSame([
            'cleanup' => true, 'by' => 'executed from CLI by a sysop', 'domains_cleared' => 1,
            'contacts_deleted' => 1, 'already_gone' => 0, 'still_linked' => 0, 'failed' => 1,
        ], json_decode($rows[0]['data'], true));
    }

    public function testNoHistoryRowWhenNothingChanged(): void {
        $this->cleanup([], []);

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(1) FROM history'));
    }

    public function testCandidateSelection(): void {
        $this->contact('A_ACTIVE_DOMAIN');
        $this->contact('B_INACTIVE_DOMAIN');
        $this->contact('C_PENDING_TRANSFER');
        $this->contact('D_CANCELLED_TRANSFER');
        $this->contact('E_INACTIVE_CONTACT', 1, 0);
        $this->contact('F_UNUSED');
        $this->domain('a.it', 'A_ACTIVE_DOMAIN');
        $this->domain('b.it', 'B_INACTIVE_DOMAIN', 1, 0);
        R::exec("INSERT INTO transfers (registrant, status) VALUES ('C_PENDING_TRANSFER', 'pending')");
        R::exec("INSERT INTO transfers (registrant, status) VALUES ('D_CANCELLED_TRANSFER', 'cancelled')");

        $answers = array_fill(0, 3, CommandCatalog::OK_RESPONSE);
        [$code, $out] = $this->cleanup(['--jsonl'], $answers);

        $this->assertSame(0, $code);
        $this->assertCount(3, $this->deletes());
        $handles = array_column(array_filter(array_map(fn($l) => json_decode($l, true), explode("\n", trim($out))), fn($r) => isset($r['outcome'])), 'handle');
        $this->assertSame(['B_INACTIVE_DOMAIN', 'D_CANCELLED_TRANSFER', 'F_UNUSED'], $handles);
        $this->assertTrue($this->isActive('A_ACTIVE_DOMAIN'));
        $this->assertTrue($this->isActive('C_PENDING_TRANSFER'));
        $this->assertFalse($this->isActive('B_INACTIVE_DOMAIN'));
        $this->assertFalse($this->isActive('F_UNUSED'));
    }

    public static function outcomes(): array {
        return [
            'deleted'      => [CommandCatalog::OK_RESPONSE, false, 0, 'deleted'],
            'already gone' => [null, false, 0, 'already gone at the registry', '2303'],
            'still linked' => [null, true, 0, 'still linked at the registry, kept', '2305', '8005'],
            'other error'  => [null, true, CONTACT_DELETE_FAILED, null, '2400'],
        ];
    }

    #[DataProvider('outcomes')]
    public function testRegistryOutcome(?string $response, bool $stillActive, int $exit, ?string $text, string $code = '', string $reason = ''): void {
        $this->contact('IDLE');

        [$status, $out] = $this->cleanup([], [$response ?? $this->refusal($code, $reason)]);

        $this->assertSame($exit, $status);
        $this->assertSame($stillActive, $this->isActive('IDLE'));
        if ($text !== null) {
            $this->assertStringContainsString("IDLE {$text}", $out);
        } else {
            rewind($this->errors);
            $this->assertStringContainsString("IDLE:", (string) stream_get_contents($this->errors));
        }
    }

    public function testASingleSessionServesTheWholeRunAndAFailureDoesNotStopIt(): void {
        $this->contact('A');
        $this->contact('B');
        $this->contact('C');

        [$code, $out] = $this->cleanup([], [
            CommandCatalog::OK_RESPONSE,
            $this->refusal('2400'),
            CommandCatalog::OK_RESPONSE,
        ]);

        $this->assertSame(CONTACT_DELETE_FAILED, $code);
        $this->assertCount(3, $this->deletes());
        $this->assertCount(1, array_filter($this->transport->requests, fn($r) => str_contains($r, '<login>')));
        $this->assertStringContainsString('contacts deleted 2, already gone 0, still linked 0, failed 1', $out);
        $this->assertTrue($this->isActive('B'));
    }

    public function testDryRunChangesAndSendsNothing(): void {
        $this->contact('IDLE');
        $this->contact('USED');
        $this->domain('a.it', 'USED');

        [$code, $out] = $this->cleanup(['--dry-run']);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->transport->requests);
        $this->assertSame('ADM1', R::getCell('SELECT admin FROM domains'));
        $this->assertTrue($this->isActive('IDLE'));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(1) FROM history'));
        $this->assertStringContainsString('1 domain(s) would lose', $out);
        $this->assertStringContainsString('IDLE Name IDLE (Registrar (self)) would be deleted', $out);
    }
}
