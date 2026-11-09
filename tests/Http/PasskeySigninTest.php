<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use RedBeanPHP\R;

/**
 * Signing in with a passkey: the options, the assertion, who is refused and
 * how, and what the token carries.
 */
final class PasskeySigninTest extends PasskeyHttpTestCase
{
    private const FAILED = 'Passkey sign-in failed';

    /** @return array{0: SoftAuthenticator, 1: array} alice's only key and its row */
    private function oneKey(): array {
        [$key] = $this->keys(1);
        return [$key, Passkeys::listForUser(5)[0]];
    }

    private function loginRows(): int {
        return (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'login'");
    }

    private function assertFailed(\Psr\Http\Message\ResponseInterface $response, string $reason): void {
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(self::FAILED, self::json($response)['error']);
        $this->assertArrayNotHasKey('token', self::json($response));
        $logged = $this->events('login_failed');
        $this->assertSame($reason, end($logged)['reason']);
        $this->assertSame('denied', end($logged)['action']);
    }

    // -----------------------------------------------------------------
    // options
    // -----------------------------------------------------------------

    public function testTheOptionsAreUsernamelessAndWriteNothing(): void {
        $response = $this->call('POST', '/v1/users/authenticate/passkey/options');
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('localhost', $body['publicKey']['rpId']);
        $this->assertSame('required', $body['publicKey']['userVerification']);
        $this->assertSame([], $body['publicKey']['allowCredentials']);
        $this->assertIsString($body['challenge_id']);
        $this->assertSame(0, $this->loginRows());
    }

    public function testAFloodOfOptionsLeavesNoTrace(): void {
        for ($i = 0; $i < 25; $i++) {
            $this->call('POST', '/v1/users/authenticate/passkey/options');
        }

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testTheOptionsAreRefusedWhilePasskeysAreOff(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $response = $this->call('POST', '/v1/users/authenticate/passkey/options');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(self::json($response)['passkeys_available']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testSigningInIsRefusedWhilePasskeysAreOff(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $response = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ]);

        $this->assertFailed($response, 'passkeys are not enabled');
    }

    // -----------------------------------------------------------------
    // a good sign-in
    // -----------------------------------------------------------------

    public function testAGoodSignInYieldsAPasskeyTokenForTheUser(): void {
        [$key, $row] = $this->oneKey();

        $response = $this->passkey($key);
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('alice', $body['username']);
        $this->assertSame('passkey', $body['amr']);
        $this->assertSame((int) $row['id'], $body['cred']);
        $this->assertTrue($body['strong_auth']);
        $this->assertFalse($body['totp_verified'], 'no code was checked');
        $this->assertFalse($body['needs_totp']);
        $this->assertFalse($body['has_totp']);
        $this->assertNotEmpty($body['token']);
        $this->assertSame(0, $this->denied());
    }

    public function testASuccessfulSignInIsRecordedAndMovesTheCounter(): void {
        [$key, $row] = $this->oneKey();

        $this->passkey($key);

        $stored = Passkeys::find((int) $row['id']);
        $this->assertSame(1, (int) $stored['sign_count']);
        $this->assertSame(self::OUTSIDE, $stored['last_used_ip']);
        $this->assertNotNull($stored['last_used_at']);
        $event = $this->events('login_succeeded')[0];
        $this->assertSame('passkey', $event['method']);
        $this->assertSame((int) $row['id'], $event['credential']);
        $this->assertSame('login', $event['action']);
    }

    public function testTheTokenWorksAsASession(): void {
        [$key] = $this->oneKey();
        $token = self::json($this->passkey($key))['token'];

        $me = $this->call('GET', '/v1/users/me', [], $token);

        $this->assertSame(200, $me->getStatusCode());
        $this->assertSame('passkey', self::json($me)['amr']);
    }

    public function testAUserWithTotpSignsInWithTheKeyAloneFromAnywhere(): void {
        [$key] = $this->oneKey();
        $this->withTotp();

        $response = $this->passkey($key);
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['has_totp']);
        $this->assertFalse($body['needs_totp']);
        $this->assertSame('passkey', $body['amr']);
    }

    // -----------------------------------------------------------------
    // refusals
    // -----------------------------------------------------------------

    public function testAnUnknownCredentialAndAWrongHandleAnswerAlike(): void {
        [$key] = $this->oneKey();
        $stranger = new SoftAuthenticator();
        $stranger->userHandle = random_bytes(32);

        $unknown = $this->passkey($stranger);
        $unknownLog = $this->events('login_failed');
        $wrongHandle = $this->passkey($key, ['userHandle' => random_bytes(32)]);

        $this->assertSame($unknown->getStatusCode(), $wrongHandle->getStatusCode());
        $this->assertSame((string) $unknown->getBody(), (string) $wrongHandle->getBody());
        $this->assertSame('unknown credential', $unknownLog[0]['reason']);
        $this->assertSame('user handle mismatch', $this->events('login_failed')[1]['reason']);
        $this->assertSame(2, $this->denied());
    }

    public function testAChallengeIsSpentEvenWhenTheCredentialIsUnknown(): void {
        $stranger = new SoftAuthenticator();
        $stranger->userHandle = random_bytes(32);
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));

        $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($stranger->assert($options['publicKey']), true),
        ]);

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'login' AND used_at IS NOT NULL"));
    }

    public function testAMissingHandleIsRefusedLikeAWrongOne(): void {
        [$key] = $this->oneKey();

        $this->assertFailed($this->passkey($key, ['userHandle' => null]), 'user handle mismatch');
    }

    public function testABadSignatureIsRefused(): void {
        [$key] = $this->oneKey();

        $this->assertFailed($this->passkey($key, ['badSignature' => true]), 'signature');
        $this->assertSame(0, (int) Passkeys::listForUser(5)[0]['sign_count']);
    }

    public function testAWrongOriginAndAMissingUserVerificationAreRefused(): void {
        [$key] = $this->oneKey();

        $this->assertFailed($this->passkey($key, ['origin' => 'https://evil.example']), 'origin');
        $this->assertFailed($this->passkey($key, ['uv' => false]), 'user_verification');
    }

    public function testAMalformedRequestIsRefused(): void {
        foreach ([[], ['challenge_id' => 1], ['challenge_id' => 1, 'credential' => 'not json']] as $body) {
            $response = $this->call('POST', '/v1/users/authenticate/passkey', $body);

            $this->assertSame(401, $response->getStatusCode());
            $this->assertSame(self::FAILED, self::json($response)['error']);
        }
        $this->assertSame(3, $this->denied());
    }

    public function testAChallengeWorksOnce(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $body = [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ];

        $this->assertSame(200, $this->call('POST', '/v1/users/authenticate/passkey', $body)->getStatusCode());
        $replay = $this->call('POST', '/v1/users/authenticate/passkey', $body);

        $this->assertFailed($replay, 'challenge unknown, spent or expired');
    }

    public function testAnExpiredChallengeIsRefused(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        WebAuthnChallenges::useClock(static fn(): int => time() + WebAuthnChallenges::LIFETIME + 1);

        $response = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ]);

        $this->assertFailed($response, 'challenge unknown, spent or expired');
    }

    public function testASpentChallengeLeavesOneRowAndNoSecondUse(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $body = [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ];

        $this->assertSame(200, $this->call('POST', '/v1/users/authenticate/passkey', $body)->getStatusCode());
        $this->call('POST', '/v1/users/authenticate/passkey', $body);

        $rows = R::getAll("SELECT purpose, user_id, used_at FROM webauthn_challenges WHERE purpose = 'login'");
        $this->assertCount(1, $rows);
        $this->assertSame('login', $rows[0]['purpose']);
        $this->assertNull($rows[0]['user_id']);
        $this->assertNotNull($rows[0]['used_at']);
    }

    public function testATamperedChallengeIdIsRefusedWithoutARow(): void {
        Config::set('login_ratelimit', ['max_failures' => 0] + Config::get('login_ratelimit'));
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        [$payload, $mac] = explode('.', $options['challenge_id']);
        $flip = static fn(string $text): string => ($text[0] === 'A' ? 'B' : 'A') . substr($text, 1);

        foreach ([$flip($payload) . '.' . $mac, $payload . '.' . $flip($mac), $payload, $mac . '.' . $payload, '.', ''] as $forged) {
            $response = $this->call('POST', '/v1/users/authenticate/passkey', [
                'challenge_id' => $forged,
                'credential'   => json_decode($key->assert($options['publicKey']), true),
            ]);
            $this->assertSame(401, $response->getStatusCode(), $forged);
        }
        $this->assertSame(0, $this->loginRows());
    }

    public function testAChallengeSignedWithAnotherKeyIsRefused(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        Config::set('jwt_psk', 'another-signing-key-entirely');

        $response = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ]);

        $this->assertFailed($response, 'challenge unknown, spent or expired');
        $this->assertSame(0, $this->loginRows());
    }

    public function testAnExpiredChallengeIdWritesNothing(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        WebAuthnChallenges::useClock(static fn(): int => time() + WebAuthnChallenges::LIFETIME + 1);

        $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ]);

        $this->assertSame(0, $this->loginRows());
    }

    public function testAnAssertionOfAnotherChallengeDoesNotSpendThisOne(): void {
        [$key] = $this->oneKey();
        $mine = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $other = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));

        $wrong = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $mine['challenge_id'],
            'credential'   => json_decode($key->assert($other['publicKey']), true),
        ]);
        $right = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $mine['challenge_id'],
            'credential'   => json_decode($key->assert($mine['publicKey']), true),
        ]);

        $this->assertSame(401, $wrong->getStatusCode());
        $this->assertSame(200, $right->getStatusCode());
    }

    public function testAChallengeForAnotherPurposeIsRefused(): void {
        [$key] = $this->oneKey();
        [$id, $challenge] = WebAuthnChallenges::issue('stepup', 5);
        $options = ['challenge' => SoftAuthenticator::encode($challenge)];

        $response = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => (string) $id,
            'credential'   => json_decode($key->assert($options), true),
        ]);

        $this->assertFailed($response, 'challenge unknown, spent or expired');
    }

    public function testAnAssertionForAnotherChallengeIdIsRefused(): void {
        [$key] = $this->oneKey();
        $mine = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $other = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));

        $response = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $other['challenge_id'],
            'credential'   => json_decode($key->assert($mine['publicKey']), true),
        ]);

        $this->assertFailed($response, 'challenge unknown, spent or expired');
    }

    public function testACounterThatDidNotAdvanceIsRefusedAndRecorded(): void {
        [$key, $row] = $this->oneKey();
        $this->assertSame(200, $this->passkey($key)->getStatusCode());
        $this->assertSame(200, $this->passkey($key)->getStatusCode());

        $response = $this->passkey($key, ['signCount' => 1]);

        $this->assertFailed($response, 'counter_regression');
        $event = $this->events('passkey_counter_regression');
        $this->assertCount(1, $event);
        $this->assertSame((int) $row['id'], $event[0]['credential']);
        $this->assertSame('secread', $event[0]['action']);
        $this->assertSame(2, (int) Passkeys::find((int) $row['id'])['sign_count']);
    }

    public function testASyncedKeyThatAlwaysCountsZeroIsAccepted(): void {
        $key = new SoftAuthenticator();
        $key->countsSigns = false;
        $this->storedKey($key);

        $this->assertSame(200, $this->passkey($key)->getStatusCode());
        $this->assertSame(200, $this->passkey($key)->getStatusCode());
    }

    // -----------------------------------------------------------------
    // who may sign in
    // -----------------------------------------------------------------

    public function testAnInactiveUserIsRefusedLikeAnUnknownCredential(): void {
        [$key] = $this->oneKey();
        R::exec('UPDATE users SET active = 0 WHERE id = 5');

        $this->assertFailed($this->passkey($key), 'credential of an inactive user');
    }

    public function testADeactivatedResellerIsToldSoOnlyOnceTheKeyVerified(): void {
        [$key] = $this->oneKey();
        R::exec('UPDATE resellers SET active = 0 WHERE id = 2');

        $bad = $this->passkey($key, ['badSignature' => true]);
        $good = $this->passkey($key);

        $this->assertSame(401, $bad->getStatusCode());
        $this->assertSame(403, $good->getStatusCode());
        $this->assertSame('Your reseller account is deactivated', self::json($good)['error']);
        $this->assertArrayNotHasKey('token', self::json($good));
        $reasons = array_column($this->events('login_failed'), 'reason');
        $this->assertSame(['signature', 'reseller deactivated'], $reasons);
    }

    public function testAPendingPasswordChangeBlocksPasskeySignInToo(): void {
        [$key] = $this->oneKey();
        R::exec('UPDATE users SET must_change_password = 1 WHERE id = 5');

        $response = $this->passkey($key);
        $body = self::json($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('password_change', $body['required']);
        $this->assertArrayHasKey('password_policy', $body);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertSame(0, $this->denied(), 'a pending requirement is not a failure');
        $this->assertSame('password_change', $this->events('login_requirement_pending')[0]['required']);
    }

    public function testAKeyOnlyAccountIsTurnedAwayFromThePasswordOnlyOnceItIsRight(): void {
        $this->keys(1);
        $this->requirement('passkey');

        $wrong = $this->password(['password' => 'wrong']);
        $right = $this->password();

        $this->assertSame(401, $wrong->getStatusCode(), 'a guess learns nothing about the account');
        $this->assertSame(403, $right->getStatusCode());
        $this->assertSame(
            ['error' => 'This account signs in with a passkey', 'required' => 'passkey'],
            self::json($right)
        );
        $this->assertSame(1, $this->denied(), 'only the wrong password counts');
        $pending = $this->events('login_requirement_pending');
        $this->assertSame(['passkey', 'secread'], [$pending[0]['required'], $pending[0]['action']]);
    }

    public function testAKeyOnlyAccountCanStillChangeAPendingPasswordAndThenUsesItsKey(): void {
        [$key] = $this->keys(1);
        $this->requirement('passkey');
        R::exec('UPDATE users SET must_change_password = 1 WHERE id = 5');

        $pending = $this->password();
        $this->assertSame('password_change', self::json($pending)['required']);

        $changed = $this->call('POST', '/v1/users/authenticate/password', [
            'username' => 'alice', 'password' => self::PASSWORD, 'new_password' => 'Fr3sh-Enough-Pw!',
        ]);
        $this->assertSame(403, $changed->getStatusCode());
        $this->assertSame('passkey', self::json($changed)['required']);
        $this->assertSame(0, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 5'));
        $this->assertTrue(password_verify('Fr3sh-Enough-Pw!', (string) R::getCell('SELECT password FROM users WHERE id = 5')));

        $this->assertSame(200, $this->passkey($key)->getStatusCode());
    }

    public function testAKeyThatNoLongerMeetsThePolicyIsRefusedAtSignIn(): void {
        [$key] = $this->oneKey();
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertFailed($this->passkey($key), 'key does not meet the passkey policy');
    }

    // -----------------------------------------------------------------
    // the rate limit
    // -----------------------------------------------------------------

    public function testFailuresCountAndBlockBothStepsBeforeAnythingIsVerified(): void {
        [$key] = $this->oneKey();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $this->passkey($key, ['badSignature' => true])->getStatusCode());
        }
        $this->assertSame(3, $this->denied());
        $challenges = (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges');

        $options = $this->call('POST', '/v1/users/authenticate/passkey/options');
        $signIn = $this->call('POST', '/v1/users/authenticate/passkey', ['challenge_id' => 1, 'credential' => []]);

        $this->assertSame(429, $options->getStatusCode());
        $this->assertSame(429, $signIn->getStatusCode());
        $this->assertNotEmpty($signIn->getHeaderLine('Retry-After'));
        $this->assertSame($challenges, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'), 'no challenge for a blocked network');
        $this->assertSame(0, (int) Passkeys::listForUser(5)[0]['sign_count']);
        $this->assertSame(3, $this->denied(), 'a block is not counted again');
        $this->assertCount(2, $this->events('login_blocked'));
    }

    public function testAGoodKeyIsNotLetInOnceTheNetworkIsBlocked(): void {
        [$key] = $this->oneKey();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        for ($i = 0; $i < 3; $i++) {
            $this->password(['password' => 'wrong']);
        }

        $response = $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey']), true),
        ]);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(0, (int) Passkeys::listForUser(5)[0]['sign_count']);
    }

    public function testPasswordFailuresAndPasskeyFailuresShareOneBudget(): void {
        [$key] = $this->oneKey();
        $this->password(['password' => 'wrong']);
        $this->passkey($key, ['badSignature' => true]);
        $this->passkey($key, ['badSignature' => true]);

        $this->assertSame(429, $this->password()->getStatusCode());
        $this->assertSame(429, $this->call('POST', '/v1/users/authenticate/passkey/options')->getStatusCode());
    }
}
