<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\StepUp;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * The proof asked before a key is added or removed, and before a key-only
 * account loses its authenticator app: a passkey where the user has one, else
 * a TOTP code, else the password. Tried here on removing key 1 of alice, whose
 * requirement leaves that allowed.
 */
final class PasskeyStepUpTest extends PasskeyHttpTestCase
{
    /** @var SoftAuthenticator[] alice's keys, ids 1 and 2 */
    private array $keys = [];

    protected function setUp(): void {
        parent::setUp();
        $this->account(7, 'user', 2, 'bob');
    }

    private function token(): string {
        return $this->sessionOf(5, 'pwd');
    }

    private function attempt(mixed $proof, ?string $token = null, int $pid = 1): ResponseInterface {
        return $this->call('DELETE', "/v1/users/5/passkeys/{$pid}", ['stepup' => $proof], $token ?? $this->token());
    }

    private function unlimited(): void {
        Config::set('login_ratelimit', ['max_failures' => 0] + Config::get('login_ratelimit'));
    }

    private function assertRefused(ResponseInterface $response, string $method, string $reason): void {
        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('Step-up verification failed', self::json($response)['error']);
        $this->assertSame($method, self::json($response)['stepup']);
        $this->assertNotNull(Passkeys::find(1), 'nothing was removed');
        $logged = $this->events('stepup_failed');
        $this->assertSame($reason, end($logged)['reason']);
        $this->assertSame($method, end($logged)['method']);
        $this->assertSame('denied', end($logged)['action']);
    }

    // -----------------------------------------------------------------
    // what is asked
    // -----------------------------------------------------------------

    public function testTheStrongestFactorDecides(): void {
        $user = fn(): array => R::getRow('SELECT * FROM users WHERE id = 5');
        $this->assertSame(StepUp::PASSWORD, StepUp::method($user()));

        $this->withTotp();
        $this->assertSame(StepUp::TOTP, StepUp::method($user()));

        $this->keys(1);
        $this->assertSame(StepUp::PASSKEY, StepUp::method($user()));
    }

    public function testAKeyThatBreaksThePolicyOrPasskeysBeingOffLeaveTheCodeOrPassword(): void {
        $this->keys(1);
        $this->withTotp();
        $user = fn(): array => R::getRow('SELECT * FROM users WHERE id = 5');

        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->assertSame(StepUp::TOTP, StepUp::method($user()));

        Config::set('passkeys', ['type' => 'any', 'rp_id' => '', 'origins' => []] + Config::get('passkeys'));
        $this->assertSame(StepUp::TOTP, StepUp::method($user()));
        R::exec('UPDATE users SET totp_secret = NULL WHERE id = 5');
        $this->assertSame(StepUp::PASSWORD, StepUp::method($user()));
    }

    public function testThePasskeyChallengeNamesOnlyTheUsersCompliantKeysAndIsBoundToThem(): void {
        $this->keys(2);
        R::exec('UPDATE passkeys SET attestation_verified = 1, backup_eligible = 0 WHERE id = 1');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $response = $this->call('POST', '/v1/users/5/passkeys/stepup', [], $this->token());
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('passkey', $body['stepup']);
        $this->assertCount(1, $body['publicKey']['allowCredentials']);
        $this->assertSame(
            rtrim(strtr(base64_encode(Passkeys::find(1)['credential_id']), '+/', '-_'), '='),
            $body['publicKey']['allowCredentials'][0]['id']
        );
        $row = R::getRow('SELECT purpose, user_id FROM webauthn_challenges WHERE id = ?', [$body['challenge_id']]);
        $this->assertSame(['stepup', 5], [$row['purpose'], (int) $row['user_id']]);
    }

    public function testWithoutAKeyThereIsNoPasskeyChallengeAndTheAnswerSaysWhatProvesIt(): void {
        $this->withTotp();

        $response = $this->call('POST', '/v1/users/5/passkeys/stepup', [], $this->token());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('totp', self::json($response)['stepup']);
    }

    public function testOnlyTheUserAsksForTheirChallenge(): void {
        $this->keys(1);

        foreach ([$this->managerSession(), $this->adminSession(), $this->sessionOf(7, 'pwd')] as $token) {
            $this->assertSame(403, $this->call('POST', '/v1/users/5/passkeys/stepup', [], $token)->getStatusCode());
        }
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'stepup'"));
    }

    // -----------------------------------------------------------------
    // with a passkey
    // -----------------------------------------------------------------

    public function testAFreshAssertionOfTheUsersKeyIsAccepted(): void {
        [$key] = $this->keys(2);
        $token = $this->token();

        $response = $this->attempt($this->stepupProof($key, $token), $token, 2);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $used = Passkeys::find(1);
        $this->assertSame(1, (int) $used['sign_count']);
        $this->assertNotNull($used['last_used_at']);
        $this->assertSame(0, $this->denied());
    }

    public function testAnAssertionSpentOnceIsNotGoodTwice(): void {
        [$key] = $this->keys(3);
        $token = $this->token();
        $proof = $this->stepupProof($key, $token);

        $this->assertSame(200, $this->attempt($proof, $token, 2)->getStatusCode());
        $replay = $this->attempt($proof, $token, 3);

        $this->assertSame(401, $replay->getStatusCode());
        $this->assertNotNull(Passkeys::find(3));
        $logged = $this->events('stepup_failed');
        $this->assertSame('challenge unknown, spent or expired', end($logged)['reason']);
    }

    public function testAnotherUsersKeyOnTheUsersChallengeIsRefused(): void {
        $this->keys(1);
        $bob = new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-0000000000b0');
        $this->storedKey($bob, [], 7);
        $token = $this->token();

        $proof = $this->stepupProof($bob, $token);

        $this->assertRefused($this->attempt($proof, $token), 'passkey', 'credential of another user');
        $this->assertSame(0, (int) Passkeys::listForUser(7)[0]['sign_count'], 'bob\'s key was not used');
    }

    public function testAnotherUsersChallengeAnsweredWithTheUsersKeyIsRefused(): void {
        [$key] = $this->keys(1);
        $bob = new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-0000000000b0');
        $this->storedKey($bob, [], 7);

        $bobsToken = $this->sessionOf(7, 'pwd');
        $options = self::json($this->call('POST', '/v1/users/7/passkeys/stepup', [], $bobsToken));
        $proof = ['challenge_id' => $options['challenge_id'], 'credential' => json_decode($key->assert($options['publicKey']), true)];

        $this->assertRefused($this->attempt($proof), 'passkey', 'challenge unknown, spent or expired');
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'stepup' AND used_at IS NULL"), 'bob\'s challenge is still his');
    }

    public function testAnExpiredChallengeIsRefused(): void {
        [$key] = $this->keys(1);
        $token = $this->token();
        $proof = $this->stepupProof($key, $token);
        WebAuthnChallenges::useClock(static fn(): int => time() + WebAuthnChallenges::LIFETIME + 1);

        $this->assertRefused($this->attempt($proof, $token), 'passkey', 'challenge unknown, spent or expired');
    }

    public function testALoginOrRegistrationChallengeIsNotAStepUp(): void {
        [$key] = $this->keys(1);
        [$id, $challenge] = WebAuthnChallenges::issue('register', 5);
        $options = ['challenge' => SoftAuthenticator::encode($challenge)];

        $proof = ['challenge_id' => $id, 'credential' => json_decode($key->assert($options), true)];

        $this->assertRefused($this->attempt($proof), 'passkey', 'challenge unknown, spent or expired');
    }

    public function testForgedAssertionsAreRefused(): void {
        $this->unlimited();
        [$key] = $this->keys(1);
        $token = $this->token();

        foreach ([
            'origin'            => [['origin' => 'https://evil.example'], 'origin'],
            'user_verification' => [['uv' => false], 'user_verification'],
            'signature'         => [['badSignature' => true], 'signature'],
            'rp_id_hash'        => [['rpId' => 'example.test'], 'rp_id_hash'],
        ] as $name => [$forge, $reason]) {
            $this->assertRefused($this->attempt($this->stepupProof($key, $token, 5, $forge), $token), 'passkey', $reason);
        }
        $this->assertSame(0, (int) Passkeys::find(1)['sign_count']);
    }

    public function testACounterThatDidNotAdvanceIsRefusedAndRecorded(): void {
        [$key] = $this->keys(2);
        $token = $this->token();
        $this->assertSame(200, $this->attempt($this->stepupProof($key, $token), $token, 2)->getStatusCode());

        $response = $this->attempt($this->stepupProof($key, $token, 5, ['signCount' => 1]), $token);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(1, count($this->events('passkey_counter_regression')));
    }

    public function testThereIsNoChallengeWithoutASession(): void {
        $this->keys(1);

        $this->assertSame(401, $this->call('POST', '/v1/users/5/passkeys/stepup')->getStatusCode());
    }

    // -----------------------------------------------------------------
    // with a code, with the password
    // -----------------------------------------------------------------

    public function testTheCodeProvesItForATotpUserWithoutAKey(): void {
        $this->keys(1);
        $this->withTotp();
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertSame(200, $this->attempt(['totp' => $this->code()])->getStatusCode());
    }

    public function testAWrongOrMissingCodeIsRefusedAndCounted(): void {
        $this->unlimited();
        $this->keys(1);
        $this->withTotp();
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        foreach ([['totp' => '000000'], ['totp' => ''], [], ['password' => self::PASSWORD]] as $proof) {
            $this->assertRefused($this->attempt($proof), 'totp', $proof === ['totp' => '000000'] ? 'wrong code' : 'no code');
        }
        $this->assertSame(4, $this->denied());
    }

    public function testThePasswordProvesItForAUserWithNeitherKeyNorCode(): void {
        $this->keys(1);
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertRefused($this->attempt(['password' => 'wrong']), 'password', 'wrong password');
        $this->assertSame(200, $this->attempt(['password' => self::PASSWORD])->getStatusCode());
    }

    public function testAKeyIsNotEnoughWhenTheUserHasOneButTheProofIsAPassword(): void {
        $this->keys(1);

        $this->assertRefused($this->attempt(['password' => self::PASSWORD]), 'passkey', 'no assertion');
    }

    // -----------------------------------------------------------------
    // a failure counts like a failed sign-in
    // -----------------------------------------------------------------

    public function testFailedStepUpsAreCountedAndBlockTheNetwork(): void {
        [$key] = $this->keys(2);
        $token = $this->token();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $this->attempt([])->getStatusCode());
        }

        $this->assertSame(3, $this->denied());
        $blocked = $this->attempt($this->stepupProof($key, $token), $token, 2);
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertNotNull(Passkeys::find(2));
        $this->assertArrayHasKey('retry_after', self::json($blocked));
    }

    public function testASuccessfulStepUpCountsNothing(): void {
        [$key] = $this->keys(3);
        $token = $this->token();

        $this->attempt($this->stepupProof($key, $token), $token, 2);

        $this->assertSame(0, $this->denied());
        $this->assertSame([], $this->events('stepup_failed'));
    }

    // -----------------------------------------------------------------
    // taking the authenticator app away from a key-only account
    // -----------------------------------------------------------------

    private function removeTotp(string $token, mixed $stepup = null, int $id = 5): ResponseInterface {
        return $this->call('DELETE', "/v1/users/{$id}/totp", $stepup === null ? [] : ['stepup' => $stepup], $token);
    }

    public function testAKeyOnlyAccountConfirmsRemovingItsOwnCodeWithTheKey(): void {
        [$key] = $this->keys(1);
        $this->withTotp();
        $this->requirement('passkey');
        $token = $this->session('passkey', ['cred' => 1]);

        $without = $this->removeTotp($token);
        $this->assertSame(401, $without->getStatusCode());
        $this->assertSame('passkey', self::json($without)['stepup']);
        $this->assertNotNull(R::getCell('SELECT totp_secret FROM users WHERE id = 5'));

        $with = $this->removeTotp($token, $this->stepupProof($key, $token));
        $this->assertSame(200, $with->getStatusCode(), (string) $with->getBody());
        $this->assertNull(R::getCell('SELECT totp_secret FROM users WHERE id = 5'));
    }

    public function testOtherRequirementsAndOtherPeopleNeedNoStepUp(): void {
        $this->keys(1);
        $this->withTotp();
        foreach (['totp', 'totp_or_passkey', 'none'] as $mode) {
            $this->requirement($mode);
            $this->withTotp();
            $this->assertSame(200, $this->removeTotp($this->sessionOf(5, 'pwd+otp'))->getStatusCode(), $mode);
        }

        $this->withTotp();
        $this->requirement('passkey');
        $this->assertSame(200, $this->removeTotp($this->adminSession())->getStatusCode(), 'an admin acting for the user');
        $this->withTotp();
        $this->assertSame(200, $this->removeTotp($this->managerSession())->getStatusCode(), 'their manager');
    }

    public function testAFixedTokenHasNoSessionToConfirm(): void {
        @R::getDatabaseAdapter()->getDatabase()->getPDO()->sqliteCreateFunction('UNIX_TIMESTAMP', 'time', 0);
        R::exec('UPDATE users SET api_token = ? WHERE id = 5', [hash('sha256', 'automation-token')]);
        $this->withTotp();
        $this->requirement('passkey');

        $this->assertSame(200, $this->removeTotp('automation-token')->getStatusCode());
    }
}
