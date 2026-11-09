<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A user flagged to change the password, or whose sign-in requirement asks
 * for MFA they lack, gets no token until they have, and the routes that
 * complete it answer like a login.
 */
final class LoginRequirementsTest extends TestCase
{
    private const CURRENT = 'Current-Passw0rd!';
    private const FRESH   = 'Fr3sh-Enough-Pw!';
    private const OUTSIDE = '198.51.100.7';
    private const SAFE    = '10.1.2.3';

    private function app(array $remoteAuth = ['enabled' => false, 'header' => null]): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => ['10.0.0.0/8'],
            'trusted_proxies' => [],
            'remote_auth'     => $remoteAuth,
            'login_ratelimit' => ['max_failures' => 3, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensure(1, 'admin', 1, 'admin');
        TestAccounts::ensure(2, 'manager', 2, 'manager');
        TestAccounts::ensure(3, 'user', 2, 'plain');
        R::exec('UPDATE users SET password = ?', [password_hash(self::CURRENT, PASSWORD_BCRYPT, ['cost' => 4])]);

        $_SERVER['REMOTE_ADDR'] = self::OUTSIDE;

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR']);
        Config::reset();
        parent::tearDown();
    }

    private function flag(string $column, int $id = 3): void {
        R::exec("UPDATE users SET {$column} = 1 WHERE id = ?", [$id]);
    }

    private function requirement(string $mode, int $id = 3): void {
        R::exec('UPDATE users SET signin_requirement = ? WHERE id = ?', [$mode, $id]);
    }

    private function call(\Slim\App $app, string $method, string $path, array $body = [], ?string $token = null): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
            ->withParsedBody($body);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }
        return $app->handle($request);
    }

    private function creds(array $more = []): array {
        return ['username' => 'plain', 'password' => self::CURRENT] + $more;
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $r): array {
        return (array) json_decode((string) $r->getBody(), true);
    }

    private static function denied(): int {
        return (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'security' AND action = 'denied'");
    }

    public function testAFlaggedUserGetsNoTokenAndIsNotCountedAsAFailure(): void {
        $app = $this->app();
        $this->flag('must_change_password');

        $response = $this->call($app, 'POST', '/v1/users/authenticate', $this->creds());
        $body = self::json($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Password change required', $body['error']);
        $this->assertSame('password_change', $body['required']);
        $this->assertArrayHasKey('password_policy', $body);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertSame(0, self::denied());
    }

    public function testMfaEnrollmentIsRequiredOffTheSafeNetworks(): void {
        $app = $this->app();
        $this->requirement('totp');

        $response = $this->call($app, 'POST', '/v1/users/authenticate', $this->creds());
        $body = self::json($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA enrollment required', $body['error']);
        $this->assertSame('mfa_enrollment', $body['required']);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertSame(0, self::denied());
    }

    public function testMfaEnrollmentIsSkippedOnASafeNetworkAndTheRequirementStays(): void {
        $app = $this->app();
        $this->requirement('totp');
        $_SERVER['REMOTE_ADDR'] = self::SAFE;

        $response = $this->call($app, 'POST', '/v1/users/authenticate', $this->creds());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('token', self::json($response));
        $this->assertSame('totp', R::getCell('SELECT signin_requirement FROM users WHERE id = 3'));
    }

    public function testAnEnrolledUserLogsInWithTheirCodeUnderTheRequirement(): void {
        $app = $this->app();
        $this->requirement('totp');
        $secret = TOTP::generate(null, 20)->getSecret();
        R::exec('UPDATE users SET totp_secret = ? WHERE id = 3', [$secret]);

        $response = $this->call($app, 'POST', '/v1/users/authenticate', $this->creds([
            'totp' => TOTP::createFromSecret($secret)->now(),
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('required', self::json($response));
        $this->assertSame('totp', R::getCell('SELECT signin_requirement FROM users WHERE id = 3'));
    }

    public function testTheLegacyFlagIsIgnored(): void {
        $app = $this->app();
        $this->flag('must_enroll_mfa');

        $response = $this->call($app, 'POST', '/v1/users/authenticate', $this->creds());

        $this->assertSame(200, $response->getStatusCode(), 'signin_requirement decides, not must_enroll_mfa');
    }

    public function testAWrongPasswordOnTheChangeRouteIsRefusedAndCounted(): void {
        $app = $this->app();
        $this->flag('must_change_password');

        $response = $this->call($app, 'POST', '/v1/users/authenticate/password', [
            'username' => 'plain', 'password' => 'wrong', 'new_password' => self::FRESH,
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(1, self::denied());
    }

    public function testTheChangeRouteIsRefusedWhenNoChangeIsPending(): void {
        $app = $this->app();

        $response = $this->call($app, 'POST', '/v1/users/authenticate/password', $this->creds(['new_password' => self::FRESH]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAWeakNewPasswordIsRefused(): void {
        $app = $this->app();
        $this->flag('must_change_password');

        $response = $this->call($app, 'POST', '/v1/users/authenticate/password', $this->creds(['new_password' => 'short']));
        $body = self::json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotEmpty($body['error']);
        $this->assertArrayHasKey('password_policy', $body);
        $this->assertSame(1, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 3'));
    }

    public function testAnUnchangedPasswordIsRefused(): void {
        $app = $this->app();
        $this->flag('must_change_password');

        $response = $this->call($app, 'POST', '/v1/users/authenticate/password', $this->creds(['new_password' => self::CURRENT]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 3'));
    }

    public function testASuccessfulChangeClearsTheFlagAndReturnsAToken(): void {
        $app = $this->app();
        $this->flag('must_change_password');

        $response = $this->call($app, 'POST', '/v1/users/authenticate/password', $this->creds(['new_password' => self::FRESH]));
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($body['token']);
        $this->assertSame(0, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 3'));
        $this->assertTrue(password_verify(self::FRESH, (string) R::getCell('SELECT password FROM users WHERE id = 3')));
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'users' AND object_id = 3"));
        $this->assertStringContainsString('password_changed', (string) R::getCell("SELECT data FROM history WHERE action = 'rotate'"));
        $this->assertStringNotContainsString(self::FRESH, (string) R::getCell('SELECT GROUP_CONCAT(data) FROM history'));
    }

    public function testTheChainRunsFromPasswordThroughMfaToAToken(): void {
        $app = $this->app();
        $this->flag('must_change_password');
        $this->requirement('totp');

        $step = $this->call($app, 'POST', '/v1/users/authenticate/password', $this->creds(['new_password' => self::FRESH]));
        $this->assertSame(403, $step->getStatusCode());
        $this->assertSame('mfa_enrollment', self::json($step)['required']);

        $creds = ['username' => 'plain', 'password' => self::FRESH];
        $start = $this->call($app, 'POST', '/v1/users/authenticate/mfa', $creds);
        $secret = self::json($start)['secret'];
        $this->assertSame(200, $start->getStatusCode());
        $this->assertStringStartsWith('otpauth://', self::json($start)['uri']);

        $bad = $this->call($app, 'PUT', '/v1/users/authenticate/mfa', $creds + ['totp' => '000000']);
        $this->assertSame(401, $bad->getStatusCode());
        $this->assertSame(1, self::denied());

        $done = $this->call($app, 'PUT', '/v1/users/authenticate/mfa', $creds + ['totp' => TOTP::createFromSecret($secret)->now()]);
        $body = self::json($done);
        $this->assertSame(200, $done->getStatusCode());
        $this->assertNotEmpty($body['token']);
        $this->assertTrue($body['has_totp']);
        $this->assertTrue($body['totp_verified']);
        $this->assertSame('pwd+otp', $body['amr']);
        $this->assertTrue($body['strong_auth']);
        $this->assertSame($secret, R::getCell('SELECT totp_secret FROM users WHERE id = 3'));
    }

    public function testMfaEnrollmentWaitsForAPendingPasswordChange(): void {
        $app = $this->app();
        $this->flag('must_change_password');
        $this->requirement('totp');

        $response = $this->call($app, 'POST', '/v1/users/authenticate/mfa', $this->creds());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMfaEnrollmentIsRefusedWhenNoneIsPending(): void {
        $app = $this->app();

        $this->assertSame(400, $this->call($app, 'POST', '/v1/users/authenticate/mfa', $this->creds())->getStatusCode());
        $this->assertSame(400, $this->call($app, 'PUT', '/v1/users/authenticate/mfa', $this->creds(['totp' => '123456']))->getStatusCode());
    }

    public function testAnExistingTokenIsRefusedOnceAFlagIsSet(): void {
        $app = $this->app();
        $token = TestAccounts::issueToken(['id' => 3, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60])['token'];
        $this->assertSame(200, $this->call($app, 'GET', '/v1/users/me', [], $token)->getStatusCode());

        $this->flag('must_change_password');
        $response = $this->call($app, 'GET', '/v1/users/me', [], $token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Password change required', (string) $response->getBody());

        R::exec('UPDATE users SET must_change_password = 0 WHERE id = 3');
        $this->requirement('totp');
        $response = $this->call($app, 'GET', '/v1/users/me', [], $token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('MFA enrollment required', (string) $response->getBody());

        $_SERVER['REMOTE_ADDR'] = self::SAFE;
        $this->assertSame(200, $this->call($app, 'GET', '/v1/users/me', [], $token)->getStatusCode());
    }

    public function testRemoteAuthIsUnaffected(): void {
        $app = $this->app(['enabled' => true, 'header' => null]);
        $this->flag('must_change_password');
        $this->requirement('totp');

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/users/me', ['REMOTE_USER' => 'plain'])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAFixedApiTokenIsUnaffected(): void {
        $app = $this->app();
        $this->flag('must_change_password');
        // SQLite has no UNIX_TIMESTAMP(), which the token lookup calls
        $pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
        @$pdo->sqliteCreateFunction('UNIX_TIMESTAMP', 'time', 0);
        R::exec('UPDATE users SET api_token = ? WHERE id = 3', [hash('sha256', 'automation-token')]);

        $response = $this->call($app, 'GET', '/v1/users/me', [], 'automation-token');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAManagerCanSetThePasswordFlagAtCreationAndOnUpdateAndTheLegacyFlagIsIgnored(): void {
        $app = $this->app();
        $manager = TestAccounts::issueToken(['id' => 2, 'role' => 'manager', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60])['token'];

        $created = $this->call($app, 'POST', '/v1/users', [
            'username' => 'fresh-user', 'email' => 'fresh@example.test', 'password' => self::FRESH,
            'must_change_password' => 1, 'must_enroll_mfa' => 1,
        ], $manager);
        $row = self::json($created)['users'][0];
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertArrayNotHasKey('must_enroll_mfa', $row);
        $this->assertSame('totp', $row['signin_requirement'], 'a new user starts with the default');
        $this->assertSame(0, (int) R::getCell("SELECT must_enroll_mfa FROM users WHERE username = 'fresh-user'"));

        $updated = $this->call($app, 'PUT', '/v1/users/3', ['must_enroll_mfa' => 1, 'must_change_password' => 1], $manager);
        $row = self::json($updated)['users'][0];
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertSame('none', $row['signin_requirement']);
        $this->assertSame(0, (int) R::getCell('SELECT must_enroll_mfa FROM users WHERE id = 3'));
    }

    public function testAManagerCannotFlagAnAdminAndAPlainUserCannotFlagAnyone(): void {
        $app = $this->app();
        $manager = TestAccounts::issueToken(['id' => 2, 'role' => 'manager', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60])['token'];
        $plain = TestAccounts::issueToken(['id' => 3, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60])['token'];

        $this->assertSame(403, $this->call($app, 'PUT', '/v1/users/1', ['must_change_password' => 1], $manager)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'PUT', '/v1/users/3', ['must_change_password' => 1], $plain)->getStatusCode());
        $this->assertSame(0, (int) R::getCell('SELECT SUM(must_change_password) FROM users'));
    }
}
