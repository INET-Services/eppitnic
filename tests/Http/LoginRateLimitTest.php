<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Failed logins are recorded, and enough from one network stop being answered.
 * Counted per network, so these drive several addresses in one allocation and
 * expect a shared budget -- what an IPv6 /64 cannot walk around.
 */
final class LoginRateLimitTest extends TestCase
{
    private const PATH = '/v1/users/authenticate';

    /** cost 4: these tests make a lot of password_verify() calls */
    private static function hash(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /**
     * @param array<string, mixed> $rateLimit overrides for the login_ratelimit
     *                       setting
     */
    private function app(array $rateLimit = []): \Slim\App {
        Config::loadForTesting([
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'safe_networks'   => [],
            'trusted_proxies' => [],
            'login_ratelimit' => $rateLimit + ['max_failures' => 3, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
        ]);

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('DROP TABLE IF EXISTS users');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('DROP TABLE IF EXISTS resellers');
        R::exec("CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER DEFAULT 1, role TEXT DEFAULT 'user',
                 username TEXT, password TEXT, active INTEGER, totp_secret TEXT,
                 must_change_password INTEGER DEFAULT 0, must_enroll_mfa INTEGER DEFAULT 0, signin_requirement TEXT NOT NULL DEFAULT 'none', passkey_enroll_open INTEGER DEFAULT 0, webauthn_handle BLOB, debug INTEGER, max_token_age INTEGER, token_lifetime INTEGER, max_idle_time INTEGER, last_activity TEXT)");
        R::exec("INSERT INTO users (id, username, password, role, active, totp_secret, debug, max_token_age, max_idle_time)
                 VALUES (1, 'someone', ?, 'user', 1, NULL, 0, 60, 30)", [self::hash('the-right-password')]);
        TestAccounts::ensureReseller(1);

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        return $app;
    }

    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR']);
        Config::reset();
        parent::tearDown();
    }

    private function login(\Slim\App $app, string $from, string $password, string $username = 'someone'): ResponseInterface {
        $_SERVER['REMOTE_ADDR'] = $from;

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . self::PATH)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['username' => $username, 'password' => $password]);

        return $app->handle($request);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows(): array {
        return R::getAll("SELECT * FROM history WHERE object = 'security' ORDER BY id");
    }

    // ---------------------------------------------------------------
    // logging
    // ---------------------------------------------------------------

    public function testAFailedLoginIsRecorded(): void {
        $app = $this->app();
        $this->login($app, '203.0.113.5', 'wrong');

        $rows = self::rows();
        $this->assertCount(1, $rows);

        $data = json_decode($rows[0]['data'], true);
        $this->assertSame('denied', $rows[0]['action']);
        $this->assertSame('login_failed', $data['event']);
        $this->assertSame('someone', $data['username']);
        $this->assertSame('203.0.113.5', $data['ip']);
        $this->assertSame('203.0.113.0/24', $rows[0]['network']);
    }

    /**
     * The reason is precise in the log and vague in the response: an operator
     * needs to tell a guessed username from a guessed password, and whoever is
     * guessing must not be able to.
     */
    public function testAnUnknownUsernameIsRecordedWithNobodyToBlame(): void {
        $app = $this->app();
        $response = $this->login($app, '203.0.113.5', 'wrong', 'nobody-by-that-name');

        $rows = self::rows();
        $data = json_decode($rows[0]['data'], true);

        $this->assertNull($rows[0]['user_id'], 'an unknown username was blamed on a real user');
        $this->assertSame('no such active user', $data['reason']);
        $this->assertSame('Wrong username or password', json_decode((string) $response->getBody(), true)['error']);
    }

    public function testTheAttemptedPasswordIsNeverRecorded(): void {
        $app = $this->app();
        $this->login($app, '203.0.113.5', 'hunter2-is-a-secret');

        $this->assertStringNotContainsString(
            'hunter2-is-a-secret',
            (string) R::getCell("SELECT data FROM history WHERE object = 'security'"),
            'the attempted password was written to the log'
        );
    }

    public function testASuccessfulLoginIsRecordedToo(): void {
        $app = $this->app();
        $response = $this->login($app, '203.0.113.5', 'the-right-password');

        $this->assertSame(200, $response->getStatusCode());

        $rows = self::rows();
        $this->assertCount(1, $rows);

        $data = json_decode($rows[0]['data'], true);
        $this->assertSame('login', $rows[0]['action']);
        $this->assertSame('login_succeeded', $data['event']);
        $this->assertSame('someone', $data['username']);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertSame('203.0.113.0/24', $rows[0]['network']);
    }

    /**
     * The token a successful login issues is a credential, and belongs in the
     * log no more than the password does.
     */
    public function testASuccessfulLoginDoesNotRecordTheToken(): void {
        $app = $this->app();
        $response = $this->login($app, '203.0.113.5', 'the-right-password');

        $token = json_decode((string) $response->getBody(), true)['token'];
        $this->assertNotSame('', $token);

        $this->assertStringNotContainsString(
            $token,
            (string) R::getCell("SELECT data FROM history WHERE object = 'security'"),
            'the issued token was written to the log'
        );
    }

    /**
     * Successes must not spend the failure budget, or a busy legitimate user
     * would lock out their own network.
     */
    public function testSuccessesDoNotCountTowardTheLimit(): void {
        $app = $this->app(['max_failures' => 2]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->login($app, '203.0.113.5', 'the-right-password')->getStatusCode());
        }

        $this->assertSame(401, $this->login($app, '203.0.113.5', 'wrong')->getStatusCode());
    }

    // ---------------------------------------------------------------
    // the limit
    // ---------------------------------------------------------------

    public function testTheAllowanceIsSpentAndThenRefused(): void {
        $app = $this->app(['max_failures' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $this->login($app, '203.0.113.5', 'wrong')->getStatusCode(), "attempt {$i}");
        }

        $response = $this->login($app, '203.0.113.5', 'wrong');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Retry-After'));
        $this->assertGreaterThan(0, json_decode((string) $response->getBody(), true)['retry_after']);
    }

    /**
     * Once blocked, the right password does not get through either. Otherwise
     * the limit would only slow down guessing at accounts the attacker has not
     * already broken into.
     */
    public function testABlockedNetworkIsRefusedEvenWithTheRightPassword(): void {
        $app = $this->app(['max_failures' => 2]);
        $this->login($app, '203.0.113.5', 'wrong');
        $this->login($app, '203.0.113.5', 'wrong');

        $this->assertSame(429, $this->login($app, '203.0.113.5', 'the-right-password')->getStatusCode());
    }

    /**
     * The reason for masking. Every address in one /24 spends the same budget,
     * so an attacker rotating through a network gains nothing.
     */
    public function testAddressesInOneIpv4NetworkShareTheBudget(): void {
        $app = $this->app(['max_failures' => 3]);

        $this->login($app, '203.0.113.1', 'wrong');
        $this->login($app, '203.0.113.2', 'wrong');
        $this->login($app, '203.0.113.3', 'wrong');

        $this->assertSame(429, $this->login($app, '203.0.113.4', 'wrong')->getStatusCode());
    }

    /**
     * The same, for the case that actually matters: an ordinary IPv6 customer
     * connection is a /64, which is 18 billion billion addresses to rotate
     * through if the limit were per address.
     */
    public function testAddressesInOneIpv6EndSiteShareTheBudget(): void {
        $app = $this->app(['max_failures' => 3]);

        // four different /64s inside one /48: at /64 these would be four
        // separate budgets, which is the rotation the /48 default closes
        $this->login($app, '2001:db8:1:0::1', 'wrong');
        $this->login($app, '2001:db8:1:1::1', 'wrong');
        $this->login($app, '2001:db8:1:ffff::1', 'wrong');

        $this->assertSame(429, $this->login($app, '2001:db8:1:abcd::1', 'wrong')->getStatusCode());
    }

    /**
     * A neighbouring /48 is somebody else, and keeps its own budget.
     */
    public function testANeighbouringIpv6SiteIsUnaffected(): void {
        $app = $this->app(['max_failures' => 2]);
        $this->login($app, '2001:db8:1::1', 'wrong');
        $this->login($app, '2001:db8:1::2', 'wrong');

        $this->assertSame(429, $this->login($app, '2001:db8:1::3', 'wrong')->getStatusCode());
        $this->assertSame(401, $this->login($app, '2001:db8:2::1', 'wrong')->getStatusCode());
    }

    public function testADifferentNetworkHasItsOwnBudget(): void {
        $app = $this->app(['max_failures' => 2]);
        $this->login($app, '203.0.113.1', 'wrong');
        $this->login($app, '203.0.113.2', 'wrong');

        $this->assertSame(429, $this->login($app, '203.0.113.3', 'wrong')->getStatusCode());
        $this->assertSame(401, $this->login($app, '198.51.100.1', 'wrong')->getStatusCode());
        $this->assertSame(200, $this->login($app, '198.51.100.1', 'the-right-password')->getStatusCode());
    }

    /**
     * A refused attempt is recorded, but as a block rather than a failure --
     * otherwise a network that kept knocking would keep extending its own
     * block, and a limit that never lets go is a permanent ban.
     */
    public function testBlocksDoNotFeedTheCounterThatProducedThem(): void {
        $app = $this->app(['max_failures' => 2]);
        $this->login($app, '203.0.113.1', 'wrong');
        $this->login($app, '203.0.113.1', 'wrong');

        for ($i = 0; $i < 5; $i++) {
            $this->login($app, '203.0.113.1', 'wrong');
        }

        $attempts = (int) R::getCell("SELECT COUNT(*) FROM history WHERE action = 'denied'");
        $blocks   = (int) R::getCell("SELECT COUNT(*) FROM history WHERE action = 'secread'");

        $this->assertSame(2, $attempts, 'a blocked request was counted as a failure');
        $this->assertSame(5, $blocks);
    }

    /**
     * Failures older than the window do not count, so a block lifts itself
     * rather than needing anything cleared.
     */
    public function testFailuresOutsideTheWindowAreForgotten(): void {
        $app = $this->app(['max_failures' => 2, 'timespan' => 900]);
        $this->login($app, '203.0.113.1', 'wrong');
        $this->login($app, '203.0.113.1', 'wrong');
        $this->assertSame(429, $this->login($app, '203.0.113.1', 'wrong')->getStatusCode());

        // age both failures past the window
        R::exec("UPDATE history SET timestamp = datetime('now', '-1 hour') WHERE action = 'denied'");

        $this->assertSame(401, $this->login($app, '203.0.113.1', 'wrong')->getStatusCode());
    }

    public function testAMaxOfZeroDisablesTheLimit(): void {
        $app = $this->app(['max_failures' => 0]);

        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(401, $this->login($app, '203.0.113.1', 'wrong')->getStatusCode());
        }
    }

    /**
     * A spoofed X-Forwarded-For must not create a fresh budget, or the limit is
     * one header away from being nothing at all.
     */
    public function testAForwardedHeaderCannotBuyANewBudget(): void {
        $app = $this->app(['max_failures' => 2]);
        $this->login($app, '203.0.113.1', 'wrong');
        $this->login($app, '203.0.113.1', 'wrong');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . self::PATH)
            ->withHeader('X-Forwarded-For', '198.51.100.1')
            ->withParsedBody(['username' => 'someone', 'password' => 'wrong']);

        $this->assertSame(429, $app->handle($request)->getStatusCode());
    }
}
