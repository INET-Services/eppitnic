<?php

use Eppitnic\Api\Access;
use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\StoredPayload;
use Eppitnic\Service\EppSession;
use Eppitnic\Service\EppSettings;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Support\Money;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

$app->get('/v1/session/epp', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $epp = Config::get('epp');

    // Config::EPP_PUBLIC_FIELDS is the allow-list, shared with `eppitnic
    // config show` so that a field added to `epp` is hidden from both by
    // default rather than from whichever one was remembered.
    $public = [];
    foreach (Config::EPP_PUBLIC_FIELDS as $field) {
        $public[$field] = $epp[$field] ?? null;
    }
    // never the password itself, only whether one is configured -- what a UI
    // needs to tell "not set up" from "set up". The credential has its own
    // endpoint, so reading it is deliberate
    $public['password_set'] = ($epp['password'] ?? '') !== '';

    // a rotation that did not finish: the registry may be holding a credential
    // this installation has not adopted. `eppitnic doctor epp-password` settles
    // it; the candidate itself stays out of the response.
    $public['rotation_pending'] = ($epp['pendingPassword'] ?? '') !== '';

    return Json::response($response, ['epp' => $public]);
});

/**
 * The 8 plain `epp.*` fields -- see EppSettings, the same class `config
 * epp-set`/`config epp-server` use, so a change made here or on the command
 * line is validated and audited identically (`history`, `object='epp'`).
 * `password` is not one of these -- POST /v1/session/change-password.
 */
$app->patch('/v1/session/epp', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);
    $body = $request->getParsedBody() ?? [];

    try {
        EppSettings::set($body, $user_id);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    $epp = Config::get('epp');
    $public = [];
    foreach (Config::EPP_PUBLIC_FIELDS as $field) {
        $public[$field] = $epp[$field] ?? null;
    }
    $public['password_set'] = ($epp['password'] ?? '') !== '';
    $public['rotation_pending'] = ($epp['pendingPassword'] ?? '') !== '';

    return Json::response($response, ['epp' => $public]);
});

/**
 * The server's own IPv4 addresses, for the `interface` field's picker --
 * that field binds outgoing registry connections to one of them
 * (CURLOPT_INTERFACE), so a free-text value is one typo away from a
 * connection that silently never leaves the box. Loopback is excluded: it
 * can never route to the registry.
 */
$app->get('/v1/session/epp/interfaces', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);

    $addresses = [];
    foreach (net_get_interfaces() ?: [] as $interface) {
        foreach ($interface['unicast'] ?? [] as $unicast) {
            $address = $unicast['address'] ?? null;
            if ($address === null || ($unicast['family'] ?? null) !== 2 /* AF_INET */) {
                continue;
            }
            if (str_starts_with($address, '127.')) {
                continue;
            }
            $addresses[$address] = true;
        }
    }

    return Json::response($response, ['interfaces' => array_keys($addresses)]);
});

/**
 * The registry credential itself, as its own request rather than part of GET
 * /v1/session/epp: one arriving while a settings screen renders ends up in
 * caches and screenshots. Admin only, and refused on an incomplete TOTP
 * session.
 */
$app->get('/v1/session/epp/credentials', function (Request $request, Response $response, array $args): Response {
    $user_id = Auth::requireAdmin($request);

    $epp = Config::get('epp');
    if (($epp['password'] ?? '') === '') {
        return Json::response($response, ['error' => 'No registry password is configured'], 404);
    }

    $credentials = [
        'server'   => $epp['server'] ?? null,
        'username' => $epp['username'] ?? null,
        'password' => $epp['password'],
    ];

    // An unfinished rotation means the registry holds one of two passwords,
    // and only it knows which (`doctor epp-password` asks). Withholding the
    // candidate would lock the operator out exactly when they need it
    if (($epp['pendingPassword'] ?? '') !== '') {
        $credentials['pending_password'] = $epp['pendingPassword'];
        $credentials['note'] = 'A rotation did not finish: the registry holds either password. '
                             . 'Run `eppitnic doctor epp-password` to settle it.';
    }

    // A credential is leaving, so who took it and from where is recorded first.
    // Not the password: the log is read by more people, and more casually, than
    // the thing it is about
    History::recordSecurityEvent('epp_credentials_retrieved', $request, $user_id, [
        'rotation_pending' => isset($credentials['pending_password']),
    ]);

    return Json::response($response, ['credentials' => $credentials]);
});

// the registrar's own balance, not a reseller's business
$app->get('/v1/session/credit', function (Request $request, Response $response, array $args): Response {
    Auth::requireAdmin($request);
    ['debug' => $debug] = Auth::actor($request);

    try {
        $credit = EppSession::run(function ($nic, $session) {
            return $session->showCredit();
        }, $debug, null, false);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    return Json::response($response, [
        'credit' => $credit,
        // the text for people, in region.lc_monetary
        'credit_formatted' => $credit === null ? null : Money::euro($credit),
    ]);
});

/**
 * The queue as History's listing is: `limit` (max 1000; none means all),
 * `before_id`/`after_id` cursors, and `changed_since` (archived_time >=).
 * `total` counts every match of the filters, whatever the cursors cut off.
 * `server_time` is read before the queries, so it is a safe next
 * `changed_since`.
 */
$app->get('/v1/poll-queue', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $params = $request->getQueryParams();
    $activeOnly = ($params['active'] ?? '1') !== '0';

    $changedSince = (string) ($params['changed_since'] ?? '');
    if ($changedSince !== '' && ! Validate::isDatetime($changedSince)) {
        return Json::response($response, ['error' => "changed_since must be a datetime such as '2026-09-21 14:41:36'"], 400);
    }

    $serverTime = (string) R::getCell('SELECT CURRENT_TIMESTAMP');

    [$visible, $bind] = Access::messageScope($scope);
    $where = ($activeOnly ? 'archived_time IS NULL' : '1 = 1') . " AND {$visible}";
    if ($changedSince !== '') {
        $where .= ' AND archived_time >= :changed_since';
        $bind[':changed_since'] = $changedSince;
    }
    $total = (int) R::getCell("SELECT COUNT(*) FROM messages WHERE {$where}", $bind);

    foreach (['before_id' => '<', 'after_id' => '>'] as $cursor => $comparison) {
        if (isset($params[$cursor]) && $params[$cursor] !== '') {
            $where .= " AND id {$comparison} :{$cursor}";
            $bind[":{$cursor}"] = (int) $params[$cursor];
        }
    }

    $limit = isset($params['limit']) ? min(1000, max(1, (int) $params['limit'])) : null;
    $messages = R::getAll("SELECT * FROM messages WHERE {$where} ORDER BY id DESC" . ($limit !== null ? " LIMIT {$limit}" : ''), $bind);

    return Json::response($response, ['messages' => $messages, 'total' => $total, 'server_time' => $serverTime]);
});

$app->get('/v1/poll-queue/{id}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $id = (int) $args['id'];

    // one the caller may not see is simply not there
    [$visible, $bind] = Access::messageScope($scope);
    $message = R::getRow("SELECT * FROM messages WHERE id = :id AND {$visible}", [':id' => $id] + $bind);
    if (empty($message)) {
        return Json::response($response, ['error' => "Message id {$id} not found"], 404);
    }

    // the registry's response it came from: the newest msgqueue row of its
    // clTRID (and svTRID, where the row has one); null when none is stored
    $raw = null;
    if (($message['cl_trid'] ?? '') !== '') {
        $sql = 'SELECT sv_httpdata FROM msgqueue WHERE cl_trid = :cl_trid';
        $rawBind = [':cl_trid' => $message['cl_trid']];
        if (($message['sv_trid'] ?? '') !== '') {
            $sql .= ' AND (sv_trid IS NULL OR sv_trid = :sv_trid)';
            $rawBind[':sv_trid'] = $message['sv_trid'];
        }
        $stored = R::getCell($sql . ' ORDER BY id DESC LIMIT 1', $rawBind);
        $raw = $stored === null ? null : StoredPayload::decode((string) $stored);
        $raw = $raw === '' ? null : $raw;
    }

    return Json::response($response, ['message' => $message, 'raw' => $raw]);
});

$app->post('/v1/poll-queue/{id}/archive', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'scope' => $scope] = Auth::requireManager($request);
    $id = (int) $args['id'];

    [$visible, $bind] = Access::messageScope($scope);
    if ((int) R::getCell("SELECT COUNT(*) FROM messages WHERE id = :id AND {$visible}", [':id' => $id] + $bind) === 0) {
        return Json::response($response, ['error' => "Message id {$id} not found"], 404);
    }

    R::exec("UPDATE messages SET archived_time = CURRENT_TIMESTAMP, archived_user_id = ? WHERE id = ?", [$user_id, $id]);

    return Json::response($response, [
        'archived'         => true,
        'id'               => $id,
        'archived_time'    => R::getCell("SELECT archived_time FROM messages WHERE id = ?", [$id]),
        'archived_user_id' => $user_id,
    ]);
});

/**
 * Archive every unarchived message up to a moment the caller names, in one go.
 * The moment is the newest message the caller has looked at, so whatever the
 * registry delivered after they loaded the list stays in the queue instead of
 * being archived unread. Same contract as POST /v1/history/acknowledge.
 *
 * Body: {"until": "YYYY-MM-DD HH:MM:SS"}, compared to created_time inclusively.
 * `created_time` is only second-resolution, so a burst can land several
 * messages in the same second as the one the caller saw; pass
 * {"until_id": N} (that message's id) for an exact cutoff instead -- ids are
 * monotonic.
 *
 * Or {"ids": [...]} instead: exactly the messages a screen shows. Ids outside
 * the caller's scope are left alone and do not count.
 */
$app->post('/v1/poll-queue/archive', function (Request $request, Response $response, array $args): Response {
    ['id' => $user_id, 'scope' => $scope] = Auth::requireManager($request);
    $body = $request->getParsedBody() ?? [];

    // a manager archives only what they can see -- their reseller's messages
    [$visible, $scopeBind] = Access::messageScope($scope);
    // one stamp for every row, so the caller can show it without reloading
    $now = (string) R::getCell('SELECT CURRENT_TIMESTAMP');
    $outstanding = fn() => (int) R::getCell("SELECT COUNT(*) FROM messages WHERE archived_time IS NULL AND {$visible}", $scopeBind);

    if (array_key_exists('ids', $body)) {
        $ids = $body['ids'];
        if ( ! is_array($ids) || $ids === []
            || array_filter($ids, fn($id) => filter_var($id, FILTER_VALIDATE_INT) === false) !== []) {
            return Json::response($response, ['error' => 'ids must be a non-empty list of integers'], 400);
        }
        $archived = 0;
        // chunked: a whole cached queue is thousands of placeholders
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            $bind = [];
            foreach ($chunk as $i => $id) {
                $bind[":id{$i}"] = $id;
            }
            $names = implode(', ', array_keys($bind));
            $archived += R::exec(
                "UPDATE messages SET archived_time = :now, archived_user_id = :user
                 WHERE archived_time IS NULL AND id IN ({$names}) AND {$visible}",
                [':now' => $now, ':user' => $user_id] + $bind + $scopeBind
            );
        }
        return Json::response($response, [
            'archived'         => $archived,
            'archived_time'    => $now,
            'archived_user_id' => $user_id,
            'outstanding'      => $outstanding(),
        ]);
    }

    $until = (string) ($body['until'] ?? '');

    if ( ! Validate::isDatetime($until)) {
        return Json::response($response, ['error' => "until must be a datetime such as '2026-09-21 14:41:36'"], 400);
    }

    $untilId = $body['until_id'] ?? null;
    if ($untilId !== null && filter_var($untilId, FILTER_VALIDATE_INT) === false) {
        return Json::response($response, ['error' => 'until_id must be an integer'], 400);
    }

    if ($untilId !== null) {
        $where = 'archived_time IS NULL AND id <= :until_id';
        $bind = [':user' => $user_id, ':until_id' => (int) $untilId];
    } else {
        $where = 'archived_time IS NULL AND created_time <= :until';
        $bind = [':user' => $user_id, ':until' => $until];
    }

    $where .= " AND {$visible}";

    $archived = R::exec(
        "UPDATE messages SET archived_time = :now, archived_user_id = :user WHERE {$where}",
        [':now' => $now] + $bind + $scopeBind
    );

    return Json::response($response, array_filter([
        'archived'    => (int) $archived,
        'until'       => $until,
        'until_id'    => $untilId !== null ? (int) $untilId : null,
        'archived_time'    => $now,
        'archived_user_id' => $user_id,
        'outstanding' => $outstanding(),
    ], static fn($v) => $v !== null));
});

$app->post('/v1/session/change-password', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    // A supplied password must satisfy epp:pwType first, as `config
    // epp-password` requires: apply() writes the candidate before sending, so a
    // doomed one still costs a write. An omitted one is generated and is fine
    if (($params['password'] ?? '') !== '') {
        if ($error = Validate::eppField('password', (string) $params['password'])) {
            return Json::response($response, ['error' => $error], 400);
        }
    }

    // the shared registry credential, not a per-user password. Stamping the
    // attempt is not optional: `poll process` reads the same
    // `lastPasswordUpdate`, and would rotate right after a manual change
    $outcome = RegistryPasswordChange::apply($params['password'] ?? null, true, 'manual', $userId);

    if ( ! $outcome['ok']) {
        $status = match ($outcome['stage']) {
            'connect', 'unknown' => 502,
            'registry' => 400,
            default    => 500,
        };
        return Json::response($response, ['error' => $outcome['error']], $status);
    }

    return Json::response($response, ['changed' => true]);
});

$app->post('/v1/session/adopt-password', function (Request $request, Response $response, array $args): Response {
    $userId = Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    $password = (string) ($params['password'] ?? '');
    if ($password === '') {
        return Json::response($response, ['error' => 'password is required'], 400);
    }
    if ($error = Validate::eppField('password', $password)) {
        return Json::response($response, ['error' => $error], 400);
    }

    // verified by a login with it; stored only when the registry accepts
    $outcome = RegistryPasswordChange::adopt($password, $userId);

    if ( ! $outcome['ok']) {
        return Json::response($response, ['error' => $outcome['error']],
            $outcome['stage'] === 'credentials' ? 400 : 502);
    }

    $body = ['adopted' => true];
    if (isset($outcome['warning'])) {
        $body['warning'] = $outcome['warning'];
    }
    return Json::response($response, $body);
});
