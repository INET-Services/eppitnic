<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnAssertion;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Service\WebAuthnFailure;
use Eppitnic\Tests\Support\PasskeyTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * The assertion ceremony, for usernameless sign-in and for a known user.
 */
final class WebAuthnAssertionTest extends PasskeyTestCase
{
    /** @return array{0: SoftAuthenticator, 1: array} a registered key and its row */
    private function key(array $registration = []): array {
        $key = new SoftAuthenticator();
        return [$key, $this->storedKey($key, $registration)];
    }

    /** @return string the reason the assertion was refused for */
    private function refusal(SoftAuthenticator $key, array $row, array $forge, ?string $expectedHandle = null): string {
        [$options, $challenge] = $this->loginOptions();
        try {
            WebAuthn::verifyAssertion($key->assert($options, $forge), $challenge, $row, $expectedHandle);
        } catch (WebAuthnFailure $e) {
            return $e->reason;
        }
        $this->fail('the assertion was accepted');
    }

    // -----------------------------------------------------------------
    // the options
    // -----------------------------------------------------------------

    public function testRequestOptionsAreUsernamelessAndDemandUserVerification(): void {
        [$options] = $this->loginOptions();

        $this->assertSame('localhost', $options['rpId']);
        $this->assertSame('required', $options['userVerification']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertSame([], $options['allowCredentials']);
    }

    public function testRequestOptionsCanBeRestrictedToKnownKeys(): void {
        [, $challenge] = WebAuthnChallenges::issue('stepup', 5);

        $options = WebAuthn::requestOptions($challenge, ["\xfb\xff", 'abc']);

        $this->assertSame(['-_8', 'YWJj'], array_column($options['allowCredentials'], 'id'));
    }

    // -----------------------------------------------------------------
    // a good assertion
    // -----------------------------------------------------------------

    public function testAGoodAssertionYieldsTheNewCounterAndTheFlags(): void {
        [$key, $row] = $this->key();
        [$options, $challenge] = $this->loginOptions();

        $assertion = WebAuthn::verifyAssertion($key->assert($options), $challenge, $row, null);

        $this->assertSame(1, $assertion->signCount);
        $this->assertTrue($assertion->userVerified);
        $this->assertFalse($assertion->backupEligible);
        $this->assertFalse($assertion->backupState);
    }

    /** @return array<string, array{string}> */
    public static function algorithms(): array {
        return ['ES256' => ['ES256'], 'EdDSA' => ['EdDSA'], 'RS256' => ['RS256']];
    }

    #[DataProvider('algorithms')]
    public function testEachOfTheThreeAlgorithmsRegistersAndSignsIn(string $algorithm): void {
        $key = new SoftAuthenticator(algorithm: $algorithm);
        $row = $this->storedKey($key, ['attestation' => 'packed']);
        [$options, $challenge] = $this->loginOptions();

        $assertion = WebAuthn::verifyAssertion($key->assert($options), $challenge, $row, null);

        $this->assertSame(1, $assertion->signCount);
        $this->assertSame('packed', $row['attestation_format']);
    }

    #[DataProvider('algorithms')]
    public function testAnyOfThemSignsOnlyWithItsOwnKey(string $algorithm): void {
        $key = new SoftAuthenticator(algorithm: $algorithm);
        $row = $this->storedKey($key);

        $this->assertSame(WebAuthnFailure::SIGNATURE, $this->refusal($key, $row, ['badSignature' => true]));
    }

    public function testAKnownUserIsHeldToTheirHandle(): void {
        [$key, $row] = $this->key();
        [$options, $challenge] = $this->loginOptions();

        $assertion = WebAuthn::verifyAssertion($key->assert($options), $challenge, $row, $key->userHandle);

        $this->assertSame(1, $assertion->signCount);
    }

    public function testAKnownUserNeedNotAnswerWithTheHandle(): void {
        [$key, $row] = $this->key();
        [$options, $challenge] = $this->loginOptions();

        $assertion = WebAuthn::verifyAssertion($key->assert($options, ['userHandle' => null]), $challenge, $row, $key->userHandle);

        $this->assertSame(1, $assertion->signCount);
    }

    public function testBackupFlagsComeBackFromTheAssertion(): void {
        [$key, $row] = $this->key(['be' => true]);
        [$options, $challenge] = $this->loginOptions();

        $synced = WebAuthn::verifyAssertion($key->assert($options, ['be' => true, 'bs' => true]), $challenge, $row, null);

        $this->assertTrue($synced->backupEligible);
        $this->assertTrue($synced->backupState);
    }

    public function testCountersThatStayAtZeroAreFineForSyncedKeys(): void {
        $key = new SoftAuthenticator();
        $key->countsSigns = false;
        $row = $this->storedKey($key, ['be' => true, 'bs' => true]);

        foreach ([1, 2, 3] as $ignored) {
            [$options, $challenge] = $this->loginOptions();
            $assertion = WebAuthn::verifyAssertion($key->assert($options, ['be' => true, 'bs' => true]), $challenge, $row, null);
            $this->assertSame(0, $assertion->signCount);
        }
    }

    public function testTheCounterMayJumpForward(): void {
        [$key, $row] = $this->key();
        [$options, $challenge] = $this->loginOptions();

        $assertion = WebAuthn::verifyAssertion($key->assert($options, ['signCount' => 1000]), $challenge, $row, null);

        $this->assertSame(1000, $assertion->signCount);
    }

    public function testASuccessfulSignInIsStoredAndTheNextOneChecksAgainstIt(): void {
        [$key, $row] = $this->key();
        [$options, $challenge] = $this->loginOptions();
        $assertion = WebAuthn::verifyAssertion($key->assert($options), $challenge, $row, null);
        $this->assertTrue(Passkeys::updateUsage((int) $row['id'], $assertion, '203.0.113.9'));

        $row = Passkeys::find((int) $row['id']);
        $this->assertSame(1, (int) $row['sign_count']);
        $this->assertSame('203.0.113.9', $row['last_used_ip']);
        $this->assertNotNull($row['last_used_at']);

        // the same counter again is a replay
        $this->assertSame(WebAuthnFailure::COUNTER_REGRESSION, $this->refusal($key, $row, ['signCount' => 1]));
        [$options, $challenge] = $this->loginOptions();
        $this->assertSame(2, WebAuthn::verifyAssertion($key->assert($options), $challenge, $row, null)->signCount);
    }

    public function testAssertionsAreRefusedWhileThereIsNoRelyingParty(): void {
        [$key, $row] = $this->key();
        [$options, $challenge] = $this->loginOptions();
        Config::set('passkeys', ['rp_id' => ''] + PasskeySettings::get());

        $this->expectException(WebAuthnFailure::class);
        WebAuthn::verifyAssertion($key->assert($options), $challenge, $row, null);
    }

    // -----------------------------------------------------------------
    // the counter
    // -----------------------------------------------------------------

    /** @return array<string, array{int, int}> stored counter, presented counter */
    public static function regressions(): array {
        return [
            'the same counter'            => [5, 5],
            'a lower counter'             => [5, 3],
            'zero after a counting key'   => [5, 0],
        ];
    }

    #[DataProvider('regressions')]
    public function testACounterThatDoesNotAdvanceIsRefusedWithItsOwnCode(int $stored, int $presented): void {
        [$key, $row] = $this->key();
        R::exec('UPDATE passkeys SET sign_count = ? WHERE id = ?', [$stored, $row['id']]);
        $row = Passkeys::find((int) $row['id']);

        $this->assertSame(WebAuthnFailure::COUNTER_REGRESSION, $this->refusal($key, $row, ['signCount' => $presented]));
    }

    public function testAStoredCounterGuardsAgainstARacingSignIn(): void {
        [$key, $row] = $this->key();
        $first = new WebAuthnAssertion(7, true, false, false);
        $older = new WebAuthnAssertion(6, true, false, false);

        $this->assertTrue(Passkeys::updateUsage((int) $row['id'], $first, null));
        $this->assertFalse(Passkeys::updateUsage((int) $row['id'], $older, null), 'a counter never moves back');
        $this->assertSame(7, (int) Passkeys::find((int) $row['id'])['sign_count']);
    }

    // -----------------------------------------------------------------
    // a forged one
    // -----------------------------------------------------------------

    /** @return array<string, array{array, string}> */
    public static function forgeries(): array {
        return [
            'another origin'             => [['origin' => 'https://evil.example'], WebAuthnFailure::ORIGIN],
            'the same host over https'   => [['origin' => 'https://localhost:5173'], WebAuthnFailure::ORIGIN],
            'another port'               => [['origin' => 'http://localhost:5174'], WebAuthnFailure::ORIGIN],
            'another relying party hash' => [['rpId' => 'evil.example'], WebAuthnFailure::RP_ID_HASH],
            'another challenge'          => [['challenge' => 'not the challenge issued'], WebAuthnFailure::CHALLENGE],
            'a registration type'        => [['type' => 'webauthn.create'], WebAuthnFailure::TYPE],
            'cross-origin'               => [['crossOrigin' => true], WebAuthnFailure::CROSS_ORIGIN],
            'a top origin'               => [['topOrigin' => 'https://evil.example'], WebAuthnFailure::CROSS_ORIGIN],
            'user presence missing'      => [['up' => false], WebAuthnFailure::USER_PRESENCE],
            'user verification missing'  => [['uv' => false], WebAuthnFailure::USER_VERIFICATION],
            'a corrupted signature'      => [['badSignature' => true], WebAuthnFailure::SIGNATURE],
            'backed up, not eligible'    => [['bs' => true], WebAuthnFailure::BACKUP_FLAGS],
            'eligibility that changed'   => [['be' => true], WebAuthnFailure::BACKUP_FLAGS],
            'another handle'             => [['userHandle' => 'someone else entirely'], WebAuthnFailure::USER_HANDLE],
            'no handle at all'           => [['userHandle' => null], WebAuthnFailure::USER_HANDLE],
            'another key id'             => [['rawId' => 'someone else'], WebAuthnFailure::CREDENTIAL_ID],
        ];
    }

    #[DataProvider('forgeries')]
    public function testAForgedAssertionIsRefused(array $forge, string $reason): void {
        [$key, $row] = $this->key();

        $this->assertSame($reason, $this->refusal($key, $row, $forge));
    }

    public function testAKnownUserIsRefusedAnotherUsersKey(): void {
        [$key, $row] = $this->key();

        $this->assertSame(
            WebAuthnFailure::USER_HANDLE,
            $this->refusal($key, $row, [], str_repeat("\x01", 32))
        );
    }

    public function testAnotherKeysSignatureDoesNotVerify(): void {
        [$key, $row] = $this->key();
        $impostor = new SoftAuthenticator();
        $impostor->credentialId = $key->credentialId;
        $impostor->userHandle = $key->userHandle;

        $this->assertSame(WebAuthnFailure::SIGNATURE, $this->refusal($impostor, $row, []));
    }

    public function testASubdomainOfTheRelyingPartyIsNotAnOriginUnlessListed(): void {
        Config::set('passkeys', [
            'rp_id' => 'example.it', 'origins' => ['https://eppitnic.example.it'],
        ] + PasskeySettings::get());
        $key = new SoftAuthenticator('https://eppitnic.example.it', 'example.it');
        $row = $this->storedKey($key);

        $this->assertSame(WebAuthnFailure::ORIGIN, $this->refusal($key, $row, ['origin' => 'https://other.example.it']));
        $this->assertSame(WebAuthnFailure::ORIGIN, $this->refusal($key, $row, ['origin' => 'https://example.it']));
        $this->assertSame(WebAuthnFailure::ORIGIN, $this->refusal($key, $row, ['origin' => 'https://eppitnic.example.it:8443']));
        $this->assertSame(WebAuthnFailure::ORIGIN, $this->refusal($key, $row, ['origin' => 'http://eppitnic.example.it']));
    }

    public function testAResponseForARegistrationIsNotAnAssertion(): void {
        $key = new SoftAuthenticator();
        $user = $this->user();
        [, $challenge] = WebAuthnChallenges::issue('register', 5);
        $options = WebAuthn::registrationOptions($user, [], 'none', $challenge);
        $row = $this->storedKey(new SoftAuthenticator());

        $this->expectException(WebAuthnFailure::class);
        WebAuthn::verifyAssertion($key->register($options), $challenge, $row, null);
    }

    public function testGarbageIsRefusedAsMalformed(): void {
        [, $row] = $this->key();

        foreach (['', 'nope', '{}', '{"id":"x","rawId":"x","type":"public-key","response":{}}'] as $json) {
            try {
                WebAuthn::verifyAssertion($json, 'challenge', $row, null);
                $this->fail('accepted');
            } catch (WebAuthnFailure $e) {
                $this->assertSame(WebAuthnFailure::MALFORMED, $e->reason);
            }
        }
    }

    // -----------------------------------------------------------------
    // looking the response up
    // -----------------------------------------------------------------

    public function testClaimsNameTheKeyTheChallengeAndTheHandle(): void {
        [$key] = $this->key();
        [$options, $challenge] = $this->loginOptions();

        $claims = WebAuthn::claims($key->assert($options));

        $this->assertSame($key->credentialId, $claims['credential_id']);
        $this->assertSame($challenge, $claims['challenge']);
        $this->assertSame($key->userHandle, $claims['user_handle']);
        $this->assertSame(
            Passkeys::findByCredentialId($claims['credential_id'])['credential_id'],
            $key->credentialId
        );
    }

    public function testClaimsHaveNoHandleWhenTheResponseHasNone(): void {
        [$key] = $this->key();
        [$options] = $this->loginOptions();

        $this->assertNull(WebAuthn::claims($key->assert($options, ['userHandle' => null]))['user_handle']);
    }

    public function testClaimsOfGarbageAreRefused(): void {
        $this->expectException(WebAuthnFailure::class);
        WebAuthn::claims('{"id": 5}');
    }

    public function testClaimsDoNotVerifyAnything(): void {
        [$key] = $this->key();
        [$options] = $this->loginOptions();

        $claims = WebAuthn::claims($key->assert($options, ['origin' => 'https://evil.example', 'badSignature' => true]));

        $this->assertSame($key->credentialId, $claims['credential_id']);
    }
}
