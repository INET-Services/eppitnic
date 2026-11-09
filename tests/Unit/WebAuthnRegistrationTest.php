<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Persistence\PasskeyExists;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Service\WebAuthnFailure;
use Eppitnic\Tests\Support\PasskeyTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The registration ceremony: what the options ask for, and that every part of
 * a response a verifier must check is checked.
 */
final class WebAuthnRegistrationTest extends PasskeyTestCase
{
    private function options(array $user, array $existing = [], string $attestation = 'none'): array {
        [, $challenge] = WebAuthnChallenges::issue('register', (int) $user['id']);
        return WebAuthn::registrationOptions($user, $existing, $attestation, $challenge);
    }

    private function failureOf(array $forge, ?SoftAuthenticator $key = null): string {
        try {
            $this->registered($key ?? new SoftAuthenticator(), $forge);
        } catch (WebAuthnFailure $e) {
            return $e->reason;
        }
        $this->fail('the registration was accepted');
    }

    // -----------------------------------------------------------------
    // the options
    // -----------------------------------------------------------------

    public function testOptionsAskForAResidentUserVerifyingKeyOfThreeAlgorithms(): void {
        $options = $this->options($this->user());

        $this->assertSame(['id' => 'localhost', 'name' => 'localhost'], $options['rp']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertSame([-7, -8, -257], array_column($options['pubKeyCredParams'], 'alg'));
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);
        $this->assertSame('none', $options['attestation']);
        $this->assertSame('alice', $options['user']['name']);
        $this->assertSame('alice', $options['user']['displayName']);
    }

    public function testOptionsListTheKeysTheUserHasSoTheyAreNotRegisteredAgain(): void {
        $ids = [random_bytes(32), "\xfb\xff\x00\xfe"];

        $options = $this->options($this->user(), $ids);

        $this->assertSame(
            array_map(SoftAuthenticator::encode(...), $ids),
            array_column($options['excludeCredentials'], 'id')
        );
    }

    public function testDirectAttestationCanBeAsked(): void {
        $this->metadata();
        $this->assertSame('direct', $this->options($this->user(), [], 'direct')['attestation']);
    }

    public function testAnyOtherAttestationPreferenceIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->options($this->user(), [], 'indirect');
    }

    public function testTheUserHandleIs32RandomBytesKeptForGoodAndNeverTheId(): void {
        $first = $this->options($this->user(5));
        $again = $this->options($this->user(5));
        $other = $this->options($this->user(6, 'bob'));

        $handle = SoftAuthenticator::decode($first['user']['id']);
        $this->assertSame(32, strlen($handle));
        $this->assertSame($first['user']['id'], $again['user']['id']);
        $this->assertNotSame($first['user']['id'], $other['user']['id']);
        $this->assertNotSame('5', $handle);
    }

    public function testOptionsAreRefusedWhileThereIsNoRelyingParty(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + PasskeySettings::get());

        $this->expectException(WebAuthnFailure::class);
        $this->options($this->user());
    }

    public function testNoOriginMeansNoCeremonyEvenWithARelyingParty(): void {
        Config::set('passkeys', ['origins' => []] + PasskeySettings::get());

        $this->assertSame(WebAuthnFailure::DISABLED, $this->failureOf([]));
    }

    // -----------------------------------------------------------------
    // a good response
    // -----------------------------------------------------------------

    public function testAGoodRegistrationYieldsTheCredential(): void {
        $key = new SoftAuthenticator();

        [$registration] = $this->registered($key);

        $this->assertSame($key->credentialId, $registration->credentialId);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $registration->aaguid);
        $this->assertSame(0, $registration->signCount);
        $this->assertFalse($registration->backupEligible);
        $this->assertFalse($registration->backupState);
        $this->assertSame('none', $registration->attestationFormat);
        $this->assertFalse($registration->attestationVerified, "'none' proves nothing about the device");
        $this->assertSame(['internal'], $registration->transports);
        $this->assertSame($key->userHandle, SoftAuthenticator::decode(json_decode($registration->source, true)['userHandle']));
    }

    public function testBackupFlagsAreReported(): void {
        [$registration] = $this->registered(new SoftAuthenticator(), ['be' => true, 'bs' => true]);

        $this->assertTrue($registration->backupEligible);
        $this->assertTrue($registration->backupState);
    }

    public function testSelfAttestationIsAcceptedAndNotCountedAsVerified(): void {
        [$registration] = $this->registered(new SoftAuthenticator(), ['attestation' => 'packed']);

        $this->assertSame('packed', $registration->attestationFormat);
        $this->assertFalse($registration->attestationVerified);
    }

    public function testOnlyKnownTransportsAreKept(): void {
        [$registration] = $this->registered(new SoftAuthenticator(), ['transports' => ['usb', 'nfc', 'usb', 'carrier-pigeon']]);

        $this->assertSame(['usb', 'nfc'], $registration->transports);
    }

    public function testASubdomainRelyingPartyWorksWithItsOwnOrigin(): void {
        Config::set('passkeys', [
            'rp_id' => 'example.it', 'origins' => ['https://eppitnic.example.it'],
        ] + PasskeySettings::get());

        [$registration] = $this->registered(new SoftAuthenticator('https://eppitnic.example.it', 'example.it'));

        $this->assertSame('none', $registration->attestationFormat);
    }

    // -----------------------------------------------------------------
    // a forged one
    // -----------------------------------------------------------------

    /** @return array<string, array{array, string}> */
    public static function forgeries(): array {
        return [
            'another origin'                  => [['origin' => 'https://evil.example'], WebAuthnFailure::ORIGIN],
            'the right host over https'       => [['origin' => 'https://localhost:5173'], WebAuthnFailure::ORIGIN],
            'the right host on another port'  => [['origin' => 'http://localhost:5174'], WebAuthnFailure::ORIGIN],
            'a subdomain of the relying party' => [['origin' => 'http://app.localhost:5173'], WebAuthnFailure::ORIGIN],
            'another relying party hash'      => [['rpId' => 'evil.example'], WebAuthnFailure::RP_ID_HASH],
            'another challenge'               => [['challenge' => 'not the challenge issued'], WebAuthnFailure::CHALLENGE],
            'an assertion type'               => [['type' => 'webauthn.get'], WebAuthnFailure::TYPE],
            'an unknown type'                 => [['type' => 'webauthn.make'], WebAuthnFailure::TYPE],
            'cross-origin'                    => [['crossOrigin' => true], WebAuthnFailure::CROSS_ORIGIN],
            'a top origin'                    => [['topOrigin' => 'https://evil.example'], WebAuthnFailure::CROSS_ORIGIN],
            'user presence missing'           => [['up' => false], WebAuthnFailure::USER_PRESENCE],
            'user verification missing'       => [['uv' => false], WebAuthnFailure::USER_VERIFICATION],
            'both flags missing'              => [['up' => false, 'uv' => false], WebAuthnFailure::USER_PRESENCE],
            'ES384, which is not offered'     => [['alg' => -35], WebAuthnFailure::ALGORITHM],
            'the retired RS1'                 => [['alg' => -65535], WebAuthnFailure::ALGORITHM],
            'backed up but not eligible'      => [['bs' => true], WebAuthnFailure::BACKUP_FLAGS],
            'a credential id of 1024 bytes'   => [['credentialId' => 'x'], WebAuthnFailure::CREDENTIAL_ID],
            'a raw id that is not the key id' => [['rawId' => 'someone else'], WebAuthnFailure::CREDENTIAL_ID],
        ];
    }

    #[DataProvider('forgeries')]
    public function testAForgedResponseIsRefused(array $forge, string $reason): void {
        if (($forge['credentialId'] ?? null) === 'x') {
            $forge['credentialId'] = random_bytes(1024);
        }

        $this->assertSame($reason, $this->failureOf($forge));
    }

    public function testAChallengeIssuedForAnotherCeremonyDoesNotVerify(): void {
        $user = $this->user();
        [, $mine] = WebAuthnChallenges::issue('register', 5);
        [, $other] = WebAuthnChallenges::issue('register', 5);
        $options = WebAuthn::registrationOptions($user, [], 'none', $mine);

        $this->expectException(WebAuthnFailure::class);
        WebAuthn::verifyRegistration((new SoftAuthenticator())->register($options), $other, $this->user());
    }

    public function testTheFailureCarriesAReasonAndNeverTheLibrarysText(): void {
        try {
            $this->registered(new SoftAuthenticator(), ['origin' => 'https://evil.example']);
            $this->fail('accepted');
        } catch (WebAuthnFailure $e) {
            $this->assertSame('origin', $e->getMessage());
            $this->assertSame('origin', $e->reason);
            $this->assertNotNull($e->getPrevious(), 'the cause stays available for the log');
        }
    }

    // -----------------------------------------------------------------
    // not a response at all
    // -----------------------------------------------------------------

    /** @return array<string, array{callable(string): string}> */
    public static function garbage(): array {
        $edit = static function (callable $change): callable {
            return static function (string $json) use ($change): string {
                $data = json_decode($json, true);
                $change($data);
                return json_encode($data);
            };
        };
        return [
            'not JSON'                  => [static fn(string $j): string => 'not json'],
            'an empty object'           => [static fn(string $j): string => '{}'],
            'a list'                    => [static fn(string $j): string => '[]'],
            'bytes of CBOR that is not' => [$edit(static function (array &$d): void {
                $d['response']['attestationObject'] = SoftAuthenticator::encode("\xff\xff\xff");
            })],
            'a truncated attestation'   => [$edit(static function (array &$d): void {
                $d['response']['attestationObject'] = substr($d['response']['attestationObject'], 0, 40);
            })],
            'a client data that is not JSON' => [$edit(static function (array &$d): void {
                $d['response']['clientDataJSON'] = SoftAuthenticator::encode('{"type":');
            })],
            'a wrong credential type'   => [$edit(static function (array &$d): void {
                $d['type'] = 'password';
            })],
            'ids that differ'           => [$edit(static function (array &$d): void {
                $d['rawId'] = SoftAuthenticator::encode('other');
            })],
            'no attestation object'     => [$edit(static function (array &$d): void {
                unset($d['response']['attestationObject']);
            })],
            'a non-string field'        => [$edit(static function (array &$d): void {
                $d['response']['clientDataJSON'] = ['x'];
            })],
            'padding the field has no business with' => [$edit(static function (array &$d): void {
                $d['response']['clientDataJSON'] .= '=';
            })],
        ];
    }

    #[DataProvider('garbage')]
    public function testAMalformedResponseIsRefused(callable $spoil): void {
        $user = $this->user();
        $options = $this->options($user);
        $challenge = SoftAuthenticator::decode($options['challenge']);
        $json = $spoil((new SoftAuthenticator())->register($options));

        try {
            WebAuthn::verifyRegistration($json, $challenge, $this->user());
            $this->fail('accepted');
        } catch (WebAuthnFailure $e) {
            $this->assertSame(WebAuthnFailure::MALFORMED, $e->reason);
        }
    }

    public function testAnAssertionIsNotARegistration(): void {
        $key = new SoftAuthenticator();
        $this->storedKey($key);
        [$options, $challenge] = $this->loginOptions();

        $this->expectException(WebAuthnFailure::class);
        WebAuthn::verifyRegistration($key->assert($options), $challenge, $this->user());
    }

    public function testAnOversizedResponseIsRefusedUnparsed(): void {
        $this->expectException(WebAuthnFailure::class);
        WebAuthn::verifyRegistration(str_repeat('a', 70000), 'challenge', $this->user());
    }

    // -----------------------------------------------------------------
    // storage
    // -----------------------------------------------------------------

    public function testTheSameKeyCannotBeRegisteredTwice(): void {
        $key = new SoftAuthenticator();
        $this->storedKey($key);

        [$again] = $this->registered($key);
        $this->expectException(PasskeyExists::class);
        Passkeys::add(5, $again, 'Again');
    }

    public function testNorByAnotherUser(): void {
        $key = new SoftAuthenticator();
        $this->storedKey($key);

        [$other] = $this->registered($key, [], $this->user(6, 'bob'));
        $this->expectException(PasskeyExists::class);
        Passkeys::add(6, $other, 'Stolen');
    }
}
