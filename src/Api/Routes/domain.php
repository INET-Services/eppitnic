<?php

use Eppitnic\Api\Access;
use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Domain;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Scope;
use Eppitnic\Persistence\SerializedColumn;
use Eppitnic\Service\ContactRefresh;
use Eppitnic\Service\DomainService;
use Eppitnic\Service\EppSession;
use Eppitnic\Service\PowerDnsZones;
use Eppitnic\Support\Csv;
use Eppitnic\Support\Idn;
use Eppitnic\Support\Validate;
use Eppitnic\Support\Warnings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * serialize a Domain's relevant fields for a JSON response
 */
function domainToArray(Domain $domain): array {
    return [
        'domain'     => $domain->get('domain'),
        'status'     => $domain->get('status'),
        'registrant' => $domain->get('registrant'),
        'admin'      => $domain->get('admin'),
        'tech'       => array_keys((array)$domain->get('tech')),
        'ns'         => DomainService::nameserverList((array)$domain->get('ns')),
        'ns_pending' => DomainService::nameserverList((array)$domain->get('nsToValidate')),
        'authinfo'   => $domain->get('authinfo'),
        'dnssec'     => DomainService::dnssecList((array)$domain->get('dnssec')),
        'cr_date'    => $domain->get('crDate'),
        'ex_date'    => $domain->get('exDate'),
    ];
}

/**
 * A transfer-in request as GET /v1/domains/{name} reports it: its status and
 * kind (transfer or trade), when it was asked (the server's local time) and
 * the nameservers to apply once it completes.
 */
function transferToArray(array $row): array {
    return [
        'status'       => $row['status'],
        'kind'         => $row['kind'],
        'requested_at' => $row['time'],
        'ns'           => DomainService::requestedNameserverList(SerializedColumn::toArray($row['dns'])),
    ];
}

/**
 * What is known of a transfer-in locally, in the domainToArray() shape: the
 * registry reports nothing of a domain it still sponsors for someone else.
 */
function transferDomainToArray(array $row): array {
    return [
        'domain'     => $row['domain'],
        'status'     => [],
        'registrant' => $row['registrant'],
        'admin'      => '',
        'tech'       => [],
        'ns'         => [],
        'ns_pending' => [],
        'authinfo'   => $row['authinfo'],
        'dnssec'     => [],
        'cr_date'    => null,
        'ex_date'    => null,
    ];
}

/**
 * the 403 every ownership check above answers with
 */
function domainForbidden(Response $response, string $domain): Response {
    return Json::response($response, ['error' => "You are not authorized to modify domain '{$domain}'"], 403);
}

$app->get('/v1/domains', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $params  = $request->getQueryParams();

    $nic = new Client();
    $domain = new Domain($nic);
    $domains = $domain->listDomains(
        $scope,
        $params['registrant'] ?? null,
        ($params['active'] ?? '1') !== '0',
        isset($params['age']) ? (int) $params['age'] : 0
    );

    return Json::response($response, ['domains' => $domains]);
});

$app->get('/v1/domains/expiring', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $days = (int) ($request->getQueryParams()['days'] ?? 30);

    // scoped by the DOMAIN's reseller, like every other domain route
    $where = ['1 = 1'];
    $params = [':days' => $days];
    if ( ! $scope->isAdmin()) {
        $where[] = 'd.reseller_id = :reseller_id';
        $params[':reseller_id'] = $scope->resellerId;
    }

    $domains = R::getAll("
        SELECT d.*, c.handle, c.org, c.name, c.email
        FROM domains d
        LEFT JOIN contacts c ON d.registrant = c.handle
        WHERE
            d.ex_date < NOW() + INTERVAL :days DAY AND
            d.active = 1 AND
            " . implode(' AND ', $where) . "
        ORDER BY d.ex_date ASC", $params);

    // raw SQL, so nothing has turned these back into arrays yet -- shipping
    // them as stored would put PHP's serialize() format in a JSON response
    foreach ($domains as &$row) {
        foreach (['ns', 'tech', 'status', 'dnssec'] as $column) {
            $row[$column] = SerializedColumn::toArray($row[$column] ?? null);
        }
        $row['dnssec'] = DomainService::dnssecList($row['dnssec']);
    }
    unset($row);

    return Json::response($response, ['domains' => $domains]);
});

$app->get('/v1/domains/autocomplete', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $term = $request->getQueryParams()['term'] ?? '';
    $limit = (int) ($request->getQueryParams()['limit'] ?? 10) ?: 10;

    // a literal term: % and _ would otherwise match anything
    $where = ["domain LIKE :term ESCAPE '!'"];
    $params = [':term' => '%' . strtr((string) $term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'];
    if ( ! $scope->isAdmin()) {
        $where[] = 'reseller_id = :reseller_id';
        $params[':reseller_id'] = $scope->resellerId;
    }

    $domains = R::getCol("SELECT domain FROM domains WHERE active = 1 AND " . implode(' AND ', $where), $params);
    $transfersIn = R::getCol("
        SELECT concat(domain, CASE WHEN status = 'cancelled' THEN ' (transfer-in cancelled)' ELSE ' (transfer-in)' END)
        FROM transfers WHERE " . implode(' AND ', $where) . "
        AND NOT EXISTS (SELECT 1 FROM domains d WHERE d.domain = transfers.domain AND d.active = 1)", $params);
    $domains = array_merge($domains, $transfersIn);
    sort($domains);

    return Json::response($response, ['domains' => array_slice($domains, 0, $limit)]);
});

$app->get('/v1/domains/export', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);

    $where = ['1 = 1'];
    $params = [];
    if ( ! $scope->isAdmin()) {
        $where[] = 'd.reseller_id = :reseller_id';
        $params[':reseller_id'] = $scope->resellerId;
    }
    $records = R::getAll("
        SELECT
            d.active, d.domain, d.authinfo, d.cr_date, d.ex_date,
            c.handle, c.org, c.name, c.email
        FROM
            domains d
            LEFT JOIN contacts c ON d.registrant = c.handle
        WHERE
            " . implode(' AND ', $where) . "
        ORDER BY d.domain ASC", $params);

    $titles = ['Active', 'Domain', 'Auth-Info', 'Created', 'Expires', 'Registrant Handle', 'Registrant Org', 'Registrant Name', 'Registrant Email'];
    $fields = ['active', 'domain', 'authinfo', 'cr_date', 'ex_date', 'handle', 'org', 'name', 'email'];

    // Csv::row() rather than a fourth inline copy of the quoting -- it also
    // doubles embedded quotes, which this did not: 'Rossi "Da Bepi" S.r.l.'
    // ended the field early and shifted every following column
    $csv = Csv::row($titles, ';');
    foreach ($records as $record) {
        $row = [];
        foreach ($fields as $field) {
            $row[] = $record[$field];
        }
        $csv .= Csv::row($row, ';');
    }

    $response->getBody()->write($csv);
    return $response
        ->withHeader('Content-Type', 'text/csv; charset=utf-8')
        ->withHeader('Content-Disposition', 'attachment; filename="domains-export.csv"');
});

$app->get('/v1/domains/transfers', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $registrant = $request->getQueryParams()['registrant'] ?? '';

    // scoped by the requesting reseller (transfers.reseller_id), matching the
    // transfer/cancel authorization check
    $where = ['1 = 1'];
    $bind = [];
    if ($registrant !== '') {
        $where[] = 't.registrant = :registrant';
        $bind[':registrant'] = $registrant;
    }
    if ( ! $scope->isAdmin()) {
        $where[] = 't.reseller_id = :reseller_id';
        $bind[':reseller_id'] = $scope->resellerId;
    }

    $rows = R::getAll("
        SELECT t.id, t.domain, t.status, t.kind, t.techc, t.dns, t.reseller_id, c.name, c.email
        FROM transfers t LEFT JOIN contacts c ON c.handle = t.registrant
        WHERE " . implode(' AND ', $where), $bind);

    $transfers = array_map(function ($row) {
        $row['techc'] = empty($row['techc']) ? [] : unserialize($row['techc']);
        $row['dns'] = empty($row['dns']) ? [] : unserialize($row['dns']);
        return $row;
    }, $rows);

    return Json::response($response, ['transfers' => $transfers]);
});

$app->get('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];

    // before the registry: its answer carries the authinfo, which is ours for
    // every reseller's domain. 404 so another reseller's names don't leak. A
    // cancelled transfer-in is not accessible to canAccessDomain(), but shown
    $transferIn = Access::transferIn($name, $scope);
    if ($transferIn === null && ! Access::canAccessDomain($name, $scope, true)) {
        return Json::response($response, ['error' => "Domain '{$name}' not found"], 404);
    }
    $transfer = $transferIn === null ? null : transferToArray($transferIn);

    // the registry is authoritative: its answer is returned as-is, never
    // overlaid with the local row -- loadDB() re-initializes before its lookup
    // and leaves the object empty when that misses. A transfer-in is asked
    // with its request's code, or not at all without one
    $domain = null;
    if ($transferIn === null || ($transferIn['authinfo'] ?? '') !== '') {
        try {
            $domain = EppSession::run(function ($nic) use ($name, $transferIn) {
                $domain = new Domain($nic);
                return $domain->fetch($name, $transferIn['authinfo'] ?? null) ? $domain : null;
            }, $debug);
        } catch (\RuntimeException $e) {
            // registry unreachable -- indistinguishable from "not found" as far as
            // this route is concerned, both fall through to the local fallback
            $domain = null;
        }
    }

    if ($domain !== null) {
        return Json::response($response, ['domain' => domainToArray($domain), 'stale' => false, 'transfer' => $transfer]);
    }

    // a transfer-in only lives in `transfers`: never a 404
    if ($transferIn !== null) {
        return Json::response($response, ['domain' => transferDomainToArray($transferIn), 'stale' => true, 'transfer' => $transfer]);
    }

    // registry lookup failed: serve the last known local state, flagged as
    // possibly stale. loadDB() scopes by reseller, so another reseller's
    // domain is simply not found. (via a variable: Domain takes it by
    // reference)
    $nic = new Client();
    $domain = new Domain($nic);
    if ( ! $domain->loadDB($name, $scope)) {
        return Json::response($response, ['error' => "Domain '{$name}' not found"], 404);
    }

    return Json::response($response, ['domain' => domainToArray($domain), 'stale' => true, 'transfer' => null]);
});

$app->post('/v1/domains', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $params = $request->getParsedBody() ?? [];

    // an AuthInfo asks for a transfer, which keeps the registrant unless one is given
    $authinfo = trim((string) ($params['authinfo'] ?? ''));
    $registrant = trim((string) ($params['registrant'] ?? ''));
    $required = $authinfo === '' ? ['domain', 'registrant'] : ['domain'];
    if ($err = Validate::requireFields($params, $required) ?? Validate::maxLength($params, Validate::DOMAIN_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }
    if ( ! Validate::isDomain($params['domain'])) {
        return Json::response($response, ['error' => "'{$params['domain']}' is not a valid .it domain name"], 400);
    }
    if (is_string($error = DomainService::checkedNameservers($params['domain'], $params['ns'] ?? []))) {
        return Json::response($response, ['error' => $error], 400);
    }
    if ($registrant !== '' && ! Access::canUseAsRegistrant($registrant, $scope)) {
        return Json::response($response, ['error' => "Contact '{$registrant}' is not yours to use as registrant"], 403);
    }
    // a transfer claims a domain, so it must not pull one from another reseller
    if ($authinfo !== '' && Access::domainHeldByAnotherReseller($params['domain'], $scope)) {
        return domainForbidden($response, $params['domain']);
    }
    if ( ! Access::withinQuota($scope)) {
        return Json::response($response, ['error' => 'Daily operation quota exceeded'], 429);
    }

    try {
        $result = EppSession::run(
            fn($nic) => DomainService::createOrTransfer($nic, $params, $scope),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    Access::recordRequest($params['domain'], $result['action'] === 'created' ? 'register' : 'transfer', $scope);
    return Json::response($response, ['domain' => domainToArray($result['domain'])] + Warnings::field($result['warnings']), 201);
});

$app->post('/v1/domains/import', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $params = $request->getParsedBody() ?? [];

    $names = array_unique(array_filter(array_map('trim', (array) ($params['domains'] ?? []))));
    if (empty($names)) {
        return Json::response($response, ['error' => 'domains is required (array of domain names)'], 400);
    }

    try {
        $results = EppSession::run(
            fn($nic) => DomainService::import($nic, $names, $scope),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    return Json::response($response, ['results' => $results]);
});

$app->patch('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if ($err = Validate::maxLength($params, Validate::DOMAIN_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }

    $dnssec = [];
    if (array_key_exists('dnssec', $params)) {
        $dnssec = DomainService::dnssecRecords($params['dnssec']);
        if (is_string($dnssec)) {
            return Json::response($response, ['error' => $dnssec], 400);
        }
    }

    $nameservers = [];
    if (array_key_exists('ns', $params)) {
        $nameservers = DomainService::nameserverEntries($params['ns']);
        if (is_string($nameservers)) {
            return Json::response($response, ['error' => $nameservers], 400);
        }
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $scope, $dnssec, $nameservers) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            // registrant changes are a distinct EPP operation
            // (Domain::updateRegistrant(), which requires authinfo to change
            // alongside it) -- handled by the dedicated
            // POST /domains/{name}/registrant endpoint instead.
            // admin and tech are accepted and ignored.
            if (array_key_exists('authinfo', $params)) $domain->set('authinfo', $params['authinfo']);

            // diffed add/remove: caller sends the full target NS/DNSSEC
            // list, we add what's missing and remove what's no longer present
            if (array_key_exists('ns', $params)) {
                $domain->startFromPendingNs();
                $glueError = DomainService::glueError($name, $nameservers, (array) $domain->get('ns'));
                if ($glueError !== null) {
                    return ['ok' => false, 'status' => 400, 'error' => $glueError];
                }
                $current = array_keys((array) $domain->get('ns'));
                $target = [];
                // addNS() leaves a nameserver alone unless its addresses changed
                foreach ($nameservers as $ns) {
                    if ($domain->addNS($ns['name'], $ns['ip']) === false) {
                        return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
                    }
                    $target[] = Idn::ascii($ns['name']);
                }
                foreach (array_diff($current, $target) as $rem) $domain->remNS($rem);
            }
            if (array_key_exists('dnssec', $params)) {
                $error = DomainService::applyDnssec($domain, $dnssec);
                if ($error !== null) {
                    return ['ok' => false, 'status' => 400, 'error' => $error];
                }
            }

            // update() resets this to 0 on success, so it must be captured
            // beforehand
            $changes = $domain->changedFields();
            $warnings = [];

            if ($changes === [] && (array_key_exists('admin', $params) || array_key_exists('tech', $params))) {
                return ['ok' => true, 'domain' => $domain, 'warnings' => $warnings];
            }

            // the new NS set's zone is prepared first, so the registry's DNS
            // check finds it answering; the old set is what undo() restores
            $zone = null;
            if (in_array('ns', $changes, true)) {
                $oldNs = array_keys(SerializedColumn::toArray((string) R::getCell('SELECT ns FROM domains WHERE domain = ?', [$name])));
                $zone = PowerDnsZones::provision($name, array_keys((array) $domain->get('ns')));
                if ($zone['warning'] !== null) {
                    $warnings[] = $zone['warning'];
                }
            }

            if ( ! $domain->update()) {
                if ($zone !== null) {
                    PowerDnsZones::undo($name, $zone, $oldNs);
                }
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            if ( ! $domain->updateDB($name, $scope, $changes)) {
                $warnings[] = Warnings::localWrite("domain '{$name}'", $domain->getError());
            }

            return ['ok' => true, 'domain' => $domain, 'warnings' => $warnings];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])] + Warnings::field($result['warnings']));
});

$app->post('/v1/domains/{name}/registrant', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['registrant'])) {
        return Json::response($response, ['error' => 'registrant is required'], 400);
    }
    // a registrant change moves the domain to that contact's reseller
    // (Domain::updateDB()), so it must be one of the caller's -- or this is a
    // way to hand a domain away by accident
    if ( ! Access::canUseAsRegistrant($params['registrant'], $scope)) {
        return Json::response($response, ['error' => "Contact '{$params['registrant']}' is not yours to use as registrant"], 403);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }

            $previous = $domain->get('registrant');
            $domain->set('registrant', $params['registrant']);
            // updateRegistrant() requires authinfo to change alongside
            // registrant -- rotate it (caller-supplied, or freshly generated)
            // as part of the change
            $domain->set('authinfo', $params['authinfo'] ?? $domain->authinfo());

            // updateRegistrant() resets the change set on success
            $changes = $domain->changedFields();
            if ( ! $domain->updateRegistrant()) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            $warnings = $domain->updateDB($name, $scope, $changes)
                ? []
                : [Warnings::localWrite("domain '{$name}'", $domain->getError())];
            ContactRefresh::run($nic, [$previous, $params['registrant']], $scope->userId);
            return ['ok' => true, 'domain' => $domain, 'warnings' => $warnings];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])] + Warnings::field($result['warnings']));
});

$app->post('/v1/domains/{name}/status', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['state'])) {
        return Json::response($response, ['error' => 'state is required'], 400);
    }
    $action = $params['action'] ?? 'add';

    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $action) {
            $domain = new Domain($nic);
            if ( ! $domain->fetch($name)) {
                return ['ok' => false, 'status' => 404, 'error' => "Domain '{$name}' not found"];
            }
            if ( ! $domain->updateStatus($params['state'], $action)) {
                return ['ok' => false, 'status' => 400, 'error' => $domain->getError()];
            }
            // the registry adds or drops ok/inactive around a change, so keep
            // what it reports now; the local guess only if that read fails
            $fresh = new Domain($nic);
            return ['ok' => true, 'domain' => $fresh->fetch($name) ? $fresh : $domain];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    // updateStatus() doesn't participate in the changes-bitmask, so it can't
    // go through the usual updateDB() guard -- sync the status column directly
    $sql = "UPDATE domains SET status = :status WHERE domain = :domain";
    $sqlParams = [':status' => serialize($result['domain']->get('status')), ':domain' => $name];
    if ( ! $scope->isAdmin()) {
        $sql .= " AND reseller_id = :reseller_id";
        $sqlParams[':reseller_id'] = $scope->resellerId;
    }
    $warnings = [];
    try {
        R::exec($sql, $sqlParams);
        $id = (int) R::getCell("SELECT id FROM domains WHERE domain = ?", [$name]);
        History::record('domains', $id, 'update', ['status' => $result['domain']->get('status')], $scope->userId);
    } catch (\Throwable $e) {
        $warnings[] = Warnings::localWrite("domain '{$name}'", $e->getMessage());
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])] + Warnings::field($warnings));
});

$app->delete('/v1/domains/{name}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getQueryParams();
    $mode = $params['mode'] ?? 'now';

    // checked up front so both the registry delete (mode=now) and the
    // schedule-a-reminder branch (mode=expiry|date) answer identically
    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }

    if ($mode === 'expiry' || $mode === 'date') {
        $date = $mode === 'date' ? ($params['date'] ?? null) : null;
        if ($mode === 'date' && empty($date)) {
            return Json::response($response, ['error' => 'date is required when mode=date'], 400);
        }
        if ($mode === 'date' && ($error = Validate::futureDateError($date)) !== null) {
            return Json::response($response, ['error' => $error], 400);
        }

        $row = R::getRow("SELECT id, ex_date FROM domains WHERE domain = :domain" . ($scope->isAdmin() ? '' : ' AND reseller_id = :reseller_id'), array_filter([
            ':domain' => $name,
            ':reseller_id' => $scope->isAdmin() ? null : $scope->resellerId,
        ], fn($v) => $v !== null));
        if (empty($row)) {
            return Json::response($response, ['error' => "Domain '{$name}' not found"], 404);
        }

        $scheduled = (int) R::getCell("SELECT COUNT(*) FROM tasks WHERE domain = ? AND object = 'registry' AND action = 'delete' AND active = 1", [$name]);
        if ($scheduled > 0) {
            return Json::response($response, ['error' => "A deletion of '{$name}' is already scheduled"], 409);
        }

        // object='registry', action='delete': `domain reap-deletions` reads
        // this and deletes the domain at the registry once `date` arrives.
        // The explicit action is what lets that job's own query say, in SQL,
        // that a delete is the only thing it will ever act on.
        R::exec("INSERT INTO tasks (domain, date, notice, email, object, action) VALUES (:domain, :date, :notice, '', 'registry', 'delete')", [
            ':domain' => $name,
            ':date'   => $date ?: $row['ex_date'],
            ':notice' => 'scheduled deletion',
        ]);
        History::record('domains', (int) $row['id'], 'update', [
            'domain'             => $name,
            'scheduled_deletion' => 'scheduled',
            'date'               => $date ?: $row['ex_date'],
            'task_id'            => (int) R::getInsertID(),
        ], $scope->userId);

        return Json::response($response, ['scheduled' => true, 'domain' => $name, 'date' => $date ?: $row['ex_date']]);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $scope) {
            $domain = new Domain($nic);
            $registrant = R::getCell('SELECT registrant FROM domains WHERE domain = ?', [$name]);
            if ( ! $domain->delete($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $warnings = $domain->deleteDomainDB($name, $scope)
                ? []
                : [Warnings::localWrite("domain '{$name}'", $domain->getError())];
            ContactRefresh::run($nic, [$registrant ?: null], $scope->userId);
            return ['ok' => true, 'warnings' => $warnings];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['deleted' => true, 'domain' => $name] + Warnings::field($result['warnings']));
});

$app->post('/v1/domains/{name}/restore', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];

    if ( ! Access::canAccessDomain($name, $scope)) {
        return domainForbidden($response, $name);
    }

    // restores are served from a host of their own
    $endpoint = Config::get('epp')['server_deleted'] ?? '';
    if ($endpoint === '') {
        return Json::response($response, ['error' => "The 'epp' setting has no server_deleted endpoint configured"], 409);
    }

    try {
        $result = EppSession::run(function ($nic) use ($name, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->restore($name)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }
            $warnings = $domain->restoreDomainDB($name, $scope)
                ? []
                : [Warnings::localWrite("domain '{$name}'", $domain->getError())];
            return ['ok' => true, 'warnings' => $warnings];
        }, $debug, new Client($endpoint));
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['restored' => true, 'domain' => $name] + Warnings::field($result['warnings']));
});

$app->post('/v1/domains/{name}/owner', function (Request $request, Response $response, array $args): Response {
    $actorId = Auth::requireAdmin($request);
    ['debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    if (empty($params['reseller_id'])) {
        return Json::response($response, ['error' => 'reseller_id (the new owner) is required'], 400);
    }
    $resellerId = (int) $params['reseller_id'];
    if ((int) R::getCell('SELECT COUNT(*) FROM resellers WHERE id = ?', [$resellerId]) === 0) {
        return Json::response($response, ['error' => "Reseller {$resellerId} not found"], 404);
    }

    try {
        $result = EppSession::run(
            fn($nic) => DomainService::changeOwner($nic, $name, $resellerId, $actorId),
            $debug
        );
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['domain' => domainToArray($result['domain'])]);
});

$app->post('/v1/domains/{name}/transfer', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $name = $args['name'];
    $params = $request->getParsedBody() ?? [];

    // a transfer-in request is a *claim*: the caller is not supposed to own the
    // domain yet, so Access::canAccessDomain() would reject every legitimate request.
    // What must be blocked is claiming a domain another reseller holds.
    if (Access::domainHeldByAnotherReseller($name, $scope)) {
        return domainForbidden($response, $name);
    }
    if (empty($params['authinfo'])) {
        return Json::response($response, ['error' => 'authinfo is required'], 400);
    }
    // a registrant makes it a transfer-trade; without, the domain keeps its own
    $registrant = trim((string) ($params['registrant'] ?? ''));
    if ($registrant !== '' && ! Access::canUseAsRegistrant($registrant, $scope)) {
        return Json::response($response, ['error' => "Contact '{$registrant}' is not yours to use as registrant"], 403);
    }
    // transfers.registrant is a foreign key onto contacts
    if ($registrant !== '' && (int) R::getCell('SELECT COUNT(*) FROM contacts WHERE handle = ?', [$registrant]) === 0) {
        return Json::response($response, ['error' => "Contact '{$registrant}' not found"], 404);
    }
    if ( ! Access::withinQuota($scope)) {
        return Json::response($response, ['error' => 'Daily operation quota exceeded'], 429);
    }
    $nameservers = DomainService::checkedNameservers($name, $params['ns'] ?? []);
    if (is_string($nameservers)) {
        return Json::response($response, ['error' => $nameservers], 400);
    }
    try {
        $result = EppSession::run(function ($nic) use ($name, $params, $registrant, $nameservers, $scope) {
            $domain = new Domain($nic);
            if ( ! $domain->transfer($name, $params['authinfo'], $registrant)) {
                return ['ok' => false, 'error' => $domain->getError()];
            }

            DomainService::recordTransferRequest(
                $name,
                $registrant === '' ? null : $registrant,
                $scope->resellerId,
                $nameservers,
                $params['authinfo']
            );
            return ['ok' => true];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    Access::recordRequest($name, 'transfer', $scope);
    return Json::response($response, ['requested' => true, 'domain' => $name], 201);
});

foreach (['approve', 'reject', 'cancel'] as $transferAction) {
    $app->post("/v1/domains/{name}/transfer/{$transferAction}", function (Request $request, Response $response, array $args) use ($transferAction): Response {
        ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
        $name = $args['name'];
        $params = $request->getParsedBody() ?? [];
        $authinfo = $params['authinfo'] ?? '';
        $method = 'transfer' . ucfirst($transferAction);

        // approve/reject answer a request for a domain we sponsor, so the
        // caller must own the `domains` row; cancel withdraws our own request,
        // which exists only in `transfers`
        if ( ! Access::canAccessDomain($name, $scope, $transferAction === 'cancel')) {
            return domainForbidden($response, $name);
        }
        // a cancel without a code uses the one the request was made with
        if ($authinfo === '' && $transferAction === 'cancel') {
            $authinfo = (string) (Access::transferIn($name, $scope)['authinfo'] ?? '');
        }

        try {
            $result = EppSession::run(function ($nic) use ($name, $authinfo, $method) {
                $domain = new Domain($nic);
                if ( ! $domain->$method($name, $authinfo)) {
                    return ['ok' => false, 'error' => $domain->getError()];
                }
                // a cancelled request stays as the record that we wanted the domain
                if ($method === 'transferCancel') {
                    R::exec("UPDATE transfers SET status = 'cancelled' WHERE domain = ?", [$name]);
                } else {
                    R::exec("DELETE FROM transfers WHERE domain = ?", [$name]);
                }
                return ['ok' => true];
            }, $debug);
        } catch (\RuntimeException $e) {
            return Json::response($response, ['error' => $e->getMessage()], 502);
        }

        if ( ! $result['ok']) {
            return Json::response($response, ['error' => $result['error']], 400);
        }

        return Json::response($response, [$transferAction => true, 'domain' => $name]);
    });
}
