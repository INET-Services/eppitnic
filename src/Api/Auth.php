<?php

namespace Eppitnic\Api;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Eppitnic\Config;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\EppSettings;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\SigninRequirement;
use OTPHP\TOTP;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Who the caller is, and whether they may do this. Three credentials reach the
 * same place -- a JWT, a hashed automation token synthesized into the same
 * claims shape, and TOTP on top of either -- and downstream reads one object.
 *
 * @category    Net
 * @package     Eppitnic\Api\Auth
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Auth
{
    /** Token lifetimes in minutes a user may pick; the first is not special. */
    public const TOKEN_LIFETIMES = [5, 10, 15, 30, 60, 90, 120, 240];

    public const DEFAULT_TOKEN_LIFETIME = 15;

    /** Upper limit when `max_token_age` is unset: the largest listed value. */
    public const MAX_TOKEN_LIFETIME = 240;

    /** @var \WeakMap<Request, object>|null verify()'s result, once per request */
    private static ?\WeakMap $verified = null;

    /**
     * The authenticated caller, as every route needs them. Just these fields: a
     * route wanting more of the token calls verify() directly, so carrying it
     * here too would be a second way to reach the same thing.
     *
     * @param Request $request the incoming HTTP request
     * @return array{id: int, role: string, isAdmin: bool, isManager: bool, resellerId: int, debug: bool, scope: Scope}
     * @throws HttpUnauthorizedException if the request carries no usable
     *                       credential
     */
    public static function actor(Request $request): array {
        $decoded = self::verify($request);
        $scope = new Scope((int) $decoded->data->id, (int) $decoded->data->reseller_id, (string) $decoded->data->role);
        return [
            'id'         => $scope->userId,
            'role'       => $scope->role,
            'isAdmin'    => $scope->isAdmin(),
            'isManager'  => $scope->isManager(),
            'resellerId' => $scope->resellerId,
            'debug'      => ! empty($decoded->data->debug),
            'scope'      => $scope,
        ];
    }

    // -----------------------------------------------------------------
    // JWT / fixed API token auth
    // -----------------------------------------------------------------

    /**
     * Match $token against users.api_token and synthesize the claims object
     * JWT::decode() would return, so downstream works the same either way.
     * Null lets callers fall through. MFA is bypassed: no human is present.
     *
     * @param string $token the raw bearer token from the Authorization header
     * @return object|null synthesized decoded-claims object, or null if not a
     *                     valid fixed token
     */
    private static function verifyFixedApiToken(string $token): ?object {
        $user = R::getRow("
            SELECT id, username
            FROM users
            WHERE api_token = :token AND active = 1
              AND (api_token_expires = 0 OR api_token_expires > UNIX_TIMESTAMP())
        ", [':token' => hash('sha256', $token)]);

        if (empty($user)) {
            return null;
        }

        return (object) [
            'data' => (object) [
                'id'            => (int) $user['id'],
                'username'      => $user['username'],
                'has_totp'      => false,
                'has_passkeys'  => false,
                'needs_totp'    => false,
                'totp_verified' => false,
            ],
        ];
    }

    // -----------------------------------------------------------------
    // remote auth (front server / trusted proxy)
    // -----------------------------------------------------------------

    /**
     * The `remote_auth` setting, or disabled if it was never seeded -- same
     * reasoning as ClientIp::trustedProxies() treating a missing key as
     * empty.
     */
    private static function remoteAuthSettings(): array {
        try {
            return (array) Config::get('remote_auth');
        } catch (\Throwable) {
            return ['enabled' => false, 'header' => null];
        }
    }

    /**
     * @return bool whether $request's Authorization header uses the Bearer
     *              scheme -- if so, it always takes the JWT/fixed-token path,
     *              remote auth or not
     */
    private static function isBearerAuth(Request $request): bool {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            return false;
        }
        return strcasecmp(explode(' ', $authHeader, 2)[0], 'Bearer') === 0;
    }

    /**
     * The remote username server mode or header mode resolved, if any.
     * Header mode never falls back to REMOTE_USER, and only ever reads the
     * header from a `trusted_proxies` peer -- everyone else is ignored.
     *
     * @param array $remoteAuth the `remote_auth` setting
     * @return string|null the trimmed username, or null if none was found
     */
    private static function resolveRemoteUsername(Request $request, array $remoteAuth): ?string {
        $serverParams = $request->getServerParams();
        $header = $remoteAuth['header'] ?? null;

        if ($header !== null && $header !== '') {
            $peer = (string) ($serverParams['REMOTE_ADDR'] ?? '');
            if ($peer === '' || ! ClientIp::isTrustedProxy($peer)) {
                return null;
            }
            $username = trim($request->getHeaderLine($header));
            return $username !== '' ? $username : null;
        }

        $username = trim((string) (
            $serverParams['REMOTE_USER'] ?? $serverParams['REDIRECT_REMOTE_USER'] ?? ''
        ));
        return $username !== '' ? $username : null;
    }

    /**
     * Match $username against a local user and synthesize the claims object
     * JWT::decode() would return -- same shape as verifyFixedApiToken(), plus
     * `remote_auth: true`. MFA is the front server's job, so has_totp is
     * always false here. An inactive account is withAccount()'s to refuse.
     *
     * @throws HttpForbiddenException if $username has no local account
     */
    private static function verifyRemoteUser(string $username, Request $request): object {
        $user = R::getRow("
            SELECT id, username, debug, max_token_age, token_lifetime, max_idle_time
            FROM users
            WHERE username = :username
        ", [':username' => $username]);

        if (empty($user)) {
            throw new HttpForbiddenException($request, "Remote user '{$username}' has no active account");
        }

        return (object) [
            'data' => (object) [
                'id'            => (int) $user['id'],
                'username'      => $user['username'],
                'has_totp'      => false,
                'has_passkeys'  => false,
                'needs_totp'    => false,
                'totp_verified' => false,
                'debug'         => (bool) $user['debug'],
                'max_token_age' => $user['max_token_age'],
                'token_lifetime' => $user['token_lifetime'],
                'max_idle_time' => $user['max_idle_time'],
                'remote_auth'   => true,
            ],
        ];
    }

    /**
     * validate the Authorization header: a real JWT (session login), falling
     * back to a fixed automation token (see verifyFixedApiToken()) if JWT
     * decoding fails for any reason. When `remote_auth.enabled` is true and
     * the request carries no Bearer token, a front server's REMOTE_USER (or
     * a trusted proxy's header) is tried first instead.
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded claims, shaped identically regardless of which
     *                auth mechanism matched
     * @throws HttpForbiddenException if a remote username was found but maps
     *                       to no active local user
     * @throws HttpUnauthorizedException if neither a valid JWT nor a valid
     *                       fixed token is found
     */
    public static function verify(Request $request): object {
        self::$verified ??= new \WeakMap();
        return self::$verified[$request] ??= self::withAccount(self::credential($request), $request);
    }

    /**
     * The account as it stands now, not as the token remembers it: a
     * deactivated user or reseller loses access at once, and a role change
     * applies from the next request. Overwrites whatever the token claimed.
     *
     * @throws HttpUnauthorizedException if the user no longer exists
     * @throws HttpForbiddenException if the user or their reseller is inactive
     */
    private static function withAccount(object $decoded, Request $request): object {
        $account = R::getRow('
            SELECT u.id, u.active, u.role, u.reseller_id, r.name AS reseller_name, r.active AS reseller_active,
                   u.must_change_password, u.signin_requirement, u.passkey_enroll_open,
                   u.totp_secret IS NOT NULL AS has_totp, u.max_idle_time, u.last_activity
            FROM users u JOIN resellers r ON r.id = u.reseller_id
            WHERE u.id = ?
        ', [(int) $decoded->data->id]);

        if (empty($account)) {
            throw new HttpUnauthorizedException($request, 'User not found');
        }
        if ((int) $account['active'] !== 1) {
            throw new HttpForbiddenException($request, 'Your account is deactivated');
        }
        if ((int) $account['reseller_active'] !== 1) {
            throw new HttpForbiddenException($request, 'Your reseller account is deactivated');
        }

        // Sessions only: a signed token carries `exp`, while remote-auth and
        // fixed-token claims are synthesized and never went through a login
        if (isset($decoded->exp)) {
            self::requireValidPasskey($decoded, $request);
            // not the token's word: a factor added after login counts too
            $decoded->data->has_totp = (bool) $account['has_totp'];
            $decoded->data->has_passkeys = PasskeyPolicy::compliantCount((int) $account['id']) > 0;
            self::requireSetupDone($decoded, $account, $request);
            self::touchActivity((int) $decoded->data->id, $account, $request);
        }

        $decoded->data->needs_second_factor = self::needsSecondFactor($decoded->data);
        unset($decoded->data->admin);
        $decoded->data->role = $account['role'];
        $decoded->data->reseller_id = (int) $account['reseller_id'];
        $decoded->data->reseller_name = $account['reseller_name'];
        $decoded->data->registry = EppSettings::environment();
        return $decoded;
    }

    /**
     * A session opened with a passkey ends when that key is gone or no longer
     * meets the policy.
     *
     * @throws HttpUnauthorizedException
     */
    private static function requireValidPasskey(object $decoded, Request $request): void {
        if (empty($decoded->data->cred)) {
            return;
        }
        $key = R::getRow(
            'SELECT user_id, aaguid, attestation_verified, backup_eligible FROM passkeys WHERE id = ?',
            [(int) $decoded->data->cred]
        );
        if (empty($key)
            || (int) $key['user_id'] !== (int) $decoded->data->id
            || ! PasskeyPolicy::complies($key, PasskeySettings::get())) {
            throw new HttpUnauthorizedException($request, 'Session ended: passkey no longer valid');
        }
    }

    /**
     * What a session still owes, as a login would be told: the password
     * change, then the setup its sign-in requirement asks for, and for
     * "passkey" in full effect a sign-in with the key itself.
     *
     * @throws HttpForbiddenException
     */
    private static function requireSetupDone(object $decoded, array $account, Request $request): void {
        $mode = (string) $account['signin_requirement'];
        $hasTotp = (bool) $account['has_totp'];
        $amr = (string) ($decoded->data->amr ?? (empty($decoded->data->totp_verified) ? 'pwd' : 'pwd+otp'));
        // the network matters only to an MFA enrolment that is due
        $safe = SigninRequirement::needsTotp($mode) && ! $hasTotp && self::onSafeNetwork();
        $requirement = SigninRequirement::evaluate(
            $account, PasskeyPolicy::usableKeys($account), $hasTotp, $safe, $amr, PasskeySettings::enabled()
        );

        // as at login: the password alone registers keys only while that is open
        $mayEnrol = (int) $account['passkey_enroll_open'] === 1 || $amr === 'pwd+otp';
        $enrol = $requirement->required === SigninRequirement::PASSKEY_ENROLLMENT
            && ($mayEnrol || ! PasskeySettings::enabled());
        $message = match ($requirement->required) {
            SigninRequirement::PASSWORD_CHANGE    => 'Password change required',
            SigninRequirement::MFA_ENROLLMENT     => 'MFA enrollment required',
            SigninRequirement::PASSKEY_ENROLLMENT => $enrol
                ? 'Passkey enrollment required' : SigninRequirement::SIGNIN_RESET_MESSAGE,
            // only a new sign-in meets methodAllowed; a session, full effect
            default => $amr !== 'passkey' && $requirement->fullEffect ? 'Passkey sign-in required' : null,
        };
        if ($message !== null) {
            throw new SessionRequirement($request, $message, $enrol
                ? ['required' => 'passkey_enrollment'] + $requirement->keyCounter() : []);
        }
    }

    /**
     * Enforce `users.max_idle_time` (minutes, empty or 0 = no limit) and
     * record the request as activity, at most once a minute to spare the
     * write. `last_activity` is UTC.
     *
     * @throws HttpUnauthorizedException if the session sat idle too long
     */
    private static function touchActivity(int $userId, array $account, Request $request): void {
        $now = time();
        $last = $account['last_activity'] === null ? null : strtotime($account['last_activity'] . ' UTC');

        $maxIdle = (int) $account['max_idle_time'];
        if ($maxIdle > 0 && $last !== null && $now - $last > $maxIdle * 60) {
            throw new HttpUnauthorizedException($request, 'Session expired after inactivity');
        }
        if ($last === null || $now - $last >= 60) {
            R::exec('UPDATE users SET last_activity = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $now), $userId]);
        }
    }

    /**
     * Start a session's idle clock, as a login does.
     */
    public static function startActivity(int $userId): void {
        R::exec('UPDATE users SET last_activity = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $userId]);
    }

    /**
     * @return bool whether the client address is inside `safe_networks`
     */
    public static function onSafeNetwork(): bool {
        foreach (Config::get('safe_networks') as $cidr) {
            if (ClientIp::inCidr($cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function credential(Request $request): object {
        $remoteAuth = self::remoteAuthSettings();
        if ( ! empty($remoteAuth['enabled']) && ! self::isBearerAuth($request)) {
            $username = self::resolveRemoteUsername($request, $remoteAuth);
            if ($username !== null) {
                return self::verifyRemoteUser($username, $request);
            }
        }

        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            throw new HttpUnauthorizedException($request, 'Authorization token not set');
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || empty($parts[1])) {
            throw new HttpUnauthorizedException($request, 'Authorization token not valid');
        }
        $token = $parts[1];

        // an automation token is opaque, not a signed triple, so it can only
        // fail JWT::decode() -- check the fixed-token store before giving up.
        // Costs nothing on the normal path
        try {
            return JWT::decode($token, new Key(Config::get('jwt_psk'), 'HS256'));
        } catch (SignatureInvalidException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Signature is not valid: ' . $e->getMessage());
        } catch (BeforeValidException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token is not yet valid: ' . $e->getMessage());
        } catch (ExpiredException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token has expired: ' . $e->getMessage());
        } catch (\UnexpectedValueException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token contains unexpected value: ' . $e->getMessage());
        } catch (\DomainException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token outside expected domain: ' . $e->getMessage());
        }
    }

    /**
     * @param Request $request the incoming HTTP request
     * @return int the authenticated user's id
     */
    public static function userId(Request $request): int {
        return (int) self::verify($request)->data->id;
    }

    /**
     * require a second factor of a user who has one: a passkey sign-in or a
     * code checked at login (`strong_auth`), or a request from a safe network.
     * A session with the password alone is refused once the account has TOTP
     * or a compliant passkey. Fixed tokens and remote auth have no session.
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded JWT claims
     * @throws HttpForbiddenException if a second factor exists but the
     *                       session did not use it
     */
    public static function requireMfa(Request $request): object {
        $decoded = self::verify($request);
        if ($decoded->data->needs_second_factor) {
            throw new HttpForbiddenException($request, 'MFA verification required');
        }
        return $decoded;
    }

    /**
     * Whether requireMfa() refuses a session with these claims: it used the
     * password alone, the account has a second factor (TOTP or a compliant
     * passkey) and the client is outside `safe_networks`.
     *
     * @param array|object $claims strong_auth, totp_verified, has_totp and
     *        has_passkeys, each missing one counting as false
     */
    public static function needsSecondFactor(array|object $claims): bool {
        $claims = (array) $claims;
        if ( ! empty($claims['strong_auth']) || ! empty($claims['totp_verified'])) {
            return false;
        }
        return ( ! empty($claims['has_totp']) || ! empty($claims['has_passkeys'])) && ! self::onSafeNetwork();
    }

    /**
     * require an admin-privileged, MFA-verified (if enabled) token
     *
     * @param Request $request the incoming HTTP request
     * @return int the authenticated admin's user id
     * @throws HttpForbiddenException if not an admin, or MFA is enabled but not
     *                       yet verified
     */
    public static function requireAdmin(Request $request): int {
        $decoded = self::verify($request);
        if ($decoded->data->role !== 'admin') {
            throw new HttpForbiddenException($request, 'Admin access required');
        }
        self::requireMfa($request);
        return (int) $decoded->data->id;
    }

    /**
     * require a manager or admin, MFA-verified (if enabled)
     *
     * @return array the caller, as actor() returns it
     * @throws HttpForbiddenException if a plain user, or MFA is enabled but not
     *                       yet verified
     */
    public static function requireManager(Request $request): array {
        $actor = self::actor($request);
        if ( ! $actor['isManager']) {
            throw new HttpForbiddenException($request, 'Manager access required');
        }
        self::requireMfa($request);
        return $actor;
    }

    /**
     * The caller, for something that belongs to user $userId: their own, an
     * admin's to reach into, or their manager's (same reseller, never an
     * admin). Acting for someone else needs a verified MFA too.
     *
     * @param Request $request the incoming HTTP request
     * @param int $userId whose data is being touched
     * @return array the caller, as actor() returns it
     * @throws HttpForbiddenException if it is someone else's and the caller may
     *                       not act for them
     */
    public static function actorFor(Request $request, int $userId): array {
        $actor = self::actor($request);
        if ($actor['id'] === $userId) {
            return $actor;
        }
        if ($actor['role'] === 'manager') {
            $target = R::getRow('SELECT reseller_id, role FROM users WHERE id = ?', [$userId]);
            if ( ! empty($target) && (int) $target['reseller_id'] === $actor['resellerId'] && $target['role'] !== 'admin') {
                self::requireMfa($request);
                return $actor;
            }
        }
        self::requireAdmin($request);
        return $actor;
    }

    /**
     * Read a submitted token lifetime.
     *
     * @return int|false|null minutes from TOKEN_LIFETIMES, null for "use the
     *         default" (null or ''), false for anything else
     */
    public static function parseTokenLifetime(mixed $value): int|false|null {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        return is_int($value) && in_array($value, self::TOKEN_LIFETIMES, true) ? $value : false;
    }

    /**
     * The limit an admin or manager set (`max_token_age`), or the largest
     * listed lifetime when none is set.
     */
    public static function tokenLifetimeLimit(mixed $maxTokenAge): int {
        return (int) $maxTokenAge > 0 ? (int) $maxTokenAge : self::MAX_TOKEN_LIFETIME;
    }

    /**
     * The lifetime a token gets: the user's own choice (default 15) held to
     * the limit.
     *
     * @return int minutes
     */
    public static function effectiveTokenLifetime(mixed $tokenLifetime, mixed $maxTokenAge): int {
        $own = (int) $tokenLifetime > 0 ? (int) $tokenLifetime : self::DEFAULT_TOKEN_LIFETIME;
        return min($own, self::tokenLifetimeLimit($maxTokenAge));
    }

    /**
     * Sign a new JWT for $data, adding the standard claims. The lifetime comes
     * from $data['token_lifetime'] and $data['max_token_age'], in minutes,
     * spelled like the users columns (see effectiveTokenLifetime()).
     *
     * @param array $data claims to embed (may include 'token_lifetime' and
     *              'max_token_age')
     * @return array $data merged with the signed 'token' string
     */
    public static function issueToken(array $data): array {
        $maxTokenAge = self::effectiveTokenLifetime($data['token_lifetime'] ?? null, $data['max_token_age'] ?? null);

        $iat = time();
        $nbf = $iat - 60 * 5;
        $exp = $iat + 60 * $maxTokenAge;

        $data['exp'] = $exp;

        $payload = [
            'data' => $data,
            'iss'  => 'eppitnic',
            'sub'  => 'eppitnic API',
            'nbf'  => $nbf,
            'iat'  => $iat,
            'exp'  => $exp,
        ];

        return array_merge(
            ['token' => JWT::encode($payload, Config::get('jwt_psk'), 'HS256')],
            $data
        );
    }

    // -----------------------------------------------------------------
    // TOTP (MFA)
    // -----------------------------------------------------------------

    /**
     * generate a new TOTP secret + provisioning URI for a user enrolling in MFA
     *
     * @param string $username shown as the label in authenticator apps
     * @param string $host the host the request reached the API at, shown next
     *              to the issuer so several installations tell apart
     * @return array ['secret' => ..., 'uri' => ...]
     */
    public static function totpGenerate(string $username, string $host): array {
        // 20 bytes, per RFC 4226 section 4 and what authenticator apps expect.
        // otphp defaults to 64, whose base32 encoding overflows
        // users.totp_secret varchar(64) and is silently truncated on write
        $totp = TOTP::generate(null, 20);
        $totp->setLabel($username);
        // otphp refuses a colon in the issuer, which an IPv6 host has
        $host = str_replace(':', '', $host);
        $totp->setIssuer($host === '' ? 'eppitnic' : "eppitnic ({$host})");
        return [
            'secret' => $totp->getSecret(),
            'uri'    => $totp->getProvisioningUri(),
        ];
    }

    /**
     * @param string $secret the user's TOTP secret
     * @param string $code the 6-digit code to check
     * @return bool status
     */
    public static function totpVerify(string $secret, string $code): bool {
        try {
            // leeway in seconds; 29 = just under one 30s period, giving
            // practical ±1-window tolerance
            return TOTP::createFromSecret($secret)->verify($code, null, 29);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
