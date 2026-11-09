<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Api\LoginRateLimit;
use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\PasskeyExists;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Service\PasskeyImpact;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\PasskeysInUse;
use Eppitnic\Service\SigninRequirement;
use Eppitnic\Service\StepUp;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Service\WebAuthnFailure;
use Eppitnic\Support\AuthenticatorModels;
use Eppitnic\Support\PasswordGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpForbiddenException;

/**
 * A user's passkeys and the setting they answer to. Adding is the user's own,
 * with a step-up; removing is the user's or their manager's and admin's.
 */

$notAuthorized = static function (Response $response): Response {
    return Json::response($response, ['error' => UserRules::NOT_AUTHORIZED], 403);
};

$passkeysOff = static function (Response $response): Response {
    return Json::response($response, ['error' => 'Passkeys are not enabled', 'passkeys_available' => false], 400);
};

/** Why no registration can start: no metadata for device_bound, or the key limit. */
$registrationRefusal = static function (Response $response, string $reason): Response {
    $error = $reason === WebAuthnFailure::METADATA ? WebAuthnFailure::METADATA_MESSAGE : Passkeys::LIMIT_MESSAGE;
    return Json::response($response, ['error' => $error, 'reason' => $reason], 400);
};

/** @return array|null the active user's row */
$activeUser = static function (int $id): ?array {
    $row = R::getRow('SELECT * FROM users WHERE id = :id AND active = 1', [':id' => $id]);
    return empty($row) ? null : $row;
};

/**
 * One key as the API shows it: never the credential id or the source. Its
 * model is what MDS3 calls the AAGUID, else the catalogue's name for it.
 *
 * @param array<string, string> $names MDS3 descriptions by AAGUID
 */
$passkeyView = static function (array $row, array $settings, ?array $names = null): array {
    $aaguid = strtolower((string) $row['aaguid']);
    $names ??= AuthenticatorMetadata::descriptions([$aaguid]);

    return [
        'id'                   => (int) $row['id'],
        'name'                 => (string) $row['name'],
        'aaguid'               => (string) $row['aaguid'],
        'model'                => $names[$aaguid] ?? AuthenticatorModels::labelFor($aaguid),
        'backup_eligible'      => (bool) (int) $row['backup_eligible'],
        'backup_state'         => (bool) (int) $row['backup_state'],
        'attestation_format'   => (string) $row['attestation_format'],
        'attestation_verified' => (bool) (int) $row['attestation_verified'],
        'compliant'            => PasskeyPolicy::complies($row, $settings),
        'created'              => $row['created_at'],
        'last_used'            => $row['last_used_at'],
    ];
};

$overview = static function (array $user) use ($passkeyView): array {
    $settings = PasskeySettings::get();
    $rows = Passkeys::listForUser((int) $user['id']);
    $names = AuthenticatorMetadata::descriptions(array_column($rows, 'aaguid'));
    $keys = array_map(static fn(array $row): array => $passkeyView($row, $settings, $names), $rows);

    return [
        'passkeys'           => $keys,
        'signin_requirement' => (string) $user['signin_requirement'],
        'minimum'            => SigninRequirement::minimumKeys((string) $user['signin_requirement']),
        'compliant_count'    => count(array_filter($keys, static fn(array $key): bool => $key['compliant'])),
        'enroll_open'        => (bool) (int) $user['passkey_enroll_open'],
        'passkeys_available' => PasskeySettings::enabled(),
    ];
};

/**
 * The step-up every self-service change starts with, after the rate limit.
 *
 * @return Response|null the 429 or 401 to answer with, or null when it holds
 */
$stepUpRefusal = static function (Request $request, Response $response, array $user, mixed $proof): ?Response {
    if (($blocked = LoginRateLimit::refusal($request, $response, (string) $user['username'])) !== null) {
        return $blocked;
    }
    if (StepUp::verify($request, $user, $proof) === null) {
        return null;
    }
    return Json::response($response, ['error' => 'Step-up verification failed', 'stepup' => StepUp::method($user)], 401);
};

// -----------------------------------------------------------------
// reading
// -----------------------------------------------------------------

$app->get('/v1/users/{id}/passkeys', function (Request $request, Response $response, array $args) use ($notAuthorized, $overview): Response {
    $id = (int) $args['id'];
    try {
        $actor = Auth::actorFor($request, $id);
    } catch (HttpForbiddenException) {
        return $notAuthorized($response);
    }

    $user = R::getRow('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    $body = $overview($user);
    if ($actor['id'] === $id) {
        $body['stepup'] = StepUp::method($user);
    }
    return Json::response($response, $body);
});

// -----------------------------------------------------------------
// adding: the user's own
// -----------------------------------------------------------------

$app->post('/v1/users/{id}/passkeys/options', function (Request $request, Response $response, array $args) use ($notAuthorized, $activeUser, $passkeysOff, $registrationRefusal): Response {
    $id = (int) $args['id'];
    if (Auth::actor($request)['id'] !== $id) {
        return $notAuthorized($response);
    }
    $user = $activeUser($id);
    if ($user === null) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if ( ! PasskeySettings::enabled()) {
        return $passkeysOff($response);
    }
    if (Passkeys::atLimit($id)) {
        return $registrationRefusal($response, WebAuthnFailure::LIMIT);
    }

    [$challengeId, $challenge] = WebAuthnChallenges::issue('register', $id, 'session');
    $existing = array_column(Passkeys::listForUser($id), 'credential_id');
    try {
        $options = WebAuthn::registrationOptions($user, $existing, PasskeySettings::attestation(), $challenge);
    } catch (WebAuthnFailure $e) {
        return $e->reason === WebAuthnFailure::METADATA ? $registrationRefusal($response, $e->reason) : $passkeysOff($response);
    }
    return Json::response($response, ['publicKey' => $options, 'challenge_id' => $challengeId]);
});

$app->post('/v1/users/{id}/passkeys/stepup', function (Request $request, Response $response, array $args) use ($notAuthorized, $activeUser, $passkeysOff): Response {
    $id = (int) $args['id'];
    if (Auth::actor($request)['id'] !== $id) {
        return $notAuthorized($response);
    }
    $user = $activeUser($id);
    if ($user === null) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if (StepUp::method($user) !== StepUp::PASSKEY) {
        return Json::response($response, ['error' => 'No passkey to confirm with', 'stepup' => StepUp::method($user)], 400);
    }

    $settings = PasskeySettings::get();
    $allowed = [];
    foreach (Passkeys::listForUser($id) as $row) {
        if (PasskeyPolicy::complies($row, $settings)) {
            $allowed[] = $row['credential_id'];
        }
    }
    [$challengeId, $challenge] = WebAuthnChallenges::issue('stepup', $id);
    try {
        $options = WebAuthn::requestOptions($challenge, $allowed);
    } catch (WebAuthnFailure) {
        return $passkeysOff($response);
    }
    return Json::response($response, ['publicKey' => $options, 'challenge_id' => $challengeId, 'stepup' => StepUp::PASSKEY]);
});

$app->post('/v1/users/{id}/passkeys', function (Request $request, Response $response, array $args) use ($notAuthorized, $activeUser, $passkeysOff, $stepUpRefusal, $overview, $passkeyView): Response {
    $id = (int) $args['id'];
    if (Auth::actor($request)['id'] !== $id) {
        return $notAuthorized($response);
    }
    $user = $activeUser($id);
    if ($user === null) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if ( ! PasskeySettings::enabled()) {
        return $passkeysOff($response);
    }

    $params = $request->getParsedBody() ?? [];
    $json = WebAuthn::credentialJson($params['credential'] ?? null);
    $challengeId = $params['challenge_id'] ?? null;
    if ($json === null || ! is_numeric($challengeId)) {
        return Json::response($response, ['error' => 'credential and challenge_id are required'], 400);
    }
    if (($refusal = $stepUpRefusal($request, $response, $user, $params['stepup'] ?? null)) !== null) {
        return $refusal;
    }

    // the proof was right, so a registration that does not verify is refused
    // with its reason and logged, not counted
    $refuse = static function (string $reason, ?string $error = null) use ($request, $response, $user): Response {
        History::recordSecurityEvent('passkey_registration_failed', $request, (int) $user['id'], [
            'username' => (string) $user['username'],
            'reason'   => $reason,
        ], 'secread');

        $error ??= match ($reason) {
            WebAuthnFailure::METADATA => WebAuthnFailure::METADATA_MESSAGE,
            WebAuthnFailure::LIMIT    => Passkeys::LIMIT_MESSAGE,
            default                   => 'Passkey registration failed',
        };
        return Json::response($response, ['error' => $error, 'reason' => $reason], 400);
    };

    if (Passkeys::atLimit($id)) {
        return $refuse(WebAuthnFailure::LIMIT);
    }
    try {
        $claims = WebAuthn::claims($json);
        if ( ! WebAuthnChallenges::consume((int) $challengeId, $claims['challenge'], 'register', $id)) {
            return $refuse(WebAuthnFailure::CHALLENGE);
        }
        $registration = WebAuthn::verifyRegistration($json, $claims['challenge'], $user, PasskeySettings::attestation());
    } catch (WebAuthnFailure $e) {
        return $refuse($e->reason);
    }

    $candidate = [
        'aaguid'               => $registration->aaguid,
        'attestation_verified' => (int) $registration->attestationVerified,
        'backup_eligible'      => (int) $registration->backupEligible,
    ];
    if ( ! PasskeyPolicy::complies($candidate, PasskeySettings::get())) {
        return $refuse('policy', 'This passkey does not meet the required type');
    }

    $name = trim((string) ($params['name'] ?? ''));
    try {
        $keyId = Passkeys::add($id, $registration, $name !== '' ? $name : 'Passkey ' . (Passkeys::countForUser($id) + 1));
    } catch (PasskeyExists) {
        return $refuse('credential_id', 'This passkey is already registered');
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    History::record('users', $id, 'update', [
        'passkey_registered' => ['id' => $keyId, 'aaguid' => $registration->aaguid],
    ], $id);
    History::recordSecurityEvent('passkey_registered', $request, $id, [
        'username'   => (string) $user['username'],
        'credential' => $keyId,
        'aaguid'     => $registration->aaguid,
        'attested'   => $registration->attestationVerified,
    ], 'secread');
    UserRules::settleEnrollOpen($id, $id);

    $user = R::getRow('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    return Json::response($response, [
        'passkey' => $passkeyView(Passkeys::find($keyId), PasskeySettings::get()),
    ] + $overview($user), 201);
});

// -----------------------------------------------------------------
// renaming and removing
// -----------------------------------------------------------------

$app->patch('/v1/users/{id}/passkeys/{pid}', function (Request $request, Response $response, array $args) use ($notAuthorized, $passkeyView): Response {
    $id = (int) $args['id'];
    if (Auth::actor($request)['id'] !== $id) {
        return $notAuthorized($response);
    }
    $key = Passkeys::find((int) $args['pid']);
    if ($key === null || (int) $key['user_id'] !== $id) {
        return Json::response($response, ['error' => 'Passkey not found'], 404);
    }

    $name = ($request->getParsedBody() ?? [])['name'] ?? null;
    if ( ! is_string($name)) {
        return Json::response($response, ['error' => 'name is required'], 400);
    }
    try {
        Passkeys::rename((int) $key['id'], $name);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    History::record('users', $id, 'update', ['passkey_renamed' => ['id' => (int) $key['id'], 'name' => trim($name)]], $id);
    return Json::response($response, ['passkey' => $passkeyView(Passkeys::find((int) $key['id']), PasskeySettings::get())]);
});

$app->delete('/v1/users/{id}/passkeys/{pid}', function (Request $request, Response $response, array $args) use ($notAuthorized, $stepUpRefusal, $overview): Response {
    $id = (int) $args['id'];
    $own = Auth::actor($request)['id'] === $id;

    // one's own key takes a step-up; someone else's, a strong session and a user in scope
    if ( ! $own) {
        try {
            Auth::actorFor($request, $id);
        } catch (HttpForbiddenException) {
            return $notAuthorized($response);
        }
    }
    $user = R::getRow('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    $key = empty($user) ? null : Passkeys::find((int) $args['pid']);
    if ($key === null || (int) $key['user_id'] !== $id) {
        return Json::response($response, ['error' => 'Passkey not found'], 404);
    }
    if ($own && ($refusal = $stepUpRefusal($request, $response, $user, ($request->getParsedBody() ?? [])['stepup'] ?? null)) !== null) {
        return $refusal;
    }

    $minimum = SigninRequirement::minimumKeys((string) $user['signin_requirement']);
    $compliant = PasskeyPolicy::complies($key, PasskeySettings::get());
    if ($compliant && PasskeyPolicy::compliantCount($id) - 1 < $minimum) {
        return Json::response($response, [
            'error'   => "The sign-in requirement needs at least {$minimum} passkey(s); reset the sign-in instead",
            'minimum' => $minimum,
        ], 400);
    }

    Passkeys::delete((int) $key['id']);
    $actorId = Auth::userId($request);
    History::record('users', $id, 'update', ['passkey_removed' => ['id' => (int) $key['id'], 'name' => $key['name']]], $actorId);
    History::recordSecurityEvent('passkey_removed', $request, $actorId, [
        'target_id'  => $id,
        'target'     => (string) $user['username'],
        'credential' => (int) $key['id'],
    ], 'secread');

    return Json::response($response, $overview($user));
});

// -----------------------------------------------------------------
// starting over
// -----------------------------------------------------------------

$app->post('/v1/users/{id}/signin-reset', function (Request $request, Response $response, array $args) use ($notAuthorized): Response {
    $actor = Auth::requireManager($request);
    $id = (int) $args['id'];

    $user = R::getRow('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if ($id === $actor['id']) {
        return Json::response($response, ['error' => 'you cannot reset your own sign-in'], 400);
    }
    if ( ! $actor['isAdmin'] && ! UserRules::reachable($actor['scope'], $user)) {
        return $notAuthorized($response);
    }

    $temporary = (new PasswordGenerator(20, true, requireNumber: true))->get();
    $result = UserRules::resetSignin($user, password_hash($temporary, PASSWORD_DEFAULT), $actor['id']);
    History::recordSecurityEvent('signin_reset', $request, $actor['id'], [
        'target_id'        => $id,
        'target'           => (string) $user['username'],
        'passkeys_revoked' => $result['passkeys'],
        'totp_removed'     => $result['totp'],
    ]);

    $users = R::getAll('SELECT ' . User::readColumns(true) . ' FROM users WHERE id = :id', [':id' => $id]);
    // shown here once, like an API token: only its hash is kept
    return Json::response($response, [
        'users'              => User::withPasskeyCounts($users),
        'temporary_password' => $temporary,
        'passkeys_revoked'   => $result['passkeys'],
        'totp_removed'       => $result['totp'],
        'enroll_open'        => $result['enroll_open'],
    ]);
});

// -----------------------------------------------------------------
// the setting
// -----------------------------------------------------------------

/** What the settings page needs besides the setting: the models to pick and the metadata's state. */
$settingsView = static function (): array {
    return [
        'passkeys'  => PasskeySettings::get(),
        'in_use'    => PasskeySettings::inUse(),
        'catalogue' => AuthenticatorModels::catalogue(),
        'metadata'  => PasskeyMetadata::status(),
    ];
};

$app->get('/v1/settings/passkeys', function (Request $request, Response $response, array $args) use ($settingsView): Response {
    Auth::requireAdmin($request);

    return Json::response($response, $settingsView());
});

/**
 * The impact of a proposed change, or the 400 that says what is wrong with it.
 *
 * @return array{0: array|null, 1: Response|null} [impact, refusal]
 */
$proposedImpact = static function (Response $response, array $changes): array {
    try {
        [, $proposed] = PasskeySettings::preview($changes);
    } catch (\InvalidArgumentException $e) {
        return [null, Json::response($response, ['error' => $e->getMessage()], 400)];
    }
    return [PasskeyImpact::of($proposed), null];
};

$app->post('/v1/settings/passkeys/impact', function (Request $request, Response $response, array $args) use ($proposedImpact): Response {
    Auth::requireAdmin($request);
    $changes = $request->getParsedBody() ?? [];
    unset($changes['confirm']);

    [$impact, $refusal] = $proposedImpact($response, $changes);
    return $refusal ?? Json::response($response, $impact);
});

$app->put('/v1/settings/passkeys', function (Request $request, Response $response, array $args) use ($settingsView, $proposedImpact): Response {
    $userId = Auth::requireAdmin($request);
    $changes = $request->getParsedBody() ?? [];
    $confirm = ($changes['confirm'] ?? false) === true;
    unset($changes['confirm']);

    [$impact, $refusal] = $proposedImpact($response, $changes);
    if ($refusal !== null) {
        return $refusal;
    }
    if ($impact['admins_able_after'] === 0) {
        return Json::response($response, [
            'error'  => 'No active admin could sign in after this change; nothing was saved',
            'impact' => $impact,
        ], 400);
    }

    try {
        PasskeySettings::set($changes, $userId, $confirm);
    } catch (PasskeysInUse $e) {
        return Json::response($response, [
            'error'           => $e->getMessage(),
            'confirm_required' => true,
            'in_use'          => ['passkeys' => $e->passkeys, 'users' => $e->users],
            'impact'          => $impact,
        ], 400);
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    History::recordSecurityEvent('passkey_policy_changed', $request, $userId, [
        'changes' => array_keys($changes),
    ] + PasskeyImpact::counts($impact));

    return Json::response($response, $settingsView() + ['impact' => $impact]);
});
