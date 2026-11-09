<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Config;
use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Service\WebAuthnRegistration;
use RedBeanPHP\R;

/**
 * An SQLite database holding what the passkey code touches, and a relying
 * party of `localhost` with the origin the dev frontend runs on -- never an IP
 * address, which WebAuthn does not accept.
 */
abstract class PasskeyTestCase extends EppTestCase
{
    protected const RP_ID = 'localhost';
    protected const ORIGIN = 'http://localhost:5173';

    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['passkeys', 'webauthn_challenges', 'authenticator_metadata', 'users', 'settings', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensurePasskeyTables();

        Config::loadForTesting(static::SETTINGS + [
            'jwt_psk'  => 'test-signing-key-for-this-suite-only',
            'passkeys' => [
                'rp_id'         => self::RP_ID,
                'origins'       => [self::ORIGIN],
                'type'          => 'any',
                'models'        => [],
                'extra_aaguids' => [],
            ],
        ]);
        WebAuthnChallenges::useClock(null);
        PasskeyMetadata::useClock(null);
        PasskeyMetadata::useRoot(null);
        PasskeyMetadata::useHttpClient(null);
    }

    protected function tearDown(): void {
        WebAuthnChallenges::useClock(null);
        PasskeyMetadata::useClock(null);
        PasskeyMetadata::useRoot(null);
        PasskeyMetadata::useHttpClient(null);
        // accounts with a requirement and keys must not outlive the test
        foreach (['passkeys', 'webauthn_challenges', 'authenticator_metadata', 'users'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        parent::tearDown();
    }

    private static ?array $fidoRoot = null;
    private static ?array $fidoSigner = null;

    /** @return array{key: \OpenSSLAsymmetricKey, pem: string} the root this test's BLOBs chain to */
    protected function fidoRoot(): array {
        return self::$fidoRoot ??= TestPki::root('Test FIDO Root');
    }

    /**
     * An MDS3 BLOB signed under fidoRoot(), which is also what the metadata
     * code is told to trust.
     *
     * @param array<string, array> $authenticators see TestPki::payload()
     */
    protected function blob(array $authenticators = [], int $serial = 1, ?string $nextUpdate = null): string {
        PasskeyMetadata::useRoot($this->fidoRoot()['pem']);
        $signer = self::$fidoSigner ??= TestPki::signer($this->fidoRoot());
        return TestPki::jwt(TestPki::payload($authenticators, $serial, $nextUpdate), $signer, [$signer]);
    }

    /**
     * MDS3 data that is current, as a fetch would have left it.
     *
     * @param array<string, array> $entries AAGUID => fields of the stored entry
     *        (attestationRootCertificates, attestationTypes, statusReports ...)
     * @param array<string, string> $status AAGUID => its latest status
     */
    protected function metadata(array $entries = [], array $status = [], ?string $nextUpdate = null, int $serial = 1): void {
        $entries += ['ffffffff-ffff-4fff-8fff-ffffffffffff' => []];
        foreach ($entries as $aaguid => $entry) {
            R::exec('REPLACE INTO authenticator_metadata (aaguid, description, status, entry, updated_at) VALUES (?, ?, ?, ?, ?)', [
                $aaguid,
                $entry['description'] ?? null,
                $status[$aaguid] ?? 'FIDO_CERTIFIED',
                json_encode(array_diff_key($entry, ['description' => 1])),
                date('Y-m-d H:i:s'),
            ]);
        }
        AuthenticatorMetadata::saveBlob($serial, $nextUpdate ?? date('Y-m-d', time() + 30 * 86400), date('Y-m-d H:i:s'));
    }

    /** @return array the users row */
    protected function user(int $id = 5, string $username = 'alice'): array {
        TestAccounts::ensure($id, 'user', 1, $username);
        return R::getRow('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * Run a registration ceremony end to end.
     *
     * @return array{0: WebAuthnRegistration, 1: array} registration, user row
     */
    protected function registered(SoftAuthenticator $key, array $forge = [], ?array $user = null): array {
        $user ??= $this->user();
        [, $challenge] = WebAuthnChallenges::issue('register', (int) $user['id']);
        $options = WebAuthn::registrationOptions($user, [], 'none', $challenge);

        $registration = WebAuthn::verifyRegistration($key->register($options, $forge), $challenge, $this->user((int) $user['id']));
        return [$registration, $user];
    }

    /**
     * A registered key, stored for $userId as Passkeys::add() would.
     *
     * @return array the passkeys row
     */
    protected function storedKey(SoftAuthenticator $key, array $forge = [], int $userId = 5): array {
        [$registration, $user] = $this->registered($key, $forge, $this->user($userId));
        $id = Passkeys::add((int) $user['id'], $registration, 'Test key');
        return Passkeys::find($id);
    }

    /** A fresh usernameless login challenge and the options for it. */
    protected function loginOptions(): array {
        [, $challenge] = WebAuthnChallenges::issueLogin();
        return [WebAuthn::requestOptions($challenge), $challenge];
    }
}
