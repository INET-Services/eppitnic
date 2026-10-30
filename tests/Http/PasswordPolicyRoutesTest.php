<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Persistence\User;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The password rule holds wherever a password is set, and nowhere else.
 * *Setting* is not *checking*: applying it to authenticate would lock out every
 * account whose password predates the rule.
 */
final class PasswordPolicyRoutesTest extends TestCase
{
    private const WEAK   = 'secret';
    private const STRONG = 'Correct-Horse-42!';

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'          => 'test-signing-key-for-this-suite-only',
            'allowed_origins'  => [],
            'allowed_headers'  => [],
            'allowed_methods'  => [],
            'safe_networks'    => ['127.0.0.1/32'],
            'trusted_proxies'  => [],
            'login_ratelimit'  => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('DROP TABLE IF EXISTS resellers');
        R::exec("CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER DEFAULT 1, role TEXT DEFAULT 'user',
                 description TEXT, username TEXT, password TEXT, email TEXT, notify_enabled INTEGER DEFAULT 0,
                 active INTEGER DEFAULT 1, totp_secret TEXT,
                 must_change_password INTEGER DEFAULT 0, must_enroll_mfa INTEGER DEFAULT 0, max_token_age INTEGER DEFAULT 60, token_lifetime INTEGER, max_idle_time INTEGER DEFAULT 30, last_activity TEXT,
                 debug INTEGER DEFAULT 0)");
        TestAccounts::ensureReseller(1);
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT,
                 acknowledged_time TEXT DEFAULT NULL, acknowledged_user_id INTEGER DEFAULT NULL)');

        // an existing account whose password predates the policy
        R::exec("INSERT INTO users (id, username, password, role, active) VALUES (1, 'legacy', ?, 'admin', 1)",
            [password_hash(self::WEAK, PASSWORD_BCRYPT, ['cost' => 4])]);

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    private function call(\Slim\App $app, string $method, string $path, array $body): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 1, 'username' => 'legacy', 'admin' => 1, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    private static function error(ResponseInterface $r): string {
        return (string) (json_decode((string) $r->getBody(), true)['error'] ?? '');
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // setting a password
    // ---------------------------------------------------------------

    public function testChangingToAWeakPasswordIsRefused(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/changepassword/1', ['password' => self::WEAK]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('password needs', self::error($response));
    }

    public function testChangingToAStrongPasswordIsAccepted(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/changepassword/1', ['password' => self::STRONG]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(password_verify(
            self::STRONG,
            (string) R::getCell('SELECT password FROM users WHERE id = 1')
        ));
    }

    public function testCreatingAUserWithAWeakPasswordIsRefused(): void {
        $app = $this->app();

        $response = $this->call($app, 'POST', '/v1/users', [
            'username' => 'newcomer', 'email' => 'nc@example.test', 'password' => self::WEAK,
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('password needs', self::error($response));
        $this->assertNull(R::getCell("SELECT id FROM users WHERE username = 'newcomer'"));
    }

    /**
     * The password column is only touched when one was supplied, so an update
     * that changes something else must not be judged on a password it never
     * sent.
     */
    public function testUpdatingAUserWithoutAPasswordIsUnaffected(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/users/1', ['username' => 'legacy', 'description' => 'renamed']);

        $this->assertNotSame(400, $response->getStatusCode(), self::error($response));
    }

    public function testUpdatingAUserToAWeakPasswordIsRefused(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/users/1', [
            'username' => 'legacy', 'password' => self::WEAK,
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('password needs', self::error($response));
    }

    // ---------------------------------------------------------------
    // checking one
    // ---------------------------------------------------------------

    /**
     * The regression this file exists for: an account created before the rule
     * still has to log in. Judging the password at the door locks everybody out
     * at once, with a message describing their own password back to them.
     */
    public function testAnExistingWeakPasswordCanStillLogIn(): void {
        $app = $this->app();

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/users/authenticate')
                ->withParsedBody(['username' => 'legacy', 'password' => self::WEAK])
        );

        $this->assertSame(200, $response->getStatusCode(), self::error($response));
        $this->assertArrayHasKey('token', (array) json_decode((string) $response->getBody(), true));
    }

    /**
     * And a wrong password is still refused the same way it always was -- not
     * with a complaint about its shape, which would say whether the account
     * exists and what its rules are.
     */
    public function testAWrongPasswordStillGetsTheGenericRefusal(): void {
        $app = $this->app();

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/users/authenticate')
                ->withParsedBody(['username' => 'legacy', 'password' => 'x'])
        );

        $this->assertSame('Wrong username or password', self::error($response));
    }

    // ---------------------------------------------------------------
    // the last line of defence
    // ---------------------------------------------------------------

    /**
     * Enforced in User::create() as well, so the CLI and the installer cannot
     * route around it -- this is the last point before a password becomes a
     * hash nobody can inspect afterwards.
     */
    public function testUserCreateRefusesAWeakPasswordToo(): void {
        $this->app();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/password needs/');
        User::create('viacli', self::WEAK);
    }

    public function testUserCreateAcceptsAStrongPassword(): void {
        $this->app();

        $this->assertGreaterThan(0, User::create('viacli', self::STRONG));
    }
}
