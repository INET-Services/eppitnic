<?php

namespace Eppitnic\Tests\Cli;

use Eppitnic\Cli\Application;
use Eppitnic\Cli\Command\ConfigPasskeysCommand;
use Eppitnic\Cli\UsageError;
use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Tests\Support\PasskeyCliTestCase;
use RedBeanPHP\R;

/**
 * `config passkeys`: the same setting PUT /v1/settings/passkeys edits, for a
 * headless install -- and what changing the relying party takes.
 */
final class ConfigPasskeysCommandTest extends PasskeyCliTestCase
{
    private function config(array $args): array {
        return $this->exec(ConfigPasskeysCommand::class, $args);
    }

    public function testItIsRegistered(): void {
        $this->assertSame(ConfigPasskeysCommand::class, Application::commands()['config passkeys']);
    }

    public function testWithoutOptionsItShowsTheSettingsAndChangesNothing(): void {
        $this->aliceKeys(2);
        $this->requirement('passkey');

        [$code, $output] = $this->config([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('rp_id:         localhost', $output);
        $this->assertStringContainsString('origins:       http://localhost:5173', $output);
        $this->assertStringContainsString('type:          any', $output);
        $this->assertStringContainsString('models:        -', $output);
        $this->assertStringContainsString('registered:    2 passkey(s); 1 user(s) need one', $output);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'passkeys'"));
    }

    public function testItShowsThatPasskeysAreOff(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        [, $output] = $this->config([]);

        $this->assertStringContainsString('rp_id:         - (passkeys are off)', $output);
    }

    public function testJsonCarriesTheSettingAndWhatIsInUse(): void {
        [, $output] = $this->config(['--json']);

        $document = json_decode($output, true)[0];
        $this->assertSame('localhost', $document['rp_id']);
        $this->assertSame(['passkeys' => 0, 'users' => 0], $document['in_use']);
    }

    public function testItSetsTheRelyingPartyAndThePolicyFromRepeatedOptions(): void {
        [$code, $output] = $this->config([
            '--user=1', '--rp-id=Example.IT', '--origin=https://example.it', '--origin=https://app.example.it', '--origin=https://example.it',
            '--type=device_bound', '--model=yubikey5', '--model=google_titan',
            '--extra-aaguid=aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', '--extra-aaguid=bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        ]);

        $this->assertSame(0, $code);
        $this->assertSame([
            'rp_id'         => 'example.it',
            'origins'       => ['https://example.it', 'https://app.example.it'],
            'type'          => 'device_bound',
            'models'        => ['yubikey5', 'google_titan'],
            'extra_aaguids' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        ], Config::get('passkeys'));
        $this->assertStringContainsString('origins:       https://example.it, https://app.example.it', $output);
        $this->assertSame(1, (int) R::getCell("SELECT user_id FROM history WHERE object = 'passkeys'"));
    }

    public function testAnEmptyValueEmptiesAList(): void {
        $this->config(['--type=device_bound', '--model=yubikey5']);

        $this->config(['--model=']);

        $this->assertSame([], Config::get('passkeys')['models']);
        $this->assertSame('device_bound', Config::get('passkeys')['type'], 'the rest is untouched');
    }

    public function testChangingTheRpIdWhileKeysExistIsRefusedUntilConfirmed(): void {
        $this->aliceKeys(2);
        $this->requirement('passkey');

        [$code] = $this->config(['--rp-id=example.it', '--origin=https://example.it']);

        $this->assertSame(INVALID_INPUT, $code);
        $this->assertStringContainsString('--confirm', $this->errorText());
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
        $this->assertSame(2, Passkeys::countForUser(5));

        [$code, $output] = $this->config(['--rp-id=example.it', '--origin=https://example.it', '--confirm']);

        $this->assertSame(0, $code);
        $this->assertSame('example.it', Config::get('passkeys')['rp_id']);
        $this->assertSame(0, Passkeys::countForUser(5));
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
        $this->assertStringContainsString('registered:    0 passkey(s); 1 user(s) need one', $output);
    }

    public function testClearingTheRpIdWhileKeyUsersExistNeedsConfirmation(): void {
        $this->requirement('totp_or_passkey');

        [$code] = $this->config(['--rp-id=']);
        $this->assertSame(INVALID_INPUT, $code);
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);

        [$code] = $this->config(['--rp-id=', '--origin=', '--confirm']);
        $this->assertSame(0, $code);
        $this->assertSame('', Config::get('passkeys')['rp_id']);
    }

    public function testARefusedValueIsAUsageErrorAndNothingIsStored(): void {
        foreach ([['--rp-id=127.0.0.1'], ['--type=sometimes'], ['--origin=http://example.com'], ['--extra-aaguid=nope']] as $args) {
            try {
                $this->config($args);
                $this->fail('accepted ' . json_encode($args));
            } catch (UsageError $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
    }

    public function testAnOptionNeedsItsValue(): void {
        $this->expectException(UsageError::class);

        $this->config(['--rp-id']);
    }

    public function testTheOutputSaysWhereTheMetadataStands(): void {
        [, $output] = $this->config([]);
        $this->assertStringContainsString('metadata:      missing', $output);

        $this->metadata([], [], '2026-12-01', 42);
        [, $output] = $this->config([]);
        $this->assertStringContainsString('metadata:      ok, serial 42, next update 2026-12-01', $output);
        $this->assertSame('ok', json_decode($this->config(['--json'])[1], true)[0]['metadata']['state']);
    }

    public function testAChangeIsRecordedAsASecurityEventWithCounts(): void {
        $this->aliceKeys(1);
        $this->requirement('passkey');
        R::exec('UPDATE users SET totp_secret = ? WHERE id = 5', ['JBSWY3DPEHPK3PXP']);

        $this->config(['--user=1', '--type=device_bound']);

        $rows = $this->history('security');
        $this->assertCount(1, $rows);
        $this->assertSame('passkey_policy_changed', $rows[0]['event']);
        $this->assertSame(['type'], $rows[0]['changes']);
        $this->assertSame('cli', $rows[0]['by']);
        $this->assertSame([1, 1, 1, 0], [$rows[0]['keys_noncompliant'], $rows[0]['users_affected'], $rows[0]['needs_enrolment'], $rows[0]['locked_out']]);
        $this->assertSame(1, (int) $rows[0]['user_id']);
    }

    public function testItDoesNotRefuseAChangeThatLocksEveryAdminOutButSaysSo(): void {
        $this->aliceKeys(1);
        R::exec("UPDATE users SET role = 'admin', reseller_id = 1, signin_requirement = 'passkey' WHERE id = 5");
        R::exec("UPDATE users SET signin_requirement = 'totp', active = 0 WHERE id = 1");

        [$code] = $this->config(['--type=device_bound']);

        $this->assertSame(0, $code);
        $this->assertSame('device_bound', Config::get('passkeys')['type']);
        $this->assertStringContainsString('locked out', $this->errorText());
        $this->assertStringContainsString('no active admin can sign in', $this->errorText());
        $this->assertStringContainsString('user signin-reset', $this->errorText());
    }

    public function testShowingRecordsNothing(): void {
        $this->config([]);

        $this->assertSame([], $this->history('security'));
    }
}
