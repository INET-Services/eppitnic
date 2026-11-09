<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use RedBeanPHP\R;

/**
 * A user may set up more than their requirement asks for, and then the weaker
 * way in stops working: in mode "none", a compliant key without a TOTP secret
 * ends the bare password sign-in. Accounts keep what they have signed in with.
 */
final class PasskeyOutranksPasswordTest extends PasskeyHttpTestCase
{
    private const REFUSAL = ['error' => 'This account signs in with a passkey', 'required' => 'passkey'];

    // -----------------------------------------------------------------
    // none with a key, no code
    // -----------------------------------------------------------------

    public function testThePasswordIsRefusedAnywhereAndTheKeySignsIn(): void {
        [$key] = $this->keys(1);
        $this->requirement('none');

        foreach ([self::OUTSIDE, self::SAFE] as $ip) {
            $this->from($ip);

            $response = $this->password();

            $this->assertSame(403, $response->getStatusCode(), $ip);
            $this->assertSame(self::REFUSAL, self::json($response), $ip);
            $this->assertSame(200, $this->passkey($key)->getStatusCode(), $ip);
        }
        $this->assertSame(0, $this->denied(), 'a requirement is not a failed login');
        $pending = $this->events('login_requirement_pending');
        $this->assertSame(['passkey', 'secread'], [$pending[0]['required'], $pending[0]['action']]);
    }

    public function testAWrongPasswordIsStillJustWrong(): void {
        $this->keys(1);
        $this->requirement('none');

        $response = $this->password(['password' => 'wrong']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Wrong username or password', self::json($response)['error']);
        $this->assertSame(1, $this->denied());
    }

    // -----------------------------------------------------------------
    // none with a key and a code
    // -----------------------------------------------------------------

    public function testACodeKeepsThePasswordAWayInAndTheKeyWorksToo(): void {
        [$key] = $this->keys(1);
        $this->requirement('none');
        $this->withTotp();

        $coded = self::json($this->password(['totp' => $this->code()]));
        $this->assertSame('pwd+otp', $coded['amr']);

        $alone = $this->password();
        $this->assertSame(401, $alone->getStatusCode());
        $this->assertSame('MFA code required', self::json($alone)['error']);

        $this->from(self::SAFE);
        $this->assertSame('pwd', self::json($this->password())['amr'], 'a safe network skips the code');

        $this->from(self::OUTSIDE);
        $this->assertSame('passkey', self::json($this->passkey($key))['amr']);
    }

    // -----------------------------------------------------------------
    // none with a code only, and the modes that ask for a code
    // -----------------------------------------------------------------

    public function testNoneWithACodeOnlyIsUnchanged(): void {
        $this->requirement('none');
        $this->withTotp();

        $this->assertSame('pwd+otp', self::json($this->password(['totp' => $this->code()]))['amr']);
        $this->assertSame(401, $this->password()->getStatusCode());
        $this->from(self::SAFE);
        $this->assertSame('pwd', self::json($this->password())['amr']);
    }

    public function testTotpModesWithKeysSignInWithPasswordAndCodeOrWithTheKey(): void {
        [$key] = $this->keys(1);
        $this->withTotp();

        foreach (['totp', 'totp_or_passkey'] as $mode) {
            $this->requirement($mode);

            $this->assertSame('pwd+otp', self::json($this->password(['totp' => $this->code()]))['amr'], $mode);
            $this->assertSame('passkey', self::json($this->passkey($key))['amr'], $mode);
        }
    }

    public function testTotpModesWithAKeyButNoCodeStillReachTheCodeSetupWithThePassword(): void {
        $this->keys(1);

        foreach (['totp', 'totp_or_passkey'] as $mode) {
            $this->requirement($mode);

            $response = $this->password();

            $this->assertSame(403, $response->getStatusCode(), $mode);
            $this->assertSame('mfa_enrollment', self::json($response)['required'], $mode);
            $this->assertSame(200, $this->call('POST', '/v1/users/authenticate/mfa', [
                'username' => 'alice', 'password' => self::PASSWORD,
            ])->getStatusCode(), $mode);
        }
    }

    // -----------------------------------------------------------------
    // order and recovery
    // -----------------------------------------------------------------

    public function testAPendingPasswordChangeStillComesFirst(): void {
        [$key] = $this->keys(1);
        $this->requirement('none');
        R::exec('UPDATE users SET must_change_password = 1 WHERE id = 5');

        $this->assertSame('password_change', self::json($this->password())['required']);

        $changed = $this->call('POST', '/v1/users/authenticate/password', [
            'username' => 'alice', 'password' => self::PASSWORD, 'new_password' => 'Fr3sh-Enough-Pw!',
        ]);
        $this->assertSame(403, $changed->getStatusCode());
        $this->assertSame(self::REFUSAL, self::json($changed));
        $this->assertSame(0, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 5'));
        $this->assertSame(200, $this->passkey($key)->getStatusCode());
    }

    public function testRemovingTheLastKeyGivesThePasswordBack(): void {
        [$key] = $this->keys(1);
        $this->requirement('none');
        $this->assertSame(403, $this->password()->getStatusCode());

        $token = self::json($this->passkey($key))['token'];
        $removed = $this->call('DELETE', '/v1/users/5/passkeys/1', ['stepup' => $this->stepupProof($key, $token)], $token);
        $this->assertSame(200, $removed->getStatusCode(), (string) $removed->getBody());
        $this->assertSame([], Passkeys::listForUser(5));

        $this->assertSame('pwd', self::json($this->password())['amr']);
    }

    public function testAKeyThatDoesNotComplyDoesNotTakeThePasswordAway(): void {
        $this->keys(1);
        $this->requirement('none');
        R::exec('UPDATE passkeys SET attestation_verified = 0, backup_eligible = 1');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertSame('pwd', self::json($this->password())['amr']);
    }

    public function testNoKeyCountsWhilePasskeysAreOff(): void {
        $this->keys(1);
        $this->requirement('none');
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $this->assertSame('pwd', self::json($this->password())['amr']);
    }

    // -----------------------------------------------------------------
    // sessions are not ended
    // -----------------------------------------------------------------

    public function testAnOpenPasswordSessionSurvivesAddingTheFirstKey(): void {
        $this->requirement('none');
        $login = self::json($this->password());
        $this->assertSame('pwd', $login['amr']);
        $token = $login['token'];

        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $token));
        $this->assertArrayHasKey('publicKey', $options, json_encode($options));
        $added = $this->call('POST', '/v1/users/5/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode((new SoftAuthenticator())->register($options['publicKey']), true),
            'stepup'       => ['password' => self::PASSWORD],
        ], $token);
        $this->assertSame(201, $added->getStatusCode(), (string) $added->getBody());

        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $token)->getStatusCode());
        $this->assertSame(200, $this->call('GET', '/v1/users/renew-token', [], $token)->getStatusCode());
        $this->assertSame(403, $this->password()->getStatusCode(), 'the next sign-in needs the key');
    }

    public function testAPasswordSessionOfAKeyHolderIsNotRefused(): void {
        $this->keys(1);
        $this->requirement('none');

        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $this->session('pwd'))->getStatusCode());
    }
}
