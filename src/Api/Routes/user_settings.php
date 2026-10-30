<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Persistence\History;
use Eppitnic\Service\Notifier;
use RedBeanPHP\R;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A user's own filter on email notifications (Notifier) -- theirs to read
 * and change, or their manager's or an admin's to reach into
 * (Auth::actorFor). Only takes effect while the system-wide
 * `smtp.recipient_mode` includes 'user'; see GET/PATCH /v1/smtp.
 */
$app->get('/v1/users/{id}/notifications', function (Request $request, Response $response, array $args): Response {
    $userId = (int) $args['id'];
    Auth::actorFor($request, $userId);

    $prefs = Notifier::loadUserPreferences($userId);
    if ($prefs === null) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    return Json::response($response, ['notifications' => $prefs, 'message_types' => Notifier::MESSAGE_TYPES]);
});

$app->patch('/v1/users/{id}/notifications', function (Request $request, Response $response, array $args): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $params = $request->getParsedBody() ?? [];
    $result = Notifier::saveUserPreferences($userId, $params);
    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    History::record('users', $userId, 'update', array_intersect_key($params, array_flip(['enabled', 'message_types', 'fulltext'])), $actor['id']);
    return Json::response($response, ['notifications' => $result['settings']]);
});

/**
 * A user's own token lifetime (`users.token_lifetime`) -- same audience as
 * notifications, held to the `max_token_age` limit an admin or manager set.
 * Applies from the next renew-token or login.
 */
$app->patch('/v1/users/{id}/session', function (Request $request, Response $response, array $args): Response {
    $userId = (int) $args['id'];
    $actor = Auth::actorFor($request, $userId);

    $params = $request->getParsedBody() ?? [];
    $minutes = Auth::parseTokenLifetime($params['token_lifetime'] ?? null);
    if ($minutes === false) {
        return Json::response($response, ['error' => 'token_lifetime must be null or one of ' . implode(', ', Auth::TOKEN_LIFETIMES) . ' (minutes)'], 400);
    }
    $row = R::getRow('SELECT max_token_age FROM users WHERE id = ?', [$userId]);
    if (empty($row)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    $limit = Auth::tokenLifetimeLimit($row['max_token_age']);
    if ($minutes !== null && $minutes > $limit) {
        return Json::response($response, ['error' => "token_lifetime must not exceed the maximum of {$limit} minutes set for this user"], 400);
    }

    R::exec('UPDATE users SET token_lifetime = ? WHERE id = ?', [$minutes, $userId]);
    History::record('users', $userId, 'update', ['token_lifetime' => $minutes], $actor['id']);
    return Json::response($response, ['session' => ['token_lifetime' => $minutes, 'max_token_age' => $row['max_token_age']]]);
});
