<?php

namespace Eppitnic\Persistence;

use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\SigninRequirement;
use Eppitnic\Support\PasswordPolicy;
use Eppitnic\Support\Validate;
use RedBeanPHP\R;

/**
 * Creates a local login account -- extracted from UserCreateCommand so
 * Setup\Installer makes the first admin by the same path, neither
 * re-implementing the insert or the hashing.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\User
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class User
{
    /**
     * The columns the read routes select. A plain user only ever reads their
     * own row; the address and description are added for a manager or admin,
     * so the edit dialog can show what it is about to change.
     */
    public static function readColumns(bool $isManager): string {
        $columns = 'id, active, role, reseller_id,
            (SELECT name FROM resellers r WHERE r.id = users.reseller_id) AS reseller_name,
            username, max_token_age, max_idle_time, debug, notify_enabled,
            totp_secret IS NOT NULL AS has_totp, signin_requirement';

        return $isManager
            ? "{$columns}, description, email, must_change_password"
            : $columns;
    }

    /**
     * Add each user's compliant passkeys: `passkeys` (a count) and
     * `has_passkeys`. One query for all of them.
     *
     * @param array<int, array> $rows users rows with an id
     * @return array<int, array>
     */
    public static function withPasskeyCounts(array $rows): array {
        $counts = PasskeyPolicy::countsFor(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        foreach ($rows as &$row) {
            $row['passkeys'] = $counts[(int) $row['id']] ?? 0;
            $row['has_passkeys'] = $row['passkeys'] > 0;
        }
        return $rows;
    }

    /**
     * @return string|null why $mode cannot be stored, or null
     */
    public static function signinRequirementError(mixed $mode): ?string {
        if ( ! in_array($mode, SigninRequirement::MODES, true)) {
            return 'signin_requirement must be one of: ' . implode(', ', SigninRequirement::MODES);
        }
        return null;
    }

    /**
     * Every account needs its own address: it is where a password reset goes.
     *
     * @param int|null $exceptId the user being saved, who may keep their address
     * @return string|null why $email cannot be saved, or null when it can
     */
    public static function emailError(mixed $email, ?int $exceptId = null): ?string {
        if ( ! is_string($email) || trim($email) === '') {
            return 'email is required';
        }
        $email = trim($email);
        if ( ! Validate::isEmail($email) || mb_strlen($email) > Validate::USER_FIELD_MAX_LENGTHS['email']) {
            return 'email must be a valid address of at most ' . Validate::USER_FIELD_MAX_LENGTHS['email'] . ' characters';
        }
        $taken = R::getCell('SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(:email) AND id <> :id', [
            ':email' => $email,
            ':id'    => $exceptId ?? 0,
        ]);
        return (int) $taken > 0 ? 'this e-mail address is already used by another account' : null;
    }

    /**
     * @return string|null why $role cannot be given to a user of $resellerId,
     *                     or null when it can
     */
    public static function roleError(string $role, int $resellerId): ?string {
        if ( ! in_array($role, Scope::ROLES, true)) {
            return 'role must be one of: ' . implode(', ', Scope::ROLES);
        }
        if ($role === 'admin' && $resellerId !== 1) {
            return 'an admin can only belong to reseller 1';
        }
        return null;
    }

    /**
     * Managers and admins start with notifications on, plain users off.
     *
     * @param int|null $actorId who creates it, for history (null: nobody, as
     *                 when the installer makes the first admin)
     * @param bool|null $notifyEnabled overrides that default
     * @param string $signinRequirement see SigninRequirement::MODES
     * @throws UsernameTaken if $username is already in use
     * @throws \InvalidArgumentException if $password does not meet
     *                       PasswordPolicy, $role does not fit $resellerId,
     *                       $signinRequirement is unknown or wants keys while
     *                       passkeys are off, or $email is given and fails
     *                       emailError()
     * @return int the new row's id
     */
    public static function create(
        string $username,
        string $password,
        ?string $email = null,
        ?string $description = null,
        int $resellerId = 1,
        string $role = 'user',
        bool $mustChangePassword = false,
        ?int $actorId = null,
        bool $active = true,
        ?int $maxTokenAge = null,
        ?int $maxIdleTime = null,
        bool $debug = false,
        ?bool $notifyEnabled = null,
        string $signinRequirement = SigninRequirement::TOTP
    ): int {
        if (($error = self::roleError($role, $resellerId)) !== null) {
            throw new \InvalidArgumentException($error);
        }
        if (($error = self::signinRequirementError($signinRequirement)) !== null) {
            throw new \InvalidArgumentException($error);
        }
        if (UserRules::keysWantedButOff($signinRequirement)) {
            throw new \InvalidArgumentException(UserRules::PASSKEYS_OFF);
        }
        // The last point before a password becomes an uninspectable hash, and
        // reached from the installer, the CLI and anything added later. Callers
        // still check first: this is the guarantee, not the error message
        if ( ! PasswordPolicy::isAcceptable($password)) {
            throw new \InvalidArgumentException(PasswordPolicy::explain($password));
        }

        if ($email !== null && ($error = self::emailError($email)) !== null) {
            throw new \InvalidArgumentException($error);
        }

        if (R::getCell('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            throw new UsernameTaken("a user named '{$username}' already exists");
        }

        R::exec("
            INSERT INTO users (reseller_id, role, description, username, password, email, notify_enabled, active,
                               max_token_age, max_idle_time, debug, must_change_password,
                               signin_requirement, passkey_enroll_open)
            VALUES (:reseller_id, :role, :description, :username, :password, :email, :notify_enabled, :active,
                    :max_token_age, :max_idle_time, :debug, :must_change_password,
                    :signin_requirement, :enroll_open)
        ", [
            ':reseller_id'    => $resellerId,
            ':role'           => $role,
            ':description'    => $description,
            ':username'       => $username,
            ':password'       => password_hash($password, PASSWORD_DEFAULT),
            ':email'          => $email,
            ':notify_enabled' => (int) ($notifyEnabled ?? $role !== 'user'),
            ':active'         => (int) $active,
            ':max_token_age'  => $maxTokenAge,
            ':max_idle_time'  => $maxIdleTime,
            ':debug'          => (int) $debug,
            ':must_change_password' => (int) $mustChangePassword,
            ':signin_requirement'   => $signinRequirement,
            ':enroll_open'          => (int) SigninRequirement::needsKeys($signinRequirement),
        ]);

        $id = (int) R::getInsertID();
        History::record('users', $id, 'create', [
            'username' => $username, 'role' => $role, 'reseller_id' => $resellerId, 'signin_requirement' => $signinRequirement,
            'passkey_enroll_open' => (int) SigninRequirement::needsKeys($signinRequirement),
        ], $actorId);

        return $id;
    }
}
