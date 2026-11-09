<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Api\Auth;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Auth::verify()'s remote-auth path: REMOTE_USER/REDIRECT_REMOTE_USER
 * (server mode) or a header from a `trusted_proxies` peer (header mode),
 * gated by `remote_auth.enabled`. A valid Bearer token always wins.
 */
final class AuthRemoteTest extends TestCase
{
    private function settings(array $remoteAuth, array $trustedProxies = []): array {
        return [
            'jwt_psk'         => 'test-signing-key-for-this-suite-only',
            'trusted_proxies' => $trustedProxies,
            'remote_auth'     => $remoteAuth,
        ];
    }

    private function seedUsers(): void {
        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS users');
        R::exec('DROP TABLE IF EXISTS resellers');
        R::exec("CREATE TABLE users (id INTEGER PRIMARY KEY, reseller_id INTEGER DEFAULT 1, role TEXT DEFAULT 'user',
                 username TEXT, active INTEGER DEFAULT 1, totp_secret TEXT,
                 must_change_password INTEGER DEFAULT 0, must_enroll_mfa INTEGER DEFAULT 0, signin_requirement TEXT NOT NULL DEFAULT 'none', passkey_enroll_open INTEGER DEFAULT 0, webauthn_handle BLOB, debug INTEGER DEFAULT 0, max_token_age INTEGER, token_lifetime INTEGER, max_idle_time INTEGER, last_activity TEXT)");
        R::exec("INSERT INTO users (id, username, role, active) VALUES
                 (1, 'admin', 'admin', 1), (2, 'someone', 'user', 1), (3, 'gone', 'user', 0)");
        TestAccounts::ensureReseller(1);
    }

    private function request(array $serverParams = [], ?string $authHeader = null): Request {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/users/me', $serverParams);
        return $authHeader !== null ? $request->withHeader('Authorization', $authHeader) : $request;
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testDisabledIgnoresRemoteUser(): void {
        Config::loadForTesting($this->settings(['enabled' => false, 'header' => null]));
        $this->seedUsers();

        $this->expectException(HttpUnauthorizedException::class);
        Auth::verify($this->request(['REMOTE_USER' => 'admin']));
    }

    public function testServerModeActiveUser(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $decoded = Auth::verify($this->request(['REMOTE_USER' => 'someone']));
        $this->assertSame(2, (int) $decoded->data->id);
        $this->assertSame('user', $decoded->data->role);
        $this->assertTrue($decoded->data->remote_auth);
        $this->assertFalse($decoded->data->has_totp);
        $this->assertFalse($decoded->data->needs_totp);
        $this->assertFalse($decoded->data->totp_verified);
    }

    public function testServerModeFallsBackToRedirectRemoteUser(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $decoded = Auth::verify($this->request(['REDIRECT_REMOTE_USER' => 'admin']));
        $this->assertSame(1, (int) $decoded->data->id);
    }

    public function testUnknownRemoteUserIs403(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $this->expectException(HttpForbiddenException::class);
        Auth::verify($this->request(['REMOTE_USER' => 'nobody']));
    }

    public function testInactiveRemoteUserIs403(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $this->expectException(HttpForbiddenException::class);
        $this->expectExceptionMessage('Your account is deactivated');
        Auth::verify($this->request(['REMOTE_USER' => 'gone']));
    }

    public function testEnabledWithNoRemoteUserIs401(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $this->expectException(HttpUnauthorizedException::class);
        Auth::verify($this->request([]));
    }

    public function testValidBearerJwtWinsOverRemoteUser(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $token = TestAccounts::issueToken(['id' => 99, 'username' => 'scripted', 'admin' => 0, 'has_totp' => false])['token'];
        $decoded = Auth::verify($this->request(['REMOTE_USER' => 'admin'], 'Bearer ' . $token));

        $this->assertSame(99, (int) $decoded->data->id);
        $this->assertArrayNotHasKey('remote_auth', (array) $decoded->data);
    }

    public function testBasicAuthorizationHeaderYieldsRemoteIdentity(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $decoded = Auth::verify($this->request(
            ['REMOTE_USER' => 'someone'],
            'Basic ' . base64_encode('someone:whatever')
        ));
        $this->assertSame(2, (int) $decoded->data->id);
        $this->assertTrue($decoded->data->remote_auth);
    }

    public function testHeaderModeFromATrustedPeer(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => 'X-Remote-User'], ['10.0.0.0/24']));
        $this->seedUsers();

        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('X-Remote-User', 'someone');
        $decoded = Auth::verify($request);
        $this->assertSame(2, (int) $decoded->data->id);
    }

    public function testHeaderModeFromAnUntrustedPeerIs401(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => 'X-Remote-User'], ['10.0.0.0/24']));
        $this->seedUsers();

        $request = $this->request(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('X-Remote-User', 'someone');
        $this->expectException(HttpUnauthorizedException::class);
        Auth::verify($request);
    }

    public function testHeaderModeIgnoresRemoteUser(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => 'X-Remote-User'], ['10.0.0.0/24']));
        $this->seedUsers();

        $request = $this->request(['REMOTE_ADDR' => '10.0.0.5', 'REMOTE_USER' => 'admin']);
        $this->expectException(HttpUnauthorizedException::class);
        Auth::verify($request);
    }

    public function testRemoteAdminPassesRequireAdmin(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $this->assertSame(1, Auth::requireAdmin($this->request(['REMOTE_USER' => 'admin'])));
    }

    public function testRemoteAdminPassesRequireMfa(): void {
        Config::loadForTesting($this->settings(['enabled' => true, 'header' => null]));
        $this->seedUsers();

        $decoded = Auth::requireMfa($this->request(['REMOTE_USER' => 'admin']));
        $this->assertSame(1, (int) $decoded->data->id);
    }
}
