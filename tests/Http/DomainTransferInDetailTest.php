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
 * GET /v1/domains/{name} on a transfer-in: the registry is asked with the
 * authinfo the request was made with; when it cannot answer, the `transfers`
 * row stands in. Every write route refuses the name.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainTransferInDetailTest extends EppTestCase
{
    private const NOT_AUTHORIZED_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2202"><msg lang="en">Invalid authorization information</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    private const AUTHINFO = 'SECRET1234567890';

    /** @var FakeTransport[] one per registry session the route opened */
    private array $sessions = [];

    /** @var string[] what the registry session is answered with, after login */
    private array $answers = [];

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
        foreach (['transfers', 'contacts', 'domains', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, name TEXT, email TEXT)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, authinfo TEXT, ns TEXT, registrant TEXT, admin TEXT, tech TEXT,
                 cr_date TEXT, ex_date TEXT, dnssec TEXT, last_invoice TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT UNIQUE,
                 registrant TEXT, authinfo TEXT, kind TEXT NOT NULL DEFAULT 'transfer', techc TEXT, dns TEXT,
                 status TEXT NOT NULL DEFAULT 'pending', time TEXT,
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT)");
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');

        R::exec("INSERT INTO domains (domain, reseller_id, registrant, status, authinfo, ns, cr_date, ex_date)
                 VALUES ('ours.it', 2, 'REGI1234REGI5678', ?, 'LOCALCODE1234567', ?, '2020-01-01', '2027-01-01')",
            [serialize(['ok']), serialize(['ns1.ours.it' => ['ip' => []]])]);
        $this->transferIn('incoming.it', ['authinfo' => self::AUTHINFO]);

        $this->sessions = [];
        $this->answers = [];
        Client::useTransportFactory(function (): FakeTransport {
            $t = new FakeTransport();
            $t->queue(CommandCatalog::GREETING_RESPONSE);
            $t->queue(CommandCatalog::OK_RESPONSE); // login
            foreach ($this->answers as $answer) {
                $t->queue($answer);
            }
            $t->queueAll(CommandCatalog::OK_RESPONSE, 3);
            return $this->sessions[] = $t;
        });
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    private function transferIn(string $name, array $values = []): void {
        $values += [
            'reseller_id' => 2,
            'domain'      => $name,
            'registrant'  => 'MINE1234MINE5678',
            'kind'        => 'trade',
            'authinfo'    => null,
            'dns'         => serialize([['name' => "ns1.{$name}", 'ip' => ['192.0.2.1', '2001:db8::1']], 'ns2.example.net']),
            'status'      => 'pending',
            'time'        => '2026-11-09 10:15:00',
        ];
        R::exec('INSERT INTO transfers (reseller_id, domain, registrant, kind, authinfo, dns, status, time)
                 VALUES (:reseller_id, :domain, :registrant, :kind, :authinfo, :dns, :status, :time)',
            array_combine(array_map(static fn($k) => ":{$k}", array_keys($values)), $values));
    }

    private function call(string $method, string $path, array $body = [], array $claims = []): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';

        $token = TestAccounts::issueToken($claims + [
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}")
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body)
        );
    }

    private function get(string $name, array $claims = []): ResponseInterface {
        return $this->call('GET', "/v1/domains/{$name}", [], $claims);
    }

    private function json(ResponseInterface $response): array {
        return json_decode((string) $response->getBody(), true);
    }

    private function sent(): string {
        return implode('', array_merge(...array_map(static fn(FakeTransport $t) => $t->requests, $this->sessions)));
    }

    private const TRANSFER = [
        'status'       => 'pending',
        'kind'         => 'trade',
        'requested_at' => '2026-11-09 10:15:00',
        'ns'           => [
            ['name' => 'ns1.incoming.it', 'ipv4' => '192.0.2.1', 'ipv6' => '2001:db8::1'],
            ['name' => 'ns2.example.net', 'ipv4' => null, 'ipv6' => null],
        ],
    ];

    public function testTheRegistryIsAskedWithTheAuthinfoTheRequestWasMadeWith(): void {
        $this->answers = [CommandCatalog::DOMAIN_INFO_RESPONSE];

        $response = $this->get('incoming.it');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertFalse($body['stale']);
        $this->assertSame('REGI1234REGI5678', $body['domain']['registrant']);
        $this->assertSame(self::TRANSFER, $body['transfer']);
        $this->assertStringContainsString('<domain:pw>' . self::AUTHINFO . '</domain:pw>', $this->sent());
    }

    public function testAnotherRegistrarsDomainRefusedByTheRegistryShowsTheLocalRow(): void {
        $this->answers = [self::NOT_AUTHORIZED_RESPONSE];

        $response = $this->get('incoming.it');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertTrue($body['stale']);
        $this->assertSame([
            'domain'     => 'incoming.it',
            'status'     => [],
            'registrant' => 'MINE1234MINE5678',
            'admin'      => '',
            'tech'       => [],
            'ns'         => [],
            'ns_pending' => [],
            'authinfo'   => self::AUTHINFO,
            'dnssec'     => [],
            'cr_date'    => null,
            'ex_date'    => null,
        ], $body['domain']);
        $this->assertSame(self::TRANSFER, $body['transfer']);
    }

    public function testAPlainTransferShowsItsKindAndNoRegistrantWhenTheRegistryCannotAnswer(): void {
        $this->transferIn('plain.it', ['registrant' => null, 'kind' => 'transfer', 'authinfo' => self::AUTHINFO]);
        $this->answers = [self::NOT_AUTHORIZED_RESPONSE];

        $response = $this->get('plain.it');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertTrue($body['stale']);
        $this->assertNull($body['domain']['registrant']);
        $this->assertSame('transfer', $body['transfer']['kind']);
        $this->assertSame('pending', $body['transfer']['status']);
    }

    public function testAnUnreachableRegistryShowsTheLocalRow(): void {
        Client::useTransportFactory(fn() => new FakeTransport());

        $response = $this->get('incoming.it');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertTrue($body['stale']);
        $this->assertSame('incoming.it', $body['domain']['domain']);
        $this->assertSame('pending', $body['transfer']['status']);
    }

    public function testARowWithoutAuthinfoNeverAsksTheRegistry(): void {
        $this->transferIn('legacy.it');

        $response = $this->get('legacy.it');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertTrue($body['stale']);
        $this->assertNull($body['domain']['authinfo']);
        $this->assertSame('pending', $body['transfer']['status']);
        $this->assertSame([], $this->sessions, 'no registry session was opened');
    }

    public function testACancelledRequestIsStillShown(): void {
        $this->transferIn('given-up.it', ['authinfo' => self::AUTHINFO, 'status' => 'cancelled', 'dns' => serialize([])]);
        $this->answers = [self::NOT_AUTHORIZED_RESPONSE];

        $response = $this->get('given-up.it');

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(['cancelled', []], [$body['transfer']['status'], $body['transfer']['ns']]);
    }

    public function testAnotherResellersRequestIs404AndNeverReachesTheRegistry(): void {
        $this->transferIn('theirs.it', ['reseller_id' => 3, 'authinfo' => self::AUTHINFO]);

        $response = $this->get('theirs.it');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->sessions);
        $this->assertStringNotContainsString(self::AUTHINFO, (string) $response->getBody());
    }

    public function testAnAdminReachesAnyResellersRequest(): void {
        $this->transferIn('theirs.it', ['reseller_id' => 3, 'authinfo' => self::AUTHINFO]);
        $this->answers = [self::NOT_AUTHORIZED_RESPONSE];

        $response = $this->get('theirs.it', ['id' => 1, 'role' => 'admin', 'reseller_id' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pending', $this->json($response)['transfer']['status']);
    }

    public function testADomainOfTheCallerHasNoTransfer(): void {
        $this->answers = [CommandCatalog::DOMAIN_INFO_RESPONSE];

        $body = $this->json($this->get('ours.it'));

        $this->assertFalse($body['stale']);
        $this->assertArrayHasKey('transfer', $body);
        $this->assertNull($body['transfer']);
        $this->assertStringNotContainsString('<domain:authInfo>', $this->sent(), 'asked without an authinfo');
    }

    public function testTheLocalRowOfADomainHasNoTransferEither(): void {
        Client::useTransportFactory(fn() => new FakeTransport());

        $body = $this->json($this->get('ours.it'));

        $this->assertTrue($body['stale']);
        $this->assertNull($body['transfer']);
    }

    public function testATransferWhoseDomainIsStoredAsActiveIsTheDomain(): void {
        R::exec("INSERT INTO domains (domain, reseller_id, registrant, status, authinfo, ns, cr_date, ex_date)
                 VALUES ('arrived.it', 2, 'MINE1234MINE5678', ?, 'LOCALCODE1234567', ?, '2020-01-01', '2027-01-01')",
            [serialize(['ok']), serialize([])]);
        $this->transferIn('arrived.it', ['authinfo' => self::AUTHINFO]);
        $this->answers = [CommandCatalog::DOMAIN_INFO_RESPONSE];

        $body = $this->json($this->get('arrived.it'));

        $this->assertNull($body['transfer']);
        $this->assertStringNotContainsString('<domain:authInfo>', $this->sent());
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    public static function writes(): array {
        return [
            'patch'     => ['PATCH', '/v1/domains/incoming.it', ['ns' => ['ns1.example.net', 'ns2.example.net']]],
            'registrant' => ['POST', '/v1/domains/incoming.it/registrant', ['registrant' => 'MINE1234MINE5678']],
            'status'    => ['POST', '/v1/domains/incoming.it/status', ['state' => 'clientHold', 'action' => 'add']],
            'delete'    => ['DELETE', '/v1/domains/incoming.it', []],
            'restore'   => ['POST', '/v1/domains/incoming.it/restore', []],
            'approve'   => ['POST', '/v1/domains/incoming.it/transfer/approve', []],
            'reject'    => ['POST', '/v1/domains/incoming.it/transfer/reject', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function testAWriteOnATransferInIsRefusedBeforeTheRegistryHearsOfIt(string $method, string $path, array $body): void {
        $response = $this->call($method, $path, $body);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->sessions, 'no registry session was opened');
    }
}
