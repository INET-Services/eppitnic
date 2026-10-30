<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Persistence\User;
use Eppitnic\Tests\Support\TestAccounts;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * POST/PUT /v1/users validate their fields and create through User::create();
 * a manager sets up TOTP and API tokens for their reseller's users; a login
 * for an unknown name costs a password check too.
 */
final class UserWriteRulesTest extends TestCase
{
    private const STRONG = 'Str0ng-Enough-Pw!';

    /** user id => [role, reseller, username] */
    private const ACCOUNTS = [
        1 => ['admin', 1, 'admin1'],
        3 => ['manager', 2, 'managerA'],
        5 => ['user', 2, 'userA'],
        6 => ['user', 2, 'userA2'],
        7 => ['user', 3, 'userB'],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => ['127.0.0.1/32'],
            'trusted_proxies' => [],
            'remote_auth'     => ['enabled' => false, 'header' => null],
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

    private function call(\Slim\App $app, string $method, string $path, array $body = [], int $as = 3): ResponseInterface {
        [$role, $reseller, $username] = self::ACCOUNTS[$as];
        $token = TestAccounts::issueToken([
            'id' => $as, 'username' => $username, 'role' => $role, 'reseller_id' => $reseller,
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

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // creating and validating users
    // ---------------------------------------------------------------

    public function testCreatingRecordsTheActorAndTheOptionalFields(): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', [
            'username' => 'newbie', 'password' => self::STRONG, 'active' => 0, 'max_token_age' => 30,
            'max_idle_time' => '10', 'notify_enabled' => 1, 'email' => 'new@example.it',
        ], 3);

        $this->assertSame(201, $response->getStatusCode());
        $id = (int) self::json($response)['users'][0]['id'];
        $row = R::getRow('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame([0, 30, 10, 1], [(int) $row['active'], (int) $row['max_token_age'], (int) $row['max_idle_time'], (int) $row['notify_enabled']]);

        $history = R::getRow("SELECT user_id, object_id FROM history WHERE object = 'users' AND action = 'create'");
        $this->assertSame([3, $id], [(int) $history['user_id'], (int) $history['object_id']]);
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'users' AND action = 'create'"));
    }

    public function testCreateWithoutAnActorRecordsNobody(): void {
        $this->app();

        $id = User::create('installer-admin', self::STRONG, resellerId: 1, role: 'admin');

        $this->assertNull(R::getCell("SELECT user_id FROM history WHERE object = 'users' AND object_id = ?", [$id]));
    }

    /** @return array<string, array{0: array}> */
    public static function invalidFields(): array {
        return [
            'bad email'          => [['email' => 'not-an-address']],
            'empty username'     => [['username' => '  ']],
            'long username'      => [['username' => str_repeat('u', 33)]],
            'negative token age' => [['max_token_age' => -1]],
            'token age off list' => [['max_token_age' => 7]],
            'text idle time'     => [['max_idle_time' => 'soon']],
            'fractional idle'    => [['max_idle_time' => 1.5]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testPostRefusesAnInvalidField(array $bad): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', $bad + ['username' => 'newbie', 'password' => self::STRONG]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = 'newbie'"));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testPutRefusesAnInvalidField(array $bad): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/5', $bad);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testValidValuesAndNullsAreAccepted(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/users/5', [
            'email' => 'valid@example.test', 'max_token_age' => null, 'max_idle_time' => 0, 'username' => str_repeat('u', 32),
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // TOTP and API tokens, for the actor's own reseller
    // ---------------------------------------------------------------

    public function testAManagerSetsUpTotpForTheirResellersUser(): void {
        $app = $this->app();

        $start = $this->call($app, 'POST', '/v1/users/5/totp', [], 3);
        $this->assertSame(200, $start->getStatusCode());
        $secret = self::json($start)['secret'];

        $done = $this->call($app, 'PUT', '/v1/users/5/totp', ['totp' => TOTP::createFromSecret($secret)->now()], 3);
        $this->assertSame(200, $done->getStatusCode());
        $this->assertSame($secret, R::getCell('SELECT totp_secret FROM users WHERE id = 5'));
        $this->assertSame(3, (int) R::getCell("SELECT user_id FROM history WHERE object = 'users' AND object_id = 5 ORDER BY id DESC LIMIT 1"));
    }

    public function testTotpIsRefusedForAnotherResellersUserAndAColleague(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'POST', '/v1/users/7/totp', [], 3)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'PUT', '/v1/users/7/totp', ['totp' => '123456'], 3)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'POST', '/v1/users/6/totp', [], 5)->getStatusCode(), 'a plain user acts for nobody else');
        $this->assertSame(200, $this->call($app, 'POST', '/v1/users/1/totp', [], 1)->getStatusCode(), 'an admin for themselves');
    }

    public function testDeletingTotpClearsBothSecretsAndRecordsIt(): void {
        $app = $this->app();
        R::exec("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP', totp_secret_pending = 'PENDINGSECRET234', must_enroll_mfa = 0 WHERE id = 5");

        $response = $this->call($app, 'DELETE', '/v1/users/5/totp', [], 3);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('userA', self::json($response)['users'][0]['username']);
        $row = R::getRow('SELECT totp_secret, totp_secret_pending, must_enroll_mfa FROM users WHERE id = 5');
        $this->assertSame([null, null, 0], [$row['totp_secret'], $row['totp_secret_pending'], (int) $row['must_enroll_mfa']]);
        $history = R::getRow("SELECT user_id, data FROM history WHERE object = 'users' AND object_id = 5");
        $this->assertSame(3, (int) $history['user_id']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $history['data']);
        $this->assertSame(403, $this->call($app, 'DELETE', '/v1/users/7/totp', [], 3)->getStatusCode());
    }

    public function testStartingTotpAgainNeedsTheOldSecretRemovedFirst(): void {
        $app = $this->app();
        R::exec("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP' WHERE id = 5");

        $response = $this->call($app, 'POST', '/v1/users/5/totp', [], 5);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('remove it first', self::json($response)['error']);
        $this->assertNull(R::getCell('SELECT totp_secret_pending FROM users WHERE id = 5'));
    }

    public function testAManagerIssuesAndRevokesAnApiTokenForTheirResellersUser(): void {
        $app = $this->app();

        $issued = $this->call($app, 'POST', '/v1/users/5/api-token', [], 3);
        $this->assertSame(200, $issued->getStatusCode());
        $this->assertNotNull(R::getCell('SELECT api_token FROM users WHERE id = 5'));

        $this->assertSame(200, $this->call($app, 'DELETE', '/v1/users/5/api-token', [], 3)->getStatusCode());
        $this->assertNull(R::getCell('SELECT api_token FROM users WHERE id = 5'));
    }

    public function testApiTokensAreRefusedAcrossResellersAndBetweenPlainUsers(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'POST', '/v1/users/7/api-token', [], 3)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'DELETE', '/v1/users/7/api-token', [], 3)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'POST', '/v1/users/6/api-token', [], 5)->getStatusCode());
        $this->assertSame(200, $this->call($app, 'POST', '/v1/users/5/api-token', [], 5)->getStatusCode());
    }

    // ---------------------------------------------------------------
    // login timing
    // ---------------------------------------------------------------

    public function testAnUnknownUsernameStillCostsAPasswordCheck(): void {
        $app = $this->app();
        $login = static fn(string $name) => $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/users/authenticate')
                ->withParsedBody(['username' => $name, 'password' => 'whatever-it-is'])
        );

        $start = hrtime(true);
        $response = $login('nobody-by-this-name');
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertSame(401, $response->getStatusCode());
        $this->assertGreaterThan(0.02, $seconds, 'answered without hashing anything');
    }
}
