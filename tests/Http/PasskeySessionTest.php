<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use RedBeanPHP\R;

/**
 * What a session keeps asking of its account: the key it was opened with,
 * the sign-in requirement, and the second factor the MFA gate wants.
 */
final class PasskeySessionTest extends PasskeyHttpTestCase
{
    /** An own-account route behind Auth::requireMfa(). */
    private const GATED = ['DELETE', '/v1/users/5/totp'];

    private function me(string $token): \Psr\Http\Message\ResponseInterface {
        return $this->call('GET', '/v1/users/me', [], $token);
    }

    private function gated(string $token): \Psr\Http\Message\ResponseInterface {
        return $this->call(self::GATED[0], self::GATED[1], [], $token);
    }

    private function message(\Psr\Http\Message\ResponseInterface $response): string {
        return (string) (self::json($response)['error'] ?? '');
    }

    /** @return array{0: string, 1: array} a passkey session and the key's row */
    private function passkeySession(): array {
        [$key] = $this->keys(1);
        $body = self::json($this->passkey($key));
        $this->assertArrayHasKey('token', $body);
        return [$body['token'], Passkeys::listForUser(5)[0]];
    }

    // -----------------------------------------------------------------
    // the key a session was opened with
    // -----------------------------------------------------------------

    public function testASessionEndsWhenItsKeyIsDeleted(): void {
        [$token, $row] = $this->passkeySession();
        $this->assertSame(200, $this->me($token)->getStatusCode());

        Passkeys::delete((int) $row['id']);

        $response = $this->me($token);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Session ended: passkey no longer valid', $this->message($response));
    }

    public function testASessionEndsWhenItsKeyStopsMeetingThePolicy(): void {
        [$token] = $this->passkeySession();

        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $response = $this->me($token);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Session ended: passkey no longer valid', $this->message($response));
    }

    public function testASessionDoesNotOutliveADifferentOwnerOfItsKey(): void {
        [, $row] = $this->passkeySession();
        $token = $this->session('passkey', ['id' => 2, 'username' => 'boss', 'role' => 'manager', 'reseller_id' => 2, 'cred' => (int) $row['id']]);

        $this->assertSame(401, $this->me($token)->getStatusCode());
    }

    public function testASessionNamingNoKeyAtAllEnds(): void {
        $this->assertSame(401, $this->me($this->session('passkey', ['cred' => 99]))->getStatusCode());
    }

    public function testASessionSurvivesAnotherKeyBeingRemoved(): void {
        [$token, $row] = $this->passkeySession();
        $otherRow = $this->storedKey(new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-000000000009'));

        Passkeys::delete((int) $otherRow['id']);

        $this->assertSame(200, $this->me($token)->getStatusCode());
        $this->assertNotNull(Passkeys::find((int) $row['id']));
    }

    // -----------------------------------------------------------------
    // the requirement, checked on every request
    // -----------------------------------------------------------------

    public function testAPasswordSessionOfAKeyOnlyAccountInFullEffectIsRefused(): void {
        $this->keys(1);
        $this->requirement('passkey');

        foreach (['pwd', 'pwd+otp'] as $method) {
            $response = $this->me($this->session($method));
            $this->assertSame(403, $response->getStatusCode(), $method);
            $this->assertSame('Passkey sign-in required', $this->message($response), $method);
        }
    }

    public function testAKeyOnlyAccountOnASafeNetworkIsNotExcused(): void {
        $this->keys(1);
        $this->requirement('passkey');
        $this->from(self::SAFE);

        $this->assertSame(403, $this->me($this->session('pwd'))->getStatusCode());
    }

    public function testAKeyOnlyAccountWithoutAKeyHasNoSessionEvenWithTheCode(): void {
        $this->requirement('passkey');
        $this->withTotp();

        $response = $this->me($this->session('pwd+otp'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Passkey enrollment required', $this->message($response));
        $this->assertSame(
            ['passkey_enrollment', 1, 1, 0],
            [self::json($response)['required'], self::json($response)['passkeys_needed'], self::json($response)['passkeys_minimum'], self::json($response)['passkeys_have']]
        );
    }

    public function testAKeyOnlyAccountWithEnoughKeysKeepsItsPasskeySession(): void {
        [$token] = $this->passkeySession();
        $this->requirement('passkey');

        $this->assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testAPendingPasswordChangeEndsASession(): void {
        $token = $this->session('pwd');
        R::exec('UPDATE users SET must_change_password = 1 WHERE id = 5');

        $response = $this->me($token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Password change required', $this->message($response));
    }

    public function testASessionOwesTheSetupItsRequirementAsks(): void {
        $token = $this->session('pwd');

        $this->requirement('totp');
        $response = $this->me($token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA enrollment required', $this->message($response));

        $this->from(self::SAFE);
        $this->assertSame(200, $this->me($token)->getStatusCode(), 'a safe network skips the code');

        $this->requirement('totp_or_passkey');
        $this->withTotp();
        $response = $this->me($token);
        $this->assertSame(403, $response->getStatusCode(), 'but never the key');
        $this->assertSame('Passkey enrollment required', $this->message($response));

        $this->requirement('passkey');
        R::exec('UPDATE users SET totp_secret = NULL WHERE id = 5');
        $response = $this->me($token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Passkey enrollment required', $this->message($response));

        $this->requirement('none');
        $this->assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testTheEnrollmentAnswerOfASessionCarriesTheKeyCounter(): void {
        $token = $this->session('pwd');
        $this->requirement('passkey');

        $body = self::json($this->me($token));

        $this->assertSame('Passkey enrollment required', $body['error']);
        $this->assertSame('passkey_enrollment', $body['required']);
        $this->assertSame([1, 1, 0], [$body['passkeys_needed'], $body['passkeys_minimum'], $body['passkeys_have']]);
    }

    public function testNoCounterWhereTheAnswerIsAResetNotAnEnrollment(): void {
        $this->requirement('passkey', open: false);

        $body = self::json($this->me($this->session('pwd')));

        $this->assertSame('Ask an administrator to reset your sign-in', $body['error']);
        $this->assertArrayNotHasKey('passkeys_minimum', $body);
    }

    public function testASessionOfAnAccountWithItsEnrolmentClosedIsToldToAskForAReset(): void {
        $password = $this->session('pwd');
        $code = $this->session('pwd+otp');
        $this->withTotp();

        foreach (['passkey', 'totp_or_passkey'] as $mode) {
            $this->requirement($mode, open: false);

            $closed = $this->me($password);
            $this->assertSame(403, $closed->getStatusCode(), $mode);
            $this->assertSame('Ask an administrator to reset your sign-in', $this->message($closed), $mode);
            $this->assertSame('Passkey enrollment required', $this->message($this->me($code)), "{$mode}: the code was checked");

            $this->requirement($mode, open: true);
            $this->assertSame('Passkey enrollment required', $this->message($this->me($password)), "{$mode}: open");
        }
    }

    public function testTheLegacyFlagNoLongerEndsASession(): void {
        $token = $this->session('pwd');
        R::exec('UPDATE users SET must_enroll_mfa = 1 WHERE id = 5');

        $this->assertSame(200, $this->me($token)->getStatusCode());
    }

    public function testATokenFromBeforePasskeysIsReadByItsCodeClaim(): void {
        $this->keys(1);
        $this->requirement('passkey');
        $legacy = $this->legacyToken(['totp_verified' => true]);

        $response = $this->me($legacy);

        $this->assertSame(403, $response->getStatusCode(), 'a code does not satisfy a key-only account');
        $this->assertSame('Passkey sign-in required', $this->message($response));
    }

    // -----------------------------------------------------------------
    // the MFA gate
    // -----------------------------------------------------------------

    public function testAPasswordSessionOfAnAccountWithoutASecondFactorPassesTheGate(): void {
        $this->assertSame(200, $this->gated($this->session('pwd'))->getStatusCode());
    }

    public function testAPasswordSessionIsRefusedOnceTheAccountHasTotp(): void {
        $token = $this->session('pwd');
        $this->withTotp();

        $response = $this->gated($token);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA verification required', $this->message($response));
    }

    public function testAPasswordSessionIsRefusedOnceTheAccountHasACompliantKey(): void {
        $token = $this->session('pwd');
        $this->keys(1);

        $response = $this->gated($token);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA verification required', $this->message($response));
    }

    public function testAKeyThatNoLongerCompliesDoesNotCountAsASecondFactor(): void {
        $token = $this->session('pwd');
        $this->keys(1);
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertSame(200, $this->gated($token)->getStatusCode());
    }

    public function testASafeNetworkPassesAPasswordSessionToTheGate(): void {
        $token = $this->session('pwd');
        $this->keys(1);
        $this->withTotp();
        $this->from(self::SAFE);

        $this->assertSame(200, $this->gated($token)->getStatusCode());
    }

    public function testACodeOrAPasskeyPassesTheGateEverywhere(): void {
        $this->withTotp();
        $coded = $this->session('pwd+otp', ['totp_verified' => true]);
        [$key] = $this->keys(1);
        $keyed = self::json($this->passkey($key))['token'];

        $this->assertSame(200, $this->gated($coded)->getStatusCode());
        $this->assertSame(200, $this->gated($keyed)->getStatusCode());
    }

    public function testAnOldTokenWithItsCodeStillPassesTheGate(): void {
        $this->withTotp();
        $this->keys(1);

        $this->assertSame(200, $this->gated($this->legacyToken(['totp_verified' => true, 'has_totp' => true]))->getStatusCode());
        $this->assertSame(403, $this->gated($this->legacyToken(['has_totp' => true]))->getStatusCode());
    }

    // -----------------------------------------------------------------
    // the claims: has_passkeys and needs_second_factor
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function claims(string $token): array {
        return self::json($this->me($token));
    }

    public function testTheClaimsTellWhetherTheAccountHasACompliantKey(): void {
        $token = $this->session('pwd');
        $this->assertFalse($this->claims($token)['has_passkeys']);

        $this->keys(1);
        $this->assertTrue($this->claims($token)['has_passkeys'], 'read from the account, not from the token');

        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->assertFalse($this->claims($token)['has_passkeys'], 'a key that does not comply is no key');
    }

    public function testNeedsSecondFactorIsWhatTheGateRefusesWithAKey(): void {
        $this->keys(1);
        $token = $this->session('pwd');
        $this->assertTrue($this->claims($token)['needs_second_factor']);
        $this->assertSame(403, $this->gated($token)->getStatusCode());

        $this->from(self::SAFE);
        $this->assertFalse($this->claims($token)['needs_second_factor']);
        $this->assertSame(200, $this->gated($token)->getStatusCode());
    }

    public function testNeedsSecondFactorForTotpAndForNothingAtAll(): void {
        $token = $this->session('pwd');
        $this->assertFalse($this->claims($token)['needs_second_factor']);
        $this->assertSame(200, $this->gated($token)->getStatusCode());

        $this->withTotp();
        $this->assertTrue($this->claims($token)['needs_second_factor'], 'a code counts like a key');
        $this->assertSame(403, $this->gated($token)->getStatusCode());

        $coded = $this->session('pwd+otp', ['totp_verified' => true]);
        $this->assertFalse($this->claims($coded)['needs_second_factor'], 'the code was checked');

        $this->from(self::SAFE);
        $this->assertFalse($this->claims($token)['needs_second_factor']);
    }

    public function testASessionOpenedWithAKeyNeverNeedsMore(): void {
        [$token] = $this->passkeySession();
        $this->withTotp();

        $claims = $this->claims($token);

        $this->assertTrue($claims['has_passkeys']);
        $this->assertFalse($claims['needs_second_factor']);
    }

    public function testLoginAndRenewCarryTheClaims(): void {
        $none = self::json($this->password());
        $this->assertFalse($none['has_passkeys']);
        $this->assertFalse($none['needs_second_factor']);

        [$key] = $this->keys(1);
        $this->withTotp();
        $login = self::json($this->password(['totp' => $this->code()]));
        $this->assertTrue($login['has_passkeys']);
        $this->assertFalse($login['needs_second_factor'], 'the code was checked');

        $renewed = self::json($this->call('GET', '/v1/users/renew-token', [], $login['token']));
        $this->assertTrue($renewed['has_passkeys']);
        $this->assertFalse($renewed['needs_second_factor']);

        $this->from(self::SAFE);
        $this->assertFalse(self::json($this->password())['needs_second_factor'], 'a safe network excuses the code');

        $viaKey = self::json($this->passkey($key));
        $this->assertTrue($viaKey['has_passkeys']);
        $this->assertFalse($viaKey['needs_second_factor']);
    }

    public function testRenewingTakesTheKeysAsTheyAreNow(): void {
        $login = self::json($this->password());
        $this->keys(1);

        $renewed = self::json($this->call('GET', '/v1/users/renew-token', [], $login['token']));

        $this->assertTrue($renewed['has_passkeys']);
        $this->assertTrue($renewed['needs_second_factor']);
    }

    // -----------------------------------------------------------------
    // renewing
    // -----------------------------------------------------------------

    public function testRenewingKeepsHowTheSessionBegan(): void {
        [$token, $row] = $this->passkeySession();

        $renewed = self::json($this->call('GET', '/v1/users/renew-token', [], $token));

        $this->assertSame('passkey', $renewed['amr']);
        $this->assertSame((int) $row['id'], $renewed['cred']);
        $this->assertTrue($renewed['strong_auth']);
        $this->assertSame(200, $this->gated($renewed['token'])->getStatusCode());
    }

    public function testRenewingAPasswordSessionDoesNotUpgradeIt(): void {
        $renewed = self::json($this->call('GET', '/v1/users/renew-token', [], $this->session('pwd')));

        $this->assertSame('pwd', $renewed['amr']);
        $this->assertFalse($renewed['strong_auth']);
        $this->assertArrayNotHasKey('cred', $renewed);
    }

    public function testRenewingAPasswordAndCodeSessionKeepsItsCode(): void {
        $this->withTotp();
        $login = self::json($this->password(['totp' => $this->code()]));

        $renewed = self::json($this->call('GET', '/v1/users/renew-token', [], $login['token']));

        $this->assertSame('pwd+otp', $renewed['amr']);
        $this->assertTrue($renewed['strong_auth']);
        $this->assertTrue($renewed['totp_verified']);
    }

    public function testNothingIsRenewedOnceTheKeyIsGone(): void {
        [$token, $row] = $this->passkeySession();
        Passkeys::delete((int) $row['id']);

        $this->assertSame(401, $this->call('GET', '/v1/users/renew-token', [], $token)->getStatusCode());
    }

    // -----------------------------------------------------------------
    // remote authentication and fixed API tokens have no sign-in
    // -----------------------------------------------------------------

    public function testRemoteAuthIsUntouchedByTheRequirement(): void {
        $this->keys(1);
        $this->withTotp();
        foreach (['passkey', 'totp_or_passkey', 'totp'] as $mode) {
            $this->requirement($mode);
            R::exec('UPDATE users SET must_change_password = ? WHERE id = 5', [$mode === 'totp' ? 1 : 0]);
            Config::set('remote_auth', ['enabled' => true, 'header' => null]);

            $me = $this->call('GET', '/v1/users/me', [], null, ['REMOTE_USER' => 'alice']);
            $gated = $this->call(self::GATED[0], self::GATED[1], [], null, ['REMOTE_USER' => 'alice']);

            $this->assertSame(200, $me->getStatusCode(), $mode);
            $this->assertTrue(self::json($me)['remote_auth'], $mode);
            $this->assertArrayNotHasKey('amr', self::json($me), $mode);
            $this->assertFalse(self::json($me)['has_passkeys'], $mode);
            $this->assertFalse(self::json($me)['needs_second_factor'], $mode);
            $this->assertSame(200, $gated->getStatusCode(), $mode);
        }
    }

    public function testAFixedApiTokenIsUntouchedByTheRequirement(): void {
        // SQLite has no UNIX_TIMESTAMP(), which the token lookup calls
        @R::getDatabaseAdapter()->getDatabase()->getPDO()->sqliteCreateFunction('UNIX_TIMESTAMP', 'time', 0);
        R::exec('UPDATE users SET api_token = ? WHERE id = 5', [hash('sha256', 'automation-token')]);
        $this->keys(1);
        $this->withTotp();
        foreach (['passkey', 'totp_or_passkey', 'totp'] as $mode) {
            $this->requirement($mode);
            R::exec('UPDATE users SET must_change_password = ? WHERE id = 5', [$mode === 'totp' ? 1 : 0]);

            $me = $this->call('GET', '/v1/users/me', [], 'automation-token');
            $gated = $this->call(self::GATED[0], self::GATED[1], [], 'automation-token');

            $this->assertSame(200, $me->getStatusCode(), $mode);
            $this->assertSame(200, $gated->getStatusCode(), $mode);
            $this->assertFalse(self::json($me)['totp_verified'], $mode);
            $this->assertFalse(self::json($me)['has_passkeys'], $mode);
            $this->assertFalse(self::json($me)['needs_second_factor'], $mode);
        }
    }
}
