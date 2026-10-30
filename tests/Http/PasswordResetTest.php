<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Service\Notifier;
use Eppitnic\Service\PasswordReset;
use Eppitnic\Tests\Support\FakeMailer;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The forgot-password flow: one answer whatever the address, a mailed link
 * that works once for ten minutes, and the limits around both.
 */
final class PasswordResetTest extends TestCase
{
    private const ORIGIN = 'https://epp.example.it';
    private const STRONG = 'Str0ng-Enough-Pw!';
    private const REQUEST = '/v1/users/password-reset';
    private const CONFIRM = '/v1/users/password-reset/confirm';

    private int $now = 1_800_000_000;
    private string $errorLog = '';

    private function app(array $overrides = []): \Slim\App {
        Config::loadForTesting($overrides + [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => [],
            'trusted_proxies' => [],
            'allowed_origins' => [self::ORIGIN, 'http://localhost:5173'],
            'allowed_headers' => ['Content-Type'],
            'allowed_methods' => ['GET', 'POST'],
            'remote_auth'     => ['enabled' => false, 'header' => null],
            'login_ratelimit' => ['max_failures' => 3, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
            'smtp'            => [
                'enabled' => true, 'host' => 'localhost', 'port' => null, 'sender' => 'eppitnic@example.it',
                'recipient_mode' => 'none', 'recipient' => '', 'username' => '', 'password' => '',
                'auth_type' => 'plain', 'message_types' => [], 'fulltext' => '',
            ],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history', 'password_resets', 'password_reset_attempts'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL,
                 token_hash TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT)');
        R::exec('CREATE TABLE password_reset_attempts (id INTEGER PRIMARY KEY, kind TEXT NOT NULL,
                 network TEXT, email_hash TEXT, created_at TEXT NOT NULL)');
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensure(1, 'admin', 1, 'admin1');
        TestAccounts::ensure(2, 'user', 2, 'alice');
        R::exec("UPDATE users SET email = 'alice@example.it', password = ? WHERE id = 2", ['not-the-new-one']);
        R::exec("UPDATE users SET email = 'admin@example.it' WHERE id = 1");

        // refusals are logged by design; keep them out of the test output
        $this->errorLog = (string) ini_get('error_log');
        ini_set('error_log', '/dev/null');

        FakeMailer::reset();
        Notifier::useMailerFactory(fn() => new FakeMailer(true));
        PasswordReset::useClock(fn() => $this->now);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/network_check.php';
        return $app;
    }

    protected function tearDown(): void {
        PasswordReset::useClock(null);
        Notifier::useMailerFactory(null);
        unset($_SERVER['REMOTE_ADDR']);
        Config::reset();
        ini_set('error_log', $this->errorLog);
        parent::tearDown();
    }

    private function post(\Slim\App $app, string $path, array $body, array $headers = ['Origin' => self::ORIGIN]): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest('POST', "http://localhost{$path}")
            ->withParsedBody($body);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $app->handle($request);
    }

    private function get(\Slim\App $app, string $path): ResponseInterface {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', "http://localhost{$path}"));
    }

    private static function json(ResponseInterface $r): array {
        return json_decode((string) $r->getBody(), true) ?? [];
    }

    /** the token in the last mail's link */
    private static function mailedToken(): string {
        preg_match('~/reset-password\?token=([0-9a-f]{64})~', FakeMailer::$sent[array_key_last(FakeMailer::$sent)]['body'], $m);
        return $m[1] ?? '';
    }

    // ---------------------------------------------------------------
    // requesting
    // ---------------------------------------------------------------

    public function testAnExistingAddressGetsALinkToTheRequestsOrigin(): void {
        $response = $this->post($this->app(), self::REQUEST, ['email' => 'alice@example.it']);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(['ok' => true], self::json($response));
        $this->assertCount(1, FakeMailer::$sent);
        $this->assertSame('alice@example.it', FakeMailer::$sent[0]['to']);
        $this->assertStringContainsString(self::ORIGIN . '/reset-password?token=' . self::mailedToken(), FakeMailer::$sent[0]['body']);
        $this->assertSame(64, strlen(self::mailedToken()));
        $this->assertStringContainsString('10 minutes', FakeMailer::$sent[0]['body']);
    }

    public function testOnlyAHashOfTheTokenIsStored(): void {
        $this->post($this->app(), self::REQUEST, ['email' => 'alice@example.it']);

        $this->assertSame(hash('sha256', self::mailedToken()), R::getCell('SELECT token_hash FROM password_resets'));
        $this->assertSame(date('Y-m-d H:i:s', $this->now + 600), R::getCell('SELECT expires_at FROM password_resets'));
    }

    /** @return array<string, array{callable(\Slim\App): void, string}> */
    public static function indistinguishable(): array {
        return [
            'unknown address' => [static fn() => null, 'nobody@example.it'],
            'inactive user' => [static fn() => R::exec('UPDATE users SET active = 0 WHERE id = 2'), 'alice@example.it'],
            'inactive reseller' => [static fn() => R::exec('UPDATE resellers SET active = 0 WHERE id = 2'), 'alice@example.it'],
            'malformed address' => [static fn() => null, 'not an address'],
            'mail failure' => [static function (): void { FakeMailer::$shouldFail = true; }, 'alice@example.it'],
            'rate limited' => [static function (): void {
                R::exec("INSERT INTO password_reset_attempts (kind, email_hash, created_at) VALUES ('request', ?, ?)", [hash('sha256', 'alice@example.it'), date('Y-m-d H:i:s', 1_800_000_000 - 10)]);
                R::exec("INSERT INTO password_reset_attempts (kind, email_hash, created_at) VALUES ('request', ?, ?)", [hash('sha256', 'alice@example.it'), date('Y-m-d H:i:s', 1_800_000_000 - 10)]);
                R::exec("INSERT INTO password_reset_attempts (kind, email_hash, created_at) VALUES ('request', ?, ?)", [hash('sha256', 'alice@example.it'), date('Y-m-d H:i:s', 1_800_000_000 - 10)]);
            }, 'alice@example.it'],
        ];
    }

    #[DataProvider('indistinguishable')]
    public function testEveryOutcomeAnswersTheSame(callable $arrange, string $email): void {
        $app = $this->app();
        $reference = $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        FakeMailer::reset();
        R::exec('DELETE FROM password_resets');
        R::exec('DELETE FROM password_reset_attempts');

        $arrange();
        $response = $this->post($app, self::REQUEST, ['email' => $email]);

        $this->assertSame($reference->getStatusCode(), $response->getStatusCode());
        $this->assertSame((string) $reference->getBody(), (string) $response->getBody());
        $this->assertSame($reference->getHeaders(), $response->getHeaders());
        $this->assertSame([], FakeMailer::$sent);
    }

    public function testNothingIsSentWhenMailIsOffOrLoginsAreRemote(): void {
        foreach ([['smtp' => ['enabled' => false, 'host' => 'localhost', 'sender' => 'a@example.it']],
                  ['remote_auth' => ['enabled' => true, 'header' => null]]] as $override) {
            $response = $this->post($this->app($override), self::REQUEST, ['email' => 'alice@example.it']);

            $this->assertSame([202, ['ok' => true]], [$response->getStatusCode(), self::json($response)]);
            $this->assertSame([], FakeMailer::$sent);
        }
    }

    public function testTheAddressMatchesRegardlessOfCase(): void {
        $this->post($this->app(), self::REQUEST, ['email' => ' Alice@Example.IT ']);

        $this->assertCount(1, FakeMailer::$sent);
    }

    #[DataProvider('languages')]
    public function testTheMailIsInTheRequestedLanguage(string $locale, string $subject): void {
        $this->post($this->app(), self::REQUEST, ['email' => 'alice@example.it', 'locale' => $locale]);

        $this->assertStringContainsString($subject, FakeMailer::$sent[0]['subject']);
    }

    /** @return array<string, array{string, string}> */
    public static function languages(): array {
        return [
            'english' => ['en', 'Reset your password'],
            'italian' => ['it', 'Reimposta'],
            'german' => ['de', 'zurücksetzen'],
            'unknown falls back' => ['fr', 'Reset your password'],
        ];
    }

    // ---------------------------------------------------------------
    // where the link points
    // ---------------------------------------------------------------

    /** @return array<string, array{array<string, string>, array<string, mixed>, string|null}> */
    public static function origins(): array {
        return [
            'origin header' => [['Origin' => 'https://epp.example.it'], [], 'https://epp.example.it'],
            'referer when no origin' => [['Referer' => 'http://localhost:5173/login?x=1'], [], 'http://localhost:5173'],
            'body origin when neither header' => [[], ['origin' => 'http://localhost:5173'], 'http://localhost:5173'],
            'unlisted referer is ignored' => [['Referer' => 'https://evil.test/x'], ['origin' => 'http://localhost:5173'], 'http://localhost:5173'],
            'unlisted body origin' => [[], ['origin' => 'https://evil.test'], null],
            'no origin at all' => [[], [], null],
            'host is never trusted' => [['Host' => 'evil.test'], [], null],
        ];
    }

    #[DataProvider('origins')]
    public function testTheLinkOriginMustBeAllowed(array $headers, array $body, ?string $expected): void {
        $response = $this->post($this->app(), self::REQUEST, ['email' => 'alice@example.it'] + $body, $headers);

        $this->assertSame(202, $response->getStatusCode());
        if ($expected === null) {
            $this->assertSame([], FakeMailer::$sent);
            return;
        }
        $this->assertStringContainsString("{$expected}/reset-password?token=", FakeMailer::$sent[0]['body']);
    }

    // ---------------------------------------------------------------
    // using the link
    // ---------------------------------------------------------------

    public function testConfirmSetsThePasswordAndRecordsItWithoutThePassword(): void {
        $app = $this->app();
        R::exec('UPDATE users SET must_change_password = 1 WHERE id = 2');
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);

        $response = $this->post($app, self::CONFIRM, ['token' => self::mailedToken(), 'password' => self::STRONG]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(password_verify(self::STRONG, R::getCell('SELECT password FROM users WHERE id = 2')));
        $this->assertSame(0, (int) R::getCell('SELECT must_change_password FROM users WHERE id = 2'));

        $row = R::getRow("SELECT user_id, action, data FROM history WHERE object = 'users' AND object_id = 2");
        $this->assertSame(2, (int) $row['user_id']);
        $this->assertSame('update', $row['action']);
        $this->assertSame(['password' => 'PASSWORD_CHANGED', 'reset' => 'email'], json_decode($row['data'], true));
        $this->assertStringNotContainsString(self::STRONG, (string) json_encode(R::getAll('SELECT * FROM history')));
    }

    public function testALinkWorksOnce(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $token = self::mailedToken();

        $first = $this->post($app, self::CONFIRM, ['token' => $token, 'password' => self::STRONG]);
        $second = $this->post($app, self::CONFIRM, ['token' => $token, 'password' => 'An0ther-Strong-Pw!']);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(400, $second->getStatusCode());
        $this->assertSame(['error' => PasswordReset::INVALID], self::json($second));
        $this->assertTrue(password_verify(self::STRONG, R::getCell('SELECT password FROM users WHERE id = 2')));
    }

    public function testALinkExpiresAfterTenMinutes(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $token = self::mailedToken();

        $this->now += 599;
        $this->assertSame(200, $this->get($app, "/v1/users/password-reset/{$token}")->getStatusCode());

        $this->now += 2;
        $this->assertSame(400, $this->get($app, "/v1/users/password-reset/{$token}")->getStatusCode());
        $response = $this->post($app, self::CONFIRM, ['token' => $token, 'password' => self::STRONG]);
        $this->assertSame([400, ['error' => PasswordReset::INVALID]], [$response->getStatusCode(), self::json($response)]);
    }

    public function testANewRequestRetiresTheOlderLink(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $old = self::mailedToken();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);

        $this->assertNotSame($old, self::mailedToken());
        $this->assertSame(400, $this->post($app, self::CONFIRM, ['token' => $old, 'password' => self::STRONG])->getStatusCode());
        $this->assertSame(200, $this->post($app, self::CONFIRM, ['token' => self::mailedToken(), 'password' => self::STRONG])->getStatusCode());
    }

    public function testASuccessfulResetDropsTheUsersOtherTokens(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $this->post($app, self::CONFIRM, ['token' => self::mailedToken(), 'password' => self::STRONG]);

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM password_resets WHERE user_id = 2'));
        $this->assertNotNull(R::getCell('SELECT used_at FROM password_resets WHERE user_id = 2'));
    }

    public function testAWeakPasswordIsRefusedAndTheLinkSurvives(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);

        $response = $this->post($app, self::CONFIRM, ['token' => self::mailedToken(), 'password' => 'short']);

        $body = self::json($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertNotSame(PasswordReset::INVALID, $body['error']);
        $this->assertNotEmpty($body['password_policy']);
        $this->assertSame('not-the-new-one', R::getCell('SELECT password FROM users WHERE id = 2'));
        $this->assertSame(200, $this->post($app, self::CONFIRM, ['token' => self::mailedToken(), 'password' => self::STRONG])->getStatusCode());
    }

    #[DataProvider('badTokens')]
    public function testAnyTokenProblemIsOneNeutralError(string $token): void {
        $app = $this->app();

        foreach ([$this->post($app, self::CONFIRM, ['token' => $token, 'password' => self::STRONG]),
                  $this->get($app, '/v1/users/password-reset/' . ($token === '' ? 'x' : $token))] as $response) {
            $this->assertSame([400, ['error' => PasswordReset::INVALID]], [$response->getStatusCode(), self::json($response)]);
        }
    }

    /** @return array<string, array{string}> */
    public static function badTokens(): array {
        return ['empty' => [''], 'garbage' => ['nope'], 'well-formed but unknown' => [str_repeat('a', 64)]];
    }

    public function testADeactivatedUsersLinkStopsWorking(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        R::exec('UPDATE users SET active = 0 WHERE id = 2');

        $this->assertSame(400, $this->post($app, self::CONFIRM, ['token' => self::mailedToken(), 'password' => self::STRONG])->getStatusCode());
    }

    public function testExpiredTokensAreDeletedOnTheNextRequest(): void {
        $app = $this->app();
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $this->now += 3600;

        $this->post($app, self::REQUEST, ['email' => 'nobody@example.it']);

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM password_resets'));
    }

    // ---------------------------------------------------------------
    // limits
    // ---------------------------------------------------------------

    public function testAnAddressGetsThreeMailsPerWindow(): void {
        $app = $this->app();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(202, $this->post($app, self::REQUEST, ['email' => 'alice@example.it'])->getStatusCode());
        }
        $this->assertCount(3, FakeMailer::$sent);

        $this->now += 901;
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $this->assertCount(4, FakeMailer::$sent);
    }

    public function testANetworkIsLimitedAcrossAddresses(): void {
        $app = $this->app();

        for ($i = 0; $i < 12; $i++) {
            $this->assertSame(202, $this->post($app, self::REQUEST, ['email' => "nobody{$i}@example.it"])->getStatusCode());
        }
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);

        $this->assertSame([], FakeMailer::$sent);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->post($app, self::REQUEST, ['email' => 'alice@example.it']);
        $this->assertCount(1, FakeMailer::$sent);
    }

    public function testConfirmingIsLimitedPerNetwork(): void {
        $app = $this->app();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(400, $this->post($app, self::CONFIRM, ['token' => 'guess' . $i, 'password' => self::STRONG])->getStatusCode());
        }
        $blocked = $this->post($app, self::CONFIRM, ['token' => 'guess', 'password' => self::STRONG]);

        $this->assertSame(429, $blocked->getStatusCode());
    }

    // ---------------------------------------------------------------
    // offering it
    // ---------------------------------------------------------------

    #[DataProvider('availability')]
    public function testNetworkCheckSaysWhetherAResetCanWork(array $override, bool $expected): void {
        $response = $this->get($this->app($override), '/v1/network-check');

        $this->assertSame($expected, self::json($response)['password_reset']);
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function availability(): array {
        $smtp = ['enabled' => true, 'host' => 'localhost', 'sender' => 'a@example.it'];
        return [
            'mail on' => [[], true],
            'mail off' => [['smtp' => ['enabled' => false] + $smtp], false],
            'no host' => [['smtp' => ['host' => ''] + $smtp], false],
            'no sender' => [['smtp' => ['sender' => ''] + $smtp], false],
            'remote auth' => [['remote_auth' => ['enabled' => true, 'header' => null]], false],
        ];
    }
}
