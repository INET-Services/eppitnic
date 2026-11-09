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
 * POST /v1/domains/{name}/transfer is a plain transfer, or a transfer-trade
 * with a registrant, and .../restore talks to the epp.server_deleted host.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainTransferRestoreRouteTest extends EppTestCase
{
    private const DELETED = 'https://epp-deleted.pubtest.nic.it';

    /** @var array<string, FakeTransport> server URL => what that client sent */
    private array $byServer = [];

    protected function setUp(): void {
        parent::setUp();
        $this->configure(self::DELETED);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['transfers', 'contacts', 'domains', 'history', 'users', 'resellers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, name TEXT, email TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT,
                 registrant TEXT, authinfo TEXT, kind TEXT NOT NULL DEFAULT \'transfer\', techc TEXT, dns TEXT,
                 status TEXT NOT NULL DEFAULT \'pending\', time TEXT,
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO contacts (handle, reseller_id) VALUES ('MINE1234MINE5678', 2), ('THEIR234THEIR678', 3)");
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('ours.it', 2)");

        $this->byServer = [];
        Client::useTransportFactory(function (string $server): FakeTransport {
            $t = new FakeTransport();
            $t->queue(CommandCatalog::GREETING_RESPONSE);
            $t->queueAll(CommandCatalog::OK_RESPONSE, 5);
            return $this->byServer[$server] = $t;
        });
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    private function configure(string $serverDeleted): void {
        $settings = static::SETTINGS;
        $settings['epp']['server_deleted'] = $serverDeleted;
        Config::loadForTesting($settings + [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'allowed_origins' => [],
            'allowed_headers' => [],
            'allowed_methods' => [],
            'safe_networks'   => ['127.0.0.1/32'],
            'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);
    }

    private ?\Slim\App $app = null;

    private function call(string $method, string $path, array $body = []): ResponseInterface {
        // domain.php declares functions, so the routes are registered once
        if ($this->app === null) {
            $this->app = AppFactory::create();
            Middleware::register($this->app);
            $app = $this->app;
            require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
        }

        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body)
        );
    }

    private function post(string $path, array $body = []): ResponseInterface {
        return $this->call('POST', $path, $body);
    }

    private function get(string $path): ResponseInterface {
        return $this->call('GET', $path);
    }

    private function lastSession(): string {
        return implode('', $this->byServer[array_key_first($this->byServer)]->requests);
    }

    public function testTransferWithoutARegistrantIsAPlainTransferOfTheRequestersReseller(): void {
        $response = $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890']);

        $this->assertSame(201, $response->getStatusCode());
        $row = R::getRow('SELECT * FROM transfers');
        $this->assertNull($row['registrant']);
        $this->assertSame('transfer', $row['kind']);
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertStringContainsString('<transfer op="request">', $this->lastSession());
        $this->assertStringNotContainsString('newRegistrant', $this->lastSession());
    }

    public function testTransferWithoutAnAuthinfoIs400AndSendsNothing(): void {
        $response = $this->post('/v1/domains/new.it/transfer', ['registrant' => 'MINE1234MINE5678']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->byServer);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testTransferStoresTheRegistrantAsATrade(): void {
        $response = $this->post('/v1/domains/new.it/transfer', [
            'authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame('MINE1234MINE5678', $row['registrant']);
        $this->assertSame('trade', $row['kind']);
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertStringContainsString('<extdom:newRegistrant>MINE1234MINE5678</extdom:newRegistrant>', $this->lastSession());
    }

    public function testARegistrantOfAnotherResellerIs403AndSendsNothing(): void {
        $response = $this->post('/v1/domains/new.it/transfer', [
            'authinfo' => 'SECRET1234567890', 'registrant' => 'THEIR234THEIR678',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->byServer);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testAPlainRequestReplacesATradeRow(): void {
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);
        R::exec("UPDATE transfers SET status = 'cancelled'");

        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890']);

        $row = R::getRow('SELECT registrant, kind, status FROM transfers');
        $this->assertSame([null, 'transfer', 'pending'], [$row['registrant'], $row['kind'], $row['status']]);
    }

    public function testAnotherResellersDomainIs403AndSendsNothing(): void {
        R::exec("INSERT INTO domains (domain, reseller_id) VALUES ('theirs.it', 3)");

        $response = $this->post('/v1/domains/theirs.it/transfer', ['authinfo' => 'SECRET1234567890']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->byServer);
    }

    public function testTransferStoresTheAuthinfoAndAReRequestReplacesIt(): void {
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);
        $this->assertSame('SECRET1234567890', R::getCell('SELECT authinfo FROM transfers'));

        R::exec("UPDATE transfers SET status = 'cancelled'");
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'OTHERCODE1234567', 'registrant' => 'MINE1234MINE5678']);

        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame('OTHERCODE1234567', R::getCell('SELECT authinfo FROM transfers'));
    }

    public function testCancelWithoutAuthinfoSendsTheStoredOne(): void {
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);

        $response = $this->post('/v1/domains/new.it/transfer/cancel');

        $this->assertSame(200, $response->getStatusCode());
        // the registry session of the cancel: each call opens its own
        $sent = $this->byServer[array_key_first($this->byServer)]->requests;
        $this->assertStringContainsString('<domain:pw>SECRET1234567890</domain:pw>', implode('', $sent));
        $this->assertSame('cancelled', R::getCell('SELECT status FROM transfers'));
    }

    public function testCancelPrefersTheAuthinfoItIsGiven(): void {
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);

        $this->post('/v1/domains/new.it/transfer/cancel', ['authinfo' => 'GIVEN12345678901']);

        // the registry session of the cancel: each call opens its own
        $sent = $this->byServer[array_key_first($this->byServer)]->requests;
        $this->assertStringContainsString('<domain:pw>GIVEN12345678901</domain:pw>', implode('', $sent));
        $this->assertStringNotContainsString('SECRET1234567890', implode('', $sent));
    }

    public function testCancelKeepsTheRowAsCancelled(): void {
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);

        $response = $this->post('/v1/domains/new.it/transfer/cancel', ['authinfo' => 'SECRET1234567890']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cancelled', R::getCell("SELECT status FROM transfers WHERE domain = 'new.it'"));
        $this->assertSame(403, $this->post('/v1/domains/new.it/transfer/cancel', ['authinfo' => 'SECRET1234567890'])->getStatusCode(), 'nothing pending to cancel');
    }

    public function testANewRequestReplacesTheCancelledRow(): void {
        R::exec("INSERT INTO transfers (reseller_id, domain, registrant, techc, dns, status)
                 VALUES (3, 'new.it', 'OLD', 'a:0:{}', 'a:0:{}', 'cancelled')");

        $response = $this->post('/v1/domains/new.it/transfer', [
            'authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678', 'tech' => ['TECH1234TECH5678'],
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('MINE1234MINE5678', $row['registrant']);
        $this->assertSame(2, (int) $row['reseller_id']);
        $this->assertSame([], unserialize($row['techc']), 'tech contacts are ignored');
    }

    public function testTheTransfersListShowsTheStatus(): void {
        $this->post('/v1/domains/new.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);
        $this->post('/v1/domains/new.it/transfer/cancel', ['authinfo' => 'SECRET1234567890']);

        $response = $this->get('/v1/domains/transfers');

        $this->assertSame(200, $response->getStatusCode());
        $rows = json_decode((string) $response->getBody(), true)['transfers'];
        $this->assertSame(['cancelled'], array_column($rows, 'status'));
    }

    public function testTheTransfersListShowsAPlainTransferWithoutARegistrant(): void {
        $this->post('/v1/domains/plain.it/transfer', ['authinfo' => 'SECRET1234567890']);
        $this->post('/v1/domains/traded.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);
        R::exec("UPDATE contacts SET name = 'Mario Rossi', email = 'mario@example.it' WHERE handle = 'MINE1234MINE5678'");

        $rows = json_decode((string) $this->get('/v1/domains/transfers')->getBody(), true)['transfers'];

        $byDomain = array_column($rows, null, 'domain');
        $this->assertSame(['transfer', null, null], [$byDomain['plain.it']['kind'], $byDomain['plain.it']['name'], $byDomain['plain.it']['email']]);
        $this->assertSame(['trade', 'Mario Rossi', 'mario@example.it'], [$byDomain['traded.it']['kind'], $byDomain['traded.it']['name'], $byDomain['traded.it']['email']]);
    }

    public function testTheTransfersListFiltersByRegistrant(): void {
        $this->post('/v1/domains/plain.it/transfer', ['authinfo' => 'SECRET1234567890']);
        $this->post('/v1/domains/traded.it/transfer', ['authinfo' => 'SECRET1234567890', 'registrant' => 'MINE1234MINE5678']);

        $rows = json_decode((string) $this->get('/v1/domains/transfers?registrant=MINE1234MINE5678')->getBody(), true)['transfers'];

        $this->assertSame(['traded.it'], array_column($rows, 'domain'));
    }

    public function testRestoreReachesTheServerDeletedHost(): void {
        $response = $this->post('/v1/domains/ours.it/restore');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::DELETED], array_keys($this->byServer));
        $this->assertStringContainsString(
            '<domain:name>ours.it</domain:name>',
            implode('', $this->byServer[self::DELETED]->requests)
        );
    }

    public function testRestoreWithoutServerDeletedContactsNobody(): void {
        $this->configure('');
        $response = $this->post('/v1/domains/ours.it/restore');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->byServer);
    }
}
