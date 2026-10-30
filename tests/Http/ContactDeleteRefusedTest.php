<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * DELETE /v1/contacts/{handle} on a registrant of a held domain or pending
 * transfer is refused before the registry is asked.
 *
 * Own process: contact.php declares functions, so it loads once per process.
 */
#[RunClassInSeparateProcess]
final class ContactDeleteRefusedTest extends TestCase
{
    protected function setUp(): void {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'trusted_proxies' => [],
            'remote_auth'     => ['enabled' => false, 'header' => null],
        ]);
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        foreach (['users', 'resellers', 'domains', 'contacts', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, reseller_id INTEGER, handle TEXT UNIQUE, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 domain TEXT UNIQUE, registrant TEXT, admin TEXT, tech TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, domain TEXT, registrant TEXT, status TEXT DEFAULT 'pending')");
        TestAccounts::ensure(2, 'user', 2);
        R::exec("INSERT INTO contacts (reseller_id, handle) VALUES (2, 'HELD'), (2, 'MOVING')");
        R::exec("INSERT INTO domains (reseller_id, domain, registrant, active) VALUES (2, 'a.it', 'HELD', 1), (2, 'b.it', 'HELD', 1)");
        R::exec("INSERT INTO transfers (domain, registrant) VALUES ('c.it', 'MOVING')");
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    private function delete(string $handle): array {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/contact.php';

        $token = TestAccounts::issueToken(['id' => 2, 'max_token_age' => 60, 'reseller_id' => 2])['token'];
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('DELETE', "http://localhost/v1/contacts/{$handle}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)['error']];
    }

    public function testAHeldDomainsRegistrantIsRefusedLocally(): void {
        [$status, $error] = $this->delete('HELD');

        $this->assertSame(409, $status);
        $this->assertSame('contact HELD is still the registrant of 2 domain(s): a.it, b.it', $error);
    }

    public function testAPendingTransfersRegistrantIsRefusedLocally(): void {
        [$status, $error] = $this->delete('MOVING');

        $this->assertSame(409, $status);
        $this->assertSame('contact MOVING is still the registrant of 1 domain(s): c.it', $error);
    }
}
