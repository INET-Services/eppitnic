<?php

namespace Eppitnic\Api;

use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use RedBeanPHP\R;

/**
 * What a caller may reach: contacts, domains and pending transfers belong to
 * a reseller, and anyone in it may work on them (an admin on anyone's). Also
 * the reseller's daily quota on registrations and transfer-in requests.
 *
 * @category    Net
 * @package     Eppitnic\Api\Access
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Access
{
    /**
     * A domain may be operated on by anyone in its reseller, or any admin. Every
     * write route calls this *before* opening a session: the *DB() helpers scope
     * by reseller only after the registry has changed.
     *
     * @param bool $includePending also accept a domain that so far only exists as a
     *                     pending transfer-in request (the `transfers` table) --
     *                     the state a transfer/cancel operates on, where the
     *                     domain isn't in `domains` yet; a cancelled one is
     *                     only a record and does not count
     */
    public static function canAccessDomain(string $domain, Scope $scope, bool $includePending = false): bool {
        if ($scope->isAdmin()) {
            return true;
        }
        $owns = (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = ? AND reseller_id = ?", [$domain, $scope->resellerId]);
        if ($owns > 0) {
            return true;
        }
        if ($includePending) {
            return (int) R::getCell("SELECT COUNT(*) FROM transfers WHERE domain = ? AND reseller_id = ? AND status = 'pending'", [$domain, $scope->resellerId]) > 0;
        }
        return false;
    }

    /**
     * The caller's transfer-in request for a name, pending or cancelled, unless
     * the domain is already stored as active, as in the domain list. An admin
     * reaches any reseller's.
     *
     * @return array|null the `transfers` row, or null
     */
    public static function transferIn(string $domain, Scope $scope): ?array {
        $where = ['domain = :domain'];
        $bind = [':domain' => $domain];
        if ( ! $scope->isAdmin()) {
            $where[] = 'reseller_id = :reseller_id';
            $bind[':reseller_id'] = $scope->resellerId;
        }
        return R::getRow("
            SELECT domain, registrant, authinfo, dns, kind, status, time
            FROM transfers WHERE " . implode(' AND ', $where) . "
              AND NOT EXISTS (SELECT 1 FROM domains d WHERE d.domain = transfers.domain AND d.active = 1)", $bind) ?: null;
    }

    /**
     * Whether another reseller already holds this domain -- the check for
     * claim-style operations (a transfer-in), where the caller is not expected to
     * own it yet but must not pull it away from another reseller either.
     */
    public static function domainHeldByAnotherReseller(string $domain, Scope $scope): bool {
        if ($scope->isAdmin()) {
            return false;
        }
        return (int) R::getCell(
            "SELECT COUNT(*) FROM domains WHERE domain = ? AND reseller_id <> ? AND active = 1",
            [$domain, $scope->resellerId]
        ) > 0;
    }

    /**
     * Whether the caller may make $handle a domain's registrant. A domain always
     * belongs to its registrant's reseller, so naming another reseller's contact
     * hands the domain away -- ownership, not canAccessContact()'s read access.
     */
    public static function canUseAsRegistrant(string $handle, Scope $scope): bool {
        if ($scope->isAdmin()) {
            return true;
        }
        return (int) R::getCell(
            "SELECT COUNT(*) FROM contacts WHERE handle = ? AND reseller_id = ?",
            [$handle, $scope->resellerId]
        ) > 0;
    }

    /**
     * whether the contact belongs to the caller's reseller (or they are an admin)
     */
    public static function ownsContact(string $handle, Scope $scope): bool {
        return $scope->isAdmin()
            || (int) R::getCell("SELECT COUNT(*) FROM contacts WHERE handle = ? AND reseller_id = ?", [$handle, $scope->resellerId]) > 0;
    }

    /**
     * a reseller may access a contact it doesn't own if it's attached (as
     * registrant, admin, or tech) to at least one domain it DOES own
     */
    public static function canAccessContact(string $handle, Scope $scope): bool {
        if (self::ownsContact($handle, $scope)) {
            return true;
        }
        $attached = (int) R::getCell("
            SELECT COUNT(*) FROM domains
            WHERE reseller_id = ? AND (registrant = ? OR admin = ? OR tech LIKE ?)
        ", [$scope->resellerId, $handle, $handle, '%"' . $handle . '"%']);
        return $attached > 0;
    }

    /**
     * Whether the caller's reseller may register or transfer in one more domain
     * today. Counts the `request` rows its users wrote today -- registrations and
     * transfer-in requests alike, whatever became of them; admins are exempt.
     */
    public static function withinQuota(Scope $scope): bool {
        if ($scope->isAdmin()) {
            return true;
        }
        $max = (int) R::getCell('SELECT max_operations FROM resellers WHERE id = ?', [$scope->resellerId]);
        if ($max <= 0) {
            return true;
        }
        $used = (int) R::getCell("
            SELECT COUNT(*) FROM history
            WHERE object = 'domains' AND action = 'request' AND DATE(timestamp) = CURRENT_DATE
              AND user_id IN (SELECT id FROM users WHERE reseller_id = ?)
        ", [$scope->resellerId]);
        return $used < $max;
    }

    /**
     * The quota row: a registration or transfer-in the caller asked for.
     */
    public static function recordRequest(string $domain, string $kind, Scope $scope): void {
        $id = (int) R::getCell('SELECT id FROM domains WHERE domain = ?', [$domain]);
        History::record('domains', $id, 'request', ['domain' => $domain, 'kind' => $kind], $scope->userId);
    }

    /**
     * Which poll messages the caller may see: an admin every one; anyone else
     * those about a domain (or pending transfer-in) of their reseller -- an
     * account-level message has no domain, so it stays the admins'.
     *
     * @return array{0: string, 1: array} a WHERE condition and its bindings
     */
    public static function messageScope(Scope $scope): array {
        if ($scope->isAdmin()) {
            return ['1 = 1', []];
        }
        return [
            "(domain IN (SELECT domain FROM domains WHERE reseller_id = :msg_reseller)
              OR domain IN (SELECT domain FROM transfers WHERE reseller_id = :msg_reseller AND status = 'pending'))",
            [':msg_reseller' => $scope->resellerId],
        ];
    }
}
