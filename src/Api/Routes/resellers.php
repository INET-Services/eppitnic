<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Service\ResellerService;
use Eppitnic\Service\ResellerSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// A reseller's defaults and NS sets: anyone in it may read them; its
// managers (and any admin) may change them. Below that, the reseller rows
// themselves -- listing, creating and editing -- which only an admin does.

$app->get('/v1/resellers', function (Request $request, Response $response, array $args): Response {
    $actor = Auth::actor($request);
    $resellers = $actor['isAdmin'] ? ResellerService::list() : [ResellerService::get($actor['resellerId'])];

    return Json::response($response, ['resellers' => array_values(array_filter($resellers))]);
});

$app->get('/v1/resellers/{id}', function (Request $request, Response $response, array $args): Response {
    $actor = Auth::actor($request);
    $resellerId = (int) $args['id'];
    if ( ! $actor['isAdmin'] && $actor['resellerId'] !== $resellerId) {
        throw new \Slim\Exception\HttpForbiddenException($request, 'Not your reseller');
    }

    $reseller = ResellerService::get($resellerId);
    if ($reseller === null) {
        return Json::response($response, ['error' => 'Reseller not found'], 404);
    }
    return Json::response($response, ['reseller' => $reseller]);
});

$app->post('/v1/resellers', function (Request $request, Response $response, array $args): Response {
    $actorId = Auth::requireAdmin($request);
    $params = $request->getParsedBody() ?? [];

    try {
        $reseller = ResellerService::create(
            (string) ($params['name'] ?? ''),
            (int) ($params['max_operations'] ?? 0),
            $actorId,
        );
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, ['reseller' => $reseller], 201);
});

$app->patch('/v1/resellers/{id}', function (Request $request, Response $response, array $args): Response {
    $actorId = Auth::requireAdmin($request);
    $resellerId = (int) $args['id'];

    if (ResellerService::get($resellerId) === null) {
        return Json::response($response, ['error' => 'Reseller not found'], 404);
    }

    $params = $request->getParsedBody() ?? [];
    $changes = array_intersect_key($params, array_flip(['name', 'max_operations', 'active']));

    try {
        $reseller = ResellerService::update($resellerId, $changes, $actorId);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    return Json::response($response, ['reseller' => $reseller]);
});

/**
 * @throws \Slim\Exception\HttpForbiddenException unless the caller belongs
 *         to $resellerId or is an admin
 */
$resellerReader = static function (Request $request, int $resellerId): array {
    $actor = Auth::actor($request);
    if ( ! $actor['isAdmin'] && $actor['resellerId'] !== $resellerId) {
        throw new \Slim\Exception\HttpForbiddenException($request, 'Not your reseller');
    }
    return $actor;
};

/**
 * @throws \Slim\Exception\HttpForbiddenException unless the caller is an
 *         MFA-verified manager of $resellerId, or an admin
 */
$resellerWriter = static function (Request $request, int $resellerId): array {
    $actor = Auth::requireManager($request);
    if ( ! $actor['isAdmin'] && $actor['resellerId'] !== $resellerId) {
        throw new \Slim\Exception\HttpForbiddenException($request, 'Not your reseller');
    }
    return $actor;
};

// Every write answers with the whole current state, so a client replaces what
// it holds instead of merging. $recorded is what the history row says changed.
$resellerSettingsReply = static function (Response $response, array $result, int $resellerId, int $actorId, array $recorded, int $status = 200): Response {
    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    History::record('resellers', $resellerId, 'update', $recorded, $actorId);
    return Json::response($response, ['settings' => $result['settings']], $status);
};

$app->get('/v1/resellers/{id}/settings', function (Request $request, Response $response, array $args) use ($resellerReader): Response {
    $resellerId = (int) $args['id'];
    $resellerReader($request, $resellerId);

    $settings = ResellerSettings::load($resellerId);
    if ($settings === null) {
        return Json::response($response, ['error' => 'Reseller not found'], 404);
    }
    return Json::response($response, ['settings' => $settings]);
});

$app->put('/v1/resellers/{id}/settings', function (Request $request, Response $response, array $args) use ($resellerWriter, $resellerSettingsReply): Response {
    $resellerId = (int) $args['id'];
    $actor = $resellerWriter($request, $resellerId);

    $params = $request->getParsedBody() ?? [];
    $result = ResellerSettings::saveDefaults($resellerId, $params);

    return $resellerSettingsReply(
        $response, $result, $resellerId, $actor['id'],
        array_intersect_key($params, array_flip(['countrycode', 'entitytype', 'techc', 'dnsset', 'dnssec_algorithm', 'dnssec_digesttype'])),
    );
});

$app->post('/v1/resellers/{id}/nssets', function (Request $request, Response $response, array $args) use ($resellerWriter, $resellerSettingsReply): Response {
    $resellerId = (int) $args['id'];
    $actor = $resellerWriter($request, $resellerId);

    $params = $request->getParsedBody() ?? [];
    $result = ResellerSettings::addSet($resellerId, $params);

    return $resellerSettingsReply(
        $response, $result, $resellerId, $actor['id'],
        ['nsset_added' => (string) ($params['name'] ?? '')], 201,
    );
});

$app->put('/v1/resellers/{id}/nssets/{name}', function (Request $request, Response $response, array $args) use ($resellerWriter, $resellerSettingsReply): Response {
    $resellerId = (int) $args['id'];
    $actor = $resellerWriter($request, $resellerId);

    $result = ResellerSettings::replaceSet($resellerId, $args['name'], $request->getParsedBody() ?? []);

    return $resellerSettingsReply($response, $result, $resellerId, $actor['id'], ['nsset_changed' => $args['name']]);
});

$app->delete('/v1/resellers/{id}/nssets/{name}', function (Request $request, Response $response, array $args) use ($resellerWriter, $resellerSettingsReply): Response {
    $resellerId = (int) $args['id'];
    $actor = $resellerWriter($request, $resellerId);

    $result = ResellerSettings::removeSet($resellerId, $args['name']);

    return $resellerSettingsReply($response, $result, $resellerId, $actor['id'], ['nsset_removed' => $args['name']]);
});
