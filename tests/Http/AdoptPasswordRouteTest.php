<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Service\RegistryPasswordChange;
use Eppitnic\Service\SessionState;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\FakeTransport;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `POST /v1/session/adopt-password` -- the API side of `config epp-password
 * --force`: verified by a login, stored only when the registry accepts it.
 */
final class AdoptPasswordRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk' => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'safe_networks' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'region'  => ['timezone' => 'Europe/Rome', 'lc_monetary' => 'it_IT'],
        'certificatefile'   => null,
        'debugfile'         => '',
        'dnssec'            => ['active' => 1],
        'keepalive'         => false,
        'session_serialize' => false,
        'session_cookies'   => [],
        'session_timestamp' => 0,
        'smtp'    => ['enabled' => false, 'host' => 'localhost', 'port' => null, 'sender' => '',
            'recipient_mode' => 'system', 'recipient' => '', 'username' => '', 'password' => '',
            'auth_type' => 'plain', 'message_types' => [], 'fulltext' => ''],
        'epp'     => [
            'server'             => 'https://epp.pubtest.nic.it',
            'server_deleted'     => '',
            'username'           => 'TEST-REG',
            'password'           => 'old-password',
            'pendingPassword'    => 'half-done',
            'port'               => null,
            'interface'          => '',
            'lang'               => 'en',
            'cl_trid_prefix'     => 'TEST',
            'lastPasswordUpdate' => 0,
        ],
    ];

    private bool $registryAccepts = true;

    private ?string $refusal = null;

    private int $logins = 0;

    private function app(): \Slim\App {
        Config::loadForTesting(self::SETTINGS);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['settings', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $transport = new FakeTransport();
            $client->setTransport($transport);
            $transport->queue(CommandCatalog::GREETING_RESPONSE);
            $transport->queueCallback(function (): string {
                $this->logins++;
                return $this->registryAccepts ? CommandCatalog::OK_RESPONSE : ($this->refusal ?? self::loginRefused());
            });
            $transport->queue(CommandCatalog::OK_RESPONSE);
            return $client;
        });

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        return $app;
    }

    protected function tearDown(): void {
        RegistryPasswordChange::useClientFactory(null);
        Config::reset();
        parent::tearDown();
    }

    private function post(\Slim\App $app, array $body, int $admin = 1): ResponseInterface {
        $token = TestAccounts::issueToken([
            'id' => 7, 'username' => 'someone', 'admin' => $admin, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/session/adopt-password')
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withParsedBody($body)
        );
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testAdoptsAnAcceptedPasswordAndRecordsHistoryWithoutIt(): void {
        $response = $this->post($this->app(), ['password' => 'already-live']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(self::body($response)['adopted']);
        $this->assertSame('already-live', Config::get('epp')['password']);
        $this->assertArrayNotHasKey('pendingPassword', Config::get('epp'));
        $this->assertGreaterThan(0, Config::get('epp')['lastPasswordUpdate']);

        $rows = R::getAll("SELECT * FROM history WHERE object = 'security' AND action = 'rotate'");
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('adopted', $rows[0]['data']);
        $this->assertStringNotContainsString('already-live', $rows[0]['data']);
        $this->assertStringNotContainsString('already-live', (string) $response->getBody());
    }

    public function testRejectedCredentialIs400AndStoresNothing(): void {
        $this->registryAccepts = false;
        $this->refusal = CommandCatalog::loginRefusal('2200', 'Authentication error', '6005', 'Invalid username or password');
        $response = $this->post($this->app(), ['password' => 'not-real']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(
            'the registry rejected this password (2200 / 6005: Invalid username or password) -- nothing was changed locally',
            self::body($response)['error']
        );
        $this->assertSame('old-password', Config::get('epp')['password']);
        $this->assertSame('half-done', Config::get('epp')['pendingPassword']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testLoginRefusedForAnotherReasonIs502AndStoresNothing(): void {
        $this->registryAccepts = false;
        $this->refusal = CommandCatalog::loginRefusal('2400', 'Command failed', '5052', 'User IP address is not allowed');
        $response = $this->post($this->app(), ['password' => 'maybe-fine']);

        $this->assertSame(502, $response->getStatusCode());
        $error = self::body($response)['error'];
        $this->assertStringContainsString('a reason other than the password', $error);
        $this->assertStringContainsString('(2400 / 5052: User IP address is not allowed)', $error);
        $this->assertStringContainsString('nothing was changed locally', $error);
        $this->assertSame('old-password', Config::get('epp')['password']);
        $this->assertSame('half-done', Config::get('epp')['pendingPassword']);
    }

    public function testTransportFailureIs502(): void {
        $app = $this->app();
        RegistryPasswordChange::useClientFactory(function (): Client {
            $client = new Client();
            $client->setTransport(new FakeTransport());
            return $client;
        });
        $response = $this->post($app, ['password' => 'maybe-fine']);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testSuccessfulAdoptClearsTheSharedSession(): void {
        $app = $this->app();
        Config::set('session_cookies', ['JSESSIONID' => 'old']);
        Config::set('session_timestamp', time());

        $response = $this->post($app, ['password' => 'already-live']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], SessionState::cookies());
        $this->assertSame(0, SessionState::timestamp());
    }

    public function testPasswordIsRequired(): void {
        $response = $this->post($this->app(), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->logins);
    }

    public function testInvalidPasswordIsRefusedBeforeTheRegistryIsAsked(): void {
        $response = $this->post($this->app(), ['password' => str_repeat('a', 17)]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->logins);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    public function testNonAdminIsRefused(): void {
        $response = $this->post($this->app(), ['password' => 'already-live'], 0);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->logins);
        $this->assertSame('old-password', Config::get('epp')['password']);
    }

    private static function loginRefused(): string {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
              <response>
                <result code="2200"><msg>Authentication error</msg></result>
                <trID><svTRID>x</svTRID></trID>
              </response>
            </epp>
            XML;
    }
}
