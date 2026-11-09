<?php

use Eppitnic\Api\Auth;
use Eppitnic\Api\ClientIp;
use Eppitnic\Api\Json;
use Eppitnic\Api\LoginRateLimit;
use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\PasskeyExists;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Persistence\User;
use Eppitnic\Persistence\UsernameTaken;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Service\EppSettings;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\PasswordReset;
use Eppitnic\Service\SigninRequirement;
use Eppitnic\Service\StepUp;
use Eppitnic\Service\WebAuthn;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Service\WebAuthnFailure;
use Eppitnic\Support\PasswordPolicy;
use Eppitnic\Support\PasswordGenerator;
use Eppitnic\Support\Validate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpForbiddenException;

$app->get('/v1/users/renew-token', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    if ( ! empty($decoded->data->remote_auth)) {
        return Json::response($response, ['error' => 'Token renewal is not available with remote authentication'], 400);
    }
    $claims = (array) $decoded->data;
    $row = R::getRow('SELECT max_token_age, token_lifetime, max_idle_time FROM users WHERE id = ?', [(int) ($claims['id'] ?? 0)]);
    if ( ! empty($row)) {
        $claims['max_token_age'] = $row['max_token_age'];
        $claims['token_lifetime'] = $row['token_lifetime'];
        $claims['max_idle_time'] = $row['max_idle_time'];
    }
    return Json::response($response, Auth::issueToken($claims));
});

$app->get('/v1/users/me', function (Request $request, Response $response, array $args): Response {
    $decoded = Auth::verify($request);
    return Json::response($response, (array) $decoded->data);
});

$rateLimited = static function (Request $request, Response $response, string $username = ''): ?Response {
    return LoginRateLimit::refusal($request, $response, $username);
};

/**
 * An active user with what a login needs of them.
 *
 * @param string $where a condition on `u` (users) and `r` (resellers)
 */
$loginUser = static function (string $where, array $bind): ?array {
    $rows = R::getAll("SELECT
        u.id, u.role, u.reseller_id, r.name AS reseller_name, r.active AS reseller_active,
        u.username, u.password, u.must_change_password, u.totp_secret, u.signin_requirement,
        u.passkey_enroll_open, u.webauthn_handle, u.debug, u.max_token_age, u.token_lifetime, u.max_idle_time
    FROM users u JOIN resellers r ON r.id = u.reseller_id
    WHERE {$where} AND u.active = 1", $bind);

    return $rows[0] ?? null;
};

/**
 * The checks every login-shaped route starts with: rate limit, username and
 * password, active reseller, a passkey-only account turned away, and the MFA
 * code of an enrolled user off the safe networks. Records each failure as
 * authenticate always has.
 *
 * @param bool $verifyCode false where the code was checked by an earlier call
 *        of the same ceremony (finishing a passkey enrolment)
 * @return array{0: array|null, 1: Response|null} the user row, or the answer
 *         to give instead
 */
$checkCredentials = static function (Request $request, Response $response, bool $verifyCode = true) use ($rateLimited, $loginUser): array {
    $params   = $request->getParsedBody() ?? [];
    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    if (($blocked = $rateLimited($request, $response, (string) $username)) !== null) {
        return [null, $blocked];
    }

    if (empty($username)) {
        return [null, Json::response($response, ['error' => 'Please provide a username'], 401)];
    }
    if (empty($password)) {
        return [null, Json::response($response, ['error' => 'Please provide a password'], 401)];
    }

    $user = $loginUser('u.username = :username', [':username' => $username]);

    // an unknown username is verified against a hash all the same, so the
    // response time does not tell which usernames exist. Same cost as
    // PASSWORD_DEFAULT; the password behind it is not a secret
    $dummyHash = '$2y$12$CZy6tosW6kbQLFDe.n8TK.HEku56zYUPZkN7rMqj2qrdEHpSp8hjy';
    $passwordMatches = password_verify($password, $user === null ? $dummyHash : (string) $user['password']);

    if ($user === null || ! $passwordMatches) {
        // The response says only "wrong username or password", so that it
        // cannot be used to find out which usernames exist. The log may be
        // precise -- it is read by an operator, not by whoever is guessing.
        History::recordSecurityEvent('login_failed', $request, $user === null ? null : (int) $user['id'], [
            'username' => (string) $username,
            'reason'   => $user === null ? 'no such active user' : 'wrong password',
        ], 'denied');

        return [null, Json::response($response, ['error' => 'Wrong username or password'], 401)];
    }

    // after the password, so this says nothing to someone merely guessing
    if ((int) $user['reseller_active'] !== 1) {
        History::recordSecurityEvent('login_failed', $request, (int) $user['id'], [
            'username' => (string) $username,
            'reason'   => 'reseller deactivated',
        ], 'denied');

        return [null, Json::response($response, ['error' => 'Your reseller account is deactivated'], 403)];
    }

    // A pending password change goes first and stays reachable: it is the one
    // thing a passkey-only account still does with its password
    if ($user['signin_requirement'] === SigninRequirement::PASSKEY && (int) $user['must_change_password'] !== 1) {
        $requirement = SigninRequirement::evaluate(
            $user, PasskeyPolicy::usableKeys($user), ! empty($user['totp_secret']), Auth::onSafeNetwork(),
            'pwd', PasskeySettings::enabled()
        );
        if ( ! $requirement->methodAllowed) {
            History::recordSecurityEvent('login_requirement_pending', $request, (int) $user['id'], [
                'username' => (string) $user['username'],
                'required' => 'passkey',
            ], 'secread');

            return [null, Json::response($response, [
                'error'    => 'This account signs in with a passkey',
                'required' => 'passkey',
            ], 403)];
        }
    }

    if ($verifyCode && !empty($user['totp_secret']) && !Auth::onSafeNetwork()) {
        $totpCode = $params['totp'] ?? '';
        if (empty($totpCode)) {
            return [null, Json::response($response, ['error' => 'MFA code required'], 401)];
        }
        if (!Auth::totpVerify($user['totp_secret'], $totpCode)) {
            // counted like any other failure: the password alone is not a
            // login here, so guessing the second factor has to cost the same
            History::recordSecurityEvent('login_failed', $request, (int) $user['id'], [
                'username' => (string) $username,
                'reason'   => 'wrong MFA code',
            ], 'denied');

            return [null, Json::response($response, ['error' => 'Invalid MFA code'], 401)];
        }
    }

    return [$user, null];
};

/**
 * What a login answers with once the credentials are right: the pending
 * password change or setup (403, no token), else the token. `amr` is how the
 * session began -- pwd, pwd+otp ($codeChecked on enrolment) or passkey.
 */
$continueLogin = static function (Request $request, Response $response, array $user, ?array $passkey = null, bool $codeChecked = false): Response {
    $hasTotp    = !empty($user['totp_secret']);
    $onSafeNet  = Auth::onSafeNetwork();
    $viaPasskey = $passkey !== null;
    $needsTotp  = $hasTotp && !$onSafeNet && !$viaPasskey;
    $method     = $viaPasskey ? 'passkey' : ($needsTotp || $codeChecked ? 'pwd+otp' : 'pwd');

    $available = PasskeySettings::enabled();
    $keys = PasskeyPolicy::usableKeys($user);
    $requirement = SigninRequirement::evaluate($user, $viaPasskey ? max(1, $keys) : $keys, $hasTotp, $onSafeNet, $method, $available);

    // The credentials were right, so this is not a failure: 'secread' keeps it
    // out of what LoginRateLimit counts
    $pending = static function (string $required, string $error, array $more = []) use ($request, $response, $user): Response {
        History::recordSecurityEvent('login_requirement_pending', $request, (int) $user['id'], [
            'username' => (string) $user['username'],
            'required' => $required,
        ], 'secread');

        return Json::response($response, ['error' => $error, 'required' => $required] + $more, 403);
    };

    if ($requirement->required === SigninRequirement::PASSWORD_CHANGE) {
        return $pending('password_change', 'Password change required', ['password_policy' => PasswordPolicy::describe()]);
    }
    if ( ! $requirement->methodAllowed) {
        return $pending('passkey', 'This account signs in with a passkey');
    }
    if ($requirement->required === SigninRequirement::MFA_ENROLLMENT) {
        return $pending('mfa_enrollment', 'MFA enrollment required');
    }
    if ($requirement->required === SigninRequirement::PASSKEY_ENROLLMENT) {
        // the password alone registers keys only while the account's enrolment
        // is open; a code from a secret just made with that password proves
        // nothing
        $mayEnrol = (int) $user['passkey_enroll_open'] === 1 || ($needsTotp && ! $codeChecked);
        if ($available && ! $mayEnrol) {
            return $pending(SigninRequirement::SIGNIN_RESET, SigninRequirement::SIGNIN_RESET_MESSAGE);
        }
        return $pending('passkey_enrollment', 'Passkey enrollment required', $requirement->keyCounter() + [
            'passkeys_available' => $available,
        ]);
    }

    Auth::startActivity((int) $user['id']);

    // Recorded like the failures, and with the same care: the token this call
    // is about to issue is a credential, so it is not written here any more
    // than the password was.
    History::recordSecurityEvent('login_succeeded', $request, (int) $user['id'], [
        'username'  => (string) $user['username'],
        'method'    => $method,
        'mfa'       => $viaPasskey ? 'passkey' : ($needsTotp ? 'verified' : ($hasTotp ? 'skipped on a safe network' : 'not configured')),
    ] + ($viaPasskey ? ['credential' => (int) $passkey['id']] : []), 'login');

    $claims = [
        'id'            => $user['id'],
        'role'          => $user['role'],
        'reseller_id'   => (int) $user['reseller_id'],
        'reseller_name' => $user['reseller_name'],
        'username'      => $user['username'],
        'has_totp'      => $hasTotp,
        'has_passkeys'  => $viaPasskey || PasskeyPolicy::compliantCount((int) $user['id']) > 0,
        'needs_totp'    => $needsTotp,
        'totp_verified' => $needsTotp || $codeChecked,
        'amr'           => $method,
        'strong_auth'   => $method !== 'pwd',
        'debug'         => (bool) $user['debug'],
        'max_token_age'   => $user['max_token_age'],
        'token_lifetime'  => $user['token_lifetime'],
        'max_idle_time'   => $user['max_idle_time'],
        'registry'        => EppSettings::environment(),
    ] + ($viaPasskey ? ['cred' => (int) $passkey['id']] : []);
    $claims['needs_second_factor'] = Auth::needsSecondFactor($claims);

    return Json::response($response, Auth::issueToken($claims));
};

$app->post('/v1/users/authenticate', function (Request $request, Response $response, array $args) use ($checkCredentials, $continueLogin): Response {
    [$user, $failure] = $checkCredentials($request, $response);
    return $failure ?? $continueLogin($request, $response, $user);
});

$app->post('/v1/users/authenticate/password', function (Request $request, Response $response, array $args) use ($checkCredentials, $continueLogin): Response {
    [$user, $failure] = $checkCredentials($request, $response);
    if ($failure !== null) {
        return $failure;
    }
    if ((int) $user['must_change_password'] !== 1) {
        return Json::response($response, ['error' => 'No password change is pending'], 400);
    }

    $params      = $request->getParsedBody() ?? [];
    $newPassword = (string) ($params['new_password'] ?? '');
    if ($newPassword === '') {
        return Json::response($response, ['error' => 'Please provide a new password'], 400);
    }
    if ( ! PasswordPolicy::isAcceptable($newPassword)) {
        return Json::response($response, [
            'error'           => PasswordPolicy::explain($newPassword),
            'password_policy' => PasswordPolicy::describe(),
        ], 400);
    }
    if (password_verify($newPassword, $user['password'])) {
        return Json::response($response, ['error' => 'The new password must differ from the current one'], 400);
    }

    R::exec('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?', [
        password_hash($newPassword, PASSWORD_DEFAULT),
        (int) $user['id'],
    ]);
    $user['must_change_password'] = 0;

    History::record('users', (int) $user['id'], 'update', ['password' => 'PASSWORD_CHANGED', 'must_change_password' => 0], (int) $user['id']);
    History::recordSecurityEvent('password_changed', $request, (int) $user['id'], [
        'username' => (string) $user['username'],
    ], 'rotate');

    return $continueLogin($request, $response, $user);
});

/**
 * Always the same answer, whatever the address: 202 and nothing else. The
 * work runs after the response where the SAPI allows it.
 */
$app->post('/v1/users/password-reset', function (Request $request, Response $response, array $args): Response {
    $params = $request->getParsedBody() ?? [];
    $email  = is_string($params['email'] ?? null) ? $params['email'] : '';
    $locale = is_string($params['locale'] ?? null) ? $params['locale'] : 'en';
    $origin = PasswordReset::linkOrigin($request, $params['origin'] ?? null);

    if (PasswordReset::available()) {
        PasswordReset::afterResponse(static function () use ($email, $locale, $origin): void {
            PasswordReset::request($email, $locale, $origin);
        });
    } else {
        error_log('password reset: mail is not configured or logins are remote, nothing sent');
    }

    return Json::response($response, ['ok' => true], 202);
});

/**
 * @return Response the answer to a reset link's outcome
 */
$resetAnswer = static function (Response $response, array $outcome): Response {
    return match ($outcome[0]) {
        'ok'      => Json::response($response, ['ok' => true, 'password_policy' => PasswordPolicy::describe()]),
        'weak'    => Json::response($response, [
            'error'           => $outcome[1],
            'password_policy' => PasswordPolicy::describe(),
        ], 400),
        'limited' => Json::response($response, ['error' => 'Too many attempts. Try again later.'], 429),
        default   => Json::response($response, ['error' => PasswordReset::INVALID], 400),
    };
};

$app->get('/v1/users/password-reset/{token}', function (Request $request, Response $response, array $args) use ($resetAnswer): Response {
    return $resetAnswer($response, [PasswordReset::check((string) $args['token'])]);
});

$app->post('/v1/users/password-reset/confirm', function (Request $request, Response $response, array $args) use ($resetAnswer): Response {
    $params = $request->getParsedBody() ?? [];
    $token  = is_string($params['token'] ?? null) ? $params['token'] : '';
    $password = is_string($params['password'] ?? null) ? $params['password'] : '';

    return $resetAnswer($response, PasswordReset::confirm($token, $password));
});

/**
 * Whether $user still owes an MFA enrollment that may be done now: the
 * password requirement comes first, and an enrolled user has nothing to do.
 */
$mfaEnrollmentPending = static function (array $user): bool {
    return SigninRequirement::needsTotp((string) $user['signin_requirement'])
        && (int) $user['must_change_password'] !== 1
        && empty($user['totp_secret']);
};

$app->post('/v1/users/authenticate/mfa', function (Request $request, Response $response, array $args) use ($checkCredentials, $mfaEnrollmentPending): Response {
    [$user, $failure] = $checkCredentials($request, $response);
    if ($failure !== null) {
        return $failure;
    }
    if ( ! $mfaEnrollmentPending($user)) {
        return Json::response($response, ['error' => 'No MFA enrollment is pending'], 400);
    }

    $totp = Auth::totpGenerate($user['username'], $request->getUri()->getHost());
    R::exec('UPDATE users SET totp_secret_pending = ? WHERE id = ?', [$totp['secret'], (int) $user['id']]);

    return Json::response($response, ['secret' => $totp['secret'], 'uri' => $totp['uri']]);
});

$app->put('/v1/users/authenticate/mfa', function (Request $request, Response $response, array $args) use ($checkCredentials, $continueLogin, $mfaEnrollmentPending): Response {
    [$user, $failure] = $checkCredentials($request, $response);
    if ($failure !== null) {
        return $failure;
    }
    if ( ! $mfaEnrollmentPending($user)) {
        return Json::response($response, ['error' => 'No MFA enrollment is pending'], 400);
    }

    $pending = (string) R::getCell('SELECT totp_secret_pending FROM users WHERE id = ?', [(int) $user['id']]);
    if ($pending === '') {
        return Json::response($response, ['error' => 'No pending TOTP setup found'], 400);
    }

    $params   = $request->getParsedBody() ?? [];
    $totpCode = (string) ($params['totp'] ?? '');
    if ($totpCode === '') {
        return Json::response($response, ['error' => 'MFA code required'], 401);
    }
    if ( ! Auth::totpVerify($pending, $totpCode)) {
        History::recordSecurityEvent('login_failed', $request, (int) $user['id'], [
            'username' => (string) $user['username'],
            'reason'   => 'wrong MFA code',
        ], 'denied');

        return Json::response($response, ['error' => 'Invalid MFA code'], 401);
    }

    R::exec('UPDATE users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = ?', [
        (int) $user['id'],
    ]);
    $user['totp_secret'] = $pending;

    History::record('users', (int) $user['id'], 'update', ['has_totp' => true], (int) $user['id']);

    return $continueLogin($request, $response, $user, null, true);
});

$passkeysOff = static function (Response $response): Response {
    return Json::response($response, ['error' => 'Passkeys are not enabled', 'passkeys_available' => false], 400);
};

$app->post('/v1/users/authenticate/passkey/options', function (Request $request, Response $response, array $args) use ($rateLimited, $passkeysOff): Response {
    if (($blocked = $rateLimited($request, $response)) !== null) {
        return $blocked;
    }
    if ( ! PasskeySettings::enabled()) {
        return $passkeysOff($response);
    }

    [$challengeId, $challenge] = WebAuthnChallenges::issueLogin();
    try {
        $options = WebAuthn::requestOptions($challenge);
    } catch (WebAuthnFailure) {
        return $passkeysOff($response);
    }
    return Json::response($response, ['publicKey' => $options, 'challenge_id' => $challengeId]);
});

$app->post('/v1/users/authenticate/passkey', function (Request $request, Response $response, array $args) use ($rateLimited, $loginUser, $continueLogin): Response {
    if (($blocked = $rateLimited($request, $response)) !== null) {
        return $blocked;
    }

    // one answer for every way this can fail; the log has the reason
    $fail = static function (string $reason, ?array $passkey = null) use ($request, $response): Response {
        History::recordSecurityEvent('login_failed', $request, $passkey === null ? null : (int) $passkey['user_id'], [
            'method'     => 'passkey',
            'reason'     => $reason,
            'credential' => $passkey === null ? null : (int) $passkey['id'],
        ], 'denied');

        return Json::response($response, ['error' => 'Passkey sign-in failed'], 401);
    };

    $params = $request->getParsedBody() ?? [];
    $json = WebAuthn::credentialJson($params['credential'] ?? null);
    $challengeId = $params['challenge_id'] ?? null;
    if ($json === null || ! is_string($challengeId) || ! PasskeySettings::enabled()) {
        return $fail($json === null || ! is_string($challengeId) ? 'malformed request' : 'passkeys are not enabled');
    }

    try {
        $claims = WebAuthn::claims($json);
    } catch (WebAuthnFailure $e) {
        return $fail('malformed response');
    }

    // spent before anything else is looked at, found or not
    if ( ! WebAuthnChallenges::consumeLogin($challengeId, $claims['challenge'])) {
        return $fail('challenge unknown, spent or expired');
    }

    $passkey = Passkeys::findByCredentialId($claims['credential_id']);
    $user = $passkey === null ? null : $loginUser('u.id = :id', [':id' => (int) $passkey['user_id']]);
    $handle = $claims['user_handle'];
    if ($user === null || $handle === null || ! hash_equals((string) $user['webauthn_handle'], $handle)) {
        // verified against a key nobody has all the same, as the password
        // route does for an unknown username
        WebAuthn::spendVerificationTime();
        return $fail(match (true) {
            $passkey === null => 'unknown credential',
            $user === null    => 'credential of an inactive user',
            default           => 'user handle mismatch',
        }, $passkey);
    }

    try {
        $assertion = WebAuthn::verifyAssertion($json, $claims['challenge'], $passkey, $handle);
    } catch (WebAuthnFailure $e) {
        if ($e->reason === WebAuthnFailure::COUNTER_REGRESSION) {
            History::recordSecurityEvent('passkey_counter_regression', $request, (int) $user['id'], [
                'username'   => (string) $user['username'],
                'credential' => (int) $passkey['id'],
            ], 'secread');
        }
        return $fail($e->reason, $passkey);
    }
    if ( ! PasskeyPolicy::complies($passkey, PasskeySettings::get())) {
        return $fail('key does not meet the passkey policy', $passkey);
    }
    if ( ! Passkeys::updateUsage((int) $passkey['id'], $assertion, ClientIp::get())) {
        // a second sign-in with this key moved the counter on meanwhile
        History::recordSecurityEvent('passkey_counter_regression', $request, (int) $user['id'], [
            'username'   => (string) $user['username'],
            'credential' => (int) $passkey['id'],
        ], 'secread');
        return $fail(WebAuthnFailure::COUNTER_REGRESSION, $passkey);
    }

    if ((int) $user['reseller_active'] !== 1) {
        History::recordSecurityEvent('login_failed', $request, (int) $user['id'], [
            'username' => (string) $user['username'],
            'method'   => 'passkey',
            'reason'   => 'reseller deactivated',
        ], 'denied');

        return Json::response($response, ['error' => 'Your reseller account is deactivated'], 403);
    }

    return $continueLogin($request, $response, $user, $passkey);
});

/**
 * Whether $user still owes a passkey, and may be asked for one now: the
 * password change comes first, and enough keys leave nothing to do.
 */
$passkeyEnrollmentPending = static function (array $user): bool {
    $requirement = SigninRequirement::evaluate(
        $user, PasskeyPolicy::usableKeys($user), ! empty($user['totp_secret']), Auth::onSafeNetwork(), 'pwd'
    );
    return $requirement->required === SigninRequirement::PASSKEY_ENROLLMENT;
};

/**
 * Whether the account may register keys on its password: it has just been
 * asked for its code, or its enrolment is open. Else 403, as the login says.
 */
$enrolmentRefusal = static function (Response $response, array $user, bool $codeChecked): ?Response {
    if ($codeChecked || (int) $user['passkey_enroll_open'] === 1) {
        return null;
    }
    return Json::response($response, [
        'error'    => SigninRequirement::SIGNIN_RESET_MESSAGE,
        'required' => SigninRequirement::SIGNIN_RESET,
    ], 403);
};

$app->post('/v1/users/authenticate/passkey/enroll', function (Request $request, Response $response, array $args) use ($checkCredentials, $passkeyEnrollmentPending, $enrolmentRefusal, $passkeysOff): Response {
    [$user, $failure] = $checkCredentials($request, $response);
    if ($failure !== null) {
        return $failure;
    }
    if ( ! PasskeySettings::enabled()) {
        return $passkeysOff($response);
    }
    if ( ! $passkeyEnrollmentPending($user)) {
        return Json::response($response, ['error' => 'No passkey enrollment is pending'], 400);
    }
    // checkCredentials asked for the code of everyone who has one off the safe networks
    $codeChecked = ! empty($user['totp_secret']) && ! Auth::onSafeNetwork();
    if (($refusal = $enrolmentRefusal($response, $user, $codeChecked)) !== null) {
        return $refusal;
    }

    if (Passkeys::atLimit((int) $user['id'])) {
        return Json::response($response, ['error' => Passkeys::LIMIT_MESSAGE, 'reason' => WebAuthnFailure::LIMIT], 400);
    }

    // what was verified here is what the finishing call may rely on
    [$challengeId, $challenge] = WebAuthnChallenges::issue('register', (int) $user['id'], $codeChecked ? 'pwd+otp' : 'pwd');
    $existing = array_column(Passkeys::listForUser((int) $user['id']), 'credential_id');

    try {
        $options = WebAuthn::registrationOptions($user, $existing, PasskeySettings::attestation(), $challenge);
    } catch (WebAuthnFailure $e) {
        return $e->reason === WebAuthnFailure::METADATA
            ? Json::response($response, ['error' => WebAuthnFailure::METADATA_MESSAGE, 'reason' => $e->reason], 400)
            : $passkeysOff($response);
    }
    return Json::response($response, ['publicKey' => $options, 'challenge_id' => $challengeId]);
});

$app->put('/v1/users/authenticate/passkey/enroll', function (Request $request, Response $response, array $args) use ($checkCredentials, $continueLogin, $passkeyEnrollmentPending, $enrolmentRefusal, $passkeysOff): Response {
    $params = $request->getParsedBody() ?? [];
    $challengeId = $params['challenge_id'] ?? null;

    // the code was asked for when the options were issued
    [$user, $failure] = $checkCredentials($request, $response, false);
    if ($failure !== null) {
        return $failure;
    }
    if ( ! PasskeySettings::enabled()) {
        return $passkeysOff($response);
    }
    if ( ! $passkeyEnrollmentPending($user)) {
        return Json::response($response, ['error' => 'No passkey enrollment is pending'], 400);
    }

    // the password was right, so a registration that does not verify is not
    // a guess at anything: refused with its reason, logged, not counted
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

    $json = WebAuthn::credentialJson($params['credential'] ?? null);
    if ($json === null || ! is_numeric($challengeId)) {
        return $refuse('malformed');
    }
    if (Passkeys::atLimit((int) $user['id'])) {
        return $refuse(WebAuthnFailure::LIMIT);
    }

    try {
        $claims = WebAuthn::claims($json);
        if ( ! WebAuthnChallenges::consume((int) $challengeId, $claims['challenge'], 'register', (int) $user['id'])) {
            return $refuse(WebAuthnFailure::CHALLENGE);
        }
        $codeChecked = WebAuthnChallenges::contextOf((int) $challengeId) === 'pwd+otp';
        if (($refusal = $enrolmentRefusal($response, $user, $codeChecked)) !== null) {
            return $refusal;
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
        $keyId = Passkeys::add(
            (int) $user['id'],
            $registration,
            $name !== '' ? $name : 'Passkey ' . (Passkeys::countForUser((int) $user['id']) + 1)
        );
    } catch (PasskeyExists) {
        return $refuse('credential_id', 'This passkey is already registered');
    } catch (\InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    History::record('users', (int) $user['id'], 'update', [
        'passkey_registered' => ['id' => $keyId, 'aaguid' => $registration->aaguid],
    ], (int) $user['id']);
    History::recordSecurityEvent('passkey_registered', $request, (int) $user['id'], [
        'username' => (string) $user['username'],
        'credential' => $keyId,
        'aaguid'     => $registration->aaguid,
        'attested'   => $registration->attestationVerified,
    ], 'secread');

    UserRules::settleEnrollOpen((int) $user['id'], (int) $user['id']);
    $user['passkey_enroll_open'] = (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = ?', [(int) $user['id']]);

    // the registration was a user-verified ceremony with the new key, so the
    // login it completes counts as a passkey sign-in
    return $continueLogin($request, $response, $user, Passkeys::find($keyId));
});

/**
 * Which users the caller may see: an admin everyone (optionally one reseller's,
 * `?reseller_id=`), a manager their reseller's, a plain user only themselves.
 *
 * @return array{0: string, 1: array} the WHERE clause and its bindings
 */
$visibleUsers = static function (array $actor, array $query): array {
    if ($actor['isAdmin']) {
        return isset($query['reseller_id']) && $query['reseller_id'] !== ''
            ? ['reseller_id = :reseller_id', [':reseller_id' => (int) $query['reseller_id']]]
            : ['1 = 1', []];
    }
    return $actor['isManager']
        ? ['reseller_id = :reseller_id', [':reseller_id' => $actor['resellerId']]]
        : ['id = :me', [':me' => $actor['id']]];
};

/**
 * A 403 in the shape every other route in this file already answers with.
 */
$notAuthorized = static function (Response $response): Response {
    return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
};

$app->get('/v1/users', function (Request $request, Response $response, array $args) use ($visibleUsers): Response {
    $actor = Auth::actor($request);
    [$where, $bind] = $visibleUsers($actor, $request->getQueryParams());

    $users = R::getAll("SELECT " . User::readColumns($actor['isManager']) . " FROM users WHERE {$where}", $bind);
    return Json::response($response, [
        'users' => User::withPasskeyCounts($users),
    ]);
});

$app->get('/v1/users/{id}', function (Request $request, Response $response, array $args) use ($visibleUsers): Response {
    $actor = Auth::actor($request);
    [$where, $bind] = $visibleUsers($actor, []);

    $users = R::getAll("SELECT " . User::readColumns($actor['isManager']) . " FROM users WHERE id = :id AND {$where}", [
        ':id' => $args['id'],
    ] + $bind);
    return Json::response($response, [
        'users' => User::withPasskeyCounts($users),
    ]);
});

$app->put('/v1/changepassword/{id}', function (Request $request, Response $response, array $args): Response {
    $decoded  = Auth::requireMfa($request);
    $user_id   = (int) $decoded->data->id;
    $params   = $request->getParsedBody() ?? [];
    $password = $params['password'] ?? '';

    if (empty($password)) {
        return Json::response($response, ['error' => 'Please provide a password'], 401);
    }

    // 400, not 401: the password was supplied, it is simply not good enough.
    // Before the authorization test, which is about *whose* password this is --
    // a caller changing their own still has to meet the rule
    if ( ! PasswordPolicy::isAcceptable($password)) {
        return Json::response($response, ['error' => PasswordPolicy::explain($password)], 400);
    }

    // 403, not 401: the caller is authenticated, they are just not allowed to
    // change this particular user's password -- their own, their manager's,
    // or an admin's to change. Matches every other authorization refusal.
    try {
        Auth::actorFor($request, (int) $args['id']);
    } catch (HttpForbiddenException) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    R::exec("
        UPDATE users SET
            password     = :password
        WHERE id = :id
    ", [
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':id'       => $args['id']
    ]);

    $users = R::getAll("SELECT
        id, active, role, username, 'PASSWORD_CHANGED' AS password, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int)$args['id'], 'update', $users[0] ?? [], $user_id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

/**
 * The fields POST and PUT /v1/users share, checked against their columns.
 *
 * @return string|null the first problem, or null when all present fields fit
 */
$userFieldError = static function (array $params): ?string {
    if (array_key_exists('username', $params)) {
        $username = $params['username'];
        if ( ! is_string($username) || trim($username) === '') {
            return 'username must not be empty';
        }
        if (mb_strlen($username) > 32) {
            return 'username must be at most 32 characters';
        }
    }
    $error = User::signinRequirementError($params['signin_requirement'] ?? SigninRequirement::TOTP);
    if ($error !== null) {
        return $error;
    }
    if (Auth::parseTokenLifetime($params['max_token_age'] ?? null) === false) {
        return 'max_token_age (the maximum) must be null or one of ' . implode(', ', Auth::TOKEN_LIFETIMES) . ' (minutes)';
    }
    foreach (['max_idle_time'] as $field) {
        $value = $params[$field] ?? null;
        if ($value !== null && ! (is_int($value) && $value >= 0) && ! (is_string($value) && ctype_digit($value))) {
            return "{$field} must be null or a non-negative whole number";
        }
    }
    return null;
};

/**
 * What an admin must prove to loosen their own sign-in requirement: a signed
 * session (fixed tokens and remote auth have none) and a fresh step-up.
 *
 * @param array $user the admin's users row
 * @return Response|null the 400, 401 or 429 to answer with, or null when the
 *         proof holds
 */
$loosenStepUp = static function (Request $request, Response $response, array $user, mixed $proof) use ($rateLimited): ?Response {
    if ( ! isset(Auth::verify($request)->exp)) {
        return Json::response($response, ['error' => UserRules::SELF_LOOSENING_SESSION], 400);
    }
    if (empty($proof)) {
        return Json::response($response, [
            'error' => 'Confirm with a step-up to loosen your own sign-in requirement',
            'stepup_required' => true,
            'stepup' => StepUp::method($user),
        ], 400);
    }
    if (($blocked = $rateLimited($request, $response, (string) $user['username'])) !== null) {
        return $blocked;
    }
    if (StepUp::verify($request, $user, $proof) === null) {
        return null;
    }
    return Json::response($response, ['error' => 'Step-up verification failed', 'stepup' => StepUp::method($user)], 401);
};

$app->put('/v1/users/{id}', function (Request $request, Response $response, array $args) use ($notAuthorized, $userFieldError, $loosenStepUp): Response {
    $actor = Auth::requireManager($request);
    $targetId = (int) $args['id'];
    $params = $request->getParsedBody() ?? [];

    // load the row first: an unknown id is a 404 rather than a silent no-op,
    // and an omitted field falls back to its current value instead of NULL --
    // as password has always done here
    $current = R::getRow("SELECT * FROM users WHERE id = :id", [':id' => $targetId]);
    if (empty($current)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    // a manager reaches only a non-admin user of their own reseller, and may
    // not hand out role=admin or debug -- an admin's own request is unrestricted
    if ( ! $actor['isAdmin']) {
        if ( ! UserRules::reachable($actor['scope'], $current)) {
            return $notAuthorized($response);
        }
        if ((isset($params['role']) && $params['role'] === 'admin') || array_key_exists('debug', $params)) {
            return $notAuthorized($response);
        }
    }

    if (($error = $userFieldError($params)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    $email = $params['email'] ?? $current['email'];
    if (($error = User::emailError($email, $targetId)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    // the UNIQUE column is pre-checked (excluding this row) for the same reason
    // as in POST: a 400 beats an uncaught SQL error
    $username = $params['username'] ?? $current['username'];
    if ($username !== $current['username']) {
        $taken = (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = :username AND id <> :id", [
            ':username' => $username,
            ':id'       => $targetId,
        ]);
        if ($taken > 0) {
            return Json::response($response, ['error' => "username '{$username}' is already taken"], 400);
        }
    }

    // a user's reseller is fixed at creation
    if (isset($params['reseller_id']) && (int) $params['reseller_id'] !== (int) $current['reseller_id']) {
        return Json::response($response, ['error' => "a user's reseller cannot be changed"], 400);
    }
    $role = (string) ($params['role'] ?? $current['role']);
    if (($error = User::roleError($role, (int) $current['reseller_id'])) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }
    $active = (int) ($params['active'] ?? $current['active']);

    // the last active admin, or the last active manager of an active reseller,
    // may not be demoted or deactivated -- someone has to be left who can fix
    // it; a promotion keeps them able to. Checked before the self-guard
    // below, so it is this message a sole admin/manager gets, not the generic one
    $rank = ['user' => 0, 'manager' => 1, 'admin' => 2];
    $demoted = $rank[$role] < $rank[$current['role']];
    if (($error = UserRules::lastStandingError($current + ['id' => $targetId], $demoted, $active === 0)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    // nobody, admin included, may touch their own role or deactivate themselves
    if ($targetId === $actor['id']) {
        if ($role !== $current['role']) {
            return Json::response($response, ['error' => 'you cannot change your own role'], 400);
        }
        if ($active !== (int) $current['active']) {
            return Json::response($response, ['error' => UserRules::SELF_DEACTIVATION], 400);
        }
    }

    $mode = (string) ($params['signin_requirement'] ?? $current['signin_requirement']);
    if (($refusal = UserRules::signinRequirementRefusal($actor['scope'], $current, $mode, true)) !== null) {
        return Json::response($response, ['error' => $refusal[1]], $refusal[0]);
    }
    // only an admin gets this far: the refusal above stops everyone else
    $loosensOwn = UserRules::loosensOwn($actor['scope'], $current, $mode);
    $enrollOpen = UserRules::enrollOpenAfter($current, $mode);

    $fields = [
        'description'    => $params['description'] ?? $current['description'],
        'username'       => $username,
        'email'          => trim($email),
        'active'         => $active,
        'role'           => $role,
        'notify_enabled' => (int) ($params['notify_enabled'] ?? $current['notify_enabled']),
        'max_token_age'  => array_key_exists('max_token_age', $params)
            ? Auth::parseTokenLifetime($params['max_token_age']) : $current['max_token_age'],
        'max_idle_time'  => $params['max_idle_time'] ?? $current['max_idle_time'],
        'debug'          => (int) ($params['debug'] ?? $current['debug']),
        'must_change_password' => (int) ($params['must_change_password'] ?? $current['must_change_password']),
        // must_enroll_mfa is still accepted and ignored: signin_requirement replaced it
        'signin_requirement'  => $mode,
        'passkey_enroll_open' => $enrollOpen,
    ];
    // the password column is only touched when a new one was actually supplied
    if ( ! empty($params['password'])) {
        if ( ! PasswordPolicy::isAcceptable($params['password'])) {
            return Json::response($response, ['error' => PasswordPolicy::explain($params['password'])], 400);
        }
        $fields['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
    }

    // last, so a request refused for anything else leaves the proof unspent
    if ($loosensOwn && ($stop = $loosenStepUp($request, $response, $current, $params['stepup'] ?? null)) !== null) {
        return $stop;
    }

    $set = [];
    $bind = [':id' => $targetId];
    foreach ($fields as $k => $v) {
        $set[] = "{$k} = :{$k}";
        $bind[":{$k}"] = $v;
    }
    R::exec("UPDATE users SET " . implode(', ', $set) . " WHERE id = :id", $bind);

    $users = R::getAll("SELECT " . User::readColumns(true) . " FROM users WHERE id = :id", [
        ':id' => $targetId,
    ]);
    History::record('users', $targetId, 'update', ($users[0] ?? []) + (
        $enrollOpen !== (int) $current['passkey_enroll_open'] ? ['passkey_enroll_open' => $enrollOpen] : []
    ), $actor['id']);
    if ($loosensOwn) {
        History::recordSecurityEvent('signin_requirement_loosened', $request, $actor['id'], [
            'target_id' => $targetId, 'target' => $username,
            'from' => $current['signin_requirement'], 'to' => $mode,
        ]);
    }
    if ($mode === SigninRequirement::NONE && $current['signin_requirement'] !== $mode) {
        History::recordSecurityEvent('signin_requirement_none', $request, $actor['id'], [
            'target_id' => $targetId, 'target' => $username,
        ]);
    }
    return Json::response($response, [
        'users' => User::withPasskeyCounts($users),
    ]);
});

$app->post('/v1/users', function (Request $request, Response $response, array $args) use ($notAuthorized, $userFieldError): Response {
    $actor = Auth::requireManager($request);
    $params = $request->getParsedBody() ?? [];

    if ($err = Validate::requireFields($params, ['username', 'password'])) {
        return Json::response($response, ['error' => $err], 400);
    }
    if (($error = $userFieldError($params)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }
    if (($error = User::emailError($params['email'] ?? null)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    // pre-check the UNIQUE column, so a collision comes back as a 400 with a
    // clear message instead of an uncaught SQL error surfacing as a 500
    if ( ! PasswordPolicy::isAcceptable($params['password'] ?? '')) {
        return Json::response($response, ['error' => PasswordPolicy::explain((string) ($params['password'] ?? ''))], 400);
    }

    $taken = R::getRow("SELECT username FROM users WHERE username = :username", [
        ':username' => $params['username'],
    ]);
    if ( ! empty($taken)) {
        return Json::response($response, ['error' => "username '{$params['username']}' is already taken"], 400);
    }

    $resellerId = (int) ($params['reseller_id'] ?? ($actor['isAdmin'] ? 1 : $actor['resellerId']));
    $role = (string) ($params['role'] ?? 'user');

    // a manager stays within their own reseller, and may not hand out
    // role=admin or debug -- an admin's request is unrestricted
    if ( ! $actor['isAdmin']) {
        if (isset($params['reseller_id']) && $resellerId !== $actor['resellerId']) {
            return $notAuthorized($response);
        }
        $resellerId = $actor['resellerId'];
        if ($role === 'admin' || array_key_exists('debug', $params)) {
            return $notAuthorized($response);
        }
    }

    $mode = (string) ($params['signin_requirement'] ?? SigninRequirement::TOTP);
    // measured against the default a new user starts with
    $stub = ['id' => 0, 'signin_requirement' => SigninRequirement::TOTP];
    if (($refusal = UserRules::signinRequirementRefusal($actor['scope'], $stub, $mode)) !== null) {
        return Json::response($response, ['error' => $refusal[1]], $refusal[0]);
    }

    if ((int) R::getCell('SELECT COUNT(*) FROM resellers WHERE id = ?', [$resellerId]) === 0) {
        return Json::response($response, ['error' => "Reseller {$resellerId} not found"], 400);
    }
    if (($error = User::roleError($role, $resellerId)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    $optionalInt = static fn(mixed $value): ?int => $value === null ? null : (int) $value;
    try {
        $id = User::create(
            username: $params['username'],
            password: (string) $params['password'],
            email: trim($params['email']),
            description: $params['description'] ?? null,
            resellerId: $resellerId,
            role: $role,
            mustChangePassword: (bool) (int) ($params['must_change_password'] ?? 0),
            actorId: $actor['id'],
            active: (bool) (int) ($params['active'] ?? 1),
            maxTokenAge: Auth::parseTokenLifetime($params['max_token_age'] ?? null),
            maxIdleTime: $optionalInt($params['max_idle_time'] ?? null),
            debug: (bool) (int) ($params['debug'] ?? 0),
            notifyEnabled: isset($params['notify_enabled']) ? (bool) (int) $params['notify_enabled'] : null,
            signinRequirement: $mode,
        );
    } catch (UsernameTaken | \InvalidArgumentException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 400);
    }

    if ($mode === SigninRequirement::NONE) {
        History::recordSecurityEvent('signin_requirement_none', $request, $actor['id'], [
            'target_id' => $id, 'target' => $params['username'],
        ]);
    }

    $users = R::getAll("SELECT " . User::readColumns(true) . " FROM users WHERE id = :id", [
        ':id' => $id,
    ]);
    return Json::response($response, [
        'users' => User::withPasskeyCounts($users),
    ], 201);
});

$app->delete('/v1/users/{id}', function (Request $request, Response $response, array $args) use ($notAuthorized): Response {
    $actor = Auth::requireManager($request);
    $targetId = (int) $args['id'];

    $current = R::getRow("SELECT role, reseller_id, active FROM users WHERE id = :id", [':id' => $targetId]);
    if (empty($current)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    if ( ! $actor['isAdmin'] && ! UserRules::reachable($actor['scope'], $current)) {
        return $notAuthorized($response);
    }

    // same last-one-standing guard as PUT .../active=0, checked before the
    // self-guard below; a no-op deactivation of someone already inactive
    // never trips it
    if ((int) $current['active'] === 1
        && ($error = UserRules::lastStandingError($current + ['id' => $targetId], false, true)) !== null) {
        return Json::response($response, ['error' => $error], 400);
    }

    if ($targetId === $actor['id']) {
        return Json::response($response, ['error' => UserRules::SELF_DEACTIVATION], 400);
    }

    R::exec("UPDATE users SET active = 0 WHERE id = :id", [
        ':id' => $targetId,
    ]);

    $users = R::getAll("SELECT
        id, active, role, username, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $targetId,
    ]);
    History::record('users', $targetId, 'delete', $users[0] ?? [], $actor['id']);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users/{id}/totp', function (Request $request, Response $response, array $args) use ($notAuthorized): Response {
    // one's own, or as their manager or an admin (Auth::actorFor)
    try {
        Auth::actorFor($request, (int) $args['id']);
    } catch (HttpForbiddenException) {
        return $notAuthorized($response);
    }

    $user = R::getAll("SELECT id, username, totp_secret FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if ( ! empty($user[0]['totp_secret'])) {
        return Json::response($response, ['error' => 'This account already has a TOTP secret; remove it first with DELETE /v1/users/{id}/totp'], 400);
    }

    $totp = Auth::totpGenerate($user[0]['username'], $request->getUri()->getHost());

    R::exec("UPDATE users SET totp_secret_pending = :secret WHERE id = :id", [
        ':secret' => $totp['secret'],
        ':id'     => $args['id'],
    ]);

    return Json::response($response, [
        'secret' => $totp['secret'],
        'uri'    => $totp['uri'],
    ]);
});

$app->put('/v1/users/{id}/totp', function (Request $request, Response $response, array $args) use ($notAuthorized): Response {
    try {
        $actor = Auth::actorFor($request, (int) $args['id']);
    } catch (HttpForbiddenException) {
        return $notAuthorized($response);
    }

    $params   = $request->getParsedBody() ?? [];
    $totpCode = $params['totp'] ?? '';

    $user = R::getAll("SELECT id, totp_secret_pending FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }
    if (empty($user[0]['totp_secret_pending'])) {
        return Json::response($response, ['error' => 'No pending TOTP setup found'], 400);
    }
    if (empty($totpCode) || !Auth::totpVerify($user[0]['totp_secret_pending'], $totpCode)) {
        return Json::response($response, ['error' => 'Invalid TOTP code'], 401);
    }

    R::exec("UPDATE users SET totp_secret = totp_secret_pending, totp_secret_pending = NULL WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    $users  = R::getAll("SELECT
        id, active, role, username, max_token_age, max_idle_time, debug
    FROM users WHERE id = :id", [
        ':id' => $args['id'],
    ]);
    History::record('users', (int) $args['id'], 'update', $users[0] ?? [], $actor['id']);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->delete('/v1/users/{id}/totp', function (Request $request, Response $response, array $args) use ($rateLimited): Response {
    $decoded = Auth::requireMfa($request);

    // one's own, or as their manager or an admin (Auth::actorFor)
    try {
        Auth::actorFor($request, (int) $args['id']);
    } catch (HttpForbiddenException) {
        return Json::response($response, ['error' => 'You are not authorized to perform this operation'], 403);
    }

    // a key-only account's authenticator app is its fallback: taking it away
    // is confirmed with the key, not with the session alone. Fixed tokens and
    // remote auth have no session to confirm
    $user = R::getRow('SELECT id, username, password, totp_secret, signin_requirement FROM users WHERE id = ?', [(int) $args['id']]);
    if (isset($decoded->exp) && (int) $decoded->data->id === (int) $args['id']
        && ($user['signin_requirement'] ?? null) === SigninRequirement::PASSKEY) {
        if (($blocked = $rateLimited($request, $response)) !== null) {
            return $blocked;
        }
        $proof = ($request->getParsedBody() ?? [])['stepup'] ?? null;
        if (StepUp::verify($request, $user, $proof) !== null) {
            return Json::response($response, ['error' => 'Step-up verification failed', 'stepup' => StepUp::method($user)], 401);
        }
    }

    $users = UserRules::resetMfa((int) $args['id'], (int) $decoded->data->id);
    return Json::response($response, [
        'users' => $users,
    ]);
});

$app->post('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args) use ($notAuthorized): Response {
    try {
        $actor = Auth::actorFor($request, (int) $args['id']);
    } catch (HttpForbiddenException) {
        return $notAuthorized($response);
    }

    $user = R::getAll("SELECT id FROM users WHERE id = :id AND active = 1", [
        ':id' => $args['id'],
    ]);
    if (empty($user)) {
        return Json::response($response, ['error' => 'User not found'], 404);
    }

    $params = $request->getParsedBody() ?? [];
    // absolute unix timestamp; 0 = no expiry (infinite)
    $expires = isset($params['expires']) ? (int) $params['expires'] : 0;

    // 32 random bytes as an opaque hex token -- stored only as a hash, same as
    // passwords
    $token = PasswordGenerator::token();
    R::exec("UPDATE users SET api_token = :token, api_token_expires = :expires WHERE id = :id", [
        ':token'   => hash('sha256', $token),
        ':expires' => $expires,
        ':id'      => $args['id'],
    ]);

    History::record('users', (int) $args['id'], 'update', ['api_token_expires' => $expires], $actor['id']);

    // the plaintext token is only ever shown here, at issue time -- it can't be
    // recovered later since only its hash is stored
    return Json::response($response, [
        'token'   => $token,
        'expires' => $expires,
    ]);
});

$app->delete('/v1/users/{id}/api-token', function (Request $request, Response $response, array $args) use ($notAuthorized): Response {
    try {
        $actor = Auth::actorFor($request, (int) $args['id']);
    } catch (HttpForbiddenException) {
        return $notAuthorized($response);
    }

    R::exec("UPDATE users SET api_token = NULL, api_token_expires = 0 WHERE id = :id", [
        ':id' => $args['id'],
    ]);

    History::record('users', (int) $args['id'], 'update', ['api_token' => null], $actor['id']);

    return Json::response($response, ['revoked' => true]);
});
