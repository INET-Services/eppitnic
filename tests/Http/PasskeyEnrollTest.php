<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * Enrolling the first passkey(s) with the password, before there is a session:
 * only while the sign-in requirement asks for one.
 */
final class PasskeyEnrollTest extends PasskeyHttpTestCase
{
    private const PATH = '/v1/users/authenticate/passkey/enroll';

    private function creds(array $more = []): array {
        return array_replace(['username' => 'alice', 'password' => self::PASSWORD], $more);
    }

    /** @return array<string, mixed> the options answer */
    private function start(array $more = []): array {
        $response = $this->call('POST', self::PATH, $this->creds($more));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return self::json($response);
    }

    private function finish(SoftAuthenticator $key, array $options, array $more = [], array $forge = []): ResponseInterface {
        return $this->call('PUT', self::PATH, $this->creds([
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey'], $forge), true),
        ] + $more));
    }

    public function testTheOptionsAskForAResidentUserVerifyingKeyWithoutAttestation(): void {
        $this->requirement('passkey');

        $options = $this->start();

        $this->assertSame('localhost', $options['publicKey']['rp']['id']);
        $this->assertSame('alice', $options['publicKey']['user']['name']);
        $this->assertSame('none', $options['publicKey']['attestation']);
        $this->assertSame('required', $options['publicKey']['authenticatorSelection']['userVerification']);
        $this->assertSame('required', $options['publicKey']['authenticatorSelection']['residentKey']);
        $this->assertSame([], $options['publicKey']['excludeCredentials']);
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'register' AND user_id = 5"));
    }

    public function testOneKeyCompletesTheEnrolmentAndSignsTheUserIn(): void {
        $this->requirement('passkey');
        $key = new SoftAuthenticator();

        $response = $this->finish($key, $this->start(), ['name' => 'Laptop']);
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = Passkeys::listForUser(5)[0];
        $this->assertSame('Laptop', $row['name']);
        $this->assertSame('passkey', $body['amr']);
        $this->assertSame((int) $row['id'], $body['cred']);
        $this->assertTrue($body['strong_auth']);
        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $body['token'])->getStatusCode());

        $this->assertSame((int) $row['id'], $this->events('passkey_registered')[0]['credential']);
        $this->assertSame('passkey', $this->events('login_succeeded')[0]['method']);
        $history = R::getRow("SELECT user_id, data FROM history WHERE object = 'users' AND object_id = 5 AND action = 'update'");
        $this->assertSame(5, (int) $history['user_id']);
        $this->assertSame((int) $row['id'], json_decode($history['data'], true)['passkey_registered']['id']);
        $this->assertSame(0, $this->denied());
    }

    public function testTheKeyGetsADefaultNameAndTheSessionNeedsNoPasswordAfterwards(): void {
        $this->requirement('passkey');

        $this->finish(new SoftAuthenticator(), $this->start());

        $this->assertSame('Passkey 1', Passkeys::listForUser(5)[0]['name']);
        $this->assertSame(403, $this->password()->getStatusCode(), 'the password no longer signs in');
    }

    public function testAChallengeWorksOnce(): void {
        $this->requirement('passkey');
        $key = new SoftAuthenticator();
        $options = $this->start();
        $this->assertSame(200, $this->finish($key, $options)->getStatusCode());
        R::exec('DELETE FROM passkeys');
        $this->requirement('passkey');

        $replay = $this->finish($key, $options);

        $this->assertSame(400, $replay->getStatusCode());
        $this->assertSame('challenge', self::json($replay)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAChallengeMadeForAnotherUserIsRefused(): void {
        $this->requirement('passkey');
        $options = $this->start();
        [$foreign, $challenge] = WebAuthnChallenges::issue('register', 2);
        $key = new SoftAuthenticator();
        $options['challenge_id'] = $foreign;
        $options['publicKey']['challenge'] = SoftAuthenticator::encode($challenge);

        $response = $this->finish($key, $options);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('challenge', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAnExpiredChallengeIsRefused(): void {
        $this->requirement('passkey');
        $options = $this->start();
        WebAuthnChallenges::useClock(static fn(): int => time() + WebAuthnChallenges::LIFETIME + 1);

        $response = $this->finish(new SoftAuthenticator(), $options);

        $this->assertSame('challenge', self::json($response)['reason']);
    }

    public function testARegistrationFromAnotherOriginIsRefusedAndLogged(): void {
        $this->requirement('passkey');

        $response = $this->finish(new SoftAuthenticator(), $this->start(), [], ['origin' => 'https://evil.example']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('origin', self::json($response)['reason']);
        $this->assertSame('origin', $this->events('passkey_registration_failed')[0]['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
        $this->assertSame(0, $this->denied(), 'the password was right');
    }

    public function testTheSameKeyCannotBeRegisteredTwice(): void {
        $this->requirement('passkey');
        $key = new SoftAuthenticator();
        $this->storedKey($key, [], 2);

        $again = $this->finish($key, $this->start());

        $this->assertSame(400, $again->getStatusCode());
        $this->assertSame('This passkey is already registered', self::json($again)['error']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAKeyThatBreaksThePolicyIsNotStored(): void {
        $this->requirement('passkey');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->metadata();

        $options = $this->start();
        $response = $this->finish(new SoftAuthenticator(), $options);

        $this->assertSame('direct', $options['publicKey']['attestation']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testATooLongNameIsRefused(): void {
        $this->requirement('passkey');

        $response = $this->finish(new SoftAuthenticator(), $this->start(), ['name' => str_repeat('n', 65)]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAMalformedFinishIsRefusedWithoutAKey(): void {
        $this->requirement('passkey');

        foreach ([[], ['challenge_id' => 1], ['challenge_id' => 1, 'credential' => 'x']] as $more) {
            $response = $this->call('PUT', self::PATH, $this->creds($more));
            $this->assertSame(400, $response->getStatusCode());
        }
        $this->assertSame([], Passkeys::listForUser(5));
    }

    // -----------------------------------------------------------------
    // only while one is due
    // -----------------------------------------------------------------

    public function testNothingToEnrolForAUserWhoseRequirementAsksForNoKey(): void {
        foreach (['none', 'totp'] as $mode) {
            $this->requirement($mode);
            $this->withTotp();

            $this->assertSame(400, $this->call('POST', self::PATH, $this->creds(['totp' => $this->code()]))->getStatusCode());
        }
    }

    public function testAPasswordChangeComesFirst(): void {
        $this->requirement('passkey');
        R::exec('UPDATE users SET must_change_password = 1 WHERE id = 5');

        $this->assertSame(400, $this->call('POST', self::PATH, $this->creds())->getStatusCode());
    }

    public function testAUserWhoHasAllTheirKeysSignsInWithThemInstead(): void {
        $this->keys(1);
        $this->requirement('passkey');

        $response = $this->call('POST', self::PATH, $this->creds());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('passkey', self::json($response)['required']);
    }

    public function testATotpUserGivesTheirCodeOutsideTheSafeNetworks(): void {
        $this->requirement('totp_or_passkey');
        $this->withTotp();

        $without = $this->call('POST', self::PATH, $this->creds());
        $with = $this->call('POST', self::PATH, $this->creds(['totp' => $this->code()]));

        $this->assertSame(401, $without->getStatusCode());
        $this->assertSame('MFA code required', self::json($without)['error']);
        $this->assertSame(200, $with->getStatusCode());
    }

    public function testASafeNetworkSkipsTheCodeButNotTheKey(): void {
        $this->requirement('totp_or_passkey');
        $this->withTotp();
        $this->from(self::SAFE);

        $login = $this->password();
        $this->assertSame('passkey_enrollment', self::json($login)['required']);

        $done = $this->finish(new SoftAuthenticator(), $this->start());
        $this->assertSame(200, $done->getStatusCode(), (string) $done->getBody());
        $this->assertSame('passkey', self::json($done)['amr']);
    }

    public function testTotpComesBeforeTheKeyForAUserWithNeither(): void {
        $this->requirement('totp_or_passkey');

        $login = $this->password();

        $this->assertSame('mfa_enrollment', self::json($login)['required']);
        $this->assertSame(400, $this->call('POST', self::PATH, $this->creds())->getStatusCode(), 'the key waits for the code');
    }

    public function testAWrongPasswordIsCountedAndRefused(): void {
        $this->requirement('passkey');

        $response = $this->call('POST', self::PATH, $this->creds(['password' => 'wrong']));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(1, $this->denied());
    }

    public function testTheRateLimitStopsEnrolmentToo(): void {
        $this->requirement('passkey');
        for ($i = 0; $i < 3; $i++) {
            $this->call('POST', self::PATH, $this->creds(['password' => 'wrong']));
        }

        $this->assertSame(429, $this->call('POST', self::PATH, $this->creds())->getStatusCode());
        $this->assertSame(429, $this->call('PUT', self::PATH, $this->creds())->getStatusCode());
    }

    // -----------------------------------------------------------------
    // passkeys switched off
    // -----------------------------------------------------------------

    private function switchOff(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));
    }

    public function testNoEnrolmentWhilePasskeysAreOff(): void {
        $this->requirement('passkey');
        $this->switchOff();

        $response = $this->call('POST', self::PATH, $this->creds());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(self::json($response)['passkeys_available']);
        $this->assertSame(400, $this->call('PUT', self::PATH, $this->creds())->getStatusCode());
    }

    public function testAKeyRequiringUserIsToldNoKeyCanBeMadeAndGetsNoToken(): void {
        foreach (['passkey', 'totp_or_passkey'] as $mode) {
            $this->requirement($mode);
            $this->withTotp();
            $this->from(self::SAFE);
            $this->switchOff();
            if ($mode === 'passkey') {
                R::exec('UPDATE users SET totp_secret = NULL WHERE id = 5');
            }

            $response = $this->password();
            $body = self::json($response);

            $this->assertSame(403, $response->getStatusCode(), $mode);
            $this->assertSame('passkey_enrollment', $body['required'], $mode);
            $this->assertFalse($body['passkeys_available'], $mode);
            $this->assertArrayNotHasKey('token', $body);
        }
    }

    public function testAUserWithKeysIsNotLetInByPasswordEitherWhilePasskeysAreOff(): void {
        $this->keys(1);
        $this->requirement('passkey');
        $this->switchOff();

        $response = $this->password();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('passkey_enrollment', self::json($response)['required']);
        $this->assertFalse(self::json($response)['passkeys_available']);
    }

    public function testAKeyOnlyUserWithTotpAndNoKeyGetsNoTokenWhilePasskeysAreOff(): void {
        $this->requirement('passkey');
        $this->withTotp();
        $this->switchOff();

        $response = $this->password(['totp' => $this->code()]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('passkey_enrollment', self::json($response)['required']);
        $this->assertFalse(self::json($response)['passkeys_available']);
    }

    // -----------------------------------------------------------------
    // the password alone registers keys only while the enrolment is open
    // -----------------------------------------------------------------

    private function assertAsksForAReset(ResponseInterface $response): void {
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('signin_reset', self::json($response)['required']);
        $this->assertSame('Ask an administrator to reset your sign-in', self::json($response)['error']);
        $this->assertArrayNotHasKey('token', self::json($response));
    }

    public function testAClosedEnrolmentRefusesThePasswordAloneAndSaysWhy(): void {
        $this->requirement('passkey', open: false);

        $login = $this->password();
        $start = $this->call('POST', self::PATH, $this->creds());

        $this->assertAsksForAReset($login);
        $this->assertAsksForAReset($start);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'register'"));
        $this->assertSame(0, $this->denied(), 'the password was right');
    }

    public function testASafeNetworkPasswordIsNotASecondFactor(): void {
        $this->requirement('passkey', open: false);
        $this->withTotp();
        $this->from(self::SAFE);

        $this->assertAsksForAReset($this->password());
        $this->assertAsksForAReset($this->call('POST', self::PATH, $this->creds()));
    }

    public function testKeysRevokedBySomeoneElseDoNotOpenThePasswordToRegisterNewOnes(): void {
        $this->keys(1);
        $this->requirement('passkey', open: false);
        R::exec('DELETE FROM passkeys');

        $this->assertAsksForAReset($this->password());
    }

    public function testAVerifiedCodeLetsTheClosedEnrolmentThrough(): void {
        $this->requirement('passkey', open: false);
        $this->withTotp();

        $options = $this->start(['totp' => $this->code()]);

        $this->assertSame('pwd+otp', WebAuthnChallenges::contextOf((int) $options['challenge_id']));
    }

    public function testAnOpenEnrolmentRecordsThatNoCodeWasChecked(): void {
        $this->requirement('passkey', open: true);

        $options = $this->start();

        $this->assertSame('pwd', WebAuthnChallenges::contextOf((int) $options['challenge_id']));
    }

    public function testTheFinishNeedsNoFreshCodeAndTheTokenIsAPasskeyOne(): void {
        $this->requirement('passkey', open: false);
        $this->withTotp();
        $key = new SoftAuthenticator();
        $options = $this->start(['totp' => $this->code()]);

        $response = $this->finish($key, $options);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('passkey', self::json($response)['amr']);
        $this->assertCount(1, Passkeys::listForUser(5));
        $this->assertSame(0, $this->denied());
    }

    public function testTheFinishStillWantsThePassword(): void {
        $this->requirement('passkey');
        $key = new SoftAuthenticator();
        $options = $this->start();

        $response = $this->finish($key, $options, ['password' => 'wrong']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], Passkeys::listForUser(5));
        $this->assertSame(1, $this->denied());
    }

    public function testTheFinishCannotRunOnAChallengeFromAPasswordOnlyStartOnceTheEnrolmentCloses(): void {
        $this->requirement('passkey');
        $key = new SoftAuthenticator();
        $options = $this->start();
        R::exec('UPDATE users SET passkey_enroll_open = 0 WHERE id = 5');

        $this->assertAsksForAReset($this->finish($key, $options));
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAChallengeFromAnOpenEnrolmentIsNoProofOfACode(): void {
        $this->requirement('passkey');
        $options = $this->start();
        $this->assertSame('pwd', WebAuthnChallenges::contextOf((int) $options['challenge_id']));

        // the user later gets TOTP and the enrolment closes: the old challenge proves nothing
        $this->withTotp();
        R::exec('UPDATE users SET passkey_enroll_open = 0 WHERE id = 5');

        $this->assertAsksForAReset($this->finish(new SoftAuthenticator(), $options));
    }

    public function testTheEnrolmentClosesWhenTheMinimumIsReached(): void {
        $this->requirement('passkey');
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));

        $this->finish(new SoftAuthenticator(), $this->start());
        $this->assertSame(0, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
        $row = R::getRow("SELECT user_id, data FROM history WHERE object = 'users' AND object_id = 5 AND data LIKE '%passkey_enroll_open%'");
        $this->assertSame(5, (int) $row['user_id']);
        $this->assertSame(0, json_decode($row['data'], true)['passkey_enroll_open']);
    }

    public function testACodeEnrolledWithThePasswordAloneOpensNothing(): void {
        $this->requirement('totp_or_passkey', open: false);
        $secret = self::json($this->call('POST', '/v1/users/authenticate/mfa', $this->creds()))['secret'];

        $done = $this->call('PUT', '/v1/users/authenticate/mfa', $this->creds([
            'totp' => \OTPHP\TOTP::createFromSecret($secret)->now(),
        ]));

        $this->assertAsksForAReset($done);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'register'"));
    }

    public function testTheSameEnrolmentOnAnOpenAccountGoesOnToTheKey(): void {
        $this->requirement('totp_or_passkey', open: true);
        $secret = self::json($this->call('POST', '/v1/users/authenticate/mfa', $this->creds()))['secret'];

        $done = $this->call('PUT', '/v1/users/authenticate/mfa', $this->creds([
            'totp' => \OTPHP\TOTP::createFromSecret($secret)->now(),
        ]));

        $this->assertSame(403, $done->getStatusCode());
        $this->assertSame('passkey_enrollment', self::json($done)['required']);
    }

    public function testAKeyMadeWhileAnOpenEnrolmentWasNotNeededLeavesItClosed(): void {
        $this->requirement('totp_or_passkey', open: true);
        $this->withTotp();
        $this->from(self::SAFE);

        $this->finish(new SoftAuthenticator(), $this->start());

        $this->assertSame(0, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
    }
}
