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

    private function lastError(ResponseInterface $response): string {
        return json_decode((string) $response->getBody(), true)['error'];
    }

    public function testGetShowsEveryNameserverWithItsAddresses(): void {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/domains/example-one.it')
                ->withHeader('Authorization', "Bearer {$token}")
        );

        $this->assertSame([
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.1', 'ipv6' => null],
            ['name' => 'ns2.example.net', 'ipv4' => null, 'ipv6' => null],
        ], json_decode((string) $response->getBody(), true)['domain']['ns']);
    }

    public function testChangingTheIpv4SendsRemovalOfTheOldAndAdditionOfTheNew(): void {
        $response = $this->patch(['ns' => [
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.77', 'ipv6' => '2001:db8::77'],
            ['name' => 'ns2.example.net', 'ipv4' => null, 'ipv6' => null],
        ]]);

        $this->assertSame(200, $response->getStatusCode());
        $update = $this->updateRequest();
        preg_match('#<domain:add>(.*?)</domain:add>#s', $update, $added);
        preg_match('#<domain:rem>(.*?)</domain:rem>#s', $update, $removed);
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.77</domain:hostAddr>', $added[1]);
        $this->assertStringContainsString('<domain:hostAddr ip="v6">2001:db8::77</domain:hostAddr>', $added[1]);
        $this->assertStringContainsString('<domain:hostName>ns1.example-one.it</domain:hostName>', $removed[1]);
        $this->assertStringNotContainsString('hostAddr', $removed[1], 'the removal names the host only');
        $this->assertStringNotContainsString('ns2.example.net', $update);
    }

    public function testNullingTheIpv6KeepsTheIpv4(): void {
        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        $this->sent->queue(str_replace(
            '<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr>',
            '<domain:hostAddr ip="v4">192.0.2.1</domain:hostAddr><domain:hostAddr ip="v6">2001:db8::1</domain:hostAddr>',
            CommandCatalog::DOMAIN_INFO_RESPONSE
        ));
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        Client::useTransportFactory(fn() => $this->sent);

        $response = $this->patch(['ns' => [
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.1', 'ipv6' => null],
            'ns2.example.net',
        ]]);

        $this->assertSame(200, $response->getStatusCode());
        preg_match('#<domain:add>(.*?)</domain:add>#s', $this->updateRequest(), $added);
        preg_match('#<domain:rem>(.*?)</domain:rem>#s', $this->updateRequest(), $removed);
        $this->assertStringNotContainsString('ip="v6"', $added[1]);
        $this->assertStringContainsString('<domain:hostName>ns1.example-one.it</domain:hostName>', $removed[1]);
    }

    public function testGlueOnAHostOutsideTheDomainIsRefused(): void {
        $response = $this->patch(['ns' => [
            'ns1.example-one.it', ['name' => 'ns2.example.net', 'ipv4' => '192.0.2.9'],
        ]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('glue addresses are only allowed for nameservers under example-one.it', $this->lastError($response));
        $this->assertSame('', $this->updateRequest());
    }

    public function testANewHostUnderTheDomainNeedsAnIpv4(): void {
        $response = $this->patch(['ns' => [
            'ns1.example-one.it', ['name' => 'ns3.example-one.it', 'ipv6' => '2001:db8::3'],
        ]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('nameserver ns3.example-one.it under example-one.it needs an IPv4 address', $this->lastError($response));
        $this->assertSame('', $this->updateRequest());
    }

    public function testTwoIpv4AddressesAreRefused(): void {
        $response = $this->patch(['ns' => [
            ['name' => 'ns1.example-one.it', 'ip' => ['192.0.2.1', '192.0.2.2']], 'ns2.example.net',
        ]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('only one IPv4', $this->lastError($response));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongFields')]
    public function testAnAddressInTheWrongFieldIsRefused(string $field, string $address, string $message): void {
        $response = $this->patch(['ns' => [['name' => 'ns1.example-one.it', $field => $address]]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString($message, $this->lastError($response));
    }

    public static function wrongFields(): array {
        return [
            'v6 in ipv4' => ['ipv4', '2001:db8::1', 'ns[0].ipv4 must be an IPv4 address'],
            'v4 in ipv6' => ['ipv6', '192.0.2.1', 'ns[0].ipv6 must be an IPv6 address'],
        ];
    }

    public function testAHostWithoutAddressFieldsKeepsItsGlue(): void {
        $response = $this->patch(['ns' => ['ns1.example-one.it', 'ns2.example.net', 'ns3.example.net']]);

        $this->assertSame(200, $response->getStatusCode());
        $update = $this->updateRequest();
        $this->assertStringNotContainsString('ns1.example-one.it', $update);
        $this->assertStringContainsString('ns3.example.net', $update);
    }

    public function testCreateRefusesBadGlueWithoutOpeningASession(): void {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/domains')
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody([
                    'domain' => 'example-two.it', 'registrant' => 'REGI1234REGI5678',
                    'ns' => [['name' => 'ns1.example.net', 'ipv4' => '192.0.2.1'], 'ns2.example.net'],
                ])
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('only allowed for nameservers under example-two.it', $this->lastError($response));
        $this->assertSame([], $this->sent->requests, 'the registry heard nothing');
    }

    private function queueInfo(string $info): void {
        $this->sent = new FakeTransport();
        $this->sent->queue(CommandCatalog::GREETING_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        $this->sent->queue($info);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        $this->sent->queue(CommandCatalog::OK_RESPONSE);
        Client::useTransportFactory(fn() => $this->sent);
    }

    public function testADnsHoldDomainSendsOnlyAnAddWithTheWholeConfiguration(): void {
        $this->queueInfo(CommandCatalog::domainInfoWithPendingNs());

        $response = $this->patch(['ns' => [
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.77', 'ipv6' => '2001:db8:1234:5678::1'],
            'ns2.example.net',
        ]]);

        $this->assertSame(200, $response->getStatusCode());
        $update = $this->updateRequest();
        $this->assertStringContainsString('<domain:add>', $update);
        $this->assertStringNotContainsString('<domain:rem>', $update);
        $this->assertStringContainsString('<domain:hostName>ns1.example-one.it</domain:hostName>', $update);
        $this->assertStringContainsString('<domain:hostName>ns2.example.net</domain:hostName>', $update);
        $this->assertStringContainsString('<domain:hostAddr ip="v4">192.0.2.77</domain:hostAddr>', $update);
        $this->assertStringContainsString('<domain:hostAddr ip="v6">2001:db8:1234:5678::1</domain:hostAddr>', $update);
    }

    public function testGetShowsThePendingSetApartFromTheValidatedOne(): void {
        $this->queueInfo(CommandCatalog::domainInfoWithPendingNs(true));
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/domains/example-one.it')
                ->withHeader('Authorization', "Bearer {$token}")
        );

        $domain = json_decode((string) $response->getBody(), true)['domain'];
        $this->assertSame(['ns1.example-one.it', 'ns2.example.net'], array_column($domain['ns'], 'name'));
        $this->assertSame([
            ['name' => 'ns2.example.net', 'ipv4' => null, 'ipv6' => null],
            ['name' => 'ns1.example-one.it', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8:1234:5678::1'],
        ], $domain['ns_pending']);
    }
}
