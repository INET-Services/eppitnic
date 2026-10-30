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
 * Registry v4.0 on the contact routes: name and org are one value, fixed at
 * creation; fax is accepted and dropped.
 */
// contact.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ContactNameOrgRouteTest extends EppTestCase
{
    private FakeTransport $sent;

    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(static::SETTINGS + [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'allowed_origins' => [], 'allowed_headers' => [], 'allowed_methods' => [],
            'safe_networks'   => ['127.0.0.1/32'], 'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['contacts', 'domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER, handle TEXT UNIQUE, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 domain TEXT UNIQUE, registrant TEXT, admin TEXT, tech TEXT)');
        R::exec("INSERT INTO contacts (reseller_id, handle) VALUES (2, 'ABCD1234EFGH5678')");

        $this->sent = new FakeTransport();
        Client::useTransportFactory(fn() => $this->sent);
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    /** @param string[] $responses what the registry answers between login and logout */
    private function registry(array $responses): void {
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        foreach ($responses as $response) {
            $this->sent->queue($response);
        }
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
    }

    private function call(string $method, string $path, array $body): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/contact.php';

        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body)
        );
    }

    private function createBody(array $extra = []): array {
        return $extra + [
            'handle' => 'NEWC1234NEWC5678', 'name' => 'Mario Rossi', 'street' => 'Via Roma 1', 'city' => 'Bolzano',
            'province' => 'BZ', 'postalcode' => '39100', 'countrycode' => 'IT', 'voice' => '+39.0471000000',
            'email' => 'mario.rossi@example.it', 'nationalitycode' => 'IT', 'entitytype' => 1,
            'regcode' => 'RSSMRA80A01A952L',
        ];
    }

    private function createRequest(): string {
        $creates = array_values(array_filter($this->sent->requests, fn($r) => str_contains($r, '<contact:create')));
        return $creates[0] ?? '';
    }

    public function testDifferingNameAndOrgAreRefusedBeforeTheRegistry(): void {
        $response = $this->call('POST', '/v1/contacts', $this->createBody(['org' => 'Rossi S.r.l.']));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('name and org must be the same', json_decode((string) $response->getBody(), true)['error']);
        $this->assertSame([], $this->sent->requests, 'the registry was not asked');
    }

    public function testOrgIsCopiedFromNameAndFaxIsDropped(): void {
        $this->registry([CommandCatalog::OK_RESPONSE]);

        $response = $this->call('POST', '/v1/contacts', $this->createBody(['fax' => '+39.0471000001']));

        $this->assertSame(201, $response->getStatusCode());
        $create = $this->createRequest();
        $this->assertStringContainsString('<contact:org>Mario Rossi</contact:org>', $create);
        $this->assertStringNotContainsString('contact:fax', $create);
    }

    public function testEntityTypeZeroIsRefused(): void {
        $this->registry([]);

        $response = $this->call('POST', '/v1/contacts', $this->createBody(['entitytype' => 0]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('entitytype', json_decode((string) $response->getBody(), true)['error']);
        $this->assertSame('', $this->createRequest());
    }

    public function testChangingTheNameIsRefused(): void {
        $this->registry([CommandCatalog::CONTACT_INFO_RESPONSE]);

        $response = $this->call('PATCH', '/v1/contacts/ABCD1234EFGH5678', ['name' => 'Someone Else']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'name and org cannot be changed once the contact exists',
            json_decode((string) $response->getBody(), true)['error']
        );
        $updates = array_filter($this->sent->requests, fn($r) => str_contains($r, '<contact:update'));
        $this->assertSame([], array_values($updates));
    }

    public function testFaxInAnUpdateIsDropped(): void {
        $this->registry([CommandCatalog::CONTACT_INFO_RESPONSE, CommandCatalog::OK_RESPONSE]);

        $response = $this->call('PATCH', '/v1/contacts/ABCD1234EFGH5678', ['fax' => '+39.0471000009', 'voice' => '+39.0471999999']);

        $this->assertSame(200, $response->getStatusCode());
        $updates = array_values(array_filter($this->sent->requests, fn($r) => str_contains($r, '<contact:update')));
        $this->assertCount(1, $updates);
        $this->assertStringNotContainsString('contact:fax', $updates[0]);
        $this->assertStringNotContainsString('contact:name', $updates[0]);
    }
}
