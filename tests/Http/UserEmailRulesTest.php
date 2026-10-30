<?php

namespace Eppitnic\Tests\Http;

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
 * Every account has an address of its own: POST and PUT /v1/users refuse a
 * missing, malformed, over-long or already used one.
 */
final class UserEmailRulesTest extends TestCase
{
    private const STRONG = 'Str0ng-Enough-Pw!';
    private const TAKEN = 'this e-mail address is already used by another account';

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
        TestAccounts::ensure(1, 'admin', 1, 'admin1');
        TestAccounts::ensure(2, 'user', 1, 'bob');
        R::exec("UPDATE users SET email = 'admin@example.it' WHERE id = 1");
        R::exec("UPDATE users SET email = 'bob@example.it' WHERE id = 2");

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    private function call(\Slim\App $app, string $method, string $path, array $body): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 1, 'username' => 'admin1', 'role' => 'admin', 'reseller_id' => 1, 'has_totp' => false, 'max_token_age' => 60,
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

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badEmails(): array {
        return [
            'missing' => [[], 'email is required'],
            'empty' => [['email' => ''], 'email is required'],
            'blank' => [['email' => '   '], 'email is required'],
            'null' => [['email' => null], 'email is required'],
            'not an address' => [['email' => 'nobody'], 'valid address'],
            'too long' => [['email' => str_repeat('a', 250) . '@example.it'], 'at most 256'],
            'taken' => [['email' => 'bob@example.it'], self::TAKEN],
            'taken, other case' => [['email' => 'BOB@Example.IT'], self::TAKEN],
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unusableEmails(): array {
        return array_diff_key(self::badEmails(), ['taken' => 1, 'taken, other case' => 1]);
    }

    #[DataProvider('badEmails')]
    public function testPostRefusesABadEmail(array $email, string $message): void {
        $response = $this->call($this->app(), 'POST', '/v1/users', ['username' => 'newbie', 'password' => self::STRONG] + $email);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString($message, self::json($response)['error']);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = 'newbie'"));
    }

    public function testPostAcceptsTheMaximumWidth(): void {
        $email = str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.' . str_repeat('d', 55) . '.it';

        $response = $this->call($this->app(), 'POST', '/v1/users', ['username' => 'newbie', 'password' => self::STRONG, 'email' => $email]);

        $this->assertLessThanOrEqual(256, strlen($email));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame($email, R::getCell("SELECT email FROM users WHERE username = 'newbie'"));
    }

    #[DataProvider('unusableEmails')]
    public function testPutRefusesABadEmailOnEverySave(array $email, string $message): void {
        $app = $this->app();
        if ($email === []) {
            R::exec('UPDATE users SET email = NULL WHERE id = 2');
        }

        $response = $this->call($app, 'PUT', '/v1/users/2', ['description' => 'renamed'] + $email);

        if ($email === ['email' => null]) {
            // null means "leave as it is", which is a valid address here
            $this->assertSame(200, $response->getStatusCode());
            return;
        }
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString($message, self::json($response)['error']);
    }

    public function testPutLetsAUserKeepTheirOwnAddress(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/2', ['email' => 'Bob@example.it']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPutRefusesAnotherUsersAddress(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/users/2', ['email' => 'admin@example.it']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(self::TAKEN, self::json($response)['error']);
    }
}
