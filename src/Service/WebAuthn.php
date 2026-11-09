<?php

namespace Eppitnic\Service;

use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\PS256;
use Cose\Algorithm\Signature\RSA\RS256;
use Eppitnic\Persistence\Passkeys;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AttestationStatement\TPMAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\AttestationStatementException;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\CounterException;
use Webauthn\Exception\InvalidUserHandleException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn ceremonies, and the only class that talks to web-auth/webauthn-lib.
 * Challenges come as raw bytes (WebAuthnChallenges), the relying party from
 * PasskeySettings; whatever goes wrong comes out as a WebAuthnFailure.
 *
 * @category    Net
 * @package     Eppitnic\Service\WebAuthn
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class WebAuthn
{
    private const TIMEOUT_MS = 300000;

    /** a response is a few KB; a certificate chain in it is the most there is */
    private const RESPONSE_MAX_BYTES = 65536;

    private const ES256 = -7;
    private const EDDSA = -8;
    private const RS256 = -257;

    /** a P-256 key nobody holds the private half of, for spendVerificationTime() */
    private const DUMMY_KEY = "-----BEGIN PUBLIC KEY-----\n"
        . "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEBxbof5Bhu2MCzbHLmAt8aFRqNRYr\n"
        . "RRBcIGFYk+oCioQbjilTwnaPyz3iGvVTWpWPYwA0X5C12RwDPeUL7Q7yfQ==\n"
        . "-----END PUBLIC KEY-----\n";

    private const DUMMY_SIGNATURE = 'MEQCIAOmVLH0cYUoywDU3Kp1YSQbuJ+bEM7qHysw3vSgUsl/AiBrKwAZBnP+tznTWPCLGb+ZuGeGcK7HoWk7c0TeQqhDIA==';

    private const TRANSPORTS = ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'];

    /**
     * Library message => reason, first match wins. A new library version that
     * words one differently shows as 'invalid' and fails the tests.
     */
    private const MESSAGES = [
        '/challenge/i'                      => WebAuthnFailure::CHALLENGE,
        '/client data/i'                    => WebAuthnFailure::TYPE,
        '/cross-origin/i'                   => WebAuthnFailure::CROSS_ORIGIN,
        '/origin|scheme|facet/i'            => WebAuthnFailure::ORIGIN,
        '/rpId hash/i'                      => WebAuthnFailure::RP_ID_HASH,
        '/not present/i'                    => WebAuthnFailure::USER_PRESENCE,
        '/authentication required/i'        => WebAuthnFailure::USER_VERIFICATION,
        '/algorithm/i'                      => WebAuthnFailure::ALGORITHM,
        '/signature/i'                      => WebAuthnFailure::SIGNATURE,
        '/credential id|credential is not/i' => WebAuthnFailure::CREDENTIAL_ID,
        '/backup/i'                         => WebAuthnFailure::BACKUP_FLAGS,
        '/attestation/i'                    => WebAuthnFailure::ATTESTATION,
    ];

    private static ?SerializerInterface $serializer = null;

    // -----------------------------------------------------------------
    // registration
    // -----------------------------------------------------------------

    /**
     * What navigator.credentials.create() is given, JSON-ready: a resident,
     * user-verifying key, ES256, EdDSA or RS256 only.
     *
     * @param array $user users row: id, username, webauthn_handle once made
     * @param string[] $credentialIds raw ids the user has, which the browser
     *        must not register again
     * @param string $attestation 'none', or 'direct' when the model matters
     * @param string $challenge raw bytes from WebAuthnChallenges::issue()
     * @throws WebAuthnFailure if passkeys are not set up, or 'direct' is asked
     *         without MDS3 metadata to check the answer against
     */
    public static function registrationOptions(array $user, array $credentialIds, string $attestation, string $challenge): array {
        if ( ! in_array($attestation, ['none', 'direct'], true)) {
            throw new \InvalidArgumentException("attestation must be 'none' or 'direct'");
        }
        $settings = self::settings();
        self::requireMetadata($attestation);
        $options = self::creationOptions($settings, $user, $challenge, $attestation);
        $options->excludeCredentials = array_map(
            static fn(string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $id),
            array_values($credentialIds)
        );
        return self::normalize($options);
    }

    /**
     * @param string $responseJson what the browser returned, as JSON
     * @param string $challenge the raw bytes issued for this ceremony
     * @param array $user the users row the options were made for
     * @param string $attestation the preference the options asked for
     * @throws WebAuthnFailure
     */
    public static function verifyRegistration(
        string $responseJson,
        string $challenge,
        array $user,
        string $attestation = 'none'
    ): WebAuthnRegistration {
        try {
            $settings = self::settings();
            self::requireMetadata($attestation);
            $credential = self::credential($responseJson);
            $response = $credential->response;
            if ( ! $response instanceof AuthenticatorAttestationResponse) {
                throw new WebAuthnFailure(WebAuthnFailure::MALFORMED);
            }
            self::refuseCrossOrigin($response->clientDataJSON->crossOrigin, $response->clientDataJSON->topOrigin);

            // 'none' here: the library's metadata step would want repositories,
            // and AttestationTrust does that part
            $options = self::creationOptions($settings, $user, $challenge, 'none');
            $record = AuthenticatorAttestationResponseValidator::create(self::ceremonies($settings)->creationCeremony())
                ->check($response, $options, $settings['rp_id']);
            if ( ! hash_equals($record->publicKeyCredentialId, $credential->rawId)) {
                throw new WebAuthnFailure(WebAuthnFailure::CREDENTIAL_ID);
            }

            return new WebAuthnRegistration(
                credentialId: $record->publicKeyCredentialId,
                source: self::serializer()->serialize($record, 'json'),
                aaguid: $record->aaguid->toRfc4122(),
                signCount: $record->counter,
                backupEligible: (bool) $record->backupEligible,
                backupState: (bool) $record->backupStatus,
                attestationFormat: $response->attestationObject->attStmt->fmt,
                attestationVerified: $attestation === 'direct' && AttestationTrust::anchored(
                    $response->attestationObject->attStmt,
                    $record->aaguid->toRfc4122(),
                    PasskeyMetadata::now()
                ),
                transports: array_values(array_unique(array_filter(
                    $response->transports,
                    static fn(mixed $t): bool => in_array($t, self::TRANSPORTS, true)
                ))),
            );
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    // -----------------------------------------------------------------
    // assertion
    // -----------------------------------------------------------------

    /**
     * What navigator.credentials.get() is given, JSON-ready. With no
     * $credentialIds any resident key may answer (usernameless sign-in).
     *
     * @param string[] $credentialIds raw ids to restrict the ceremony to
     * @throws WebAuthnFailure if passkeys are not set up
     */
    public static function requestOptions(string $challenge, array $credentialIds = []): array {
        $settings = self::settings();
        return self::normalize(PublicKeyCredentialRequestOptions::create(
            $challenge,
            $settings['rp_id'],
            array_map(
                static fn(string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', $id),
                array_values($credentialIds)
            ),
            'required',
            self::TIMEOUT_MS
        ));
    }

    /**
     * @param string $responseJson what the browser returned, as JSON
     * @param string $challenge the raw bytes issued for this ceremony
     * @param array $passkey the passkeys row the response claims to be from
     * @param string|null $userHandle the handle of the user expected, or null
     *        when the response itself names the user (usernameless sign-in)
     * @throws WebAuthnFailure COUNTER_REGRESSION when the counter did not go
     *         forward, so a caller can record a security event
     */
    public static function verifyAssertion(
        string $responseJson,
        string $challenge,
        array $passkey,
        ?string $userHandle
    ): WebAuthnAssertion {
        try {
            $settings = self::settings();
            $credential = self::credential($responseJson);
            $response = $credential->response;
            if ( ! $response instanceof AuthenticatorAssertionResponse) {
                throw new WebAuthnFailure(WebAuthnFailure::MALFORMED);
            }
            self::refuseCrossOrigin($response->clientDataJSON->crossOrigin, $response->clientDataJSON->topOrigin);

            $record = self::serializer()->deserialize((string) $passkey['source'], CredentialRecord::class, 'json');
            // the columns, not `source`, are where these move on
            $record->counter = (int) $passkey['sign_count'];
            $record->backupEligible = (bool) $passkey['backup_eligible'];
            $record->backupStatus = (bool) $passkey['backup_state'];
            if ( ! hash_equals($record->publicKeyCredentialId, $credential->rawId)) {
                throw new WebAuthnFailure(WebAuthnFailure::CREDENTIAL_ID);
            }

            $options = PublicKeyCredentialRequestOptions::create($challenge, $settings['rp_id'], [], 'required', self::TIMEOUT_MS);
            $record = AuthenticatorAssertionResponseValidator::create(self::ceremonies($settings)->requestCeremony())
                ->check($record, $response, $options, $settings['rp_id'], $userHandle);

            return new WebAuthnAssertion(
                signCount: $record->counter,
                userVerified: $response->authenticatorData->isUserVerified(),
                backupEligible: (bool) $record->backupEligible,
                backupState: (bool) $record->backupStatus,
            );
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    // -----------------------------------------------------------------
    // before verifying
    // -----------------------------------------------------------------

    /**
     * @return string|null the credential as the JSON text this class takes,
     *         from the object or string a client sent; null if it sent neither
     */
    public static function credentialJson(mixed $credential): ?string {
        if (is_array($credential)) {
            return json_encode($credential) ?: null;
        }
        return is_string($credential) && $credential !== '' ? $credential : null;
    }

    /**
     * One signature check that fails, for an answer that has no stored key to
     * verify against, so it takes about as long as one that has.
     */
    public static function spendVerificationTime(): void {
        openssl_verify('no key to verify against', (string) base64_decode(self::DUMMY_SIGNATURE), self::DUMMY_KEY, OPENSSL_ALGO_SHA256);
    }

    /**
     * What a response claims, to find the stored key and the challenge row
     * with. Nothing here is verified; verifyRegistration() and
     * verifyAssertion() do that.
     *
     * @return array{credential_id: string, challenge: string, user_handle: ?string}
     *         raw bytes; the handle only in an assertion, else null
     * @throws WebAuthnFailure MALFORMED
     */
    public static function claims(string $responseJson): array {
        try {
            $credential = self::credential($responseJson);
            $response = $credential->response;
            $userHandle = $response instanceof AuthenticatorAssertionResponse ? $response->userHandle : null;
            return [
                'credential_id' => $credential->rawId,
                'challenge'     => $response->clientDataJSON->challenge,
                'user_handle'   => $userHandle === '' ? null : $userHandle,
            ];
        } catch (\Throwable $e) {
            throw self::failure($e);
        }
    }

    // -----------------------------------------------------------------
    // library plumbing
    // -----------------------------------------------------------------

    /**
     * @return array the passkeys setting, with an rp_id and origins to hold
     *         a ceremony to; without them there is no relying party to check
     */
    private static function settings(): array {
        $settings = PasskeySettings::get();
        if ($settings['rp_id'] === '' || $settings['origins'] === []) {
            throw new WebAuthnFailure(WebAuthnFailure::DISABLED);
        }
        return $settings;
    }

    private static function creationOptions(array $settings, array $user, string $challenge, string $attestation): PublicKeyCredentialCreationOptions {
        $handle = (string) ($user['webauthn_handle'] ?? '');
        if ($handle === '') {
            $handle = Passkeys::handleFor((int) $user['id']);
        }
        $name = (string) $user['username'];

        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($settings['rp_id'], $settings['rp_id']),
            PublicKeyCredentialUserEntity::create($name, $handle, $name),
            $challenge,
            array_map(
                static fn(int $alg): PublicKeyCredentialParameters => PublicKeyCredentialParameters::createPk($alg),
                self::algorithms()
            ),
            AuthenticatorSelectionCriteria::create(null, 'required', 'required'),
            $attestation,
            [],
            self::TIMEOUT_MS
        );
    }

    /** @return int[] the credential algorithms accepted, in order of preference */
    private static function algorithms(): array {
        return self::eddsaAvailable() ? [self::ES256, self::EDDSA, self::RS256] : [self::ES256, self::RS256];
    }

    private static function eddsaAvailable(): bool {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    private static function ceremonies(array $settings): CeremonyStepManagerFactory {
        $signatures = Manager::create()->add(ES256::create(), RS256::create());
        if (self::eddsaAvailable()) {
            $signatures->add(Ed25519::create());
        }

        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins($settings['origins']);
        $factory->setAlgorithmManager($signatures);
        $factory->setAttestationStatementSupportManager(self::attestationFormats());
        return $factory;
    }

    private static function attestationFormats(): AttestationStatementSupportManager {
        // attestation signatures, not credential keys: devices sign with more
        $signatures = Manager::create()->add(
            ES256::create(), ES384::create(), ES512::create(), RS256::create(), PS256::create()
        );
        if (self::eddsaAvailable()) {
            $signatures->add(Ed25519::create());
        }

        return AttestationStatementSupportManager::create([
            NoneAttestationStatementSupport::create(),
            PackedAttestationStatementSupport::create($signatures),
            FidoU2FAttestationStatementSupport::create(),
            // some TPMs still sign with SHA-1; the chain to the root is what counts
            TPMAttestationStatementSupport::create(PasskeyMetadata::clock(), true),
            AndroidKeyAttestationStatementSupport::create(),
            AppleAttestationStatementSupport::create(),
        ]);
    }

    private static function serializer(): SerializerInterface {
        return self::$serializer ??= (new WebauthnSerializerFactory(self::attestationFormats()))->create();
    }

    private static function normalize(object $options): array {
        return self::serializer()->normalize($options, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
    }

    private static function credential(string $responseJson): PublicKeyCredential {
        if (strlen($responseJson) > self::RESPONSE_MAX_BYTES) {
            throw new WebAuthnFailure(WebAuthnFailure::MALFORMED);
        }
        return self::serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');
    }

    /** A page framing ours, or a client claiming to be in one, is no sign-in. */
    private static function refuseCrossOrigin(bool $crossOrigin, ?string $topOrigin): void {
        if ($crossOrigin || $topOrigin !== null) {
            throw new WebAuthnFailure(WebAuthnFailure::CROSS_ORIGIN);
        }
    }

    /** @throws WebAuthnFailure if attestation is wanted and there is no current metadata for it */
    private static function requireMetadata(string $attestation): void {
        if ($attestation === 'direct' && ! PasskeyMetadata::usable()) {
            throw new WebAuthnFailure(WebAuthnFailure::METADATA);
        }
    }

    private static function failure(\Throwable $e): WebAuthnFailure {
        if ($e instanceof WebAuthnFailure) {
            return $e;
        }
        // anything else is a response that did not even parse
        $reason = match (true) {
            $e instanceof CounterException              => WebAuthnFailure::COUNTER_REGRESSION,
            $e instanceof InvalidUserHandleException    => WebAuthnFailure::USER_HANDLE,
            $e instanceof AttestationStatementException => WebAuthnFailure::ATTESTATION,
            $e instanceof AuthenticatorResponseVerificationException => self::reasonFor($e->getMessage()),
            default                                     => WebAuthnFailure::MALFORMED,
        };
        return new WebAuthnFailure($reason, $e);
    }

    private static function reasonFor(string $message): string {
        foreach (self::MESSAGES as $pattern => $reason) {
            if (preg_match($pattern, $message) === 1) {
                return $reason;
            }
        }
        return WebAuthnFailure::INVALID;
    }
}
