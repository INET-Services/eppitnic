<?php

namespace Eppitnic\Persistence;

use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\SigninRequirement;
use RedBeanPHP\R;

/**
 * Who may reach and deactivate which user -- one place for the rules the
 * /v1/users routes and the `user` CLI commands both apply.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\UserRules
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class UserRules
{
    public const LAST_ADMIN = 'cannot demote or deactivate the last active admin';
    public const LAST_MANAGER = 'cannot demote or deactivate the last active manager of an active reseller';
    public const SELF_DEACTIVATION = 'you cannot deactivate yourself';
    public const SELF_LOOSENING = 'you cannot loosen your own sign-in requirement';
    public const SELF_LOOSENING_SESSION = 'loosening your own sign-in requirement needs a signed-in session to confirm it';
    public const NOT_AUTHORIZED = 'You are not authorized to perform this operation';
    public const SYSOP = 'executed from CLI by a sysop';
    public const PASSKEYS_OFF = 'passkeys are not enabled: set the relying party first (settings passkeys)';

    /**
     * An admin reaches every user; a manager only a non-admin of their own
     * reseller. Whether the actor is a manager at all is the caller's test.
     *
     * @param array $target a users row with at least reseller_id and role
     */
    public static function reachable(Scope $actor, array $target): bool {
        return $actor->isAdmin()
            || ((int) $target['reseller_id'] === $actor->resellerId && $target['role'] !== 'admin');
    }

    /**
     * Remove a user's TOTP secret and any pending enrolment, and record it.
     * What the user must set up again is their sign-in requirement's to say.
     *
     * @return array the users rows (id, active, role, ...) the API returns
     */
    public static function resetMfa(int $userId, ?int $actorId): array {
        R::exec('UPDATE users SET totp_secret = NULL, totp_secret_pending = NULL WHERE id = :id', [':id' => $userId]);

        $users = R::getAll('SELECT id, active, role, username, max_token_age, max_idle_time, debug
                            FROM users WHERE id = :id', [':id' => $userId]);
        History::record('users', $userId, 'update', $users[0] ?? [], $actorId);
        return $users;
    }

    /**
     * Take a user's sign-in back to the start: every passkey and the TOTP
     * secret go, the password becomes a temporary one that must be changed, and
     * a mode that wants keys lets the password register them again.
     *
     * @param array $user the users row
     * @param string $passwordHash the temporary password, hashed
     * @param string|null $mode a new requirement to set at the same time;
     *        null keeps the user's
     * @param array $note more for the history row, e.g. who ran it from where
     * @return array{passkeys: int, totp: bool, enroll_open: bool}
     */
    public static function resetSignin(
        array $user,
        string $passwordHash,
        ?int $actorId,
        ?string $mode = null,
        array $note = []
    ): array {
        $id = (int) $user['id'];
        $mode ??= (string) $user['signin_requirement'];
        $hadTotp = ! empty($user['totp_secret']) || ! empty($user['totp_secret_pending']);
        $open = (int) SigninRequirement::needsKeys($mode);

        $revoked = Passkeys::deleteAllForUser($id);
        R::exec('UPDATE users SET totp_secret = NULL, totp_secret_pending = NULL, password = :password,
                 must_change_password = 1, passkey_enroll_open = :open,
                 signin_requirement = :mode WHERE id = :id', [
            ':password' => $passwordHash, ':open' => $open, ':mode' => $mode, ':id' => $id,
        ]);

        History::record('users', $id, 'update', [
            'signin_reset'         => true,
            'passkeys_revoked'     => $revoked,
            'totp_removed'         => $hadTotp,
            'password'             => 'PASSWORD_CHANGED',
            'must_change_password' => 1,
            'passkey_enroll_open'  => $open,
            'signin_requirement'   => $mode,
        ] + $note, $actorId);

        return ['passkeys' => $revoked, 'totp' => $hadTotp, 'enroll_open' => $open === 1];
    }

    /**
     * What `passkey_enroll_open` is once a user's requirement has changed: set
     * when it asks for more keys than the user has, cleared when it asks for no
     * more than they have, else as it was.
     *
     * @param array $target a users row
     */
    public static function enrollOpenAfter(array $target, string $mode): int {
        $minimum = SigninRequirement::minimumKeys($mode);
        if ($minimum === 0 || PasskeyPolicy::compliantCount((int) $target['id']) >= $minimum) {
            return 0;
        }
        $before = SigninRequirement::minimumKeys((string) $target['signin_requirement']);
        return $minimum > $before ? 1 : (int) ($target['passkey_enroll_open'] ?? 0);
    }

    /** Open or close the password-only registration of a user's keys, and record a change. */
    public static function setEnrollOpen(int $userId, bool $open, ?int $actorId, string $reason): void {
        $now = (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = :id', [':id' => $userId]);
        if ($now === (int) $open) {
            return;
        }
        R::exec('UPDATE users SET passkey_enroll_open = :open WHERE id = :id', [':open' => (int) $open, ':id' => $userId]);
        History::record('users', $userId, 'update', ['passkey_enroll_open' => (int) $open, 'reason' => $reason], $actorId);
    }

    /** After a key was added: close the enrolment once the minimum is reached. */
    public static function settleEnrollOpen(int $userId, ?int $actorId): void {
        $user = R::getRow('SELECT signin_requirement, passkey_enroll_open FROM users WHERE id = :id', [':id' => $userId]);
        if (empty($user) || (int) $user['passkey_enroll_open'] !== 1) {
            return;
        }
        $minimum = SigninRequirement::minimumKeys((string) $user['signin_requirement']);
        if (PasskeyPolicy::compliantCount($userId) >= $minimum) {
            self::setEnrollOpen($userId, false, $actorId, 'minimum number of passkeys reached');
        }
    }

    /**
     * Whether $actor may give $target this sign-in requirement. Only an admin
     * sets 'none', only an admin loosens their own (the caller then asks for
     * a step-up), and a key cannot be required while passkeys are off.
     *
     * @param array $target a users row with id and signin_requirement
     * @param bool $adminMayLoosenOwn whether the caller confirms an admin's
     *        loosening of their own requirement; the CLI does not
     * @return array{0: int, 1: string}|null HTTP status and reason of the
     *         refusal, or null
     */
    public static function signinRequirementRefusal(
        Scope $actor,
        array $target,
        string $mode,
        bool $adminMayLoosenOwn = false
    ): ?array {
        $current = (string) $target['signin_requirement'];

        if ($mode === SigninRequirement::NONE && $current !== $mode && ! $actor->isAdmin()) {
            return [403, self::NOT_AUTHORIZED];
        }
        if (self::loosensOwn($actor, $target, $mode) && ! ($adminMayLoosenOwn && $actor->isAdmin())) {
            return [400, self::SELF_LOOSENING];
        }
        if (self::keysWantedButOff($mode, $current)) {
            return [400, self::PASSKEYS_OFF];
        }
        return null;
    }

    /** Whether $actor changes their own requirement to a weaker one. */
    public static function loosensOwn(Scope $actor, array $target, string $mode): bool {
        return (int) $target['id'] === $actor->userId
            && SigninRequirement::loosens((string) $target['signin_requirement'], $mode);
    }

    /** Whether a change to $mode asks for keys that cannot be registered. */
    public static function keysWantedButOff(string $mode, ?string $fromMode = null): bool {
        return SigninRequirement::needsKeys($mode)
            && $mode !== $fromMode
            && ! PasskeySettings::enabled();
    }

    /**
     * The security row for a requirement set to 'none' from the command line;
     * the API records the same event with its request (History).
     */
    public static function recordNone(?int $actorId, int $targetId, string $username): void {
        History::record('security', (int) $actorId, 'secread', [
            'event' => 'signin_requirement_none', 'target_id' => $targetId, 'target' => $username,
        ] + ($actorId === null ? ['by' => self::SYSOP] : []), $actorId);
    }

    /**
     * The last active admin, or the last active manager of an active reseller,
     * may not be demoted or deactivated -- someone has to be left who can fix
     * it; a promotion keeps them able to.
     *
     * @param array $target a users row with at least id, role and reseller_id
     * @return string|null the refusal, or null when someone else stays
     */
    public static function lastStandingError(array $target, bool $demoted, bool $deactivating): ?string {
        if ( ! $demoted && ! $deactivating) {
            return null;
        }
        $id = (int) $target['id'];
        $resellerId = (int) $target['reseller_id'];

        if ($target['role'] === 'admin' && self::activeCount('admin', null, $id) === 0) {
            return self::LAST_ADMIN;
        }
        // a deactivated reseller has nobody left to manage
        if ($target['role'] === 'manager' && self::activeCount('manager', $resellerId, $id) === 0
            && (int) R::getCell('SELECT active FROM resellers WHERE id = ?', [$resellerId]) === 1) {
            return self::LAST_MANAGER;
        }
        return null;
    }

    /**
     * How many other ACTIVE users hold $role -- in $resellerId if given, else
     * anywhere.
     */
    private static function activeCount(string $role, ?int $resellerId, int $excludeId): int {
        $sql = "SELECT COUNT(*) FROM users WHERE role = :role AND active = 1 AND id <> :id";
        $bind = [':role' => $role, ':id' => $excludeId];
        if ($resellerId !== null) {
            $sql .= " AND reseller_id = :reseller_id";
            $bind[':reseller_id'] = $resellerId;
        }
        return (int) R::getCell($sql, $bind);
    }
}
