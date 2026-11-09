<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\UserSigninResetCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Support\PasswordPolicy;
use Eppitnic\Tests\Support\PasskeyCliTestCase;
use RedBeanPHP\R;

/**
 * `user signin-reset`: the sysop's way back in -- no one needs to be able to
 * sign in for it to run.
 */
final class UserSigninResetCommandTest extends PasskeyCliTestCase
{
    private const TEMPORARY = 'Temporary-Passw0rd!';

    private function row(int $id = 5): array {
        return R::getRow('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function reset(array $args, ?string $stdin = null): array {
        return $this->exec(UserSigninResetCommand::class, $args, $stdin);
    }

    public function testItIsRegistered(): void {
        $this->assertSame(UserSigninResetCommand::class, Application::commands()['user signin-reset']);
    }

    public function testItRemovesKeysAndCodeAndSetsAPasswordToChange(): void {
        $this->aliceKeys(2);
        $this->requirement('passkey');
        R::exec("UPDATE users SET totp_secret = 'SECRET', totp_secret_pending = 'PENDING' WHERE id = 5");

        [$code, $output] = $this->reset(['--password-stdin', 'alice'], self::TEMPORARY . "\n");

        $this->assertSame(0, $code);
        $user = $this->row();
        $this->assertSame([], Passkeys::listForUser(5));
        $this->assertNull($user['totp_secret']);
        $this->assertNull($user['totp_secret_pending']);
        $this->assertSame(1, (int) $user['must_change_password']);
        $this->assertTrue(password_verify(self::TEMPORARY, $user['password']));
        $this->assertSame(1, (int) $user['passkey_enroll_open']);
        $this->assertStringContainsString("sign-in of 'alice' reset: 2 passkey(s) removed, TOTP removed", $output);
        $this->assertStringContainsString('password alone may register passkeys', $output);
        $this->assertStringNotContainsString(self::TEMPORARY, $output);
    }

    public function testAGeneratedPasswordIsPrintedOnceAndMeetsThePolicy(): void {
        [, $output] = $this->reset(['--generate', 'alice']);

        $this->assertSame(1, preg_match('/store it now\): (\S+)$/m', $output, $match));
        $this->assertTrue(PasswordPolicy::isAcceptable($match[1]));
        $this->assertTrue(password_verify($match[1], $this->row()['password']));
        $this->assertStringNotContainsString($match[1], implode(' ', R::getCol('SELECT data FROM history')));
    }

    public function testJsonCarriesTheGeneratedPasswordAndTheCounts(): void {
        $this->aliceKeys(1);

        [, $output] = $this->reset(['--json', '--generate', 'alice']);

        $record = json_decode($output, true)[0];
        $this->assertSame([5, 'alice', 1, false, false], [
            $record['id'], $record['username'], $record['passkeys_removed'], $record['totp_removed'], $record['enroll_open'],
        ]);
        $this->assertTrue(password_verify($record['password'], $this->row()['password']));
    }

    public function testThePasswordCanBeGivenAsAnArgument(): void {
        $this->reset(['--password=' . self::TEMPORARY, '5']);

        $this->assertTrue(password_verify(self::TEMPORARY, $this->row()['password']));
    }

    public function testAWeakPasswordOrNoSourceIsAUsageError(): void {
        $cases = [['--password=short', 'alice'], ['--password=a', '--generate', 'alice']];
        if ( ! stream_isatty(STDIN)) {
            $cases[] = ['alice'];
        }
        foreach ($cases as $args) {
            try {
                $this->reset($args);
                $this->fail('accepted ' . json_encode($args));
            } catch (UsageError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->row()['must_change_password']);
    }

    public function testItIsRecordedWithoutAUserAndWithoutThePassword(): void {
        $this->aliceKeys(1);
        $this->requirement('passkey');

        $this->reset(['--password=' . self::TEMPORARY, 'alice']);

        $row = $this->history('users', 5)[0];
        $this->assertNull($row['user_id']);
        $this->assertTrue($row['signin_reset']);
        $this->assertSame([1, 'executed from CLI by a sysop'], [$row['passkeys_revoked'], $row['by']]);
        $event = $this->history('security')[0];
        $this->assertSame(['signin_reset', 5, 'alice'], [$event['event'], $event['target_id'], $event['target']]);
        $this->assertNull($event['user_id']);
        $this->assertStringNotContainsString(self::TEMPORARY, implode(' ', R::getCol('SELECT data FROM history')));
    }

    public function testModesThatAskForNoKeyLeaveTheEnrolmentClosed(): void {
        foreach (['totp', 'none'] as $mode) {
            $this->requirement($mode, open: 1);

            $this->reset(['--password=' . self::TEMPORARY, 'alice']);

            $this->assertSame(0, (int) $this->row()['passkey_enroll_open'], $mode);
        }
    }

    public function testItCanSetTheRequirementAtTheSameTime(): void {
        [$code] = $this->reset(['--password=' . self::TEMPORARY, '--requirement=passkey', 'alice']);

        $this->assertSame(0, $code);
        $user = $this->row();
        $this->assertSame(['passkey', 1], [$user['signin_requirement'], (int) $user['passkey_enroll_open']]);

        $this->reset(['--password=' . self::TEMPORARY, '--requirement=totp', 'alice']);
        $user = $this->row();
        $this->assertSame(['totp', 0], [$user['signin_requirement'], (int) $user['passkey_enroll_open']]);
    }

    public function testTheRequirementIsKeptWhenNoneIsGiven(): void {
        $this->requirement('passkey');

        $this->reset(['--password=' . self::TEMPORARY, 'alice']);

        $this->assertSame('passkey', $this->row()['signin_requirement']);
    }

    public function testAnUnknownRequirementIsAUsageError(): void {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('--requirement:');

        $this->reset(['--password=' . self::TEMPORARY, '--requirement=sms', 'alice']);
    }

    public function testNoneIsRecordedAsASecurityEvent(): void {
        $this->reset(['--password=' . self::TEMPORARY, '--requirement=none', 'alice']);

        $events = array_values(array_filter($this->history('security'), static fn(array $row): bool => $row['event'] === 'signin_requirement_none'));
        $this->assertCount(1, $events);
        $this->assertSame([5, 'alice', 'executed from CLI by a sysop'], [$events[0]['target_id'], $events[0]['target'], $events[0]['by']]);
        $this->assertNull($events[0]['user_id']);
    }

    public function testAKeyRequirementIsRefusedWhilePasskeysAreOffAndNothingChanges(): void {
        $this->aliceKeys(1);
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        [$code] = $this->reset(['--password=' . self::TEMPORARY, '--requirement=passkey', 'alice']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('passkeys are not enabled', $this->errorText());
        $this->assertSame(1, Passkeys::countForUser(5));
        $this->assertSame(0, (int) $this->row()['must_change_password']);
    }

    public function testAnUnknownUserFails(): void {
        [$code] = $this->reset(['--password=' . self::TEMPORARY, 'nobody']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString("no user 'nobody'", $this->errorText());
    }

    public function testItResetsAnyoneWhoeverIsNamedAsActor(): void {
        foreach (['root', 'boss', 'alice'] as $name) {
            [$code] = $this->reset(['--user=5', '--password=' . self::TEMPORARY, $name]);

            $this->assertSame(0, $code, $name);
        }
        $this->assertSame(1, (int) $this->row(1)['must_change_password'], 'an admin, by a plain user acting');
        [$code] = $this->reset(['--user=99', '--password=' . self::TEMPORARY, 'root']);
        $this->assertSame(0, $code, 'no such actor either');
    }

    public function testItIsPossibleWithNoSessionAndNoWorkingAdmin(): void {
        R::exec("UPDATE users SET active = 0 WHERE role = 'admin'");
        R::exec("UPDATE users SET password = 'x', totp_secret = 'LOST'");

        [$code] = $this->reset(['--password=' . self::TEMPORARY, 'root']);

        $this->assertSame(0, $code);
        $this->assertTrue(password_verify(self::TEMPORARY, $this->row(1)['password']));
        $this->assertNull($this->row(1)['totp_secret']);
    }
}
