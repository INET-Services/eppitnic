<?php

namespace Eppitnic\Tests\Unit\Setup;

use Eppitnic\Config;
use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Persistence\PasskeyExists;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\MetadataBlob;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Service\WebAuthnRegistration;
use PDO;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * The passkey schema on a real MariaDB: the 7.1 upgrade (which must leave
 * every choice a re-run finds alone), the fresh install, and what binary
 * credential ids and the foreign keys do there that SQLite does not. Needs a
 * local MariaDB, like the other tests that touch one.
 */
final class PasskeySchemaTest extends TestCase
{
    private const DB_NAME = 'eppitnic_fixtest_c';
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

        if ( ! R::hasDatabase('fixtest_c')) {
            R::addDatabase('fixtest_c', 'mysql:host=localhost;dbname=' . self::DB_NAME . ';charset=utf8mb4', get_current_user(), '');
        }
        R::selectDatabase('fixtest_c', force: true);
        Config::runSqlFile(dirname(__DIR__, 3) . '/config/mariadb-schema.sql');
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

    /** Back to the shape the upgrade starts from. */
    private function rewind(): void {
        R::exec('DROP TABLE passkeys');
        R::exec('DROP TABLE webauthn_challenges');
        R::exec('DROP TABLE authenticator_metadata');
        R::exec('ALTER TABLE users DROP COLUMN signin_requirement, DROP COLUMN passkey_enroll_open, DROP COLUMN webauthn_handle');
        R::exec("DELETE FROM settings WHERE `key` IN ('passkeys', 'passkey_challenge_purge', 'passkey_metadata', 'passkey_metadata_blob')");
        R::exec("ALTER TABLE history MODIFY COLUMN `object` enum('users', 'contacts', 'domains', 'security', 'cronjobs', 'epp', 'smtp', 'remote_auth', 'trusted_proxies', 'resellers', 'region', 'allowed_origins', 'debugfile', 'dnssec') NOT NULL");
    }

    private function addUser(string $name, ?string $totp = null, int $mustEnroll = 0): int {
        R::exec('INSERT INTO users (username, totp_secret, must_enroll_mfa) VALUES (?, ?, ?)', [$name, $totp, $mustEnroll]);
        return (int) R::getInsertID();
    }

    private function requirement(string $name): string {
        return (string) R::getCell('SELECT signin_requirement FROM users WHERE username = ?', [$name]);
    }

    private function registration(?string $id = null): WebAuthnRegistration {
        return new WebAuthnRegistration($id ?? random_bytes(32), '{}', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 0, false, false, 'none', false, []);
    }

    // -----------------------------------------------------------------
    // the upgrade
    // -----------------------------------------------------------------

    public function testUsersWithTotpOrAPendingEnrolmentKeepTotpAndTheRestGoToNone(): void {
        $this->rewind();
        $this->addUser('withtotp', 'JBSWY3DPEHPK3PXP');
        $this->addUser('mustenroll', null, 1);
        $this->addUser('both', 'JBSWY3DPEHPK3PXP', 1);
        $this->addUser('plain');
        $this->addUser('emptysecret', '');

        Config::runSqlFile(self::UPGRADE);

        $this->assertSame('totp', $this->requirement('withtotp'));
        $this->assertSame('totp', $this->requirement('mustenroll'));
        $this->assertSame('totp', $this->requirement('both'));
        $this->assertSame('none', $this->requirement('plain'));
        $this->assertSame('none', $this->requirement('emptysecret'));
    }

    public function testARerunLeavesEveryChoiceAlone(): void {
        $this->rewind();
        $this->addUser('withtotp', 'JBSWY3DPEHPK3PXP');
        $this->addUser('plain');
        Config::runSqlFile(self::UPGRADE);

        // an admin has chosen since: the second run must not undo it
        R::exec("UPDATE users SET signin_requirement = 'none' WHERE username = 'withtotp'");
        R::exec("UPDATE users SET signin_requirement = 'passkey' WHERE username = 'plain'");
        $this->addUser('newcomer');

        Config::runSqlFile(self::UPGRADE);
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame('none', $this->requirement('withtotp'));
        $this->assertSame('passkey', $this->requirement('plain'));
        $this->assertSame('totp', $this->requirement('newcomer'), 'a new user starts on totp');
    }

    public function testAnUpgradeThatStoppedHalfWayFinishesOnTheNextRun(): void {
        $this->rewind();
        $this->addUser('withtotp', 'JBSWY3DPEHPK3PXP');
        // the column is added and the migration dies before the UPDATE
        R::exec("ALTER TABLE users ADD COLUMN signin_requirement enum('passkey','totp','totp_or_passkey','none') NOT NULL DEFAULT 'none'");

        Config::runSqlFile(self::UPGRADE);

        $this->assertSame('totp', $this->requirement('withtotp'));
    }

    public function testTheUpgradeSeedsTheSettingsWithoutOverwritingAnOperatorsChoice(): void {
        $this->rewind();
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame(
            ['rp_id' => '', 'origins' => [], 'type' => 'any', 'models' => [], 'extra_aaguids' => []],
            json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkeys'"), true)
        );
        $this->assertSame(
            ['enabled' => true, 'frequency_minutes' => 1440, 'last_run_at' => null],
            json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkey_challenge_purge'"), true)
        );
        $this->assertSame(
            ['enabled' => true, 'frequency_minutes' => 1440, 'last_run_at' => null],
            json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkey_metadata'"), true)
        );
        $this->assertSame(
            ['serial' => null, 'next_update' => null, 'fetched_at' => null],
            json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkey_metadata_blob'"), true)
        );

        R::exec("UPDATE settings SET value = '{\"rp_id\":\"example.it\",\"origins\":[\"https://example.it\"],\"type\":\"any\",\"models\":[],\"extra_aaguids\":[]}' WHERE `key` = 'passkeys'");
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame('example.it', json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkeys'"), true)['rp_id']);
        $this->assertSame('"070100"', R::getCell("SELECT value FROM settings WHERE `key` = 'schema_version'"));
    }

    public function testTheUpgradeMakesTheTablesAndHistoryTakesThePasskeysObject(): void {
        $this->rewind();
        Config::runSqlFile(self::UPGRADE);

        foreach (['passkeys', 'webauthn_challenges', 'authenticator_metadata'] as $table) {
            $this->assertNotEmpty(R::getAll("SHOW TABLES LIKE '{$table}'"), $table);
        }
        R::exec("INSERT INTO history (user_id, object, object_id, action, data) VALUES (NULL, 'passkeys', 0, 'update', '{}')");
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'passkeys'"));
    }

    public function testTheUpgradeAndAFreshInstallEndUpWithTheSameUsersTable(): void {
        $columns = R::getAll('SHOW COLUMNS FROM users');
        $indexes = array_column(R::getAll('SHOW INDEX FROM users'), 'Key_name');
        $this->rewind();
        Config::runSqlFile(self::UPGRADE);

        $this->assertEquals($columns, R::getAll('SHOW COLUMNS FROM users'));
        $this->assertEqualsCanonicalizing($indexes, array_column(R::getAll('SHOW INDEX FROM users'), 'Key_name'));
    }

    public function testTheUpgradeAndAFreshInstallEndUpWithTheSameChallengesTable(): void {
        $columns = R::getAll('SHOW COLUMNS FROM webauthn_challenges');
        $indexes = array_column(R::getAll('SHOW INDEX FROM webauthn_challenges'), 'Key_name');
        $this->rewind();
        Config::runSqlFile(self::UPGRADE);

        $this->assertEquals($columns, R::getAll('SHOW COLUMNS FROM webauthn_challenges'));
        $this->assertEqualsCanonicalizing($indexes, array_column(R::getAll('SHOW INDEX FROM webauthn_challenges'), 'Key_name'));
    }

    public function testAChallengesTableOfAnEarlierRunGainsTheContextAndTheUniqueHash(): void {
        $this->rewind();
        R::exec('CREATE TABLE webauthn_challenges (
            id serial, purpose enum(\'register\',\'login\',\'stepup\') NOT NULL, user_id bigint unsigned NULL,
            challenge_hash char(64) NOT NULL, created_at datetime NOT NULL, expires_at datetime NOT NULL,
            used_at datetime NULL, PRIMARY KEY (id), KEY (expires_at), KEY (user_id)
        ) ENGINE=InnoDB');

        Config::runSqlFile(self::UPGRADE);
        Config::runSqlFile(self::UPGRADE);

        $this->assertContains('context', array_column(R::getAll('SHOW COLUMNS FROM webauthn_challenges'), 'Field'));
        $this->assertContains('0', array_map('strval', array_column(
            R::getAll("SHOW INDEX FROM webauthn_challenges WHERE Column_name = 'challenge_hash'"), 'Non_unique'
        )));
    }

    public function testTheEnrolmentFlagIsClosedByDefaultAfterAnUpgradeAndAFreshInstall(): void {
        $this->addUser('fresh');
        $this->rewind();
        $this->addUser('old');
        Config::runSqlFile(self::UPGRADE);

        $this->assertSame([0, 0], array_map('intval', R::getCol('SELECT passkey_enroll_open FROM users ORDER BY id')));
        R::exec("UPDATE users SET passkey_enroll_open = 1 WHERE username = 'old'");
        Config::runSqlFile(self::UPGRADE);
        $this->assertSame(1, (int) R::getCell("SELECT passkey_enroll_open FROM users WHERE username = 'old'"), 'a re-run leaves it');
    }

    // -----------------------------------------------------------------
    // a fresh install
    // -----------------------------------------------------------------

    public function testAFreshInstallDefaultsNewUsersToTotpAndSeedsTheSettings(): void {
        $this->addUser('first');

        $this->assertSame('totp', $this->requirement('first'));
        $this->assertNull(R::getCell("SELECT webauthn_handle FROM users WHERE username = 'first'"));
        $this->assertSame('', json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkeys'"), true)['rp_id']);
        $this->assertNotNull(R::getCell("SELECT value FROM settings WHERE `key` = 'passkey_challenge_purge'"));
        $this->assertTrue(json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkey_metadata'"), true)['enabled']);
        $this->assertNull(json_decode(R::getCell("SELECT value FROM settings WHERE `key` = 'passkey_metadata_blob'"), true)['serial']);
    }

    public function testTheRequirementTakesOnlyItsFourValues(): void {
        $this->addUser('first');
        foreach (['passkey', 'totp', 'totp_or_passkey', 'none'] as $value) {
            R::exec('UPDATE users SET signin_requirement = ? WHERE username = ?', [$value, 'first']);
            $this->assertSame($value, $this->requirement('first'));
        }

        $this->expectException(\Throwable::class);
        R::exec("UPDATE users SET signin_requirement = 'sms' WHERE username = 'first'");
    }

    // -----------------------------------------------------------------
    // what MariaDB does with it
    // -----------------------------------------------------------------

    public function testAMetadataBlobIsStoredReplacedAndReadBack(): void {
        $entry = static fn(string $description, string $status): array => [
            'description' => $description, 'status' => $status,
            'entry' => ['attestationRootCertificates' => ['MIIB/+=\\'], 'attestationTypes' => ['basic_full'], 'keyProtection' => [], 'matcherProtection' => [], 'statusReports' => []],
        ];
        $make = static function (array $entries): MetadataBlob {
            $instance = (new \ReflectionClass(MetadataBlob::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty($instance, 'entries'))->setValue($instance, $entries);
            return $instance;
        };
        $one = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';

        AuthenticatorMetadata::replaceAll($make([
            $one => $entry('Ünïcode Key', 'FIDO_CERTIFIED'),
            'aaaaaaaa-bbbb-4ccc-8ddd-000000000002' => $entry('Two', 'REVOKED'),
        ]), '2026-11-08 12:00:00');
        $this->assertSame(['aaaaaaaa-bbbb-4ccc-8ddd-000000000002'], AuthenticatorMetadata::compromised([$one, 'aaaaaaaa-bbbb-4ccc-8ddd-000000000002']));
        AuthenticatorMetadata::replaceAll($make([$one => $entry('Ünïcode Key v2', 'FIDO_CERTIFIED')]), '2026-11-09 12:00:00');

        $this->assertSame(1, AuthenticatorMetadata::count());
        $row = AuthenticatorMetadata::find(strtoupper($one));
        $this->assertSame('Ünïcode Key v2', $row['description']);
        $this->assertSame(['MIIB/+=\\'], $row['entry']['attestationRootCertificates']);
        $this->assertSame([], AuthenticatorMetadata::compromised([$one]));
    }

    public function testBinaryCredentialIdsRoundTripAndAreCaseSensitive(): void {
        $user = $this->addUser('first');
        $binary = "\x00\xff\xfe\x80 \xc3\x28\xa0\xa1\x00";

        Passkeys::add($user, $this->registration($binary), 'Binary');
        Passkeys::add($user, $this->registration('AbC'), 'Upper');
        Passkeys::add($user, $this->registration('abc'), 'Lower');

        $this->assertSame($binary, Passkeys::findByCredentialId($binary)['credential_id']);
        $this->assertSame('Upper', Passkeys::findByCredentialId('AbC')['name']);
        $this->assertSame('Lower', Passkeys::findByCredentialId('abc')['name']);
    }

    public function testACredentialIdOf1023BytesIsStoredWhole(): void {
        $user = $this->addUser('first');
        $id = random_bytes(1023);

        Passkeys::add($user, $this->registration($id), 'Long');

        $this->assertSame($id, Passkeys::findByCredentialId($id)['credential_id']);
    }

    public function testTheUniqueKeyRefusesADuplicateEvenWhenTheApplicationCheckIsBypassed(): void {
        $first = $this->addUser('first');
        $second = $this->addUser('second');
        $id = random_bytes(32);
        Passkeys::add($first, $this->registration($id), 'One');

        try {
            Passkeys::add($second, $this->registration($id), 'Two');
            $this->fail('accepted');
        } catch (PasskeyExists) {
            $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
        }

        $this->expectException(\Throwable::class);
        R::exec(
            "INSERT INTO passkeys (user_id, credential_id, source, aaguid, attestation_format, name, created_at)
             VALUES (?, ?, '{}', 'x', 'none', 'Dup', NOW())",
            [$second, $id]
        );
    }

    public function testTheSourceMustBeJson(): void {
        $user = $this->addUser('first');

        $this->expectException(\Throwable::class);
        R::exec(
            "INSERT INTO passkeys (user_id, credential_id, source, aaguid, attestation_format, name, created_at)
             VALUES (?, 'id', 'not json', 'x', 'none', 'Bad', NOW())",
            [$user]
        );
    }

    public function testDeletingAUserTakesTheirKeysAndChallengesAlong(): void {
        $user = $this->addUser('first');
        $other = $this->addUser('second');
        Passkeys::add($user, $this->registration(), 'Gone');
        Passkeys::add($other, $this->registration(), 'Stays');
        WebAuthnChallenges::issue('stepup', $user);
        WebAuthnChallenges::issue('stepup', $other);
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);
        [$loginId, $loginChallenge] = WebAuthnChallenges::issueLogin();
        WebAuthnChallenges::consumeLogin($loginId, $loginChallenge);

        R::exec('DELETE FROM users WHERE id = ?', [$user]);

        $this->assertSame(0, Passkeys::countForUser($user));
        $this->assertSame(1, Passkeys::countForUser($other));
        $this->assertSame(2, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testAKeyNeedsAUser(): void {
        $this->expectException(\Throwable::class);
        Passkeys::add(424242, $this->registration(), 'Orphan');
    }

    public function testTheUserHandleIsBinarySafeAndUnique(): void {
        $first = $this->addUser('first');
        $second = $this->addUser('second');

        $handle = Passkeys::handleFor($first);

        $this->assertSame(32, strlen($handle));
        $this->assertSame($handle, Passkeys::handleFor($first));
        $this->assertNotSame($handle, Passkeys::handleFor($second));
        $this->expectException(\Throwable::class);
        R::exec('UPDATE users SET webauthn_handle = ? WHERE id = ?', [$handle, $second]);
    }

    public function testAChallengeIsSpentOnceAndPurgedWhenExpired(): void {
        $user = $this->addUser('first');
        [$id, $challenge] = WebAuthnChallenges::issue('stepup', $user);

        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', $user));
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', $user));

        R::exec("UPDATE webauthn_challenges SET expires_at = '2020-01-01 00:00:00'");
        $this->assertSame(1, WebAuthnChallenges::purge());
    }

    public function testALoginChallengeSpentTwiceIsRefusedByTheUniqueHash(): void {
        Config::loadForTesting(['jwt_psk' => 'test-signing-key-for-this-suite-only']);
        [$id, $challenge] = WebAuthnChallenges::issueLogin();

        $this->assertTrue(WebAuthnChallenges::consumeLogin($id, $challenge));
        $this->assertFalse(WebAuthnChallenges::consumeLogin($id, $challenge));

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'login'"));
    }

    public function testAChallengeForAnotherPurposeOrUserIsRefusedOnMariaDbToo(): void {
        $user = $this->addUser('first');
        $other = $this->addUser('second');
        [$id, $challenge] = WebAuthnChallenges::issue('stepup', $user);

        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'login', $user));
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', $other));
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));
        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', $user));
    }
}
