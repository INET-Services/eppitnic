<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A user's role and reseller on the way in and out: an admin only in
 * reseller 1, a reseller fixed at creation, and a login refused while the
 * reseller is deactivated.
 */
final class UserRolesTest extends TestCase
{
    private const STRONG = 'Str0ng-Enough-Pw!';

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => [],
            'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
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
        R::exec('UPDATE users SET password = ? WHERE id = 2', [password_hash(self::STRONG, PASSWORD_BCRYPT, ['cost' => 4])]);

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    private function asAdmin(\Slim\App $app, string $method, string $path, array $body = []): ResponseInterface {
        $token = TestAccounts::issueToken(['id' => 1, 'role' => 'admin', 'has_totp' => false, 'max_token_age' => 60])['token'];
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    private static function json(ResponseInterface $r): array {
        return (array) json_decode((string) $r->getBody(), true);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testAUserIsCreatedWithARoleInAReseller(): void {
        $response = $this->asAdmin($this->app(), 'POST', '/v1/users', [
            'username' => 'new-manager', 'email' => 'nm@example.test', 'password' => self::STRONG, 'role' => 'manager', 'reseller_id' => 2,
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $row = self::json($response)['users'][0];
        $this->assertSame('manager', $row['role']);
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertSame(1, (int) $row['notify_enabled'], 'managers start with notifications on');
    }

    public function testANewPlainUserStartsWithNotificationsOff(): void {
        $response = $this->asAdmin($this->app(), 'POST', '/v1/users', [
            'username' => 'new-user', 'email' => 'nu@example.test', 'password' => self::STRONG, 'reseller_id' => 2,
        ]);

        $this->assertSame('user', self::json($response)['users'][0]['role']);
        $this->assertSame(0, (int) self::json($response)['users'][0]['notify_enabled']);
    }

    public function testAnAdminCanOnlyBelongToResellerOne(): void {
        $response = $this->asAdmin($this->app(), 'POST', '/v1/users', [
            'username' => 'bad-admin', 'email' => 'ba@example.test', 'password' => self::STRONG, 'role' => 'admin', 'reseller_id' => 2,
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('reseller 1', self::json($response)['error']);
    }

    public function testAnUnknownRoleOrResellerIsRefused(): void {
        $app = $this->app();

        $this->assertSame(400, $this->asAdmin($app, 'POST', '/v1/users', [
            'username' => 'x', 'password' => self::STRONG, 'role' => 'superuser',
        ])->getStatusCode());
        $this->assertSame(400, $this->asAdmin($app, 'POST', '/v1/users', [
            'username' => 'y', 'password' => self::STRONG, 'reseller_id' => 99,
        ])->getStatusCode());
    }

    public function testAUsersResellerCannotChange(): void {
        $response = $this->asAdmin($this->app(), 'PUT', '/v1/users/2', ['reseller_id' => 1]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(2, (int) R::getCell('SELECT reseller_id FROM users WHERE id = 2'));
    }

    public function testPromotingAnotherResellersUserToAdminIsRefused(): void {
        $response = $this->asAdmin($this->app(), 'PUT', '/v1/users/2', ['role' => 'admin']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('manager', R::getCell('SELECT role FROM users WHERE id = 2'));
    }

    public function testLoggingInToADeactivatedResellerIsRefusedWithTheReason(): void {
        $app = $this->app();
        R::exec('UPDATE resellers SET active = 0 WHERE id = 2');

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/users/authenticate')
                ->withParsedBody(['username' => 'manager', 'password' => self::STRONG])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Your reseller account is deactivated', self::json($response)['error']);
    }

    public function testALoginCarriesTheRoleAndReseller(): void {
        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/users/authenticate')
                ->withParsedBody(['username' => 'manager', 'password' => self::STRONG])
        );

        $this->assertSame(200, $response->getStatusCode());
        $claims = self::json($response);
        $this->assertSame('manager', $claims['role']);
        $this->assertSame(2, $claims['reseller_id']);
        $this->assertSame('Two', $claims['reseller_name']);
        $this->assertArrayNotHasKey('admin', $claims);
    }
}
