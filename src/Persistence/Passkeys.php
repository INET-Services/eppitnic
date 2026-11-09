<?php

namespace Eppitnic\Persistence;

use Eppitnic\Service\WebAuthnAssertion;
use Eppitnic\Service\WebAuthnRegistration;
use Eppitnic\Support\Validate;
use RedBeanPHP\R;

/**
 * The `passkeys` table. Credential ids are raw bytes here, base64url only on
 * the wire. `source` is the library's record as registered; the counter and
 * backup state live in their columns, which an assertion is checked against.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\Passkeys
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Passkeys
{
    /** the longest credential id WebAuthn allows, in bytes */
    public const CREDENTIAL_ID_MAX = 1023;

    /** the most keys one user has registered, whether they comply or not */
    public const LIMIT = 10;
    public const LIMIT_MESSAGE = 'A user can have at most ' . self::LIMIT . ' passkeys; remove one first';

    /**
     * @throws PasskeyExists if the credential id is registered already, for
     *         this or any other user
     * @throws \InvalidArgumentException if a value does not fit its column, or
     *         the user has LIMIT keys already
     * @return int the new row's id
     */
    public static function add(int $userId, WebAuthnRegistration $registration, string $name): int {
        $id = $registration->credentialId;
        $transports = $registration->transports === [] ? null : json_encode($registration->transports);
        $row = [
            'name'               => trim($name),
            'attestation_format' => $registration->attestationFormat,
            'transports'         => $transports,
        ];
        if ($id === '' || strlen($id) > self::CREDENTIAL_ID_MAX) {
            throw new \InvalidArgumentException('credential id must be 1 to ' . self::CREDENTIAL_ID_MAX . ' bytes');
        }
        if ($row['name'] === '') {
            throw new \InvalidArgumentException('name is required');
        }
        if (($error = Validate::maxLength($row, Validate::PASSKEY_FIELD_MAX_LENGTHS)) !== null) {
            throw new \InvalidArgumentException($error);
        }
        if (self::findByCredentialId($id) !== null) {
            throw new PasskeyExists('this passkey is already registered');
        }
        if (self::atLimit($userId)) {
            throw new \InvalidArgumentException(self::LIMIT_MESSAGE);
        }

        try {
            R::exec(
                'INSERT INTO passkeys (user_id, credential_id, source, aaguid, sign_count, backup_eligible,
                        backup_state, attestation_format, attestation_verified, transports, name, created_at)
                 VALUES (:user, :credential, :source, :aaguid, :count, :eligible, :state, :format, :verified,
                         :transports, :name, :created)',
                [
                    ':user'       => $userId,
                    ':credential' => $id,
                    ':source'     => $registration->source,
                    ':aaguid'     => $registration->aaguid,
                    ':count'      => $registration->signCount,
                    ':eligible'   => (int) $registration->backupEligible,
                    ':state'      => (int) $registration->backupState,
                    ':format'     => $row['attestation_format'],
                    ':verified'   => (int) $registration->attestationVerified,
                    ':transports' => $transports,
                    ':name'       => $row['name'],
                    ':created'    => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // two registrations of one key racing past the check above
            if (self::findByCredentialId($id) !== null) {
                throw new PasskeyExists('this passkey is already registered', 0, $e);
            }
            throw $e;
        }
        return (int) R::getInsertID();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array {
        $row = R::getRow('SELECT * FROM passkeys WHERE id = :id', [':id' => $id]);
        return empty($row) ? null : $row;
    }

    /**
     * @param string $credentialId raw bytes
     * @return array<string, mixed>|null
     */
    public static function findByCredentialId(string $credentialId): ?array {
        if ($credentialId === '' || strlen($credentialId) > self::CREDENTIAL_ID_MAX) {
            return null;
        }
        $row = R::getRow('SELECT * FROM passkeys WHERE credential_id = :credential', [':credential' => $credentialId]);
        return empty($row) ? null : $row;
    }

    /** @return array<int, array<string, mixed>> oldest first */
    public static function listForUser(int $userId): array {
        return R::getAll('SELECT * FROM passkeys WHERE user_id = :user ORDER BY id', [':user' => $userId]);
    }

    public static function countForUser(int $userId): int {
        return (int) R::getCell('SELECT COUNT(*) FROM passkeys WHERE user_id = :user', [':user' => $userId]);
    }

    public static function atLimit(int $userId): bool {
        return self::countForUser($userId) >= self::LIMIT;
    }

    /**
     * Record a sign-in with the key. The counter only moves forward, in the
     * statement itself, so two sign-ins racing on one key cannot both win.
     *
     * @param string|null $ip the client's address
     * @return bool false if the key is gone or its counter has gone ahead of
     *         this assertion's meanwhile
     */
    public static function updateUsage(int $id, WebAuthnAssertion $assertion, ?string $ip): bool {
        return R::exec(
            'UPDATE passkeys SET sign_count = :count, backup_state = :state, last_used_at = :now, last_used_ip = :ip
             WHERE id = :id AND sign_count <= :newest',
            [
                ':count'  => $assertion->signCount,
                ':state'  => (int) $assertion->backupState,
                ':now'    => date('Y-m-d H:i:s'),
                ':ip'     => $ip === null ? null : mb_substr($ip, 0, Validate::PASSKEY_FIELD_MAX_LENGTHS['last_used_ip']),
                ':id'     => $id,
                ':newest' => $assertion->signCount,
            ]
        ) === 1;
    }

    /**
     * @throws \InvalidArgumentException if $name is empty or too long
     */
    public static function rename(int $id, string $name): bool {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name is required');
        }
        if (($error = Validate::maxLength(['name' => $name], Validate::PASSKEY_FIELD_MAX_LENGTHS)) !== null) {
            throw new \InvalidArgumentException($error);
        }
        return R::exec('UPDATE passkeys SET name = :name WHERE id = :id', [':name' => $name, ':id' => $id]) === 1;
    }

    public static function delete(int $id): bool {
        return R::exec('DELETE FROM passkeys WHERE id = :id', [':id' => $id]) === 1;
    }

    /** @return int how many keys went */
    public static function deleteAllForUser(int $userId): int {
        return (int) R::exec('DELETE FROM passkeys WHERE user_id = :user', [':user' => $userId]);
    }

    /**
     * The user handle authenticators store with a key: 32 random bytes made on
     * first use, kept for good, never the id.
     *
     * @return string raw bytes
     * @throws \InvalidArgumentException if there is no such user
     */
    public static function handleFor(int $userId): string {
        $handle = R::getCell('SELECT webauthn_handle FROM users WHERE id = :id', [':id' => $userId]);
        if ($handle === null && (int) R::getCell('SELECT COUNT(*) FROM users WHERE id = :id', [':id' => $userId]) === 0) {
            throw new \InvalidArgumentException("no user with id {$userId}");
        }
        if (is_string($handle) && $handle !== '') {
            return $handle;
        }

        // of two first uses at once, the later one finds the earlier one's
        R::exec('UPDATE users SET webauthn_handle = :handle WHERE id = :id AND webauthn_handle IS NULL', [
            ':handle' => random_bytes(32), ':id' => $userId,
        ]);
        $handle = (string) R::getCell('SELECT webauthn_handle FROM users WHERE id = :id', [':id' => $userId]);
        if ($handle === '') {
            throw new \RuntimeException("could not store a user handle for user {$userId}");
        }
        return $handle;
    }
}
