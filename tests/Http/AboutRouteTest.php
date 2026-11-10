<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Api\Middleware;
use Eppitnic\Config;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/** `GET /v1/about` -- identity, licence and the runtime supply chain. */
final class AboutRouteTest extends TestCase
{
    private const SETTINGS = [
        'jwt_psk'         => 'test-signing-key-for-this-suite-only',
        'trusted_proxies' => [],
        'login_ratelimit' => ['max_failures' => 10, 'timespan' => 900, 'ipv4_prefix' => 24, 'ipv6_prefix' => 64],
        'remote_auth'     => ['enabled' => false, 'header' => null],
    ];

    private function get(bool $authenticated): ResponseInterface {
        Config::loadForTesting(self::SETTINGS);

        $app = AppFactory::create();
        Middleware::register($app);
        require EPPITNIC_ROOT . '/src/Api/Routes/about.php';

        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/about');
        if ($authenticated) {
            $token = TestAccounts::issueToken([
                'id' => 7, 'username' => 'someone', 'admin' => 0, 'has_totp' => false, 'max_token_age' => 60,
            ])['token'];
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        return $app->handle($request);
    }

    protected function tearDown(): void {
        Config::reset();
        parent::tearDown();
    }

    public function testRequiresAToken(): void {
        $this->assertSame(401, $this->get(false)->getStatusCode());
    }

    public function testAPlainUserGetsTheIdentityAndTheRuntimePackages(): void {
        $response = $this->get(true);
        $body = (array) json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('eppitnic', $body['name']);
        $this->assertSame(APP_VERSION, $body['version']);
        $this->assertSame('BSD-3-Clause', $body['license']);
        $this->assertStringStartsWith('Copyright (c)', $body['copyright']);
        $this->assertArrayHasKey('commit', $body);
        $this->assertArrayHasKey('built', $body);

        $names = array_column($body['dependencies'], 'name');
        $this->assertContains('slim/slim', $names);
        $this->assertNotContains('phpunit/phpunit', $names);
        $this->assertSame($names, (function (array $n) { sort($n); return $n; })($names));

        $this->assertNotContains('kevinoo/phpwhois', $names);

        $slim = $body['dependencies'][array_search('slim/slim', $names, true)];
        $this->assertSame(['name', 'version', 'license'], array_keys($slim));
        $this->assertSame('MIT', $slim['license']);
    }
}
