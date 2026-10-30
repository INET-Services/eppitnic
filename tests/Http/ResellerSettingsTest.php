<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Service\ResellerSettings;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A reseller's default country, contact type, default technical contacts and NS sets: who
 * may touch them, what they must look like, and how the default set follows
 * the set it names.
 */
final class ResellerSettingsTest extends TestCase
{
    private const SET = ['name' => 'Main', 'ns' => ['ns1.example.it', 'ns2.example.it']];

    /** user id => [role, reseller] */
    private const ACCOUNTS = [
        1 => ['admin', 1],
        2 => ['manager', 2],
        3 => ['manager', 3],
        4 => ['user', 2],
    ];

    private function app(): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'          => 'test-signing-key-for-this-suite-only',
            'allowed_origins'  => [],
            'allowed_headers'  => [],
            'allowed_methods'  => [],
            'safe_networks'    => ['127.0.0.1/32'],
            'trusted_proxies'  => [],
            'login_ratelimit'  => ['max_failures' => 0, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'history'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        TestAccounts::ensureReseller(2, 'reseller');
        TestAccounts::ensureReseller(3, 'other');
        foreach (self::ACCOUNTS as $id => [$role, $reseller]) {
            TestAccounts::ensure($id, $role, $reseller);
        }

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/resellers.php';
        return $app;
    }

    /**
     * @param int $as the user making the request (see ACCOUNTS)
     */
    private function call(\Slim\App $app, string $method, string $path, array $body = [], int $as = 2): ResponseInterface {
        [$role, $reseller] = self::ACCOUNTS[$as];
        $token = TestAccounts::issueToken([
            'id' => $as, 'username' => 'someone', 'role' => $role, 'reseller_id' => $reseller,
            'has_totp' => false, 'max_token_age' => 60,
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

    private static function error(ResponseInterface $r): string {
        return (string) (self::json($r)['error'] ?? '');
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // who may
    // ---------------------------------------------------------------

    public function testAManagerReadsTheirResellersSettings(): void {
        $response = $this->call($this->app(), 'GET', '/v1/resellers/2/settings');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['countrycode' => '', 'entitytype' => null, 'techc' => [], 'dnsset' => '', 'nssets' => [],
                'dnssec_algorithm' => null, 'dnssec_digesttype' => null],
            self::json($response)['settings'],
        );
    }

    public function testAManagerCannotReadOrChangeAnotherResellers(): void {
        $app = $this->app();

        $this->assertSame(403, $this->call($app, 'GET', '/v1/resellers/3/settings')->getStatusCode());
        $this->assertSame(403, $this->call($app, 'PUT', '/v1/resellers/3/settings', ['countrycode' => 'DE'])->getStatusCode());
        $this->assertSame(403, $this->call($app, 'POST', '/v1/resellers/3/nssets', self::SET)->getStatusCode());
        $this->assertNull(R::getCell('SELECT countrycode FROM resellers WHERE id = 3'));
    }

    public function testAPlainUserReadsButCannotChangeTheirResellers(): void {
        $app = $this->app();

        $this->assertSame(200, $this->call($app, 'GET', '/v1/resellers/2/settings', [], 4)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'PUT', '/v1/resellers/2/settings', ['countrycode' => 'DE'], 4)->getStatusCode());
        $this->assertSame(403, $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET, 4)->getStatusCode());
        $this->assertNull(R::getCell('SELECT countrycode FROM resellers WHERE id = 2'));
    }

    public function testAnAdminMayActForAnyone(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/resellers/3/settings', ['countrycode' => 'DE'], 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('DE', R::getCell('SELECT countrycode FROM resellers WHERE id = 3'));
    }

    public function testAnUnknownResellerIsNotFound(): void {
        $this->assertSame(404, $this->call($this->app(), 'GET', '/v1/resellers/99/settings', [], 1)->getStatusCode());
    }

    // ---------------------------------------------------------------
    // defaults
    // ---------------------------------------------------------------

    public function testTheCountryIsStoredUpperCased(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/resellers/2/settings', ['countrycode' => ' de ']);

        $this->assertSame('DE', self::json($response)['settings']['countrycode']);
    }

    public function testAnEmptyCountryClearsIt(): void {
        $app = $this->app();
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['countrycode' => 'DE']);

        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['countrycode' => '']);

        $this->assertNull(R::getCell('SELECT countrycode FROM resellers WHERE id = 2'));
    }

    public function testTheContactTypeIsStoredAndReturnedAsAnInteger(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/resellers/2/settings', ['entitytype' => '3']);

        $this->assertSame(3, self::json($response)['settings']['entitytype']);
        $this->assertSame(3, self::json($this->call($app, 'GET', '/v1/resellers/2/settings'))['settings']['entitytype']);
    }

    public function testNoContactTypeReadsAsNull(): void {
        $settings = self::json($this->call($this->app(), 'GET', '/v1/resellers/2/settings'))['settings'];

        $this->assertNull($settings['entitytype']);
    }

    #[DataProvider('clearingValues')]
    public function testAnEmptyContactTypeClearsIt(mixed $value): void {
        $app = $this->app();
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['entitytype' => 2]);

        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['entitytype' => $value]);

        $this->assertNull(R::getCell('SELECT entitytype FROM resellers WHERE id = 2'));
    }

    public static function clearingValues(): array {
        return [[null], [''], [0], ['0']];
    }

    #[DataProvider('badContactTypes')]
    public function testAContactTypeOutsideOneToSevenIsRefused(mixed $value): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', ['entitytype' => $value]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('entitytype must be 1 to 7', self::error($response));
    }

    public static function badContactTypes(): array {
        return [[8], ['x'], [-1], ['2.5'], [[2]]];
    }

    public function testTheDnssecDefaultsAreStoredAndReturnedAsIntegers(): void {
        $app = $this->app();

        $response = $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnssec_algorithm' => '8', 'dnssec_digesttype' => 4]);

        $settings = self::json($this->call($app, 'GET', '/v1/resellers/2/settings'))['settings'];
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(8, $settings['dnssec_algorithm']);
        $this->assertSame(4, $settings['dnssec_digesttype']);
    }

    #[DataProvider('clearingValues')]
    public function testEmptyDnssecDefaultsClearThem(mixed $value): void {
        $app = $this->app();
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnssec_algorithm' => 13, 'dnssec_digesttype' => 2]);

        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnssec_algorithm' => $value, 'dnssec_digesttype' => $value]);

        $this->assertNull(R::getCell('SELECT dnssec_algorithm FROM resellers WHERE id = 2'));
        $this->assertNull(R::getCell('SELECT dnssec_digesttype FROM resellers WHERE id = 2'));
    }

    #[DataProvider('badDnssecDefaults')]
    public function testADnssecDefaultOutsideTheRegistrysListIsRefused(string $key, mixed $value, string $message): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', [$key => $value]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, self::error($response));
    }

    public static function badDnssecDefaults(): array {
        $algorithms = 'dnssec_algorithm must be one of 3, 5, 6, 7, 8, 10, 12, 13, 14';
        $digests = 'dnssec_digesttype must be one of 1, 2, 3, 4';
        return [
            ['dnssec_algorithm', 9, $algorithms],
            ['dnssec_algorithm', 15, $algorithms],
            ['dnssec_algorithm', 'x', $algorithms],
            ['dnssec_digesttype', 5, $digests],
            ['dnssec_digesttype', 'x', $digests],
            ['dnssec_digesttype', [2], $digests],
        ];
    }

    public function testAMalformedCountryIsRefused(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', ['countrycode' => 'Italy']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('two-letter', self::error($response));
    }

    public function testWhatIsNotGivenStays(): void {
        $app = $this->app();
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['countrycode' => 'DE', 'techc' => ['TECH-1']]);

        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['techc' => ['TECH-2']]);

        $settings = self::json($this->call($app, 'GET', '/v1/resellers/2/settings'))['settings'];
        $this->assertSame('DE', $settings['countrycode']);
        $this->assertSame(['TECH-2'], $settings['techc']);
    }

    public function testTechnicalContactsAreCleanedAndDeduplicated(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', [
            'techc' => [' TECH-1 ', 'TECH-2', 'TECH-1', ''],
        ]);

        $this->assertSame(['TECH-1', 'TECH-2'], self::json($response)['settings']['techc']);
    }

    public function testMoreThanSixTechnicalContactsAreRefused(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', [
            'techc' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAHandleWithASpaceInItIsRefused(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', ['techc' => ['not a handle']]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTechnicalContactsMustBeAList(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/settings', ['techc' => 'TECH-1']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * Rows written before techc became a list hold one bare handle.
     */
    public function testALegacySingleHandleReadsAsAOneElementList(): void {
        $app = $this->app();
        R::exec("UPDATE resellers SET techc = 'TECH-OLD' WHERE id = 2");

        $settings = self::json($this->call($app, 'GET', '/v1/resellers/2/settings'))['settings'];

        $this->assertSame(['TECH-OLD'], $settings['techc']);
        $this->assertSame([], ResellerSettings::decodeTech(null));
        $this->assertSame([], ResellerSettings::decodeTech('  '));
        $this->assertSame(['A', 'B'], ResellerSettings::decodeTech('["A","B"]'));
    }

    // ---------------------------------------------------------------
    // NS sets
    // ---------------------------------------------------------------

    public function testASetIsAdded(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers/2/nssets', self::SET);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([self::SET], self::json($response)['settings']['nssets']);
    }

    public function testHostnamesAreLowerCased(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers/2/nssets', [
            'name' => 'Main', 'ns' => ['NS1.Example.IT', ' ns2.example.it '],
        ]);

        $this->assertSame(['ns1.example.it', 'ns2.example.it'], self::json($response)['settings']['nssets'][0]['ns']);
    }

    public function testASetNeedsBetweenTwoAndSixNameservers(): void {
        $app = $this->app();

        $one = $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => 'A', 'ns' => ['ns1.example.it']]);
        $seven = $this->call($app, 'POST', '/v1/resellers/2/nssets', [
            'name' => 'B', 'ns' => array_map(fn($i) => "ns{$i}.example.it", range(1, 7)),
        ]);
        $six = $this->call($app, 'POST', '/v1/resellers/2/nssets', [
            'name' => 'C', 'ns' => array_map(fn($i) => "ns{$i}.example.it", range(1, 6)),
        ]);

        $this->assertSame(400, $one->getStatusCode());
        $this->assertSame(400, $seven->getStatusCode());
        $this->assertSame(201, $six->getStatusCode());
    }

    public function testAnAddressIsNotAHostname(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers/2/nssets', [
            'name' => 'A', 'ns' => ['ns1.example.it', '192.0.2.1'],
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('192.0.2.1', self::error($response));
    }

    public function testAHostnameTwiceIsRefused(): void {
        $response = $this->call($this->app(), 'POST', '/v1/resellers/2/nssets', [
            'name' => 'A', 'ns' => ['ns1.example.it', 'NS1.example.it'],
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('twice', self::error($response));
    }

    public function testANameMustBeUniqueIgnoringCase(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);

        $response = $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => 'MAIN'] + self::SET);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('already exists', self::error($response));
    }

    public function testTwoResellersMayUseTheSameName(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);

        $response = $this->call($app, 'POST', '/v1/resellers/3/nssets', self::SET, 3);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testANameNeedsNoSlashAndAFittingLength(): void {
        $app = $this->app();

        $slash = $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => 'a/b'] + self::SET);
        $long = $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => str_repeat('x', 65)] + self::SET);
        $blank = $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => '  '] + self::SET);

        $this->assertSame(400, $slash->getStatusCode());
        $this->assertSame(400, $long->getStatusCode());
        $this->assertSame(400, $blank->getStatusCode());
    }

    public function testASetIsReplaced(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);

        $response = $this->call($app, 'PUT', '/v1/resellers/2/nssets/Main', [
            'ns' => ['a.example.it', 'b.example.it', 'c.example.it'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [['name' => 'Main', 'ns' => ['a.example.it', 'b.example.it', 'c.example.it']]],
            self::json($response)['settings']['nssets'],
        );
    }

    public function testASetKeepsItsOwnNameWhenSavedAgain(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);

        $response = $this->call($app, 'PUT', '/v1/resellers/2/nssets/Main', self::SET);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReplacingAnUnknownSetIsNotFound(): void {
        $response = $this->call($this->app(), 'PUT', '/v1/resellers/2/nssets/Nope', self::SET);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // the default set
    // ---------------------------------------------------------------

    public function testTheDefaultMustBeASetTheResellerHas(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);

        $unknown = $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'Nope']);
        $known = $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'main']);

        $this->assertSame(400, $unknown->getStatusCode());
        $this->assertSame('Main', self::json($known)['settings']['dnsset'], 'stored under the set\'s own spelling');
    }

    public function testAnEmptyDefaultClearsIt(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'Main']);

        $response = $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => '']);

        $this->assertSame('', self::json($response)['settings']['dnsset']);
    }

    public function testRenamingTheDefaultSetKeepsItTheDefault(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'Main']);

        $response = $this->call($app, 'PUT', '/v1/resellers/2/nssets/Main', ['name' => 'Primary'] + self::SET);

        $settings = self::json($response)['settings'];
        $this->assertSame('Primary', $settings['nssets'][0]['name']);
        $this->assertSame('Primary', $settings['dnsset']);
    }

    public function testRenamingAnotherSetLeavesTheDefaultAlone(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);
        $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => 'Backup'] + self::SET);
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'Main']);

        $response = $this->call($app, 'PUT', '/v1/resellers/2/nssets/Backup', ['name' => 'Spare'] + self::SET);

        $this->assertSame('Main', self::json($response)['settings']['dnsset']);
    }

    public function testRemovingTheDefaultSetLeavesNoDefault(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'Main']);

        $response = $this->call($app, 'DELETE', '/v1/resellers/2/nssets/Main');

        $settings = self::json($response)['settings'];
        $this->assertSame([], $settings['nssets']);
        $this->assertSame('', $settings['dnsset']);
    }

    public function testRemovingAnotherSetLeavesTheDefaultAlone(): void {
        $app = $this->app();
        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);
        $this->call($app, 'POST', '/v1/resellers/2/nssets', ['name' => 'Backup'] + self::SET);
        $this->call($app, 'PUT', '/v1/resellers/2/settings', ['dnsset' => 'Main']);

        $response = $this->call($app, 'DELETE', '/v1/resellers/2/nssets/Backup');

        $settings = self::json($response)['settings'];
        $this->assertSame(['Main'], array_column($settings['nssets'], 'name'));
        $this->assertSame('Main', $settings['dnsset']);
    }

    public function testRemovingAnUnknownSetIsNotFound(): void {
        $this->assertSame(404, $this->call($this->app(), 'DELETE', '/v1/resellers/2/nssets/Nope')->getStatusCode());
    }

    public function testAChangeIsRecordedInTheHistory(): void {
        $app = $this->app();

        $this->call($app, 'POST', '/v1/resellers/2/nssets', self::SET);

        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'resellers' AND object_id = 2 AND action = 'update'"));
    }
}
