<?php

namespace Eppitnic\Service;

use Eppitnic\Api\Auth;
use Eppitnic\Api\ClientIp;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\Passkeys;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A fresh proof, on top of a session, before a passkey or the authenticator app
 * is added or removed: the strongest factor the user has -- a passkey, else a
 * TOTP code, else the password. A failure counts like a failed sign-in.
 *
 * @category    Net
 * @package     Eppitnic\Service\StepUp
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class StepUp
{
    public const PASSKEY = 'passkey';
    public const TOTP = 'totp';
    public const PASSWORD = 'password';

    /**
     * @param array $user a users row with id and totp_secret
     * @return string PASSKEY, TOTP or PASSWORD: what the proof must be
     */
    public static function method(array $user): string {
        if (PasskeySettings::enabled() && PasskeyPolicy::compliantCount((int) $user['id']) > 0) {
            return self::PASSKEY;
        }
        return empty($user['totp_secret']) ? self::PASSWORD : self::TOTP;
    }

    /**
     * @param array $user a users row with id, username, password, totp_secret
     *        and webauthn_handle
     * @param mixed $proof `{challenge_id, credential}`, `{totp}` or
     *        `{password}`, as method() says
     * @return string|null why it was refused, or null when it holds
     */
    public static function verify(Request $request, array $user, mixed $proof): ?string {
        $method = self::method($user);
        $proof = is_array($proof) ? $proof : [];

        $reason = match ($method) {
            self::PASSKEY => self::assertion($request, $user, $proof),
            self::TOTP    => self::code($user, $proof),
            default       => self::password($user, $proof),
        };
        if ($reason !== null) {
            History::recordSecurityEvent('stepup_failed', $request, (int) $user['id'], [
                'username' => (string) $user['username'],
                'method'   => $method,
                'reason'   => $reason,
            ], 'denied');
        }
        return $reason;
    }

    private static function code(array $user, array $proof): ?string {
        $code = $proof['totp'] ?? null;
        if (( ! is_string($code) && ! is_int($code)) || (string) $code === '') {
            return 'no code';
        }
        return Auth::totpVerify((string) $user['totp_secret'], (string) $code) ? null : 'wrong code';
    }

    private static function password(array $user, array $proof): ?string {
        $password = $proof['password'] ?? null;
        if ( ! is_string($password) || $password === '') {
            return 'no password';
        }
        return password_verify($password, (string) $user['password']) ? null : 'wrong password';
    }

    private static function assertion(Request $request, array $user, array $proof): ?string {
        $json = WebAuthn::credentialJson($proof['credential'] ?? null);
        $challengeId = $proof['challenge_id'] ?? null;
        if ($json === null || ! is_numeric($challengeId)) {
            return 'no assertion';
        }

        try {
            $claims = WebAuthn::claims($json);
        } catch (WebAuthnFailure) {
            return 'malformed response';
        }
        $userId = (int) $user['id'];
        if ( ! WebAuthnChallenges::consume((int) $challengeId, $claims['challenge'], 'stepup', $userId)) {
            return 'challenge unknown, spent or expired';
        }

        // another user's key answers a challenge of this user just as well
        $passkey = Passkeys::findByCredentialId($claims['credential_id']);
        if ($passkey === null || (int) $passkey['user_id'] !== $userId) {
            WebAuthn::spendVerificationTime();
            return $passkey === null ? 'unknown credential' : 'credential of another user';
        }

        try {
            $assertion = WebAuthn::verifyAssertion($json, $claims['challenge'], $passkey, Passkeys::handleFor($userId));
        } catch (WebAuthnFailure $e) {
            return $e->reason === WebAuthnFailure::COUNTER_REGRESSION ? self::regression($request, $user, $passkey) : $e->reason;
        }
        if ( ! PasskeyPolicy::complies($passkey, PasskeySettings::get())) {
            return 'key does not meet the passkey policy';
        }
        if ( ! Passkeys::updateUsage((int) $passkey['id'], $assertion, ClientIp::get())) {
            return self::regression($request, $user, $passkey);
        }
        return null;
    }

    private static function regression(Request $request, array $user, array $passkey): string {
        History::recordSecurityEvent('passkey_counter_regression', $request, (int) $user['id'], [
            'username'   => (string) $user['username'],
            'credential' => (int) $passkey['id'],
        ], 'secread');
        return WebAuthnFailure::COUNTER_REGRESSION;
    }
}
