<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * Domain names compare byte for byte: unicode_ci made 'città.it' and 'citta.it'
 * one name to the unique keys and the lookups. The 7.1 upgrade moves the
 * columns over and a CHECK keeps capitals out. Needs a local MariaDB, like the
 * other tests that touch one.
 */
final class DomainCollationUpgradeTest extends TestCase
{
    private const DB_NAME = 'eppitnic_fixtest_e';
    private const UPGRADE = __DIR__ . '/../../../config/mariadb-schema-upgrade-070000-to-070100.sql';
    private const COLUMNS = ['domains', 'transfers', 'tasks', 'messages'];
    /** tasks.domain follows domains.domain by its foreign key: no CHECK fits there */
    private const CHECKED = ['domains', 'transfers', 'messages'];

    private static ?string $skipReason = null;

    protected function setUp(): void {
        parent::setUp();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        try {
            $root = new PDO('mysql:host=localhost', get_current_user(), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            self::$skipReason = 'no local MariaDB reachable: ' . $e->getMessage();
            $this->markTestSkipped(self::$skipReason);
        }

        $root->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
        $root->exec('CREATE DATABASE `' . self::DB_NAME . '`');

        if ( ! R::hasDatabase('fixtest_e')) {
            R::addDatabase('fixtest_e', 'mysql:host=localhost;dbname=' . self::DB_NAME . ';charset=utf8', get_current_user(), '');
        }
        R::selectDatabase('fixtest_e', force: true);
        Config::runSqlFile(dirname(__DIR__, 3) . '/config/mariadb-schema.sql');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 1)");
    }

    protected function tearDown(): void {
        if (self::$skipReason === null && R::hasDatabase('default')) {
            R::selectDatabase('default');
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void {
        if (self::$skipReason !== null) {
            return;
        }
        try {
            $root = new PDO('mysql:host=localhost', get_current_user(), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $root->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
        } catch (\Throwable) {
            // best effort
        }
    }

    private function taskForeignKey(): ?string {
        return R::getCell("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'domain'
              AND REFERENCED_TABLE_NAME = 'domains'");
    }

    /** back to the shape the upgrade starts from */
    private function toUnicodeCi(): void {
        foreach (self::CHECKED as $table) {
            R::exec("ALTER TABLE {$table} DROP CONSTRAINT {$table}_domain_lower");
        }
        R::exec('ALTER TABLE tasks DROP FOREIGN KEY `' . $this->taskForeignKey() . '`');
        $ci = 'varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        R::exec("ALTER TABLE domains MODIFY domain {$ci} NOT NULL");
        R::exec("ALTER TABLE transfers MODIFY domain {$ci} NOT NULL");
        R::exec("ALTER TABLE tasks MODIFY domain {$ci} NOT NULL");
        R::exec("ALTER TABLE messages MODIFY domain {$ci} NULL");
        R::exec('ALTER TABLE tasks ADD CONSTRAINT FOREIGN KEY (domain) REFERENCES domains(domain) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    private function collation(string $table): string {
        return (string) R::getCell("SELECT COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'domain'", [$table]);
    }

    private function addDomain(string $name): void {
        R::exec("INSERT INTO domains (domain, registrant) VALUES (?, 'REGI1234REGI5678')", [$name]);
    }

    /** @return string[] */
    private function lowerCaseChecks(): array {
        return R::getCol("SELECT CONCAT(TABLE_NAME, '.', CONSTRAINT_NAME) FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE '%_domain_lower' ORDER BY 1");
    }

    /** the table's CHECK, or for tasks the foreign key onto the checked domains */
    private function assertRefusedByCheck(string $table, string $sql, array $bind): void {
        try {
            R::exec($sql, $bind);
        } catch (\Throwable $e) {
            $constraint = $table === 'tasks' ? 'foreign key constraint fails' : "{$table}_domain_lower";
            $this->assertStringContainsString($constraint, $e->getMessage());
            return;
        }
        $this->fail("{$table} took " . json_encode($bind));
    }

    /** a file run by a client that does not speak UTF-8, as mysql may */
    private function runOverLatin1(string $path): void {
        R::exec('SET NAMES latin1');
        try {
            Config::runSqlFile($path);
        } finally {
            R::exec('SET NAMES utf8');
        }
    }

    private function seedLowerCaseRows(): void {
        $this->addDomain('example.it');
        R::exec("INSERT INTO transfers (reseller_id, domain) VALUES (1, 'example.it')");
        R::exec("INSERT INTO tasks (domain, date) VALUES ('example.it', CURRENT_DATE)");
        R::exec("INSERT INTO messages (type, domain, data) VALUES ('a', 'example.it', '')");
    }

    private function assertCapitalsRefused(): void {
        $this->seedLowerCaseRows();
        $inserts = [
            'domains'   => "INSERT INTO domains (domain, registrant) VALUES (?, 'REGI1234REGI5678')",
            'transfers' => 'INSERT INTO transfers (reseller_id, domain) VALUES (1, ?)',
            'tasks'     => 'INSERT INTO tasks (domain, date) VALUES (?, CURRENT_DATE)',
            'messages'  => "INSERT INTO messages (type, domain, data) VALUES ('a', ?, '')",
        ];

        foreach ($inserts as $table => $insert) {
            foreach (['Example.it', 'CITTÀ.it', 'straẞe.it'] as $name) {
                $this->assertRefusedByCheck($table, $insert, [$name]);
            }
            $this->assertRefusedByCheck($table, "UPDATE {$table} SET domain = ?", ['EXAMPLE.it']);
            $this->assertRefusedByCheck($table, "UPDATE {$table} SET domain = ?", ['straẞe.it']);
            $this->assertSame(['example.it'], R::getCol("SELECT domain FROM {$table}"), $table);
        }
    }

    public function testNamesThatOnlyDifferInAccentsOrSpellingCoexist(): void {
        foreach (['città.it', 'citta.it', 'sesamstraße.it', 'sesamstrasse.it'] as $name) {
            $this->addDomain($name);
            R::exec('INSERT INTO transfers (reseller_id, domain) VALUES (1, ?)', [$name]);
        }

        $this->assertSame(4, (int) R::getCell('SELECT COUNT(*) FROM domains'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM domains WHERE domain = ?', ['città.it']));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM domains WHERE domain = ?', ['sesamstraße.it']));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers WHERE domain = ?', ['citta.it']));
    }

    public function testCaseIsNotFoldedAnyMore(): void {
        $this->addDomain('italia.it');

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM domains WHERE domain = ?', ['ITALIA.it']));
    }

    /** why the autocomplete route normalises its term */
    public function testALikeSearchIsExact(): void {
        $this->addDomain('città.it');
        $count = fn(string $term): int => (int) R::getCell(
            "SELECT COUNT(*) FROM domains WHERE domain LIKE ? ESCAPE '!'", ["%{$term}%"]
        );

        $this->assertSame(1, $count('citt'));
        $this->assertSame(1, $count('città'));
        $this->assertSame(0, $count('CITT'));
        $this->assertSame(0, $count('citta'));
    }

    public function testTheSameNameTwiceIsStillRefused(): void {
        $this->addDomain('città.it');

        $this->expectException(\Throwable::class);
        $this->addDomain('città.it');
    }

    public function testATaskFollowsItsDomainByTheExactName(): void {
        $this->addDomain('città.it');
        R::exec("INSERT INTO tasks (domain, date) VALUES ('città.it', CURRENT_DATE)");

        $this->expectException(\Throwable::class);
        R::exec("INSERT INTO tasks (domain, date) VALUES ('citta.it', CURRENT_DATE)");
    }

    public function testTheUpgradeLowersTheCaseAndConvertsEveryColumn(): void {
        $this->toUnicodeCi();
        $this->addDomain('Mixed.IT');
        $this->addDomain('città.it');
        R::exec("INSERT INTO tasks (domain, date) VALUES ('MIXED.it', CURRENT_DATE), ('città.it', CURRENT_DATE)");
        R::exec("INSERT INTO transfers (reseller_id, domain) VALUES (1, 'Moving.IT')");
        R::exec("INSERT INTO messages (type, domain, data) VALUES ('a', 'Msg.IT', ''), ('b', '', ''), ('c', NULL, '')");
        foreach (self::COLUMNS as $table) {
            $this->assertSame('utf8mb4_unicode_ci', $this->collation($table));
        }

        Config::runSqlFile(self::UPGRADE);

        foreach (self::COLUMNS as $table) {
            $this->assertSame('utf8mb4_bin', $this->collation($table), $table);
        }
        $this->assertEqualsCanonicalizing(['mixed.it', 'città.it'], R::getCol('SELECT domain FROM domains'));
        $this->assertEqualsCanonicalizing(['mixed.it', 'città.it'], R::getCol('SELECT domain FROM tasks'));
        $this->assertSame(['moving.it'], R::getCol('SELECT domain FROM transfers'));
        $this->assertEqualsCanonicalizing(['msg.it', '', null], R::getCol('SELECT domain FROM messages'));
    }

    public function testTheUpgradeKeepsTheKeysAndTheForeignKey(): void {
        $this->toUnicodeCi();

        Config::runSqlFile(self::UPGRADE);

        foreach (['domains', 'transfers'] as $table) {
            $this->assertSame(0, (int) R::getCell("SELECT NON_UNIQUE FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'domain'", [$table]), $table);
        }
        $this->assertNotNull($this->taskForeignKey());
        $this->assertSame('255', (string) R::getCell("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'domain'"));

        $this->addDomain('città.it');
        $this->addDomain('citta.it');
        R::exec("INSERT INTO tasks (domain, date) VALUES ('citta.it', CURRENT_DATE)");
        $this->expectException(\Throwable::class);
        R::exec("INSERT INTO tasks (domain, date) VALUES ('cittá.it', CURRENT_DATE)");
    }

    public function testRunningItAgainChangesNothing(): void {
        $this->toUnicodeCi();
        $this->addDomain('Mixed.IT');
        R::exec("INSERT INTO tasks (domain, date) VALUES ('mixed.it', CURRENT_DATE)");

        Config::runSqlFile(self::UPGRADE);
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame(['mixed.it'], R::getCol('SELECT domain FROM domains'));
        $this->assertSame(['mixed.it'], R::getCol('SELECT domain FROM tasks'));
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'domain'
              AND REFERENCED_TABLE_NAME = 'domains'"));
    }

    public function testARunCutShortAfterDroppingTheForeignKeyIsFinished(): void {
        $this->toUnicodeCi();
        R::exec('ALTER TABLE tasks DROP FOREIGN KEY `' . $this->taskForeignKey() . '`');

        Config::runSqlFile(self::UPGRADE);

        $this->assertNotNull($this->taskForeignKey());
    }

    public function testAFreshInstallRefusesCapitals(): void {
        $this->assertSame(
            ['domains.domains_domain_lower', 'messages.messages_domain_lower', 'transfers.transfers_domain_lower'],
            $this->lowerCaseChecks()
        );

        $this->assertCapitalsRefused();
    }

    public function testTheUpgradeAddsTheChecks(): void {
        $this->toUnicodeCi();
        $this->assertSame([], $this->lowerCaseChecks());
        $this->addDomain('Mixed.IT');

        Config::runSqlFile(self::UPGRADE);

        $this->assertCount(3, $this->lowerCaseChecks());
        $this->assertSame(['mixed.it'], R::getCol('SELECT domain FROM domains'));
        R::exec('DELETE FROM domains');
        $this->assertCapitalsRefused();
    }

    public function testLowerCaseNamesCoexistAfterTheUpgrade(): void {
        $this->toUnicodeCi();
        Config::runSqlFile(self::UPGRADE);

        foreach (['città.it', 'citta.it', 'straße.it', 'strasse.it'] as $name) {
            $this->addDomain($name);
            R::exec('INSERT INTO transfers (reseller_id, domain) VALUES (1, ?)', [$name]);
            R::exec('INSERT INTO tasks (domain, date) VALUES (?, CURRENT_DATE)', [$name]);
            R::exec("INSERT INTO messages (type, domain, data) VALUES ('a', ?, '')", [$name]);
        }
        R::exec("INSERT INTO messages (type, domain, data) VALUES ('b', NULL, '')");

        foreach (self::COLUMNS as $table) {
            $this->assertSame(4, (int) R::getCell("SELECT COUNT(DISTINCT domain) FROM {$table}"), $table);
        }
        $this->assertSame(5, (int) R::getCell('SELECT COUNT(*) FROM messages'));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM messages WHERE domain IS NULL'));
    }

    public function testRunningTheUpgradeAgainKeepsTheChecks(): void {
        $this->toUnicodeCi();

        Config::runSqlFile(self::UPGRADE);
        $first = R::getAll("SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY 1, 2");
        Config::runSqlFile(self::UPGRADE);

        $this->assertCount(3, $this->lowerCaseChecks());
        $this->assertSame($first, R::getAll("SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY 1, 2"));
    }

    public function testTheUpgradeRunOverLatin1StillRefusesTheCapitalEszett(): void {
        $this->toUnicodeCi();

        $this->runOverLatin1(self::UPGRADE);

        $this->assertCount(3, $this->lowerCaseChecks());
        $this->assertCapitalsRefused();
    }

    public function testAFreshInstallOverLatin1StillRefusesTheCapitalEszett(): void {
        R::exec('DROP DATABASE `' . self::DB_NAME . '`');
        R::exec('CREATE DATABASE `' . self::DB_NAME . '`');
        R::exec('USE `' . self::DB_NAME . '`');
        $this->runOverLatin1(dirname(__DIR__, 3) . '/config/mariadb-schema.sql');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 1)");

        $this->assertCount(3, $this->lowerCaseChecks());
        $this->assertCapitalsRefused();
    }
}
