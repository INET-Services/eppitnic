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
 * A domain name is stored once, in Unicode and lower case, and every route
 * takes it in any spelling: "xn--...", upper case, decomposed.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainIdnRouteTest extends EppTestCase
{
    private \Slim\App $app;

    protected function setUp(): void {
        parent::setUp();
        Config::loadForTesting(static::SETTINGS + ['jwt_psk' => 'test-signing-key-for-this-suite-only']);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['domains', 'contacts', 'transfers', 'tasks', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, registrant TEXT,
                 active INTEGER DEFAULT 1, authinfo TEXT, cr_date TEXT, ex_date TEXT)');
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, handle TEXT, org TEXT, name TEXT, email TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, registrant TEXT,
                 authinfo TEXT, kind TEXT NOT NULL DEFAULT 'transfer', dns TEXT, time TEXT,
                 status TEXT NOT NULL DEFAULT 'pending')");
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, email TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("INSERT INTO domains (domain, reseller_id, registrant, ex_date) VALUES
                 ('sesamstraße.it', 2, 'KNOWN', '2027-01-01'), ('città.it', 3, 'KNOWN', '2027-01-01')");
        R::exec("INSERT INTO transfers (domain, reseller_id, registrant, time, dns) VALUES ('café.it', 2, 'KNOWN', '2026-11-09 10:00:00', 'a:0:{}')");

        Client::useTransportFactory(function (): FakeTransport {
            $t = new FakeTransport();
            $t->queue(CommandCatalog::GREETING_RESPONSE);
            $t->queueAll(CommandCatalog::OK_RESPONSE, 3);
            return $t;
        });

        $this->app = AppFactory::create();
        Middleware::register($this->app);
        $app = $this->app;
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/tasks.php';
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    private function call(string $method, string $path, array $body = []): ResponseInterface {
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

    private function json(ResponseInterface $response): array {
        return json_decode((string) $response->getBody(), true);
    }

    /** as a browser sends it: UTF-8, percent-encoded */
    private static function path(string $name, string $suffix = ''): string {
        return '/v1/domains/' . rawurlencode($name) . $suffix;
    }

    public static function spellings(): array {
        return [
            'unicode'      => ['sesamstraße.it'],
            'punycode'     => ['xn--sesamstrae-e4a.it'],
            'upper case'   => ['SESAMSTRAßE.IT'],
            'capital ẞ'    => ['SESAMSTRAẞE.It'],
            'trailing dot' => ['sesamstraße.it.'],
        ];
    }

    #[DataProvider('spellings')]
    public function testAnySpellingFindsTheStoredDomainForADeletionSchedule(string $given): void {
        $response = $this->call('DELETE', self::path($given) . '?mode=expiry');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('sesamstraße.it', $this->json($response)['domain']);
        $this->assertSame(['sesamstraße.it'], R::getCol('SELECT domain FROM tasks'));
    }

    public function testASpellingOfAnotherResellersDomainIsStillForbidden(): void {
        $response = $this->call('DELETE', self::path('XN--CITT-3NA.IT') . '?mode=expiry');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame("You are not authorized to modify domain 'città.it'", $this->json($response)['error']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM tasks'));
    }

    public function testATransferInIsFoundBySpellingToo(): void {
        $response = $this->call('GET', self::path('XN--CAF-DMA.IT'));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('café.it', $this->json($response)['domain']['domain']);
    }

    public function testAnUnknownNameIsAnotherNameNotAnotherSpelling(): void {
        $this->assertSame(404, $this->call('GET', self::path('sesamstrasse.it'))->getStatusCode());
        $this->assertSame(403, $this->call('DELETE', self::path('sesamstrasse.it') . '?mode=expiry')->getStatusCode());
    }

    public function testTasksAreKeptAndListedUnderTheStoredName(): void {
        $created = $this->call('POST', self::path('XN--SESAMSTRAE-E4A.IT', '/tasks'), ['date' => '2999-01-01', 'notice' => 'renew']);

        $this->assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->assertSame('sesamstraße.it', $this->json($created)['domain']);
        $this->assertSame(['sesamstraße.it'], R::getCol('SELECT domain FROM tasks'));

        $listed = $this->json($this->call('GET', self::path('SESAMSTRAßE.it', '/tasks')))['tasks'];
        $this->assertSame(['sesamstraße.it'], array_column($listed, 'domain'));
    }

    public function testAnImportListIsReducedToOneNamePerDomain(): void {
        R::exec("UPDATE domains SET reseller_id = 3 WHERE domain = 'sesamstraße.it'");

        $response = $this->call('POST', '/v1/domains/import', [
            'domains' => ['SESAMSTRAßE.IT.', 'xn--sesamstrae-e4a.it', ' sesamstraße.it '],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['sesamstraße.it'], array_keys($this->json($response)['results']));
    }

    public function testTheAutocompleteTermIsNormalisedLikeTheNames(): void {
        R::exec("UPDATE domains SET reseller_id = 2 WHERE domain = 'città.it'");

        foreach (['CITTÀ', 'xn--citt-3na', "citta\u{0300}"] as $term) {
            $response = $this->call('GET', '/v1/domains/autocomplete?term=' . rawurlencode($term));

            $this->assertSame(['città.it'], $this->json($response)['domains'], $term);
        }
    }
}
