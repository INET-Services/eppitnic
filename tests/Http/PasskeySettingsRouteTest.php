<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * GET and PUT /v1/settings/passkeys: the relying party and policy, for admins,
 * and what changing the relying party does to the keys already registered.
 */
final class PasskeySettingsRouteTest extends PasskeyHttpTestCase
{
    private function get(?string $token = null): ResponseInterface {
        return $this->call('GET', '/v1/settings/passkeys', [], $token ?? $this->adminSession());
    }

    private function put(array $body, ?string $token = null): ResponseInterface {
        return $this->call('PUT', '/v1/settings/passkeys', $body, $token ?? $this->adminSession());
    }

    public function testAnAdminReadsTheSettingAndWhatIsInUse(): void {
        $this->keys(2);
        $this->requirement('passkey');

        $body = self::json($this->get());

        $this->assertSame(
            ['rp_id' => 'localhost', 'origins' => ['http://localhost:5173'], 'type' => 'any', 'models' => [], 'extra_aaguids' => []],
            $body['passkeys']
        );
        $this->assertSame(['passkeys' => 2, 'users' => 1], $body['in_use']);
    }

    public function testNoOneElseReadsOrWritesIt(): void {
        foreach ([$this->managerSession(), $this->sessionOf(5, 'pwd')] as $token) {
            $this->assertSame(403, $this->get($token)->getStatusCode());
            $this->assertSame(403, $this->put(['type' => 'device_bound'], $token)->getStatusCode());
        }
        $this->assertSame(401, $this->call('GET', '/v1/settings/passkeys')->getStatusCode());
        $this->assertSame('any', Config::get('passkeys')['type']);
    }

    public function testWritingNeedsAStrongSession(): void {
        $this->withTotp(1);

        $response = $this->put(['type' => 'device_bound'], $this->sessionOf(1, 'pwd'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('MFA verification required', self::json($response)['error']);
        $this->assertSame('any', Config::get('passkeys')['type']);
    }

    public function testAnAdminChangesThePolicyAndItIsRecorded(): void {
        $response = $this->put(['type' => 'device_bound', 'extra_aaguids' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('device_bound', self::json($response)['passkeys']['type']);
        $this->assertSame('device_bound', Config::get('passkeys')['type']);
        $row = R::getRow("SELECT user_id, action, data FROM history WHERE object = 'passkeys'");
        $this->assertSame([1, 'update'], [(int) $row['user_id'], $row['action']]);
        $this->assertSame('device_bound', json_decode($row['data'], true)['changes']['type']);
    }

    public function testARefusedValueIsA400WithTheServicesMessage(): void {
        foreach ([
            ['rp_id' => '127.0.0.1'],
            ['type' => 'sometimes'],
            ['colour' => 'red'],
            ['origins' => ['http://example.com']],
            ['models' => 'yubikey5'],
        ] as $body) {
            $response = $this->put($body);

            $this->assertSame(400, $response->getStatusCode(), json_encode($body));
            $this->assertNotEmpty(self::json($response)['error']);
        }
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
    }

    public function testConfirmIsNotASettingAndAnythingButTrueIsNotConfirmation(): void {
        $this->keys(1);

        foreach ([['confirm' => 'yes'], ['confirm' => 1]] as $more) {
            $response = $this->put(['rp_id' => 'example.it', 'origins' => ['https://example.it']] + $more);
            $this->assertSame(400, $response->getStatusCode(), json_encode($more));
        }
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
        $this->assertSame(1, Passkeys::countForUser(5));
        $this->assertArrayNotHasKey('confirm', Config::get('passkeys'));
    }

    // -----------------------------------------------------------------
    // the relying party
    // -----------------------------------------------------------------

    public function testChangingTheRpIdWithKeysAsksForConfirmationAndChangesNothing(): void {
        $this->keys(2);
        $this->requirement('passkey');

        $response = $this->put(['rp_id' => 'example.it', 'origins' => ['https://example.it']]);
        $body = self::json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertTrue($body['confirm_required']);
        $this->assertSame(['passkeys' => 2, 'users' => 1], $body['in_use']);
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
        $this->assertSame(2, Passkeys::countForUser(5));
    }

    public function testConfirmingDeletesTheKeysOpensEnrolmentAndRecordsTheCount(): void {
        $this->keys(2);
        $this->requirement('passkey', open: false);

        $response = $this->put(['rp_id' => 'example.it', 'origins' => ['https://example.it'], 'confirm' => true]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('example.it', Config::get('passkeys')['rp_id']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
        $event = json_decode((string) R::getCell("SELECT data FROM history WHERE object = 'security' AND data LIKE '%passkeys_invalidated%'"), true);
        $this->assertSame([2, [5]], [$event['passkeys'], $event['users']]);
        $this->assertSame(['passkeys' => 0, 'users' => 1], self::json($response)['in_use']);
    }

    public function testClearingTheRpIdWhileKeyUsersExistNeedsConfirmationToo(): void {
        $this->requirement('totp_or_passkey');

        $refused = $this->put(['rp_id' => '', 'origins' => []]);
        $this->assertSame(400, $refused->getStatusCode());
        $this->assertTrue(self::json($refused)['confirm_required']);
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);

        $done = $this->put(['rp_id' => '', 'origins' => [], 'confirm' => true]);
        $this->assertSame(200, $done->getStatusCode());
        $this->assertSame('', Config::get('passkeys')['rp_id']);
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
    }

    public function testOtherChangesNeedNoConfirmationEvenWithKeys(): void {
        $this->keys(2);
        $this->requirement('passkey');

        $response = $this->put(['origins' => ['http://localhost:5173', 'http://localhost:5174'], 'type' => 'device_bound']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, Passkeys::countForUser(5));
    }

    public function testSwitchingPasskeysOffMakesAKeyRequirementImpossibleToSetAgain(): void {
        $this->put(['rp_id' => '', 'origins' => []]);

        $response = $this->call('PUT', '/v1/users/5', ['signin_requirement' => 'passkey'], $this->adminSession());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('passkeys are not enabled', self::json($response)['error']);
    }
}
