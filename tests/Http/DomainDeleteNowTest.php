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
 * DELETE /v1/domains/{name}?mode=now on a domain with a scheduled deletion:
 * accepted, and once the registry deleted it the task is deactivated, on
 * record in the deletion's history row.
 */
// domain.php declares functions: each test loads it in a process of its own
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DomainDeleteNowTest extends EppTestCase
{
    private const REFUSED_RESPONSE = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
      <response>
        <result code="2305"><msg lang="en">Object association prohibits operation</msg></result>
        <trID><clTRID>TEST-0000000000-00000</clTRID><svTRID>TEST-SVTRID</svTRID></trID>
      </response>
    </epp>
    XML;

    /** @var string what the registry answers the delete with */
    private string $answer = CommandCatalog::OK_RESPONSE;

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
        foreach (['tasks', 'domains', 'history', 'transfers'] as $table) {
            R::exec("DROP TABLE IF EXISTS {$table}");
        }
        R::exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, domain TEXT, reseller_id INTEGER, active INTEGER DEFAULT 1,
                 status TEXT, authinfo TEXT, ns TEXT, registrant TEXT, ex_date TEXT)');
        R::exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, domain TEXT, date TEXT, notice TEXT, email TEXT,
                 object TEXT, action TEXT, active INTEGER DEFAULT 1)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reseller_id INTEGER, domain TEXT, status TEXT)");
        R::exec("INSERT INTO domains (domain, reseller_id, ns, ex_date) VALUES ('ours.it', 2, ?, '2027-05-01')", [serialize([])]);

        Client::useTransportFactory(function (): FakeTransport {
            $t = new FakeTransport();
            $t->queue(CommandCatalog::GREETING_RESPONSE);
            $t->queue(CommandCatalog::OK_RESPONSE); // login
            $t->queue($this->answer);
            $t->queueAll(CommandCatalog::OK_RESPONSE, 3);
            return $t;
        });
    }

    protected function tearDown(): void {
        Client::useTransportFactory(null);
        parent::tearDown();
    }

    private function delete(string $mode): ResponseInterface {
        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/domain.php';

        $token = TestAccounts::issueToken([
            'id' => 4, 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false, 'max_token_age' => 60,
        ])['token'];

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('DELETE', "http://localhost/v1/domains/ours.it?mode={$mode}")
                ->withHeader('Authorization', "Bearer {$token}")
        );
    }

    private static function schedule(): int {
        R::exec("INSERT INTO tasks (domain, date, notice, email, object, action) VALUES ('ours.it', '2027-05-01', 'scheduled deletion', '', 'registry', 'delete')");
        return (int) R::getInsertID();
    }

    public function testAnImmediateDeletionCancelsTheScheduledOne(): void {
        $id = self::schedule();
        R::exec("INSERT INTO tasks (domain, date, notice, email) VALUES ('ours.it', '2027-04-01', 'renew?', '')");

        $response = $this->delete('now');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, (int) R::getCell('SELECT active FROM tasks WHERE id = ?', [$id]));
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE object IS NULL'), 'a notice stays');
        $row = R::getRow("SELECT action, data FROM history WHERE object = 'domains'");
        $this->assertSame('delete', $row['action']);
        $this->assertSame(['domain' => 'ours.it', 'scheduled_deletion' => 'cancelled', 'task_id' => $id], json_decode($row['data'], true));
    }

    public function testWithoutAScheduledDeletionTheHistoryRowIsUnchanged(): void {
        $this->assertSame(200, $this->delete('now')->getStatusCode());

        $data = json_decode(R::getCell("SELECT data FROM history WHERE object = 'domains'"), true);
        $this->assertSame(['domain' => 'ours.it'], $data);
    }

    public function testARefusedDeletionLeavesTheScheduledOne(): void {
        $id = self::schedule();
        $this->answer = self::REFUSED_RESPONSE;

        $this->assertSame(400, $this->delete('now')->getStatusCode());
        $this->assertSame(1, (int) R::getCell('SELECT active FROM tasks WHERE id = ?', [$id]));
    }

    public function testASecondScheduledDeletionIsStillAConflict(): void {
        self::schedule();

        $this->assertSame(409, $this->delete('expiry')->getStatusCode());
    }
}
