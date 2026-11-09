<?php

namespace Eppitnic\Persistence;

use Eppitnic\Api\ClientIp;
use Eppitnic\Api\LoginRateLimit;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * The audit trail: who did what, and when. History rather than changelog
 * because not everything is a change -- a `security` row can note an event
 * that altered nothing, such as an admin reading the registry credential.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\History
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class History
{
    // -----------------------------------------------------------------
    // history
    // -----------------------------------------------------------------

    /**
     * Headers never worth keeping: `authorization` is a live bearer token, and
     * anyone with SELECT here could then act as whoever was logged; `cookie` is
     * the same problem. The rest is what makes an entry useful.
     */
    private const REDACTED_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    /** the `history.action` enum */
    public const ACTIONS = ['create', 'update', 'delete', 'secread', 'login', 'denied', 'request', 'rotate'];

    /** the `history.object` enum */
    public const OBJECTS = ['users', 'contacts', 'domains', 'security', 'cronjobs', 'epp', 'smtp', 'remote_auth', 'trusted_proxies', 'resellers', 'region', 'allowed_origins', 'debugfile', 'dnssec', 'passkeys'];

    /**
     * record an audit-trail entry
     *
     * @param string $object contacts/domains/users/security
     * @param int $object_id the object's DB row id; for `security`, the acting
     *            user
     * @param string $action create/update/delete, or on a `security` row
     *            secread/login/denied/rotate
     * @param array $data changed fields (or a minimal identifying set, for
     *              create/delete)
     * @param int|null $user_id acting user
     */
    public static function record(
        string $object,
        int $object_id,
        string $action,
        array $data,
        ?int $user_id,
        ?string $network = null
    ): void {
        R::exec("
            INSERT INTO history (user_id, object, object_id, action, network, data)
            VALUES (:user_id, :object, :object_id, :action, :network, :data)
        ", [
            ':user_id'   => $user_id,
            ':object'    => $object,
            ':object_id' => $object_id,
            ':action'    => $action,
            ':network'   => $network,
            ':data'      => json_encode($data),
        ]);
    }

    /**
     * Record something that is not a change to an object, with where it came
     * from -- masked to its rate-limiting prefix in `network`, what
     * LoginRateLimit counts.
     *
     * @param string $event what happened, e.g. 'epp_credentials_retrieved'
     * @param Request $request the request that caused it
     * @param int|null $user_id the acting user, also used as object_id so that
     *                 GET /v1/history/security/{id} answers "what did they do".
     *                 Null when there is nobody to attribute it to, which a
     *                 login attempt at an unknown username genuinely is --
     *                 blaming user 1 for it would be a false entry.
     * @param array $detail anything else worth keeping. Must not contain a
     *              secret -- this table is read casually, and by more people
     *              than the thing being recorded was shown to.
     * @param string $action 'secread' for a disclosure or a turned-away
     *               request; 'login' or 'denied' for an authentication
     */
    public static function recordSecurityEvent(
        string $event,
        Request $request,
        ?int $user_id,
        array $detail = [],
        string $action = 'secread'
    ): void {
        self::record(
            'security',
            (int) $user_id,
            $action,
            ['event' => $event, 'ip' => ClientIp::get(), 'headers' => self::safeHeaders($request)] + $detail,
            $user_id,
            LoginRateLimit::network()
        );
    }

    /**
     * The request's headers, less the ones that are themselves credentials.
     *
     * @return array<string, string> header name => value, comma-joined where a
     *         header appeared more than once
     */
    /**
     * Entries $scope may see, newest first: an admin sees everything; everyone
     * else their reseller's domains and contacts, the registrations and
     * transfer-ins its users requested, and their own `users` row -- a manager
     * also every user of the reseller and the reseller itself. Nobody but an
     * admin sees `security`, which carries other people's addresses and headers.
     *
     * @param array<string, mixed> $filters object, object_id, action (or several,
     *        comma-separated), network, acknowledged ('0'/'1'), since, until,
     *        before_id, after_id, limit, offset
     * @return array{rows: array<int, array<string, mixed>>, total: int} total
     *         counts every match, whatever before_id/after_id cut off
     */
    public static function visibleTo(Scope $scope, array $filters = []): array {
        [$where, $params] = self::scope($scope, $filters);
        $total = (int) R::getCell("SELECT COUNT(*) FROM history WHERE {$where}", $params);

        foreach (['before_id' => '<', 'after_id' => '>'] as $filter => $comparison) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $where .= " AND id {$comparison} :{$filter}";
                $params[":{$filter}"] = (int) $filters[$filter];
            }
        }

        $limit  = min(1000, max(1, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return [
            'rows'  => R::getAll(
                "SELECT * FROM history WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            ),
            'total' => $total,
        ];
    }

    /**
     * How many `security` entries nobody has acknowledged. Admin-only figure,
     * since admins are the only ones who can see those entries at all.
     */
    public static function outstandingSecurityCount(): int {
        return (int) R::getCell(
            "SELECT COUNT(*) FROM history WHERE object = 'security' AND acknowledged_time IS NULL"
        );
    }

    /**
     * The WHERE clause and its parameters: what this caller may see, narrowed
     * by whatever they asked for.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function scope(Scope $scope, array $filters): array {
        $params = [];
        $clauses = [];

        if ($scope->isAdmin()) {
            $clauses[] = '1 = 1';
        } else {
            // security is excluded by omission rather than by a NOT: a new
            // object type defaults to invisible until it is listed here. A
            // transfer-in request may have no domains row yet, hence its own
            // rule by who asked
            $params[':reseller'] = $scope->resellerId;
            if ($scope->isManager()) {
                $users = "object = 'users' AND object_id IN (SELECT id FROM users WHERE reseller_id = :reseller)
                   OR object = 'resellers' AND object_id = :reseller";
            } else {
                $params[':me'] = $scope->userId;
                $users = "object = 'users' AND object_id = :me";
            }
            $clauses[] = "({$users}
                        OR object = 'domains'  AND object_id IN (SELECT id FROM domains  WHERE reseller_id = :reseller)
                        OR object = 'contacts' AND object_id IN (SELECT id FROM contacts WHERE reseller_id = :reseller)
                        OR object = 'domains'  AND action = 'request'
                           AND user_id IN (SELECT id FROM users WHERE reseller_id = :reseller))";
        }

        foreach (['object' => 'object', 'object_id' => 'object_id', 'network' => 'network'] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $clauses[] = "{$column} = :{$filter}";
                $params[":{$filter}"] = $filters[$filter];
            }
        }

        // one action, or a comma-separated list of them meaning "any of these"
        $actions = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($filters['action'] ?? ''))),
            'strlen'
        )));
        if ($actions !== []) {
            $names = [];
            foreach ($actions as $i => $action) {
                $names[] = ":action{$i}";
                $params[":action{$i}"] = $action;
            }
            $clauses[] = 'action IN (' . implode(', ', $names) . ')';
        }

        $acknowledged = (string) ($filters['acknowledged'] ?? '');
        if ($acknowledged === '0' || $acknowledged === '1') {
            $clauses[] = $acknowledged === '0'
                ? 'acknowledged_time IS NULL'
                : 'acknowledged_time IS NOT NULL';
        }

        foreach (['since' => '>=', 'until' => '<=', 'changed_since' => '>='] as $filter => $comparison) {
            if ( ! empty($filters[$filter])) {
                $column = $filter === 'changed_since' ? 'acknowledged_time' : '`timestamp`';
                $clauses[] = "{$column} {$comparison} :{$filter}";
                $params[":{$filter}"] = $filters[$filter];
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    private static function safeHeaders(Request $request): array {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), self::REDACTED_HEADERS, true)) {
                // recorded as present, so that its absence from a log is not
                // mistaken for its absence from the request
                $headers[$name] = '[redacted]';
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }
}
