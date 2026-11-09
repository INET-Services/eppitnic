<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Command\UserActivateCommand;
use Eppitnic\Cli\Command\UserDeactivateCommand;
use Eppitnic\Cli\Command\UserMfaResetCommand;
use Eppitnic\Cli\Command\UserPasswordCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * `user password`, `user activate`, `user deactivate` and `user mfa-reset`: the CLI shell over
 * the rules in UserRules, which the API routes share.
 */
final class UserAccountCommandsTest extends TestCase
{
    private const GOOD = 'Correct-Horse-9-Battery';

    private $errors;

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensure(1, 'admin', 1, 'root');
        TestAccounts::ensure(2, 'manager', 2, 'boss');
        TestAccounts::ensure(3, 'user', 2, 'alice');
        TestAccounts::ensure(4, 'manager', 3, 'other');
        TestAccounts::ensure(5, 'admin', 1, 'root2');

        $this->errors = fopen('php://memory', 'w+');
        Config::loadForTesting([]);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function exec(object $command): array {
        $command->useErrorStream($this->errors);
        ob_start();
        $code = $command->run();
        return [$code, (string) ob_get_clean()];
    }

    private function errorText(): string {
        rewind($this->errors);
        return (string) stream_get_contents($this->errors);
    }

    private function stdin(string $text) {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $text);
        rewind($stream);
        return $stream;
    }

    private function history(int $id): array {
        return R::getAll("SELECT * FROM history WHERE object = 'users' AND object_id = ?", [$id]);
    }

    // ---------------------------------------------------------------
    // user password
    // ---------------------------------------------------------------

    public function testPasswordFromTheOption(): void {
        [$code] = $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, 'alice']));

        $this->assertSame(0, $code);
        $this->assertTrue(password_verify(self::GOOD, R::getCell('SELECT password FROM users WHERE id = 3')));
        $this->assertSame(0, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 3'));
    }

    public function testPasswordFromStdin(): void {
        $command = new UserPasswordCommand(['--password-stdin', '3']);
        $command->useInputStream($this->stdin(self::GOOD . "\n"));
        [$code] = $this->exec($command);

        $this->assertSame(0, $code);
        $this->assertTrue(password_verify(self::GOOD, R::getCell('SELECT password FROM users WHERE id = 3')));
    }

    public function testEmptyStdinIsRefused(): void {
        $command = new UserPasswordCommand(['--password-stdin', 'alice']);
        $command->useInputStream($this->stdin(''));

        $this->expectException(UsageError::class);
        $command->run();
    }

    public function testGeneratedPasswordIsPrintedOnceAndMeetsThePolicy(): void {
        [$code, $output] = $this->exec(new UserPasswordCommand(['--generate', '--jsonl', 'alice']));

        $this->assertSame(0, $code);
        $password = json_decode(trim($output), true)['password'];
        $this->assertTrue(\Eppitnic\Support\PasswordPolicy::isAcceptable($password));
        $this->assertTrue(password_verify($password, R::getCell('SELECT password FROM users WHERE id = 3')));
    }

    #[DataProvider('conflictingSources')]
    public function testOnlyOneSourceIsAllowed(array $args): void {
        $this->expectException(UsageError::class);
        (new UserPasswordCommand([...$args, 'alice']))->run();
    }

    public static function conflictingSources(): array {
        return [
            'option and generate' => [['--password=x', '--generate']],
            'stdin and generate'  => [['--password-stdin', '--generate']],
            'option and stdin'    => [['--password=x', '--password-stdin']],
        ];
    }

    public function testNoSourceWithoutATerminalIsAUsageError(): void {
        if (stream_isatty(STDIN)) {
            $this->markTestSkipped('stdin is a terminal');
        }
        $this->expectException(UsageError::class);
        (new UserPasswordCommand(['alice']))->run();
    }

    public function testPolicyViolationIsRefusedAndNothingChanges(): void {
        $before = R::getCell('SELECT password FROM users WHERE id = 3');

        try {
            (new UserPasswordCommand(['--password=short', 'alice']))->run();
            $this->fail('expected a UsageError');
        } catch (UsageError $e) {
            $this->assertStringContainsString('The password needs', $e->getMessage());
        }
        $this->assertSame($before, R::getCell('SELECT password FROM users WHERE id = 3'));
        $this->assertSame([], $this->history(3));
    }

    public function testMustChangePassword(): void {
        $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, '--must-change-password', 'alice']));

        $this->assertSame(1, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 3'));
    }

    public function testHistoryNeverHoldsThePasswordOrItsHash(): void {
        $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, '--must-change-password', '--user=2', 'alice']));

        $rows = $this->history(3);
        $this->assertCount(1, $rows);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame(2, (int) $rows[0]['user_id']);
        $this->assertSame(
            ['password' => 'PASSWORD_CHANGED', 'must_change_password' => 1],
            json_decode($rows[0]['data'], true)
        );
        $this->assertStringNotContainsString(self::GOOD, $rows[0]['data']);
        $this->assertStringNotContainsString(R::getCell('SELECT password FROM users WHERE id = 3'), $rows[0]['data']);
    }

    public function testManagerResetsAUserOfTheirReseller(): void {
        [$code] = $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, '--user=2', 'alice']));

        $this->assertSame(0, $code);
    }

    public function testManagerCannotResetAnotherResellersUserOrAnAdmin(): void {
        foreach (['other', 'root'] as $target) {
            [$code] = $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, '--user=2', $target]));
            $this->assertSame(INVALID_INPUT, $code);
        }
        $this->assertStringContainsString('not authorized', $this->errorText());
        $this->assertNull(R::getCell('SELECT password FROM users WHERE id = 4'));
    }

    public function testAPlainUserMayResetOnlyTheirOwn(): void {
        [$own] = $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, '--user=3', 'alice']));
        [$other] = $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, '--user=3', 'boss']));

        $this->assertSame(0, $own);
        $this->assertSame(INVALID_INPUT, $other);
    }

    public function testUnknownUserFails(): void {
        [$code] = $this->exec(new UserPasswordCommand(['--password=' . self::GOOD, 'nobody']));

        $this->assertSame(INVALID_INPUT, $code);
    }

    // ---------------------------------------------------------------
    // user deactivate / activate
    // ---------------------------------------------------------------

    public function testDeactivateThenActivate(): void {
        [$code, $output] = $this->exec(new UserDeactivateCommand(['alice']));
        $this->assertSame(0, $code);
        $this->assertStringContainsString('now inactive', $output);
        $this->assertSame(0, (int) R::getCell('SELECT active FROM users WHERE id = 3'));

        [$code, $output] = $this->exec(new UserActivateCommand(['3']));
        $this->assertSame(0, $code);
        $this->assertStringContainsString('now active', $output);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM users WHERE id = 3'));
    }

    public function testHistoryRecordsTheActiveFlag(): void {
        $this->exec(new UserDeactivateCommand(['--user=2', 'alice']));
        $this->exec(new UserActivateCommand(['--user=2', 'alice']));

        $rows = $this->history(3);
        $this->assertCount(2, $rows);
        $this->assertSame(['active' => 0], json_decode($rows[0]['data'], true));
        $this->assertSame(['active' => 1], json_decode($rows[1]['data'], true));
        $this->assertSame(2, (int) $rows[0]['user_id']);
    }

    public function testSeveralUsersInOneRun(): void {
        $this->exec(new UserDeactivateCommand(['alice', 'root2']));

        $this->assertSame(0, (int) R::getCell('SELECT active FROM users WHERE id = 3'));
        $this->assertSame(0, (int) R::getCell('SELECT active FROM users WHERE id = 5'));
    }

    #[DataProvider('noOps')]
    public function testAlreadyInTheWantedStateIsASuccessWithoutHistory(string $class, int $active, string $said): void {
        R::exec('UPDATE users SET active = ? WHERE id = 3', [$active]);

        [$code, $output] = $this->exec(new $class(['alice']));

        $this->assertSame(0, $code);
        $this->assertStringContainsString($said, $output);
        $this->assertSame([], $this->history(3));
    }

    public static function noOps(): array {
        return [
            'activate'   => [UserActivateCommand::class, 1, 'already active'],
            'deactivate' => [UserDeactivateCommand::class, 0, 'already inactive'],
        ];
    }

    public function testTheLastActiveAdminCannotBeDeactivated(): void {
        R::exec('UPDATE users SET active = 0 WHERE id = 5');

        [$code] = $this->exec(new UserDeactivateCommand(['root']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('cannot demote or deactivate the last active admin', $this->errorText());
        $this->assertSame(1, (int) R::getCell('SELECT active FROM users WHERE id = 1'));
    }

    public function testTheLastActiveManagerOfAnActiveResellerCannotBeDeactivated(): void {
        [$code] = $this->exec(new UserDeactivateCommand(['boss']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString(
            'cannot demote or deactivate the last active manager of an active reseller',
            $this->errorText()
        );
    }

    public function testTheLastManagerOfAnInactiveResellerMayGo(): void {
        R::exec('UPDATE resellers SET active = 0 WHERE id = 2');

        [$code] = $this->exec(new UserDeactivateCommand(['boss']));

        $this->assertSame(0, $code);
    }

    public function testNobodyDeactivatesThemselves(): void {
        [$code] = $this->exec(new UserDeactivateCommand(['--user=1', 'root']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('you cannot deactivate yourself', $this->errorText());
    }

    public function testManagerCannotReachAnotherResellersUser(): void {
        TestAccounts::ensure(6, 'user', 3, 'carol');

        [$code] = $this->exec(new UserDeactivateCommand(['--user=2', 'carol']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('not authorized', $this->errorText());
        $this->assertSame(1, (int) R::getCell('SELECT active FROM users WHERE id = 6'));
    }

    public function testAPlainUserMayChangeNobodysActiveFlag(): void {
        [$code] = $this->exec(new UserDeactivateCommand(['--user=3', 'alice']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertSame(1, (int) R::getCell('SELECT active FROM users WHERE id = 3'));
    }

    public function testUnknownUserFailsButTheRestAreProcessed(): void {
        [$code] = $this->exec(new UserDeactivateCommand(['nobody', 'alice']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertSame(0, (int) R::getCell('SELECT active FROM users WHERE id = 3'));
    }

    public function testNeedsAName(): void {
        $this->expectException(UsageError::class);
        (new UserActivateCommand([]))->run();
    }

    public function testActingAsAnUnknownUserIsAUsageError(): void {
        $this->expectException(UsageError::class);
        (new UserActivateCommand(['--user=99', 'alice']))->run();
    }

    // ---------------------------------------------------------------
    // user mfa-reset
    // ---------------------------------------------------------------

    private function withMfa(int $id): void {
        R::exec("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP', totp_secret_pending = 'PENDINGSECRET234' WHERE id = ?", [$id]);
    }

    public function testMfaResetClearsBothSecretsLikeTheApi(): void {
        $this->withMfa(3);

        [$code, $output] = $this->exec(new UserMfaResetCommand(['--user=2', 'alice']));

        $this->assertSame(0, $code);
        $this->assertSame("MFA removed for 'alice'\n", $output);
        $row = R::getRow('SELECT totp_secret, totp_secret_pending FROM users WHERE id = 3');
        $this->assertNull($row['totp_secret']);
        $this->assertNull($row['totp_secret_pending']);
    }

    public function testMfaResetHistoryNeverHoldsTheSecret(): void {
        $this->withMfa(3);

        $this->exec(new UserMfaResetCommand(['--user=2', 'alice']));

        $rows = $this->history(3);
        $this->assertCount(1, $rows);
        $this->assertSame([2, 'update'], [(int) $rows[0]['user_id'], $rows[0]['action']]);
        $this->assertSame('alice', json_decode($rows[0]['data'], true)['username']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $rows[0]['data']);
        $this->assertStringNotContainsString('PENDINGSECRET234', $rows[0]['data']);
    }

    public function testMfaResetOfAUserWithoutMfaChangesNothing(): void {
        [$code, $output] = $this->exec(new UserMfaResetCommand(['--user=2', 'alice']));

        $this->assertSame(0, $code);
        $this->assertSame("'alice' has no MFA\n", $output);
        $this->assertSame([], $this->history(3));
    }

    public function testMfaResetNoLongerTakesTheOldEnrolmentFlag(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage("unknown option '--must-enroll-mfa'");

        $this->exec(new UserMfaResetCommand(['--user=2', '--must-enroll-mfa', 'alice']));
    }

    public function testMfaResetByAnAdminOnAnyUserAndOnSelf(): void {
        $this->withMfa(4);
        $this->withMfa(1);

        [$code] = $this->exec(new UserMfaResetCommand(['other', 'root']));

        $this->assertSame(0, $code);
        $this->assertNull(R::getCell('SELECT totp_secret FROM users WHERE id = 4'));
        $this->assertNull(R::getCell('SELECT totp_secret FROM users WHERE id = 1'));
    }

    public function testMfaResetByAPlainUserOnlyForThemselves(): void {
        $this->withMfa(3);
        $this->withMfa(2);

        [$code] = $this->exec(new UserMfaResetCommand(['--user=3', 'alice', 'boss']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertNull(R::getCell('SELECT totp_secret FROM users WHERE id = 3'));
        $this->assertNotNull(R::getCell('SELECT totp_secret FROM users WHERE id = 2'));
        $this->assertStringContainsString('You are not authorized to perform this operation', $this->errorText());
    }

    #[DataProvider('unreachableTargets')]
    public function testMfaResetRefusesWhatTheActorCannotReach(string $target): void {
        $this->withMfa((int) R::getCell('SELECT id FROM users WHERE username = ?', [$target]));

        [$code] = $this->exec(new UserMfaResetCommand(['--user=2', $target]));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertNotNull(R::getCell('SELECT totp_secret FROM users WHERE username = ?', [$target]));
        $this->assertStringContainsString('You are not authorized to perform this operation', $this->errorText());
    }

    public static function unreachableTargets(): array {
        return ['another reseller\'s manager' => ['other'], 'an admin' => ['root']];
    }

    public function testMfaResetReportsAnUnknownUserAndContinues(): void {
        $this->withMfa(3);

        [$code] = $this->exec(new UserMfaResetCommand(['--user=2', 'nobody', 'alice']));

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertNull(R::getCell('SELECT totp_secret FROM users WHERE id = 3'));
        $this->assertStringContainsString('no such user', $this->errorText());
    }
}
