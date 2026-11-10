<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * The 7.1 upgrade gives `transfers` its `kind` (existing rows are plain
 * transfers) and lets `registrant` be NULL, keeping its foreign key. It also
 * indexes `msgqueue.cl_trid`. Needs a local MariaDB, like the other tests that
 * touch one.
 */
final class TransfersKindUpgradeTest extends TestCase
{
    private const DB_NAME = 'eppitnic_fixtest_d';
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

        if ( ! R::hasDatabase('fixtest_d')) {
            R::addDatabase('fixtest_d', 'mysql:host=localhost;dbname=' . self::DB_NAME . ';charset=utf8', get_current_user(), '');
        }
        R::selectDatabase('fixtest_d', force: true);
        Config::runSqlFile(dirname(__DIR__, 3) . '/config/mariadb-schema.sql');

        // back to the shape the upgrade starts from
        R::exec('ALTER TABLE transfers DROP COLUMN kind, MODIFY registrant varchar(32) NOT NULL');
        R::exec('DROP INDEX msgqueue_cl_trid ON msgqueue');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('REGI1234REGI5678', 1)");
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant) VALUES (1, 'old.it', 'REGI1234REGI5678')");
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

    public function testAnExistingRowIsAPlainTransferThatKeepsItsRegistrant(): void {
        Config::runSqlFile(self::UPGRADE);

        $row = R::getRow("SELECT kind, registrant FROM transfers WHERE domain = 'old.it'");
        $this->assertSame(['transfer', 'REGI1234REGI5678'], [$row['kind'], $row['registrant']]);
    }

    public function testTheRegistrantMayBeNullButStaysAForeignKey(): void {
        Config::runSqlFile(self::UPGRADE);

        R::exec("INSERT INTO transfers (reseller_id, domain) VALUES (1, 'plain.it')");
        $this->assertNull(R::getCell("SELECT registrant FROM transfers WHERE domain = 'plain.it'"));

        $this->expectException(\Throwable::class);
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant) VALUES (1, 'bad.it', 'NOSUCH12NOSUCH34')");
    }

    public function testKindOnlyTakesTransferOrTrade(): void {
        Config::runSqlFile(self::UPGRADE);

        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, kind) VALUES (1, 'trade.it', 'REGI1234REGI5678', 'trade')");
        $this->assertSame('trade', R::getCell("SELECT kind FROM transfers WHERE domain = 'trade.it'"));

        $this->expectException(\Throwable::class);
        R::exec("INSERT INTO transfers (reseller_id, domain, kind) VALUES (1, 'bad.it', 'sideways')");
    }

    public function testMsgqueueIsIndexedByItsClTridOnce(): void {
        $indexed = fn() => (int) R::getCell("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'msgqueue' AND COLUMN_NAME = 'cl_trid'");
        $this->assertSame(0, $indexed());

        Config::runSqlFile(self::UPGRADE);
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame(1, $indexed());
    }

    public function testRunningItAgainChangesNothing(): void {
        Config::runSqlFile(self::UPGRADE);
        R::exec("UPDATE transfers SET kind = 'trade' WHERE domain = 'old.it'");

        Config::runSqlFile(self::UPGRADE);

        $this->assertSame('trade', R::getCell("SELECT kind FROM transfers WHERE domain = 'old.it'"));
        $this->assertSame('YES', R::getCell("SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transfers' AND COLUMN_NAME = 'registrant'"));
    }
}
