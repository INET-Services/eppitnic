<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Support\Idn;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

// A task belongs to its domain's reseller: anyone in it sees the reseller's
// tasks, an admin all of them.
$app->get('/v1/tasks', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $params = $request->getQueryParams();

    $page = max(1, (int) ($params['page'] ?? 1));
    $pageSize = min(200, max(1, (int) ($params['pageSize'] ?? 25)));

    $where = ['1 = 1'];
    $bind = [];
    if ( ! $scope->isAdmin()) {
        $where[] = 'domain IN (SELECT domain FROM domains WHERE reseller_id = :reseller_id)';
        $bind[':reseller_id'] = $scope->resellerId;
    }
    if (isset($params['object']) && $params['object'] !== '') {
        $where[] = $params['object'] === 'null' ? 'object IS NULL' : 'object = :object';
        if ($params['object'] !== 'null') {
            $bind[':object'] = $params['object'];
        }
    }
    if (isset($params['action']) && $params['action'] !== '') {
        $where[] = $params['action'] === 'null' ? 'action IS NULL' : 'action = :action';
        if ($params['action'] !== 'null') {
            $bind[':action'] = $params['action'];
        }
    }
    if (isset($params['active']) && $params['active'] !== '') {
        $where[] = 'active = :active';
        $bind[':active'] = (int) $params['active'];
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) R::getCell("SELECT COUNT(*) FROM tasks WHERE {$whereSql}", $bind);
    $rows = R::getAll("
        SELECT id, domain, date, notice, email, object, action, active,
               executed_time, exit_code, exit_message, created_time
        FROM tasks
        WHERE {$whereSql}
        ORDER BY id DESC
        LIMIT " . (($page - 1) * $pageSize) . ", " . $pageSize, $bind);

    return Json::response($response, [
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'rows' => $rows,
    ]);
});

$app->get('/v1/domains/{name}/tasks', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $name = Idn::domain($args['name']);

    // object IS NULL: only the plain human-facing notices belong here --
    // an automated consumer's own rows (object set) are its own business,
    // not something to surface as if a person scheduled them.
    $where = ['d.domain = r.domain', 'r.active = 1', 'r.object IS NULL', 'd.domain = :domain'];
    $bind = [':domain' => $name];
    if ( ! $scope->isAdmin()) {
        $where[] = 'd.reseller_id = :reseller_id';
        $bind[':reseller_id'] = $scope->resellerId;
    }

    $tasks = R::getAll("
        SELECT r.id, r.date, r.domain, r.email, r.notice
        FROM domains d, tasks r
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.date DESC", $bind);

    return Json::response($response, ['tasks' => $tasks]);
});

$app->post('/v1/domains/{name}/tasks', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $name = Idn::domain($args['name']);
    $params = $request->getParsedBody() ?? [];

    if (empty($params['date']) || empty($params['notice'])) {
        return Json::response($response, ['error' => 'date and notice are required'], 400);
    }

    if (($error = Validate::futureDateError($params['date'])) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    $where = ['domain = :domain'];
    $bind = [':domain' => $name];
    if ( ! $scope->isAdmin()) {
        $where[] = 'reseller_id = :reseller_id';
        $bind[':reseller_id'] = $scope->resellerId;
    }
    $owns = (int) R::getCell("SELECT COUNT(*) FROM domains WHERE " . implode(' AND ', $where), $bind);
    if ($owns !== 1) {
        return Json::response($response, ['error' => "Domain '{$name}' does not belong to this reseller"], 403);
    }

    R::exec("INSERT INTO tasks (domain, date, notice, email) VALUES (:domain, :date, :notice, :email)", [
        ':domain' => $name,
        ':date'   => $params['date'],
        ':notice' => $params['notice'],
        ':email'  => $params['email'] ?? '',
    ]);

    return Json::response($response, ['created' => true, 'domain' => $name], 201);
});

$app->delete('/v1/tasks/{id}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $id = (int) $args['id'];

    $where = ['r.id = :id', 'r.domain = d.domain'];
    $bind = [':id' => $id];
    // a DNS-sync event is the system's own bookkeeping, not the reseller's
    if ( ! $scope->isAdmin()) {
        $where[] = 'd.reseller_id = :reseller_id';
        $where[] = "(r.object IS NULL OR r.object = 'registry')";
        $bind[':reseller_id'] = $scope->resellerId;
    }
    $task = R::getRow("
        SELECT r.domain, r.date, r.object, r.action, r.active, d.id AS domain_id
        FROM domains d, tasks r WHERE " . implode(' AND ', $where), $bind);
    if (empty($task)) {
        return Json::response($response, ['error' => "Task not found, or not yours to deactivate"], 403);
    }

    R::exec("UPDATE tasks SET active = 0 WHERE id = ?", [$id]);

    // a cancelled deletion is a decision about the domain, so it is on record
    if ($task['object'] === 'registry' && $task['action'] === 'delete' && (int) $task['active'] === 1) {
        History::record('domains', (int) $task['domain_id'], 'update', [
            'domain'             => $task['domain'],
            'scheduled_deletion' => 'deactivated',
            'date'               => $task['date'],
            'task_id'            => $id,
        ], $scope->userId);
    }

    return Json::response($response, ['archived' => true, 'id' => $id]);
});
