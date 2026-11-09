<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Eppitnic\Tests\Support\TestAccounts;
use Eppitnic\Tests\Support\TestPki;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Symfony\Component\Clock\MockClock;

/**
 * Registering under type device_bound: the attestation must lead to a root
 * MDS3 lists for the model, the model must be one the admin accepts, and the
 * key must not sync -- and what MDS3 later says of the model, or its absence,
 * does to the keys already there.
 */
final class PasskeyDeviceBoundTest extends PasskeyHttpTestCase
{
    private const VENDOR_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001';
    private const YUBIKEY5 = 'fa2b99dc-9e39-4257-8f92-4a30d23c4118';

    private array $vendorRoot;

    protected function setUp(): void {
        parent::setUp();
        $this->account(7, 'user', 2, 'bob');
        $this->vendorRoot = TestPki::root('Vendor Root');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->listed([self::VENDOR_KEY, self::YUBIKEY5]);
    }

    /** @param string[] $aaguids models MDS3 knows, all under the vendor root */
    private function listed(array $aaguids, array $status = [], ?string $nextUpdate = null): void {
        $entries = [];
        foreach ($aaguids as $aaguid) {
            $entries[$aaguid] = [
                'description' => "Model {$aaguid}",
                'attestationRootCertificates' => [TestPki::der($this->vendorRoot)],
                'attestationTypes' => ['basic_full'],
            ];
        }
        $this->metadata($entries, $status, $nextUpdate);
    }

    /** @return array the forge for a full attestation, by a vendor certificate naming $aaguid */
    private function attested(string $aaguid = self::VENDOR_KEY, ?array $root = null, ?string $certificateAaguid = null): array {
        $certificate = TestPki::attestation($root ?? $this->vendorRoot, $certificateAaguid ?? $aaguid);
        return [
            'attestation'    => 'packed',
            'attestationKey' => $certificate['key'],
            'x5c'            => [base64_decode(TestPki::der($certificate))],
        ];
    }

    private function add(SoftAuthenticator $key, array $forge = [], array $more = [], ?string $token = null): ResponseInterface {
        $token ??= $this->sessionOf(5, 'pwd');
        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $token));
        $this->assertArrayHasKey('publicKey', $options, json_encode($options));

        return $this->call('POST', '/v1/users/5/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey'], $forge), true),
            'stepup'       => ['password' => self::PASSWORD],
        ] + $more, $token);
    }

    /** A key that registered with full attestation, stored for alice. */
    private function stored(SoftAuthenticator $key, ?array $forge = null): array {
        $user = $this->user(5);
        [, $challenge] = WebAuthnChallenges::issue('register', 5);
        $options = WebAuthn::registrationOptions($user, [], 'direct', $challenge);
        $registration = WebAuthn::verifyRegistration($key->register($options, $forge ?? $this->attested($key->aaguid)), $challenge, $user, 'direct');
        $this->assertTrue($registration->attestationVerified);

        $row = Passkeys::find(Passkeys::add(5, $registration, 'Attested'));
        TestAccounts::ensure(5, 'user', 2, 'alice');
        return $row;
    }

    // -----------------------------------------------------------------
    // what is accepted
    // -----------------------------------------------------------------

    public function testAKeyWithAChainToTheListedRootIsStoredAsVerified(): void {
        $key = new SoftAuthenticator(aaguid: self::VENDOR_KEY);

        $response = $this->add($key, $this->attested());
        $body = self::json($response);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $row = Passkeys::listForUser(5)[0];
        $this->assertSame([self::VENDOR_KEY, 'packed', 1, 0], [$row['aaguid'], $row['attestation_format'], (int) $row['attestation_verified'], (int) $row['backup_eligible']]);
        $this->assertTrue($body['passkey']['attestation_verified']);
        $this->assertTrue($body['passkey']['compliant']);
        $this->assertSame('Model ' . self::VENDOR_KEY, $body['passkey']['model'], 'named by MDS3');
        $this->assertTrue($this->events('passkey_registered')[0]['attested']);
    }

    public function testTheOptionsAskForDirectAttestation(): void {
        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $this->sessionOf(5, 'pwd')));

        $this->assertSame('direct', $options['publicKey']['attestation']);
    }

    public function testASelectedModelAcceptsItsAaguid(): void {
        Config::set('passkeys', ['models' => ['yubikey5']] + Config::get('passkeys'));

        $response = $this->add(new SoftAuthenticator(aaguid: self::YUBIKEY5), $this->attested(self::YUBIKEY5));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testAnAaguidOutsideTheSelectedModelsIsRefused(): void {
        Config::set('passkeys', ['models' => ['yubikey5']] + Config::get('passkeys'));

        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAnExtraAaguidIsAcceptedBesideTheModels(): void {
        Config::set('passkeys', ['models' => ['yubikey5'], 'extra_aaguids' => [self::VENDOR_KEY]] + Config::get('passkeys'));

        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested());

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    // -----------------------------------------------------------------
    // what is not
    // -----------------------------------------------------------------

    public function testNoAttestationIsRefused(): void {
        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
        $this->assertSame('policy', $this->events('passkey_registration_failed')[0]['reason']);
    }

    public function testSelfAttestationIsRefused(): void {
        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), ['attestation' => 'packed']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAnUntrustedChainIsRefused(): void {
        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested(root: TestPki::root('Vendor Root')));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAChainToAnotherModelsRootIsRefused(): void {
        $this->metadata([self::VENDOR_KEY => ['attestationRootCertificates' => [TestPki::der(TestPki::root('Someone Else'))]]]);

        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested());

        $this->assertSame('policy', self::json($response)['reason']);
    }

    public function testAnAaguidFidoDoesNotKnowIsRefused(): void {
        $unknown = 'aaaaaaaa-bbbb-4ccc-8ddd-0000000000ff';

        $response = $this->add(new SoftAuthenticator(aaguid: $unknown), $this->attested($unknown));

        $this->assertSame('policy', self::json($response)['reason']);
    }

    public function testAKeyThatCanSyncIsRefusedEvenWhenAttested(): void {
        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested() + ['be' => true]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testACertificateThatNamesAnotherAaguidIsRefusedOutright(): void {
        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested(certificateAaguid: self::YUBIKEY5));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('attestation', self::json($response)['reason']);
    }

    public function testAnAttestationSignatureThatDoesNotVerifyIsRefusedOutright(): void {
        $forge = $this->attested();
        $forge['attestationKey'] = TestPki::attestation($this->vendorRoot)['key'];

        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $forge);

        $this->assertSame('attestation', self::json($response)['reason']);
    }

    public function testAModelMds3HasWrittenOffIsRefused(): void {
        $this->listed([self::VENDOR_KEY], [self::VENDOR_KEY => 'ATTESTATION_KEY_COMPROMISE']);

        $response = $this->add(new SoftAuthenticator(aaguid: self::VENDOR_KEY), $this->attested());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    // -----------------------------------------------------------------
    // fail closed
    // -----------------------------------------------------------------

    public function testWithoutMetadataNoRegistrationStarts(): void {
        R::exec('DELETE FROM authenticator_metadata');
        Config::set('passkey_metadata_blob', ['serial' => null, 'next_update' => null, 'fetched_at' => null]);

        $response = $this->call('POST', '/v1/users/5/passkeys/options', [], $this->sessionOf(5, 'pwd'));
        $body = self::json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('metadata', $body['reason']);
        $this->assertStringContainsString('metadata is missing or out of date', $body['error']);
        $this->assertArrayNotHasKey('publicKey', $body);
    }

    public function testStaleMetadataRefusesTheRegistrationItself(): void {
        $key = new SoftAuthenticator(aaguid: self::VENDOR_KEY);
        $token = $this->sessionOf(5, 'pwd');
        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $token));
        PasskeyMetadata::useClock(new MockClock(new \DateTimeImmutable('+10 days')));
        $this->metadata([self::VENDOR_KEY => ['attestationRootCertificates' => [TestPki::der($this->vendorRoot)]]], nextUpdate: date('Y-m-d', time() + 86400));
        PasskeyMetadata::useClock(new MockClock(new \DateTimeImmutable('+40 days')));

        $response = $this->call('POST', '/v1/users/5/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey'], $this->attested()), true),
            'stepup'       => ['password' => self::PASSWORD],
        ], $token);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('metadata', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
        $this->assertSame('metadata', $this->events('passkey_registration_failed')[0]['reason']);
    }

    public function testTheEnrolmentBeforeASessionIsClosedToo(): void {
        $this->requirement('passkey');
        R::exec('DELETE FROM authenticator_metadata');

        $response = $this->call('POST', '/v1/users/authenticate/passkey/enroll', ['username' => 'alice', 'password' => self::PASSWORD]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('metadata', self::json($response)['reason']);
    }

    public function testAnyNeedsNoMetadataAtAll(): void {
        Config::set('passkeys', ['type' => 'any'] + Config::get('passkeys'));
        R::exec('DELETE FROM authenticator_metadata');

        $response = $this->add(new SoftAuthenticator());

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testKeysAlreadyRegisteredKeepSigningInWhenTheMetadataGoesStale(): void {
        $key = new SoftAuthenticator(aaguid: self::VENDOR_KEY);
        $this->stored($key);
        $this->requirement('passkey', open: false);
        PasskeyMetadata::useClock(new MockClock(new \DateTimeImmutable('+60 days')));
        $this->assertFalse(PasskeyMetadata::usable());

        $response = $this->passkey($key);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('passkey', self::json($response)['amr']);
    }

    // -----------------------------------------------------------------
    // what MDS3 says afterwards
    // -----------------------------------------------------------------

    public function testAModelWrittenOffLaterStopsItsKeysSigningIn(): void {
        $key = new SoftAuthenticator(aaguid: self::VENDOR_KEY);
        $this->stored($key);
        $this->requirement('passkey', open: false);
        $this->assertSame(200, $this->passkey($key)->getStatusCode());

        R::exec("UPDATE authenticator_metadata SET status = 'USER_VERIFICATION_BYPASS' WHERE aaguid = ?", [self::VENDOR_KEY]);
        $response = $this->passkey($key);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Passkey sign-in failed', self::json($response)['error']);
        $this->assertSame('key does not meet the passkey policy', $this->events('login_failed')[0]['reason']);
    }

    public function testAModelWrittenOffLaterEndsTheSessionItSignedIn(): void {
        $key = new SoftAuthenticator(aaguid: self::VENDOR_KEY);
        $row = $this->stored($key);
        $this->requirement('passkey', open: false);
        $token = self::json($this->passkey($key))['token'];
        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $token)->getStatusCode());

        R::exec("UPDATE authenticator_metadata SET status = 'REVOKED' WHERE aaguid = ?", [self::VENDOR_KEY]);
        $response = $this->call('GET', '/v1/users/5/passkeys', [], $token);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Session ended: passkey no longer valid', self::json($response)['error']);
        $this->assertSame((int) $row['id'], (int) Passkeys::listForUser(5)[0]['id'], 'the key is still on file');
    }

    public function testTheListShowsAWrittenOffKeyAsNotCompliant(): void {
        $this->stored(new SoftAuthenticator(aaguid: self::VENDOR_KEY));
        R::exec("UPDATE authenticator_metadata SET status = 'REVOKED'");

        $body = self::json($this->call('GET', '/v1/users/5/passkeys', [], $this->sessionOf(5, 'pwd')));

        $this->assertFalse($body['passkeys'][0]['compliant']);
        $this->assertTrue($body['passkeys'][0]['attestation_verified']);
        $this->assertSame(0, $body['compliant_count']);
    }

    public function testTheModelIsNamedByMds3ElseTheCatalogueElseNothing(): void {
        Config::set('passkeys', ['type' => 'any'] + Config::get('passkeys'));
        R::exec('DELETE FROM authenticator_metadata');
        $this->metadata([self::VENDOR_KEY => ['description' => 'Vendor Key 3']]);
        $this->storedKey(new SoftAuthenticator(aaguid: self::VENDOR_KEY), [], 5);
        $this->storedKey(new SoftAuthenticator(aaguid: self::YUBIKEY5), [], 5);
        $this->storedKey(new SoftAuthenticator(aaguid: '00000000-0000-0000-0000-000000000000'), [], 5);
        $this->storedKey(new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-0000000000ee'), [], 5);
        TestAccounts::ensure(5, 'user', 2, 'alice');

        $body = self::json($this->call('GET', '/v1/users/5/passkeys', [], $this->sessionOf(5, 'pwd')));

        $this->assertSame(['Vendor Key 3', 'YubiKey 5 Series', null, null], array_column($body['passkeys'], 'model'));
    }

    public function testAnMdsDescriptionBeatsTheCataloguesName(): void {
        Config::set('passkeys', ['type' => 'any'] + Config::get('passkeys'));
        $this->metadata([self::YUBIKEY5 => ['description' => 'YubiKey 5 Series with NFC']]);
        $this->storedKey(new SoftAuthenticator(aaguid: self::YUBIKEY5), [], 5);
        TestAccounts::ensure(5, 'user', 2, 'alice');

        $body = self::json($this->call('GET', '/v1/users/5/passkeys', [], $this->sessionOf(5, 'pwd')));

        $this->assertSame('YubiKey 5 Series with NFC', $body['passkeys'][0]['model']);
    }
}
