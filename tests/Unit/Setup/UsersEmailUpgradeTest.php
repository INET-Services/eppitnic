<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * The 7.1 upgrade makes users.email unique: the lowest id keeps a shared
 * address (compared case-insensitively), the others lose it, and each loss is
 * recorded. Needs a local MariaDB, like the other tests that touch one.
 */
final class UsersEmailUpgradeTest extends TestCase
{
    private const DB_NAME = 'eppitnic_fixtest_b';
    private const UPGRADE = __DIR__ . '/../../../config/mariadb-schema-upgrade-070000-to-070100.sql';

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

        if ( ! R::hasDatabase('fixtest_b')) {
            R::addDatabase('fixtest_b', 'mysql:host=localhost;dbname=' . self::DB_NAME . ';charset=utf8', get_current_user(), '');
        }
        R::selectDatabase('fixtest_b', force: true);
        Config::runSqlFile(dirname(__DIR__, 3) . '/config/mariadb-schema.sql');

        // back to the shape the upgrade starts from
        R::exec('DROP TABLE password_resets');
        R::exec('DROP TABLE password_reset_attempts');
        R::exec('ALTER TABLE users DROP INDEX email, MODIFY email varchar(64) NULL');
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

    private function addUser(string $name, ?string $email): int {
        R::exec('INSERT INTO users (username, email) VALUES (?, ?)', [$name, $email]);
        return (int) R::getInsertID();
    }

    public function testTheLowestIdKeepsASharedAddressAndTheOthersAreRecorded(): void {
        $first = $this->addUser('first', 'Shared@Example.it');
        $second = $this->addUser('second', 'shared@example.it');
        $third = $this->addUser('third', 'SHARED@EXAMPLE.IT');
        $alone = $this->addUser('alone', 'alone@example.it');

        Config::runSqlFile(self::UPGRADE);

        $emails = R::getAssoc('SELECT id, email FROM users');
        $this->assertSame('Shared@Example.it', $emails[$first]);
        $this->assertNull($emails[$second]);
        $this->assertNull($emails[$third]);
        $this->assertSame('alone@example.it', $emails[$alone]);

        $rows = R::getAll("SELECT object_id, user_id, action, data FROM history WHERE object = 'users' ORDER BY object_id");
        $this->assertSame([$second, $third], array_map(static fn(array $r): int => (int) $r['object_id'], $rows));
        $this->assertNull($rows[0]['user_id']);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame(
            ['email' => null, 'reason' => 'duplicate address removed by the 7.1 upgrade'],
            json_decode($rows[0]['data'], true)
        );
    }

    public function testEmptyAddressesBecomeNullAndNullsMayRepeat(): void {
        $this->addUser('blank1', '');
        $this->addUser('blank2', '');
        $this->addUser('none', null);

        Config::runSqlFile(self::UPGRADE);

        $this->assertSame(3, (int) R::getCell('SELECT COUNT(*) FROM users WHERE email IS NULL'));
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'users'"));
    }

    public function testTheColumnIsWideAndUniqueAfterwards(): void {
        Config::runSqlFile(self::UPGRADE);

        $this->addUser('one', str_repeat('a', 240) . '@example.it');
        $this->expectException(\Throwable::class);
        $this->addUser('two', strtoupper(str_repeat('a', 240)) . '@example.it');
    }

    public function testRunningItAgainChangesNothing(): void {
        $this->addUser('one', 'a@example.it');
        Config::runSqlFile(self::UPGRADE);
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame('a@example.it', R::getCell("SELECT email FROM users WHERE username = 'one'"));
        $this->assertNotEmpty(R::getAll("SHOW TABLES LIKE 'password_resets'"));
    }
}
