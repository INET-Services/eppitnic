<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Api\Auth;
use RedBeanPHP\R;

/**
 * Auth looks every caller up (active user, active reseller, role), so a token
 * for an account the test database does not hold is refused. These make the
 * account exist first, on whatever users table the test already built.
 */
final class TestAccounts
{
    /**
     * Auth::issueToken() for an account that then exists. `admin` (1/0) in
     * $claims is read as the role, as the tests have always written it.
     */
    public static function issueToken(array $claims): array {
        $role = $claims['role'] ?? ((int) ($claims['admin'] ?? 0) === 1 ? 'admin' : 'user');
        $resellerId = (int) ($claims['reseller_id'] ?? 1);
        self::ensure((int) $claims['id'], $role, $resellerId, (string) ($claims['username'] ?? 'user' . $claims['id']));

        unset($claims['admin']);
        return Auth::issueToken($claims + ['role' => $role, 'reseller_id' => $resellerId]);
    }

    public static function ensure(int $id, string $role = 'user', int $resellerId = 1, ?string $username = null): void {
        self::ensureReseller(1);
        self::ensureReseller($resellerId);

        R::exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, email TEXT, description TEXT)");
        $columns = array_column(R::getAll('PRAGMA table_info(users)'), 'name');
        $wanted = [
            'username'             => 'TEXT',
            'password'             => 'TEXT',
            'email'                => 'TEXT',
            'description'          => 'TEXT',
            'reseller_id'          => 'INTEGER NOT NULL DEFAULT 1',
            'role'                 => "TEXT NOT NULL DEFAULT 'user'",
            'active'               => 'INTEGER DEFAULT 1',
            'debug'                => 'INTEGER DEFAULT 0',
            'max_token_age'        => 'INTEGER',
            'token_lifetime'       => 'INTEGER',
            'max_idle_time'        => 'INTEGER',
            'last_activity'        => 'TEXT',
            'totp_secret'          => 'TEXT',
            'totp_secret_pending'  => 'TEXT',
            'must_change_password' => 'INTEGER NOT NULL DEFAULT 0',
            'must_enroll_mfa'      => 'INTEGER NOT NULL DEFAULT 0',
            'signin_requirement'   => "TEXT NOT NULL DEFAULT 'none'",
            'passkey_enroll_open'  => 'INTEGER NOT NULL DEFAULT 0',
            'webauthn_handle'      => 'BLOB',
            'api_token'            => 'TEXT',
            'api_token_expires'    => 'INTEGER NOT NULL DEFAULT 0',
            'notify_enabled'       => 'INTEGER NOT NULL DEFAULT 0',
            'notify_message_types' => 'TEXT',
            'notify_fulltext'      => 'TEXT',
        ];
        foreach ($wanted as $column => $type) {
            if ( ! in_array($column, $columns, true)) {
                R::exec("ALTER TABLE users ADD COLUMN {$column} {$type}");
            }
        }

        self::ensurePasskeyTables();

        R::exec('INSERT OR IGNORE INTO users (id, username) VALUES (?, ?)', [$id, $username ?? "user{$id}"]);
        R::exec('UPDATE users SET reseller_id = ?, role = ?, active = 1 WHERE id = ?', [$resellerId, $role, $id]);
        R::exec('UPDATE users SET email = ? WHERE id = ? AND email IS NULL', ["user{$id}@example.test", $id]);
    }

    /**
     * The tables Auth reads for a user with passkeys. Test users default to
     * `none` where the schema's default is `totp`, so a test that is not about
     * sign-in is not asked to set up MFA first.
     */
    public static function ensurePasskeyTables(): void {
        R::exec('CREATE TABLE IF NOT EXISTS passkeys (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, credential_id BLOB NOT NULL UNIQUE,
            source TEXT NOT NULL, aaguid TEXT NOT NULL, sign_count INTEGER NOT NULL DEFAULT 0,
            backup_eligible INTEGER NOT NULL DEFAULT 0, backup_state INTEGER NOT NULL DEFAULT 0,
            attestation_format TEXT NOT NULL, attestation_verified INTEGER NOT NULL DEFAULT 0,
            transports TEXT, name TEXT NOT NULL, created_at TEXT NOT NULL, last_used_at TEXT, last_used_ip TEXT)');
        R::exec('CREATE TABLE IF NOT EXISTS webauthn_challenges (
            id INTEGER PRIMARY KEY, purpose TEXT NOT NULL, user_id INTEGER, challenge_hash TEXT NOT NULL UNIQUE,
            context TEXT, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT)');
        R::exec('CREATE TABLE IF NOT EXISTS authenticator_metadata (
            aaguid TEXT PRIMARY KEY, description TEXT, status TEXT, entry TEXT NOT NULL, updated_at TEXT NOT NULL)');
    }

    public static function ensureReseller(int $id, ?string $name = null, int $maxOperations = 0): void {
        R::exec('CREATE TABLE IF NOT EXISTS resellers (
            id INTEGER PRIMARY KEY, name TEXT NOT NULL, max_operations INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1, creation_time TEXT DEFAULT CURRENT_TIMESTAMP,
            techc TEXT, countrycode TEXT, entitytype INTEGER, nssets TEXT, dnsset TEXT,
            dnssec_algorithm INTEGER, dnssec_digesttype INTEGER)');
        R::exec('INSERT OR IGNORE INTO resellers (id, name, max_operations) VALUES (?, ?, ?)', [
            $id, $name ?? ($id === 1 ? 'Registrar (self)' : "Reseller {$id}"), $maxOperations,
        ]);
    }
}
