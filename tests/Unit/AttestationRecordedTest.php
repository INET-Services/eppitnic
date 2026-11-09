<?php

namespace Eppitnic\Tests\Unit;

use CBOR\Decoder;
use CBOR\StringStream;
use Eppitnic\Config;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnFailure;
use Eppitnic\Service\WebAuthnRegistration;
use Eppitnic\Tests\Support\PasskeyTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Eppitnic\Tests\Support\TestPki;
use Symfony\Component\Clock\MockClock;

/**
 * Registrations recorded from real authenticators (a Yubico key's packed
 * attestation, Windows Hello's TPM attestation, a U2F key's), checked against
 * metadata entries listing the vendors' public roots. Their certificates are
 * dated, so the clock stands still. The responses are web-auth/webauthn-framework's
 * test fixtures, MIT (see tests/fixtures/attestation/NOTICE).
 */
final class AttestationRecordedTest extends PasskeyTestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/attestation/';
    private const WINDOWS_HELLO = '08987058-cadc-4b81-b6e1-30de50dcbe96';

    private function fixture(string $name): array {
        return json_decode((string) file_get_contents(self::FIXTURES . $name . '.json'), true);
    }

    private function yubicoRoot(): string {
        return (string) file_get_contents(self::FIXTURES . 'yubico-u2f-root.pem');
    }

    /** The CA above a recorded TPM key's certificate, as the chain carried it. */
    private function tpmIssuer(array $fixture): string {
        $object = base64_decode(strtr($fixture['credential']['response']['attestationObject'], '-_', '+/'));
        $x5c = Decoder::create()->decode(new StringStream($object))->normalize()['attStmt']['x5c'];
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($x5c[1]), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    protected function setUp(): void {
        parent::setUp();
        PasskeyMetadata::useClock(new MockClock('2026-11-08 12:00:00'));
    }

    /**
     * @param array<string, array> $entries AAGUID => stored entry fields
     */
    private function register(array $fixture, array $entries, string $attestation = 'direct'): WebAuthnRegistration {
        Config::set('passkeys', [
            'rp_id' => $fixture['rp_id'], 'origins' => [$fixture['origin']],
            'type' => 'device_bound', 'models' => [], 'extra_aaguids' => [],
        ]);
        $this->metadata($entries, nextUpdate: PasskeyMetadata::now()->modify('+20 days')->format('Y-m-d'));

        return WebAuthn::verifyRegistration(
            json_encode($fixture['credential']),
            base64_decode(strtr($fixture['challenge'], '-_', '+/')),
            $this->user(),
            $attestation
        );
    }

    private static function entry(string $root, array $types = ['basic_full']): array {
        return ['attestationRootCertificates' => [preg_replace('/-----[A-Z ]+-----|\s+/', '', $root)], 'attestationTypes' => $types];
    }

    // -----------------------------------------------------------------
    // a Yubico key, packed attestation
    // -----------------------------------------------------------------

    public function testASecurityKeyByYubicoIsAnchoredToTheYubicoRoot(): void {
        $fixture = $this->fixture('yubico-security-key-packed');

        $registration = $this->register($fixture, [$fixture['aaguid'] => self::entry($this->yubicoRoot())]);

        $this->assertSame('packed', $registration->attestationFormat);
        $this->assertSame($fixture['aaguid'], $registration->aaguid);
        $this->assertTrue($registration->attestationVerified);
        $this->assertFalse($registration->backupEligible);
    }

    public function testAYubiKey5IsAnchoredToTheYubicoRoot(): void {
        $fixture = $this->fixture('yubikey5-nfc-packed');

        $registration = $this->register($fixture, [$fixture['aaguid'] => self::entry($this->yubicoRoot())]);

        $this->assertSame($fixture['aaguid'], $registration->aaguid);
        $this->assertTrue($registration->attestationVerified);
    }

    public function testTheBatchCertificateItselfListedAsRootAnchorsToo(): void {
        $fixture = $this->fixture('yubikey5-nfc-packed');
        $object = base64_decode(strtr($fixture['credential']['response']['attestationObject'], '-_', '+/'));
        $leaf = Decoder::create()->decode(new StringStream($object))->normalize()['attStmt']['x5c'][0];

        $registration = $this->register($fixture, [$fixture['aaguid'] => self::entry(base64_encode($leaf))]);

        $this->assertTrue($registration->attestationVerified);
    }

    public function testAnotherRootForTheAaguidLeavesItUnverifiedButRegistered(): void {
        $fixture = $this->fixture('yubico-security-key-packed');

        $registration = $this->register($fixture, [$fixture['aaguid'] => self::entry(TestPki::root('Not Yubico')['pem'])]);

        $this->assertSame($fixture['aaguid'], $registration->aaguid, 'the claim is recorded');
        $this->assertFalse($registration->attestationVerified, 'but it is only a claim');
    }

    public function testAnAaguidFidoDoesNotListLeavesItUnverified(): void {
        $fixture = $this->fixture('yubico-security-key-packed');

        $registration = $this->register($fixture, []);

        $this->assertFalse($registration->attestationVerified);
    }

    public function testTheSameResponseForNoAttestationIsNeverVerified(): void {
        $fixture = $this->fixture('yubico-security-key-packed');

        $registration = $this->register($fixture, [$fixture['aaguid'] => self::entry($this->yubicoRoot())], 'none');

        $this->assertFalse($registration->attestationVerified);
    }

    public function testTheResponseIsHeldToTheRelyingPartyAsEver(): void {
        $fixture = $this->fixture('yubico-security-key-packed');
        $fixture['origin'] = 'https://evil.example';

        try {
            $this->register($fixture, [$fixture['aaguid'] => self::entry($this->yubicoRoot())]);
            $this->fail('accepted');
        } catch (WebAuthnFailure $e) {
            $this->assertSame(WebAuthnFailure::ORIGIN, $e->reason);
        }
    }

    // -----------------------------------------------------------------
    // Windows Hello, TPM attestation
    // -----------------------------------------------------------------

    public function testWindowsHelloIsAnchoredThroughItsTpmCertificate(): void {
        $fixture = $this->fixture('windows-hello-tpm');

        $registration = $this->register($fixture, [self::WINDOWS_HELLO => self::entry($this->tpmIssuer($fixture), ['basic_surrogate', 'attca'])]);

        $this->assertSame('tpm', $registration->attestationFormat);
        $this->assertSame(self::WINDOWS_HELLO, $registration->aaguid);
        $this->assertTrue($registration->attestationVerified);
        $this->assertFalse($registration->backupEligible, 'Windows Hello keys do not sync');
    }

    public function testWindowsHelloNeedsTheTypeFidoListsForIt(): void {
        $fixture = $this->fixture('windows-hello-tpm');

        $registration = $this->register($fixture, [self::WINDOWS_HELLO => self::entry($this->tpmIssuer($fixture), ['basic_full'])]);

        $this->assertFalse($registration->attestationVerified);
    }

    public function testWindowsHelloAnchorsToNothingElse(): void {
        $fixture = $this->fixture('windows-hello-tpm');

        $registration = $this->register($fixture, [self::WINDOWS_HELLO => self::entry($this->yubicoRoot(), ['attca'])]);

        $this->assertFalse($registration->attestationVerified);
    }

    public function testATpmCertificateThatHasExpiredIsRefused(): void {
        $fixture = $this->fixture('windows-hello-tpm');
        PasskeyMetadata::useClock(new MockClock('2028-01-01 12:00:00'));

        try {
            $this->register($fixture, [self::WINDOWS_HELLO => self::entry($this->tpmIssuer($fixture), ['attca'])]);
            $this->fail('accepted');
        } catch (WebAuthnFailure $e) {
            $this->assertSame(WebAuthnFailure::ATTESTATION, $e->reason);
        }
    }

    // -----------------------------------------------------------------
    // a U2F key
    // -----------------------------------------------------------------

    public function testAU2fKeyCannotRegisterAsAPasskey(): void {
        $fixture = $this->fixture('fido-u2f');

        try {
            $this->register($fixture, []);
            $this->fail('accepted');
        } catch (WebAuthnFailure $e) {
            $this->assertSame(WebAuthnFailure::USER_VERIFICATION, $e->reason, 'U2F cannot verify the user');
        }
    }

    // -----------------------------------------------------------------
    // fail closed
    // -----------------------------------------------------------------

    public function testWithoutCurrentMetadataADirectRegistrationIsRefusedBeforeAnythingIsChecked(): void {
        $fixture = $this->fixture('yubico-security-key-packed');
        $this->metadata([$fixture['aaguid'] => self::entry($this->yubicoRoot())], nextUpdate: '2026-10-01');
        Config::set('passkeys', ['rp_id' => $fixture['rp_id'], 'origins' => [$fixture['origin']], 'type' => 'device_bound', 'models' => [], 'extra_aaguids' => []]);

        try {
            WebAuthn::verifyRegistration(json_encode($fixture['credential']), base64_decode(strtr($fixture['challenge'], '-_', '+/')), $this->user(), 'direct');
            $this->fail('accepted');
        } catch (WebAuthnFailure $e) {
            $this->assertSame(WebAuthnFailure::METADATA, $e->reason);
        }
    }

    public function testNoMetadataNeededWhereNoAttestationIsAsked(): void {
        $key = new SoftAuthenticator();

        [$registration] = $this->registered($key);

        $this->assertFalse($registration->attestationVerified);
    }

    public function testTheOptionsAreRefusedToo(): void {
        $this->expectException(WebAuthnFailure::class);
        $this->expectExceptionMessage(WebAuthnFailure::METADATA);

        WebAuthn::registrationOptions($this->user(), [], 'direct', random_bytes(32));
    }
}
