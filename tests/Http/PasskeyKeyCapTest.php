<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * A user holds at most 10 passkeys, compliant or not.
 */
final class PasskeyKeyCapTest extends PasskeyHttpTestCase
{
    /** @var SoftAuthenticator[] */
    private array $keys;

    private function options(): ResponseInterface {
        return $this->call('POST', '/v1/users/5/passkeys/options', [], $this->sessionOf(5, 'pwd'));
    }

    private function add(SoftAuthenticator $key, array $options, SoftAuthenticator $proof): ResponseInterface {
        $token = $this->sessionOf(5, 'pwd');
        return $this->call('POST', '/v1/users/5/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey']), true),
            'stepup'       => $this->stepupProof($proof, $token),
        ], $token);
    }

    public function testTheTenthKeyIsRegisteredAndTheEleventhIsNot(): void {
        $this->keys = $this->keys(9);
        $options = self::json($this->options());
        $tenth = new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-000000000010');

        $this->assertSame(201, $this->add($tenth, $options, $this->keys[0])->getStatusCode());
        $this->assertCount(10, Passkeys::listForUser(5));

        $response = $this->options();
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('limit', self::json($response)['reason']);
        $this->assertStringContainsString('at most 10 passkeys', self::json($response)['error']);
        $this->assertSame(0, $this->denied());
    }

    public function testNoChallengeIsMadeForAUserAtTheLimit(): void {
        $this->keys(10);
        $before = (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges');

        $this->options();

        $this->assertSame($before, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testTheLimitIsHeldWhenTheKeyArrivesTooLate(): void {
        $this->keys = $this->keys(9);
        $options = self::json($this->options());
        $this->keys(1);

        $response = $this->add(new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-000000000011'), $options, $this->keys[0]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('limit', self::json($response)['reason']);
        $this->assertCount(10, Passkeys::listForUser(5));
        $this->assertSame('limit', $this->events('passkey_registration_failed')[0]['reason']);
    }

    public function testKeysThatNoLongerCompleteCountToo(): void {
        $this->keys(10);
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->metadata();

        $response = $this->options();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('limit', self::json($response)['reason']);
    }

    public function testAKeyRemovedMakesRoomAgain(): void {
        $this->keys(10);
        Passkeys::delete((int) Passkeys::listForUser(5)[0]['id']);

        $this->assertSame(200, $this->options()->getStatusCode());
    }

    public function testAnotherUsersKeysAreNotMine(): void {
        $this->account(7, 'user', 2, 'bob');
        for ($i = 0; $i < 10; $i++) {
            $this->storedKey(new SoftAuthenticator(), [], 7);
        }
        $this->account(7, 'user', 2, 'bob');

        $this->assertSame(200, $this->options()->getStatusCode());
        $this->assertTrue(Passkeys::atLimit(7));
        $this->assertFalse(Passkeys::atLimit(5));
    }

    public function testTheStorageRefusesAnElevenAsWell(): void {
        $this->keys(10);
        [$registration] = $this->registered(new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-000000000012'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Passkeys::LIMIT_MESSAGE);
        Passkeys::add(5, $registration, 'One too many');
    }

    public function testTheEnrolmentBeforeASessionHoldsTheLimitToo(): void {
        $this->keys(10);
        R::exec('UPDATE passkeys SET attestation_verified = 0');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->metadata();
        $this->requirement('passkey');

        $response = $this->call('POST', '/v1/users/authenticate/passkey/enroll', ['username' => 'alice', 'password' => self::PASSWORD]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('limit', self::json($response)['reason']);
    }

    public function testAUserAtTheLimitWithNoKeyThatCountsIsLockedOutByThePreview(): void {
        $this->keys(10);
        $this->requirement('passkey', 5, true);
        $this->withTotp();

        $impact = self::json($this->call('POST', '/v1/settings/passkeys/impact', ['type' => 'device_bound'], $this->adminSession()));

        $this->assertSame('locked_out', $impact['users'][0]['outcome'], 'no room for a key that would count');
    }
}
