<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Service\SigninRequirement;
use OTPHP\TOTP;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The users routes on the passkey database: accounts that sign in by
 * password, code and passkey, from inside or outside the safe networks.
 * Account 5 (alice, reseller 2) is the one signing in; 1 is an admin.
 */
abstract class PasskeyHttpTestCase extends PasskeyTestCase
{
    protected const SETTINGS = EppTestCase::SETTINGS + [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'safe_networks'   => ['10.0.0.0/8'],
        'trusted_proxies' => [],
        'remote_auth'     => ['enabled' => false, 'header' => null],
        'login_ratelimit' => ['max_failures' => 3, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 48],
    ];

    protected const PASSWORD = 'Current-Passw0rd!';
    protected const OUTSIDE  = '198.51.100.7';
    protected const SAFE     = '10.1.2.3';

    protected \Slim\App $app;

    protected string $totpSecret;

    protected function setUp(): void {
        parent::setUp();

        R::exec('DROP TABLE IF EXISTS resellers');
        TestAccounts::ensureReseller(2, 'Two');
        TestAccounts::ensure(1, 'admin', 1, 'admin');
        TestAccounts::ensure(2, 'manager', 2, 'boss');
        TestAccounts::ensure(5, 'user', 2, 'alice');
        R::exec('UPDATE users SET password = ?', [password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->totpSecret = TOTP::generate(null, 20)->getSecret();

        $_SERVER['REMOTE_ADDR'] = self::OUTSIDE;

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/users.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/passkeys.php';
        require EPPITNIC_ROOT . '/src/Api/Routes/session.php';
        $this->app = $app;
    }

    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR']);
        Config::reset();
        parent::tearDown();
    }

    protected function from(string $ip): void {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    /** @param bool|null $open whether the password may register keys; as a requirement set through the API leaves it, by default */
    protected function requirement(string $mode, int $id = 5, ?bool $open = null): void {
        R::exec('UPDATE users SET signin_requirement = ?, passkey_enroll_open = ? WHERE id = ?', [
            $mode, (int) ($open ?? SigninRequirement::needsKeys($mode)), $id,
        ]);
    }

    protected function withTotp(int $id = 5): void {
        R::exec('UPDATE users SET totp_secret = ? WHERE id = ?', [$this->totpSecret, $id]);
    }

    protected function code(): string {
        return TOTP::createFromSecret($this->totpSecret)->now();
    }

    /** @return SoftAuthenticator[] $count registered keys of alice */
    protected function keys(int $count, array $forge = []): array {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = $key = new SoftAuthenticator(aaguid: sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $i));
            $this->storedKey($key, $forge);
        }
        // the key helpers reset the account, which is not what a test wants
        TestAccounts::ensure(5, 'user', 2, 'alice');
        return $keys;
    }

    protected function call(string $method, string $path, array $body = [], ?string $token = null, array $server = []): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, "http://localhost{$path}", $server)
            ->withParsedBody($body);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$token}");
        }
        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    protected static function json(ResponseInterface $response): array {
        return (array) json_decode((string) $response->getBody(), true);
    }

    protected function password(array $more = []): ResponseInterface {
        return $this->call('POST', '/v1/users/authenticate', array_replace(['username' => 'alice', 'password' => self::PASSWORD], $more));
    }

    /** @param array $forge SoftAuthenticator fields to forge in the assertion */
    protected function passkey(SoftAuthenticator $key, array $forge = []): ResponseInterface {
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/options'));
        $this->assertArrayHasKey('publicKey', $options);

        return $this->call('POST', '/v1/users/authenticate/passkey', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey'], $forge), true),
        ]);
    }

    /** A signed-in session, by default alice's, however she got it. */
    protected function session(string $method, array $claims = []): string {
        return TestAccounts::issueToken(array_replace([
            'id' => 5, 'username' => 'alice', 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false,
            'max_token_age' => 60, 'amr' => $method, 'strong_auth' => $method !== 'pwd',
        ], $claims))['token'];
    }

    /** A token as issued before passkeys: no amr, no strong_auth. */
    protected function legacyToken(array $claims = []): string {
        return TestAccounts::issueToken(array_replace([
            'id' => 5, 'username' => 'alice', 'role' => 'user', 'reseller_id' => 2, 'has_totp' => false,
            'max_token_age' => 60, 'totp_verified' => false,
        ], $claims))['token'];
    }

    /** An account that signs in with the suite's password. */
    protected function account(int $id, string $role, int $resellerId, string $username): void {
        TestAccounts::ensure($id, $role, $resellerId, $username);
        R::exec('UPDATE users SET password = ? WHERE id = ?', [password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), $id]);
    }

    /** A session of any account, read from the database; strong unless told otherwise. */
    protected function sessionOf(int $id, string $method = 'pwd+otp', array $claims = []): string {
        $row = R::getRow('SELECT username, role, reseller_id FROM users WHERE id = ?', [$id]);
        return $this->session($method, ['id' => $id, 'username' => $row['username'], 'role' => $row['role'], 'reseller_id' => (int) $row['reseller_id']] + $claims);
    }

    /** @return array{challenge_id: mixed, credential: array} a fresh assertion with $key for the step-up of $id */
    protected function stepupProof(SoftAuthenticator $key, string $token, int $id = 5, array $forge = []): array {
        $options = self::json($this->call('POST', "/v1/users/{$id}/passkeys/stepup", [], $token));
        $this->assertArrayHasKey('publicKey', $options, json_encode($options));

        return [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->assert($options['publicKey'], $forge), true),
        ];
    }

    protected function adminSession(): string {
        return $this->session('pwd+otp', ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'reseller_id' => 1]);
    }

    protected function managerSession(): string {
        return $this->session('pwd+otp', ['id' => 2, 'username' => 'boss', 'role' => 'manager', 'reseller_id' => 2]);
    }

    protected function denied(): int {
        return (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'security' AND action = 'denied'");
    }

    /** @return array<int, array> the decoded `data` of each security row of this event */
    protected function events(string $event): array {
        $rows = R::getAll("SELECT action, data FROM history WHERE object = 'security' ORDER BY id");
        $found = [];
        foreach ($rows as $row) {
            $data = json_decode($row['data'], true);
            if (($data['event'] ?? null) === $event) {
                $found[] = $data + ['action' => $row['action']];
            }
        }
        return $found;
    }
}
