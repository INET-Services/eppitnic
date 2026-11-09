<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\UserPasskeyRevokeCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Tests\Support\PasskeyCliTestCase;
use RedBeanPHP\R;

/**
 * `user passkey-revoke`: a sysop removes keys whatever the minimum says, and
 * a user left short may register again on the password alone.
 */
final class UserPasskeyRevokeCommandTest extends PasskeyCliTestCase
{
    private function open(): int {
        return (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5');
    }

    public function testItIsRegistered(): void {
        $this->assertSame(UserPasskeyRevokeCommand::class, Application::commands()['user passkey-revoke']);
    }

    public function testItRemovesOneKeyAndRecordsItWithoutAUser(): void {
        $this->aliceKeys(2);

        [$code, $output] = $this->exec(UserPasskeyRevokeCommand::class, ['alice', '2']);

        $this->assertSame(0, $code);
        $this->assertSame("1 passkey(s) of 'alice' removed, 1 left\n", $output);
        $this->assertNull(Passkeys::find(2));
        $this->assertNotNull(Passkeys::find(1));
        $row = $this->history('users', 5)[0];
        $this->assertNull($row['user_id']);
        $this->assertSame([['id' => 2, 'name' => 'Test key']], $row['passkeys_removed']);
        $this->assertSame('executed from CLI by a sysop', $row['by']);
        $event = $this->history('security')[0];
        $this->assertSame(['passkey_removed', 5, [2]], [$event['event'], $event['target_id'], $event['credentials']]);
        $this->assertNull($event['user_id']);
    }

    public function testTheUserAndTheKeyMayHaveTheSameNumber(): void {
        $this->aliceKeys(5);

        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['5', '5']);

        $this->assertSame(0, $code);
        $this->assertNull(Passkeys::find(5));
        $this->assertSame(4, Passkeys::countForUser(5));
    }

    public function testAboveTheMinimumTheEnrolmentStaysClosed(): void {
        $this->aliceKeys(2);
        $this->requirement('passkey');

        $this->exec(UserPasskeyRevokeCommand::class, ['alice', '1']);

        $this->assertSame(0, $this->open());
    }

    public function testBelowTheMinimumItGoesAnywayOpensTheEnrolmentAndSaysSo(): void {
        $this->aliceKeys(1);
        $this->requirement('passkey');

        [$code, $output] = $this->exec(UserPasskeyRevokeCommand::class, ['alice', '1']);

        $this->assertSame(0, $code);
        $this->assertSame([], Passkeys::listForUser(5));
        $this->assertSame(1, $this->open());
        $this->assertStringContainsString('below the minimum of 1', $output);
        $this->assertStringContainsString('password alone may register', $output);
        $flag = array_values(array_filter($this->history('users', 5), static fn(array $row): bool => isset($row['passkey_enroll_open'])))[0];
        $this->assertSame([1, null], [$flag['passkey_enroll_open'], $flag['user_id']]);
    }

    public function testAllRemovesEveryKeyAndOpensTheEnrolment(): void {
        $this->aliceKeys(2);
        $this->requirement('passkey');

        [, $output] = $this->exec(UserPasskeyRevokeCommand::class, ['--all', 'alice']);

        $this->assertStringContainsString("2 passkey(s) of 'alice' removed, 0 left", $output);
        $this->assertStringContainsString('below the minimum of 1', $output);
        $this->assertSame(1, $this->open());
    }

    public function testAModeThatAsksForNoKeyIsNeverOpened(): void {
        $this->aliceKeys(1);

        $this->exec(UserPasskeyRevokeCommand::class, ['--all', 'alice']);

        $this->assertSame(0, $this->open());
    }

    public function testJsonReportsWhatHappened(): void {
        $this->aliceKeys(1);
        $this->requirement('totp_or_passkey');

        [, $output] = $this->exec(UserPasskeyRevokeCommand::class, ['--json', '--all', '5']);

        $this->assertSame(
            ['id' => 5, 'username' => 'alice', 'removed' => [1], 'remaining' => 0, 'minimum' => 1, 'enroll_open' => true],
            json_decode($output, true)[0]
        );
    }

    public function testAKeyOfAnotherUserOrNoSuchKeyIsNotTouched(): void {
        $this->aliceKeys(1);

        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['root', '1']);
        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString("'root' has no passkey 1", $this->errorText());

        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['alice', '99']);
        $this->assertSame(INVALID_INPUT, $code);
        $this->assertNotNull(Passkeys::find(1));
    }

    public function testAUserWithoutKeysAndAnUnknownUserFail(): void {
        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['--all', 'alice']);
        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString("'alice' has no passkeys", $this->errorText());

        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['--all', 'nobody']);
        $this->assertSame(INVALID_INPUT, $code);
    }

    public function testItNeedsAKeyOrAll(): void {
        foreach ([[], ['alice'], ['alice', '1', '--all'], ['alice', '1', '2'], ['alice', 'x']] as $args) {
            try {
                $this->exec(UserPasskeyRevokeCommand::class, $args);
                $this->fail('accepted ' . json_encode($args));
            } catch (UsageError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItIgnoresWhoIsNamedAsActor(): void {
        $this->aliceKeys(1);

        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['--user=5', '--all', 'root']);
        $this->assertSame(INVALID_INPUT, $code, 'root has none');

        [$code] = $this->exec(UserPasskeyRevokeCommand::class, ['--user=99', '--all', 'alice']);
        $this->assertSame(0, $code);
    }
}
