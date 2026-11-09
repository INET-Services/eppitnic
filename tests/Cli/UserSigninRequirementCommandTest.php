<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\UserSigninRequirementCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * `user signin-requirement`: the CLI shell over the rules PUT /v1/users/{id}
 * applies (UserRules).
 */
final class UserSigninRequirementCommandTest extends TestCase
{
    private $errors;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history', 'passkeys'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensure(1, 'admin', 1, 'root');
        TestAccounts::ensure(2, 'manager', 2, 'boss');
        TestAccounts::ensure(3, 'user', 2, 'alice');
        TestAccounts::ensure(4, 'manager', 3, 'other');

        $this->errors = fopen('php://memory', 'w+');
        Config::loadForTesting(['passkeys' => [
            'rp_id' => 'localhost', 'origins' => ['http://localhost:5173'], 'type' => 'any', 'models' => [], 'extra_aaguids' => [],
        ]]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function exec(array $args): array {
        $command = new UserSigninRequirementCommand($args);
        $command->useErrorStream($this->errors);
        ob_start();
        try {
            $code = $command->run();
            return [$code, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    private function errorText(): string {
        rewind($this->errors);
        return (string) stream_get_contents($this->errors);
    }

    private function mode(int $id): string {
        return (string) R::getCell('SELECT signin_requirement FROM users WHERE id = ?', [$id]);
    }

    private function history(int $objectId, string $object = 'users'): array {
        return R::getAll('SELECT user_id, action, data FROM history WHERE object = ? AND object_id = ? ORDER BY id', [$object, $objectId]);
    }

    public function testAnAdminSetsAUsersRequirementAndItIsRecorded(): void {
        [$code, $output] = $this->exec(['--user=1', 'alice', 'passkey']);

        $this->assertSame(0, $code);
        $this->assertSame("user 'alice' now signs in with: passkey\n", $output);
        $this->assertSame('passkey', $this->mode(3));
        $history = $this->history(3);
        $this->assertCount(1, $history);
        $this->assertSame([1, 'update'], [(int) $history[0]['user_id'], $history[0]['action']]);
        $this->assertSame(
            ['signin_requirement' => 'passkey', 'passkey_enroll_open' => 1],
            json_decode($history[0]['data'], true)
        );
    }

    public function testTheUserCanBeNamedByIdAndTheDefaultActorIsTheFirstAdmin(): void {
        [$code] = $this->exec(['3', 'totp_or_passkey']);

        $this->assertSame(0, $code);
        $this->assertSame('totp_or_passkey', $this->mode(3));
    }

    public function testNothingChangesWhenTheRequirementIsAlreadySet(): void {
        R::exec("UPDATE users SET signin_requirement = 'totp' WHERE id = 3");

        [$code, $output] = $this->exec(['alice', 'totp']);

        $this->assertSame(0, $code);
        $this->assertSame("user 'alice' already signs in with: totp\n", $output);
        $this->assertSame([], $this->history(3));
    }

    public function testManagersSetEveryRequirementButNoneInTheirReseller(): void {
        $this->assertSame(0, $this->exec(['--user=2', 'alice', 'passkey'])[0]);


        [$code] = $this->exec(['--user=2', 'alice', 'none']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('not authorized', $this->errorText());
        $this->assertSame('passkey', $this->mode(3));
    }

    public function testAManagerDoesNotReachAnotherResellersUser(): void {
        [$code] = $this->exec(['--user=4', 'alice', 'totp']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('not authorized', $this->errorText());
    }

    public function testNobodyLoosensTheirOwn(): void {
        R::exec("UPDATE users SET signin_requirement = 'passkey' WHERE id = 1");

        [$code] = $this->exec(['--user=1', 'root', 'totp']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('cannot loosen your own', $this->errorText());
        $this->assertSame('passkey', $this->mode(1));

        [$code] = $this->exec(['--user=1', 'root', 'totp_or_passkey']);

        $this->assertSame(INVALID_INPUT, $code, 'a password with a code beside the key is a loosening');
        $this->assertSame('passkey', $this->mode(1));
    }

    public function testNoneNeedsAnAdminAndIsRecordedAsASecurityEvent(): void {
        R::exec("UPDATE users SET signin_requirement = 'totp' WHERE id = 3");

        $this->exec(['--user=1', 'alice', 'none']);

        $events = $this->history(1, 'security');
        $this->assertCount(1, $events);
        $data = json_decode($events[0]['data'], true);
        $this->assertSame('signin_requirement_none', $data['event']);
        $this->assertSame([3, 'alice'], [$data['target_id'], $data['target']]);
        $this->assertSame(1, (int) $events[0]['user_id']);
    }

    public function testNoneAgainIsNotRecordedAgain(): void {
        R::exec("UPDATE users SET signin_requirement = 'totp' WHERE id = 3");
        $this->exec(['--user=1', 'alice', 'none']);
        $this->exec(['--user=1', 'alice', 'none']);

        $this->assertCount(1, $this->history(1, 'security'));
        $this->assertCount(1, $this->history(3));
    }

    public function testAnUnknownRequirementIsAUsageError(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('signin_requirement must be one of');

        $this->exec(['alice', 'sms']);
    }

    public function testBothArgumentsAreNeeded(): void {
        $this->expectException(UsageError::class);

        $this->exec(['alice']);
    }

    public function testAnUnknownUserFails(): void {
        [$code] = $this->exec(['nobody', 'totp']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString("no user 'nobody'", $this->errorText());
    }

    // -----------------------------------------------------------------
    // the enrolment flag and passkeys being off
    // -----------------------------------------------------------------

    private function open(int $id): int {
        return (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = ?', [$id]);
    }

    private function addKey(int $userId): void {
        R::exec("INSERT INTO passkeys (user_id, credential_id, source, aaguid, attestation_format, name, created_at)
                 VALUES (?, ?, '{}', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'none', 'Key', '2026-01-01 00:00:00')", [$userId, random_bytes(8)]);
    }

    public function testAskingForKeysOpensTheEnrolmentAndTheHistoryRowSaysSo(): void {
        $this->exec(['--user=1', 'alice', 'totp_or_passkey']);

        $this->assertSame(1, $this->open(3));
        $this->assertSame(1, json_decode($this->history(3)[0]['data'], true)['passkey_enroll_open']);
    }

    public function testAUserWhoHasTheKeysAlreadyIsNotLeftOpen(): void {
        $this->addKey(3);

        $this->exec(['--user=1', 'alice', 'passkey']);

        $this->assertSame(0, $this->open(3));
    }

    public function testGoingBackToACodeClosesItAndSwitchingBetweenKeyModesLeavesItAlone(): void {
        $this->exec(['--user=1', 'alice', 'passkey']);
        $this->exec(['--user=1', 'alice', 'totp_or_passkey']);
        $this->assertSame(1, $this->open(3));

        $this->exec(['--user=1', 'alice', 'totp']);
        $this->assertSame(0, $this->open(3));
    }

    public function testAKeyRequirementIsRefusedWhilePasskeysAreOff(): void {
        Config::loadForTesting([]);

        [$code] = $this->exec(['--user=1', 'alice', 'passkey']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('passkeys are not enabled', $this->errorText());
        $this->assertSame('none', $this->mode(3));
        $this->assertSame(0, $this->exec(['--user=1', 'alice', 'totp'])[0], 'the others are fine');
    }

    public function testAKeyUserStaysAsTheyAreWhilePasskeysAreOff(): void {
        R::exec("UPDATE users SET signin_requirement = 'passkey' WHERE id = 3");
        Config::loadForTesting([]);

        [$code] = $this->exec(['--user=1', 'alice', 'passkey']);

        $this->assertSame(0, $code, 'unchanged is not a new requirement');
    }
}
