<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Epp\Client;
use Eppitnic\Tests\Support\CommandCatalog;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\FakeTransport;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * POST /v1/domains is decided by its input, the registry's <check> only
 * guards it: no authinfo registers (a registrant is required), an authinfo
 * requests a transfer, and with a registrant too a transfer-trade.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainCreateRouteTest extends EppTestCase
{
    private const AUTHINFO = 'SECRET1234567890';

    /** @var FakeTransport[] one per registry session the route opened */
    private array $sessions = [];

    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(static::SETTINGS + [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'allowed_origins' => [], 'allowed_headers' => [], 'allowed_methods' => [],
            'safe_networks'   => ['127.0.0.1/32'], 'trusted_proxies' => [],
            'login_ratelimit' => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
            'pdns'            => ['enabled' => false, 'apis' => [], 'nameservers' => []],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['transfers', 'domains', 'contacts', 'history', 'resellers', 'tasks', 'settings'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER DEFAULT 1, reseller_id INTEGER,
                 user_id INTEGER, registrant TEXT, admin TEXT, tech TEXT, ns TEXT, authinfo TEXT, dnssec TEXT,
                 status TEXT, cr_date TEXT, ex_date TEXT, last_invoice TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, name TEXT, org TEXT, email TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT UNIQUE,
                 registrant TEXT, authinfo TEXT, kind TEXT NOT NULL DEFAULT 'transfer', techc TEXT, dns TEXT,
                 status TEXT NOT NULL DEFAULT 'pending', time TEXT,
                 attempts INTEGER NOT NULL DEFAULT 0, attempted_at TEXT)");
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, object TEXT, action TEXT, active INTEGER)');
        R::exec("INSERT INTO contacts (handle, reseller_id, status) VALUES ('MINE1234MINE5678', 2, ?), ('THEIR234THEIR678', 3, ?)",
            [serialize(['ok']), serialize(['ok'])]);
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('held.it', 3, 'THEIR234THEIR678')");

        $this->sessions = [];
        Client::useTransportFactory(function (): FakeTransport {
            $t = new FakeTransport();
            $t->queue(CommandCatalog::GREETING_RESPONSE);
            for ($i = 0; $i < 8; $i++) {
                $t->queueCallback(fn(string $xml) => match (true) {
                    str_contains($xml, '<domain:check') => self::checkAnswer($xml),
                    str_contains($xml, '<contact:info') => CommandCatalog::CONTACT_INFO_RESPONSE,
                    default => CommandCatalog::OK_RESPONSE,
                });
            }
            return $this->sessions[] = $t;
        });
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    /** `taken.it` and `held.it` are registered, any other name is free */
    private static function checkAnswer(string $request): string {
        preg_match('#<domain:name>([^<]+)</domain:name>#', $request, $m);
        $taken = in_array($m[1], ['taken.it', 'held.it'], true);
        $reason = $taken ? '<domain:reason lang="en">Domain already registered</domain:reason>' : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . '<result code="1000"><msg lang="en">Command completed successfully</msg></result>'
            . '<resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:cd>'
            . '<domain:name avail="' . ($taken ? 'false' : 'true') . "\">{$m[1]}</domain:name>{$reason}"
            . '</domain:cd></domain:chkData></resData>'
            . '<trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>'
            . '</response></epp>';
    }

    private ?\Slim\App $app = null;

    private function post(array $body): ResponseInterface {
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
            (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/v1/domains')
                ->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body)
        );
    }

    private function json(ResponseInterface $response): array {
        return json_decode((string) $response->getBody(), true);
    }

    private function sent(): string {
        return implode('', array_merge(...array_map(static fn(FakeTransport $t) => $t->requests, $this->sessions)));
    }

    private function requestedKinds(): array {
        return array_map(
            static fn(string $data): string => json_decode($data, true)['kind'],
            R::getCol("SELECT data FROM history WHERE object = 'domains' AND action = 'request'")
        );
    }

    public function testNoAuthinfoRegistersTheDomain(): void {
        $response = $this->post(['domain' => 'free.it', 'registrant' => 'MINE1234MINE5678', 'ns' => ['ns1.example.it', 'ns2.example.it']]);

        $this->assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('MINE1234MINE5678', $body['domain']['registrant']);
        $this->assertSame(16, strlen($body['domain']['authinfo']), 'a generated code');
        $this->assertStringContainsString('<domain:create', $this->sent());
        $this->assertStringNotContainsString('<transfer', $this->sent());
        $this->assertSame(['register'], $this->requestedKinds());
        $this->assertSame(2, (int) R::getCell("SELECT reseller_id FROM domains WHERE domain = 'free.it'"));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    /** stored and asked in Unicode, whatever the spelling; hosts in punycode */
    #[DataProvider('idnSpellings')]
    public function testAnIdnNameIsRegisteredInUnicodeWhateverTheSpelling(string $given): void {
        $response = $this->post([
            'domain' => $given, 'registrant' => 'MINE1234MINE5678',
            'ns' => [['name' => 'ns1.sesamstraße.it', 'ipv4' => '192.0.2.1'], 'ns2.example.it'],
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('sesamstraße.it', $this->json($response)['domain']['domain']);
        $this->assertSame(['sesamstraße.it'], R::getCol('SELECT domain FROM domains WHERE reseller_id = 2'));
        $this->assertStringContainsString('<domain:name>sesamstraße.it</domain:name>', $this->sent());
        $this->assertStringContainsString('ns1.xn--sesamstrae-e4a.it', $this->sent());
        $this->assertStringNotContainsString('ns1.sesamstraße.it', $this->sent());
        $this->assertSame(['register'], $this->requestedKinds());
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'domains' AND action = 'request' AND object_id > 0"));
    }

    public static function idnSpellings(): array {
        return [
            'unicode'    => ['sesamstraße.it'],
            'upper case' => ['SESAMSTRAßE.IT'],
            'punycode'   => ['xn--sesamstrae-e4a.it'],
            'dot, space' => [' Sesamstraße.it. '],
        ];
    }

    #[DataProvider('notItDomains')]
    public function testANameThatIsNotAnItDomainIsRefusedAsGiven(string $given): void {
        $response = $this->post(['domain' => $given, 'registrant' => 'MINE1234MINE5678']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("'{$given}' is not a valid .it domain name", $this->json($response)['error']);
        $this->assertSame([], $this->sessions);
    }

    public static function notItDomains(): array {
        return [
            'Cyrillic'     => ['пример.it'],
            'Greek'        => ['Σάσα.it'],
            'three labels' => ['www.sesamstraße.it'],
            'another TLD'  => ['sesamstraße.com'],
        ];
    }

    public function testAnIdnNameHeldByAnotherResellerIsNotClaimedInAnotherSpelling(): void {
        R::exec("INSERT INTO domains (domain, reseller_id, registrant) VALUES ('sesamstraße.it', 3, 'THEIR234THEIR678')");

        $response = $this->post(['domain' => 'XN--SESAMSTRAE-E4A.IT', 'authinfo' => self::AUTHINFO]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->sessions);
    }

    public function testNoAuthinfoNeedsARegistrant(): void {
        $response = $this->post(['domain' => 'free.it']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('registrant', $this->json($response)['error']);
        $this->assertSame([], $this->sessions, 'no registry session was opened');
    }

    public function testNoAuthinfoForATakenNameIsRefused(): void {
        $response = $this->post(['domain' => 'taken.it', 'registrant' => 'MINE1234MINE5678']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("'taken.it' is already registered; give its AuthInfo code to transfer it", $this->json($response)['error']);
        $this->assertStringNotContainsString('<domain:create', $this->sent());
        $this->assertStringNotContainsString('<transfer', $this->sent());
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
        $this->assertSame([], $this->requestedKinds());
    }

    public function testAnAuthinfoForAFreeNameIsRefused(): void {
        $response = $this->post(['domain' => 'free.it', 'authinfo' => self::AUTHINFO, 'registrant' => 'MINE1234MINE5678']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("'free.it' is not registered; leave the AuthInfo code empty to register it", $this->json($response)['error']);
        $this->assertStringNotContainsString('<domain:create', $this->sent());
        $this->assertStringNotContainsString('<transfer', $this->sent());
        $this->assertSame([], $this->requestedKinds());
    }

    public function testAnAuthinfoWithoutARegistrantRequestsAPlainTransfer(): void {
        $response = $this->post(['domain' => 'taken.it', 'authinfo' => self::AUTHINFO]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringContainsString('<transfer op="request">', $this->sent());
        $this->assertStringNotContainsString('newRegistrant', $this->sent());
        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame(['taken.it', null, 'transfer', 2], [$row['domain'], $row['registrant'], $row['kind'], (int) $row['reseller_id']]);
        $this->assertSame(self::AUTHINFO, $row['authinfo']);
        $this->assertSame(['transfer'], $this->requestedKinds());
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM domains WHERE domain = 'taken.it'"));
    }

    public function testAnAuthinfoWithARegistrantRequestsATransferTrade(): void {
        $response = $this->post(['domain' => 'taken.it', 'authinfo' => self::AUTHINFO, 'registrant' => 'MINE1234MINE5678']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringContainsString('<extdom:newRegistrant>MINE1234MINE5678</extdom:newRegistrant>', $this->sent());
        $row = R::getRow('SELECT * FROM transfers');
        $this->assertSame(['taken.it', 'MINE1234MINE5678', 'trade', 2], [$row['domain'], $row['registrant'], $row['kind'], (int) $row['reseller_id']]);
        $this->assertSame(['transfer'], $this->requestedKinds(), 'a trade counts as a transfer');
    }

    public function testARegistrantOfAnotherResellerIs403AndSendsNothing(): void {
        foreach (['', self::AUTHINFO] as $authinfo) {
            $response = $this->post(['domain' => 'taken.it', 'authinfo' => $authinfo, 'registrant' => 'THEIR234THEIR678']);

            $this->assertSame(403, $response->getStatusCode());
        }
        $this->assertSame([], $this->sessions);
    }

    public function testATransferOfADomainAnotherResellerHoldsIs403AndSendsNothing(): void {
        $response = $this->post(['domain' => 'held.it', 'authinfo' => self::AUTHINFO]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->sessions);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM transfers'));
    }

    public function testAnInvalidNameserverIsRefusedBeforeTheRegistryIsAsked(): void {
        $response = $this->post(['domain' => 'taken.it', 'authinfo' => self::AUTHINFO, 'ns' => [['name' => 'ns1.other.it', 'ipv4' => '192.0.2.1']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->sessions);
    }
}
