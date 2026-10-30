<?php

namespace Eppitnic\Persistence;

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
