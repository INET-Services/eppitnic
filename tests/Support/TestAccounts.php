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

        R::exec('INSERT OR IGNORE INTO users (id, username) VALUES (?, ?)', [$id, $username ?? "user{$id}"]);
        R::exec('UPDATE users SET reseller_id = ?, role = ?, active = 1 WHERE id = ?', [$resellerId, $role, $id]);
        R::exec('UPDATE users SET email = ? WHERE id = ? AND email IS NULL', ["user{$id}@example.test", $id]);
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
