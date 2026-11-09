<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\UserPasskeysCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Tests\Support\PasskeyCliTestCase;
use RedBeanPHP\R;

/**
 * `user passkeys`: what a sysop sees of a user's keys.
 */
final class UserPasskeysCommandTest extends PasskeyCliTestCase
{
    public function testItIsRegistered(): void {
        $this->assertSame(UserPasskeysCommand::class, Application::commands()['user passkeys']);
    }

    public function testItListsTheKeysWithTheRequirementAndTheMinimum(): void {
        $this->aliceKeys(2);
        $this->requirement('passkey', open: 1);
        R::exec("UPDATE passkeys SET name = 'Laptop', last_used_at = '2026-11-01 10:00:00' WHERE id = 1");

        [$code, $output] = $this->exec(UserPasskeysCommand::class, ['alice']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('alice: passkey, minimum 1, 2 compliant of 2, enrolment open', $output);
        $this->assertStringContainsString('Laptop', $output);
        $this->assertStringContainsString('aaaaaaaa-bbbb-4ccc-8ddd-000000000001', $output);
        $this->assertStringContainsString('last used 2026-11-01 10:00:00', $output);
        $this->assertStringContainsString('last used never', $output);
    }

    public function testJsonCarriesOneDocumentWithoutTheSecrets(): void {
        $this->aliceKeys(1);
        $this->requirement('totp_or_passkey');

        [, $output] = $this->exec(UserPasskeysCommand::class, ['--json', '5']);

        $document = json_decode($output, true)[0];
        $this->assertSame(['alice', 'totp_or_passkey', 1, 1, false, true], [
            $document['username'], $document['signin_requirement'], $document['minimum'], $document['compliant_count'],
            $document['enroll_open'], $document['passkeys_available'],
        ]);
        $key = $document['passkeys'][0];
        $this->assertSame(['id', 'name', 'aaguid', 'compliant', 'backup_eligible', 'backup_state', 'attestation_format', 'attestation_verified', 'created', 'last_used'], array_keys($key));
        $this->assertStringNotContainsString('credential', $output);
        $this->assertStringNotContainsString('source', $output);
    }

    public function testAKeyThatBreaksThePolicyIsMarked(): void {
        $this->aliceKeys(1);
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        [, $output] = $this->exec(UserPasskeysCommand::class, ['alice']);

        $this->assertStringContainsString('0 compliant of 1', $output);
        $this->assertStringContainsString(' NO ', $output);
    }

    public function testAUserWithNoKeysAndPasskeysBeingOffSaySo(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        [, $output] = $this->exec(UserPasskeysCommand::class, ['alice']);

        $this->assertStringContainsString('0 compliant of 0', $output);
        $this->assertStringContainsString('passkeys are off', $output);
    }

    public function testItReachesAnyUserWhoeverIsNamedAsActor(): void {
        $this->aliceKeys(1);

        [$code, $output] = $this->exec(UserPasskeysCommand::class, ['--user=5', 'root']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('root: totp', $output);
    }

    public function testAnUnknownUserFails(): void {
        [$code] = $this->exec(UserPasskeysCommand::class, ['nobody']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString("no user 'nobody'", $this->errorText());
    }

    public function testAUserIsNeeded(): void {
        $this->expectException(UsageError::class);

        $this->exec(UserPasskeysCommand::class, []);
    }
}
