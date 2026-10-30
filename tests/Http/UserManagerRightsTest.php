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
 * A manager's rights over POST/PUT/DELETE /v1/users: only within their own
 * reseller, never an admin target or role, never `debug`, plus the guard
 * rails everyone (including an admin) is held to -- no touching one's own
 * role or `active`, and never demoting or deactivating the last active admin
 * or the last active manager of an active reseller.
 */
final class UserManagerRightsTest extends TestCase
{
    private const STRONG = 'Str0ng-Enough-Pw!';

    /** user id => [role, reseller, username] */
    private const ACCOUNTS = [
        1 => ['admin', 1, 'admin1'],
        2 => ['admin', 1, 'admin2'],
        3 => ['manager', 2, 'managerA'],
        4 => ['manager', 2, 'managerA2'],
        5 => ['user', 2, 'userA'],
        6 => ['manager', 3, 'managerB'],
        7 => ['user', 3, 'userB'],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => ['127.0.0.1/32'],
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
        TestAccounts::ensureReseller(3, 'Three');
        foreach (self::ACCOUNTS as $id => [$role, $reseller, $username]) {
            TestAccounts::ensure($id, $role, $reseller, $username);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    /**
     * @param int $as the user making the request (see ACCOUNTS)
     */
    private function call(\Slim\App $app, string $method, string $path, array $body = [], int $as = 3): ResponseInterface {
        [$role, $reseller] = self::ACCOUNTS[$as];
        $token = TestAccounts::issueToken([
            'id' => $as, 'username' => self::ACCOUNTS[$as][2], 'role' => $role, 'reseller_id' => $reseller,
            'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withParsedBody($body)
        );
    }

    private static function json(ResponseInterface $r): array {
        return json_decode((string) $r->getBody(), true) ?? [];
    }

    private static function error(ResponseInterface $r): string {
        return (string) (self::json($r)['error'] ?? '');
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // POST -- creating a user
    // ---------------------------------------------------------------

    public function testAManagerCreatesAUserInTheirOwnReseller(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG,
        ], 3);

        $this->assertSame(201, $response->getStatusCode());
        $row = self::json($response)['users'][0];
        $this->assertSame('user', $row['role']);
        $this->assertSame(2, (int) $row['reseller_id']);
    }

    public function testAManagerMayExplicitlyNameTheirOwnReseller(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG, 'reseller_id' => 2,
        ], 3);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testAManagerCannotCreateAUserInAnotherReseller(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG, 'reseller_id' => 3,
        ], 3);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAManagerMayCreateAnotherManager(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newmanager', 'email' => 'newmanager@example.test', 'password' => self::STRONG, 'role' => 'manager',
        ], 3);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('manager', self::json($response)['users'][0]['role']);
    }

    public function testAManagerCannotCreateAnAdmin(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG, 'role' => 'admin',
        ], 3);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAManagerCannotSetDebug(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG, 'debug' => 1,
        ], 3);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAPlainUserCannotCreateAUser(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG,
        ], 5);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnAdminIsStillUnrestricted(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'email' => 'newbie@example.test', 'password' => self::STRONG, 'role' => 'admin', 'debug' => 1,
        ], 1);

        $this->assertSame(201, $response->getStatusCode());
        $row = self::json($response)['users'][0];
        $this->assertSame('admin', $row['role']);
        $this->assertSame(1, (int) $row['debug']);
    }

    // ---------------------------------------------------------------
    // PUT -- editing a user
    // ---------------------------------------------------------------

    public function testAManagerEditsAUserOfTheirOwnReseller(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/5', ['description' => 'changed'], 3);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('changed', self::json($response)['users'][0]['description']);
    }

    public function testAManagerCannotEditAUserOfAnotherReseller(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/7', ['description' => 'changed'], 3);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAManagerCannotEditAnAdmin(): void {
        $app = $this->app();
        // isolate the role check from the reseller check above
        R::exec('UPDATE users SET reseller_id = 2 WHERE id = 1');

        $response = $this->call($app, 'PUT', '/v1/users/1', ['description' => 'changed'], 3);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAManagerCannotPromoteAUserToAdmin(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/5', ['role' => 'admin'], 3);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('user', R::getCell('SELECT role FROM users WHERE id = 5'));
    }

    public function testAManagerMayPromoteAUserToManager(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/5', ['role' => 'manager'], 3);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('manager', self::json($response)['users'][0]['role']);
    }

    public function testAManagerCannotSetDebugOnPut(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/5', ['debug' => 1], 3);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnAdminMayEditAnyone(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/7', ['description' => 'changed'], 1);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // self: role and active
    // ---------------------------------------------------------------

    public function testNobodyCanChangeTheirOwnRole(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/3', ['role' => 'user'], 3);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('you cannot change your own role', self::error($response));
    }

    public function testAnAdminCannotChangeTheirOwnRoleEither(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/1', ['role' => 'manager'], 1);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNobodyCanDeactivateThemselvesViaPut(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/3', ['active' => 0], 3);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('you cannot deactivate yourself', self::error($response));
    }

    public function testNobodyCanDeactivateThemselvesViaDelete(): void {
        $response = $this->call($this->app(), 'DELETE', '/v1/users/3', [], 3);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('you cannot deactivate yourself', self::error($response));
    }

    public function testASameValueForOwnRoleAndActiveIsAllowed(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/3', ['role' => 'manager', 'active' => 1, 'description' => 'x'], 3);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // last active admin / last active manager
    // ---------------------------------------------------------------

    /**
     * The last-active-admin guard runs before the self-guard, so this is the
     * message the sole remaining admin gets for demoting themselves.
     */
    public function testTheLastActiveAdminCannotBeDemoted(): void {
        $app = $this->app();
        R::exec("UPDATE users SET active = 0 WHERE id = 2"); // only admin 1 remains active

        $response = $this->call($app, 'PUT', '/v1/users/1', ['role' => 'manager'], 1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('last active admin', self::error($response));
    }

    public function testTheLastActiveAdminCannotBeDeactivated(): void {
        $app = $this->app();
        R::exec("UPDATE users SET active = 0 WHERE id = 2");

        $response = $this->call($app, 'DELETE', '/v1/users/1', [], 1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('last active admin', self::error($response));
    }

    public function testAnAdminMayBeDemotedWhenAnotherRemains(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/2', ['role' => 'manager', 'reseller_id' => 1], 1);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheLastActiveManagerOfAResellerCannotBeDeactivated(): void {
        $app = $this->app();
        R::exec("UPDATE users SET active = 0 WHERE id = 4"); // managerA is now the only active manager of reseller 2

        $response = $this->call($app, 'DELETE', '/v1/users/3', [], 1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('last active manager', self::error($response));
    }

    public function testTheLastActiveManagerOfAResellerCannotBeDemoted(): void {
        $app = $this->app();
        R::exec("UPDATE users SET active = 0 WHERE id = 4");

        $response = $this->call($app, 'PUT', '/v1/users/3', ['role' => 'user'], 1);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('last active manager', self::error($response));
    }

    public function testAManagerMayBeDeactivatedWhenAnotherRemainsInTheSameReseller(): void {
        $response = $this->call($this->app(), 'DELETE', '/v1/users/4', [], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) self::json($response)['users'][0]['active']);
    }

    public function testTheLastActiveManagerMayBePromotedToAdmin(): void {
        $app = $this->app();
        TestAccounts::ensure(8, 'manager', 1, 'managerMain'); // reseller 1's only manager

        $response = $this->call($app, 'PUT', '/v1/users/8', ['role' => 'admin'], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('admin', self::json($response)['users'][0]['role']);
    }

    public function testTheLastActiveManagerOfADeactivatedResellerMayBeDeactivated(): void {
        $app = $this->app();
        R::exec('UPDATE resellers SET active = 0 WHERE id = 3'); // managerB is reseller 3's only manager

        $response = $this->call($app, 'DELETE', '/v1/users/6', [], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) self::json($response)['users'][0]['active']);
    }

    public function testTheLastActiveManagerOfADeactivatedResellerMayBeDemoted(): void {
        $app = $this->app();
        R::exec('UPDATE resellers SET active = 0 WHERE id = 3');

        $this->assertSame(200, $this->call($app, 'PUT', '/v1/users/6', ['role' => 'user'], 1)->getStatusCode());
    }

}
