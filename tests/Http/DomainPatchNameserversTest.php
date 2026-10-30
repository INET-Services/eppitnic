<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use Eppitnic\Tests\Support\TestAccounts;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * PATCH /v1/domains/{name} takes nameservers as names or {name, ip} objects; a
 * nameserver whose glue changed is sent again with the new addresses.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainPatchNameserversTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(static::SETTINGS + [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'allowed_origins' => [], 'allowed_headers' => [], 'allowed_methods' => [],
            'safe_networks'   => ['127.0.0.1/32'], 'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
            'pdns'            => ['enabled' => false, 'apis' => [], 'nameservers' => [], 'ttl' => 3600, 'delay_hours' => 12, 'frequency_minutes' => 15, 'last_run_at' => null],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, authinfo TEXT, ns TEXT, registrant TEXT, admin TEXT, tech TEXT,
                 cr_date TEXT, ex_date TEXT, dnssec TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('example-one.it', 2, 'REGI1234REGI5678')");

        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);          // login
        $this->sent->queue(CommandCatalog::DOMAIN_INFO_RESPONSE); // fetch
        $this->sent->queue(CommandCatalog::OK_RESPONSE);          // update
        $this->sent->queue(CommandCatalog::OK_RESPONSE);          // logout
        Client::useTransportFactory(fn() => $this->sent);
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    private function patch(array $body): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';

        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('PATCH', 'http://localhost/v1/domains/example-one.it')
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body)
        );
    }

    private function updateRequest(): string {
        $updates = array_values(array_filter($this->sent->requests, fn($r) => str_contains($r, '<update>')));
        return $updates[0] ?? '';
    }

    public function testChangedGlueIsSentAsARemovalAndAnAddition(): void {
        $response = $this->patch(['ns' => [
            ['name' => 'ns1.example-one.it', 'ip' => ['192.0.2.77']],
            'ns2.example.net',
        ]]);

        $this->assertSame(200, $response->getStatusCode());
        $update = $this->updateRequest();
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.77</domain:hostAddr>', $update);
        $this->assertStringContainsString('<domain:rem>', $update, 'the old glue is removed');
        $this->assertStringNotContainsString('ns2.example.net', $update, 'an unchanged nameserver is not touched');
    }

    public function testNamesAloneLeaveExistingGlueAsItIs(): void {
        $response = $this->patch(['ns' => ['ns1.example-one.it', 'ns2.example.net']]);

        $this->assertSame(400, $response->getStatusCode(), 'nothing changed, so the registry has nothing to update');
        $this->assertSame('', $this->updateRequest());
    }

    public function testAdminAndTechAloneAreAcceptedAndNothingIsSent(): void {
        $response = $this->patch(['admin' => 'NEWADMIN12345678', 'tech' => ['TECH-NEW']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $this->updateRequest(), 'no update goes to the registry');
    }

    public function testAdminAndTechAlongsideNameserversNeverReachTheWire(): void {
        $response = $this->patch([
            'admin' => 'NEWADMIN12345678', 'tech' => ['TECH-NEW'],
            'ns' => [['name' => 'ns1.example-one.it', 'ip' => ['192.0.2.77']], 'ns2.example.net'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $update = $this->updateRequest();
        $this->assertStringContainsString('192.0.2.77', $update);
        $this->assertStringNotContainsString('domain:contact', $update);
    }

    public function testAnInvalidAddressIsRefusedBeforeAnyUpdate(): void {
        $response = $this->patch(['ns' => [['name' => 'ns1.example-one.it', 'ip' => ['not-an-ip']], 'ns2.example.net']]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('not a valid IPv4 or IPv6', json_decode((string) $response->getBody(), true)['error']);
        $this->assertSame('', $this->updateRequest());
    }

    public function testAnEntryWithoutANameIsRefused(): void {
        $response = $this->patch(['ns' => [['ip' => ['192.0.2.1']]]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->sent->requests, 'the registry was not asked');
    }
}
