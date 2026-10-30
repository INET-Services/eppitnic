<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Auth;
use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * a user's own `token_lifetime` (15 minutes by default, PATCH
 * /v1/users/{id}/session) held to the `max_token_age` an admin or manager set
 * (240 when unset); both are picked up on renewal.
 */
final class UserSessionTest extends TestCase
{
    /** user id => [role, reseller] */
    private const ACCOUNTS = [
        1 => ['admin', 1],
        2 => ['manager', 2],
        4 => ['user', 2],
        5 => ['user', 2],
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
        foreach (self::ACCOUNTS as $id => [$role, $reseller]) {
            TestAccounts::ensure($id, $role, $reseller);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/user_settings.php';
        return $app;
    }

    private function token(int $as, ?int $maxTokenAge = 60): string {
        [$role, $reseller] = self::ACCOUNTS[$as];
        return TestAccounts::issueToken([
            'id' => $as, 'username' => 'someone', 'role' => $role, 'reseller_id' => $reseller,
            'has_totp' => false, 'max_token_age' => $maxTokenAge,
        ])['token'];
    }

    private function call(\Slim\App $app, string $method, string $path, array $body = [], int $as = 4, ?string $token = null): ResponseInterface {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token($as)))
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

    /** @return array<string, array{0: ?int, 1: ?int, 2: int}> */
    public static function effective(): array {
        return [
            'both unset'           => [null, null, 15],
            'user above max'       => [60, 30, 30],
            'user below max'       => [10, 60, 10],
            'user without a limit' => [240, null, 240],
            'only a limit'         => [null, 5, 5],
        ];
    }

    #[DataProvider('effective')]
    public function testTheTokenGetsTheSmallerOfChoiceAndLimit(?int $own, ?int $max, int $minutes): void {
        $this->app();
        $signed = Auth::issueToken(['id' => 4, 'token_lifetime' => $own, 'max_token_age' => $max]);

        $this->assertEqualsWithDelta(time() + $minutes * 60, $signed['exp'], 5);
    }

    /** @return array<string, array{0: int}> */
    public static function lifetimes(): array {
        return array_combine(array_map('strval', Auth::TOKEN_LIFETIMES), array_map(fn($m) => [$m], Auth::TOKEN_LIFETIMES));
    }

    #[DataProvider('lifetimes')]
    public function testAUserPicksTheirOwnLifetimeFromTheList(int $minutes): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/4/session', ['token_lifetime' => $minutes]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($minutes, self::json($response)['session']['token_lifetime']);
        $this->assertSame($minutes, (int) R::getCell('SELECT token_lifetime FROM users WHERE id = 4'));
        $this->assertSame(4, (int) R::getCell("SELECT user_id FROM history WHERE object = 'users' AND object_id = 4"));
    }

    public function testNullOrEmptyGoesBackToTheDefault(): void {
        $app = $this->app();
        R::exec('UPDATE users SET token_lifetime = 60 WHERE id = 4');

        $this->assertSame(200, $this->call($app, 'PATCH', '/v1/users/4/session', ['token_lifetime' => ''])->getStatusCode());
        $this->assertNull(R::getCell('SELECT token_lifetime FROM users WHERE id = 4'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function refused(): array {
        return ['seven' => [7], '480' => [480], 'text' => ['x'], 'zero' => [0], 'float' => [15.5]];
    }

    #[DataProvider('refused')]
    public function testValuesOutsideTheListAreRefused(mixed $value): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/4/session', ['token_lifetime' => $value]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(R::getCell('SELECT token_lifetime FROM users WHERE id = 4'));
    }

    public function testAChoiceAboveTheLimitIsRefused(): void {
        $app = $this->app();
        R::exec('UPDATE users SET max_token_age = 30 WHERE id = 4');

        $this->assertSame(400, $this->call($app, 'PATCH', '/v1/users/4/session', ['token_lifetime' => 60])->getStatusCode());
        $this->assertSame(200, $this->call($app, 'PATCH', '/v1/users/4/session', ['token_lifetime' => 30])->getStatusCode());
    }

    public function testAUserCannotRaiseTheirOwnLimit(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/4', ['max_token_age' => 240], 4);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAPlainUserCannotChangeSomeoneElse(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/5/session', ['token_lifetime' => 30], 4);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(R::getCell('SELECT token_lifetime FROM users WHERE id = 5'));
    }

    public function testAManagerMayChangeTheirUsersLifetime(): void {
        $response = $this->call($this->app(), 'PATCH', '/v1/users/5/session', ['token_lifetime' => 30], 2);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminPutLimitsFromTheListAndClears(): void {
        $app = $this->app();
        R::exec('UPDATE users SET max_token_age = 60, token_lifetime = 90 WHERE id = 5');

        $this->assertSame(400, $this->call($app, 'PUT', '/v1/users/5', ['max_token_age' => 7], 1)->getStatusCode());
        $this->assertSame(60, (int) R::getCell('SELECT max_token_age FROM users WHERE id = 5'));
        $this->assertSame(200, $this->call($app, 'PUT', '/v1/users/5', ['max_token_age' => 30], 1)->getStatusCode());
        $this->assertSame(90, (int) R::getCell('SELECT token_lifetime FROM users WHERE id = 5'), 'the choice is kept');
        $this->assertSame(200, $this->call($app, 'PUT', '/v1/users/5', ['max_token_age' => null], 1)->getStatusCode());
        $this->assertNull(R::getCell('SELECT max_token_age FROM users WHERE id = 5'));
    }

    public function testRenewalPicksUpBothValues(): void {
        $app = $this->app();
        $old = $this->token(4, 240);
        $this->call($app, 'PATCH', '/v1/users/4/session', ['token_lifetime' => 30], 4, $old);

        $renewed = self::json($this->call($app, 'GET', '/v1/users/renew-token', [], 4, $old));
        $this->assertSame(30, (int) $renewed['token_lifetime']);
        $this->assertEqualsWithDelta(time() + 30 * 60, $renewed['exp'], 5);

        R::exec('UPDATE users SET max_token_age = 10 WHERE id = 4');
        $renewed = self::json($this->call($app, 'GET', '/v1/users/renew-token', [], 4, $old));
        $this->assertSame(10, (int) $renewed['max_token_age']);
        $this->assertEqualsWithDelta(time() + 10 * 60, $renewed['exp'], 5);
    }
}
