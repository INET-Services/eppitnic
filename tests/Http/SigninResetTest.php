<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Persistence\Passkeys;
use Eppitnic\Support\PasswordPolicy;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * POST /v1/users/{id}/signin-reset: a manager's or admin's way to start a
 * user's sign-in over -- keys and code gone, a temporary password to change,
 * and the password allowed to register the keys again.
 */
final class SigninResetTest extends PasskeyHttpTestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->account(4, 'manager', 3, 'other');
        $this->account(6, 'user', 3, 'carol');
        $this->account(7, 'user', 2, 'bob');
    }

    private function reset(string $token, int $id = 5): ResponseInterface {
        return $this->call('POST', "/v1/users/{$id}/signin-reset", [], $token);
    }

    private function row(int $id = 5): array {
        return R::getRow('SELECT * FROM users WHERE id = ?', [$id]);
    }

    // -----------------------------------------------------------------
    // what it does
    // -----------------------------------------------------------------

    public function testItRevokesKeysAndCodeSetsATemporaryPasswordAndReopensEnrolment(): void {
        $this->keys(2);
        $this->withTotp();
        R::exec("UPDATE users SET totp_secret_pending = 'PENDING' WHERE id = 5");
        $this->requirement('passkey', open: false);

        $response = $this->reset($this->adminSession());
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], Passkeys::listForUser(5));
        $user = $this->row();
        $this->assertNull($user['totp_secret']);
        $this->assertNull($user['totp_secret_pending']);
        $this->assertSame(1, (int) $user['must_change_password']);
        $this->assertSame(1, (int) $user['passkey_enroll_open']);
        $this->assertSame('passkey', $user['signin_requirement'], 'the requirement stays');
        $this->assertSame([2, true, true], [$body['passkeys_revoked'], $body['totp_removed'], $body['enroll_open']]);
        $this->assertSame('alice', $body['users'][0]['username']);
    }

    public function testTheTemporaryPasswordIsReturnedOnceAndMeetsThePolicy(): void {
        $body = self::json($this->reset($this->adminSession()));

        $temporary = $body['temporary_password'];
        $this->assertTrue(PasswordPolicy::isAcceptable($temporary));
        $this->assertTrue(password_verify($temporary, $this->row()['password']));
        $this->assertFalse(password_verify(self::PASSWORD, $this->row()['password']), 'the old password is gone');
        $this->assertNotSame($temporary, self::json($this->reset($this->adminSession()))['temporary_password']);
    }

    public function testNeitherThePasswordNorItsHashReachesTheHistory(): void {
        $body = self::json($this->reset($this->adminSession()));

        $all = implode(' ', R::getCol('SELECT data FROM history'));
        $this->assertStringNotContainsString($body['temporary_password'], $all);
        $this->assertStringNotContainsString($this->row()['password'], $all);
    }

    public function testItIsRecordedForTheUserAndAsASecurityEvent(): void {
        $this->keys(1);
        $this->withTotp();

        $this->reset($this->adminSession());

        $row = R::getRow("SELECT user_id, data FROM history WHERE object = 'users' AND object_id = 5 AND data LIKE '%signin_reset%'");
        $data = json_decode($row['data'], true);
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame([1, true, 'PASSWORD_CHANGED'], [$data['passkeys_revoked'], $data['totp_removed'], $data['password']]);
        $event = $this->events('signin_reset')[0];
        $this->assertSame([5, 'alice', 1, true], [$event['target_id'], $event['target'], $event['passkeys_revoked'], $event['totp_removed']]);
        $this->assertSame(1, (int) R::getCell("SELECT user_id FROM history WHERE object = 'security' AND data LIKE '%signin_reset%'"));
    }

    public function testAModeThatAsksForNoKeyLeavesTheEnrolmentClosed(): void {
        foreach (['totp', 'none'] as $mode) {
            $this->requirement($mode);

            $body = self::json($this->reset($this->adminSession()));

            $this->assertFalse($body['enroll_open'], $mode);
            $this->assertSame(0, (int) $this->row()['passkey_enroll_open'], $mode);
        }
    }

    public function testBothKeyModesOpenIt(): void {
        foreach (['passkey', 'totp_or_passkey'] as $mode) {
            $this->requirement($mode, open: false);

            $this->reset($this->adminSession());

            $this->assertSame(1, (int) $this->row()['passkey_enroll_open'], $mode);
        }
    }

    // -----------------------------------------------------------------
    // sessions
    // -----------------------------------------------------------------

    public function testEveryExistingSessionOfTheUserEndsAtTheNextRequest(): void {
        [$key] = $this->keys(1);
        $this->requirement('passkey');
        $passkeySession = self::json($this->passkey($key))['token'];
        $passwordSession = $this->sessionOf(5, 'pwd+otp');
        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $passkeySession)->getStatusCode());

        $this->reset($this->adminSession());

        $gone = $this->call('GET', '/v1/users/me', [], $passkeySession);
        $this->assertSame(401, $gone->getStatusCode(), 'its key is gone');
        $pending = $this->call('GET', '/v1/users/me', [], $passwordSession);
        $this->assertSame(403, $pending->getStatusCode());
        $this->assertSame('Password change required', self::json($pending)['error']);
    }

    // -----------------------------------------------------------------
    // the way back in
    // -----------------------------------------------------------------

    public function testTheUserSignsInWithTheTemporaryPasswordChangesItAndRegistersAKey(): void {
        $this->keys(1);
        $this->withTotp();
        $this->requirement('passkey');
        $temporary = self::json($this->reset($this->adminSession()))['temporary_password'];

        $login = $this->password(['password' => $temporary]);
        $this->assertSame('password_change', self::json($login)['required']);

        $changed = $this->call('POST', '/v1/users/authenticate/password', [
            'username' => 'alice', 'password' => $temporary, 'new_password' => 'Brand-New-Passw0rd!',
        ]);
        $this->assertSame(403, $changed->getStatusCode());
        $this->assertSame('passkey_enrollment', self::json($changed)['required'], 'the password alone may register now');

        $key = new SoftAuthenticator();
        $creds = ['username' => 'alice', 'password' => 'Brand-New-Passw0rd!'];
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/enroll', $creds));
        $done = $this->call('PUT', '/v1/users/authenticate/passkey/enroll', $creds + [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey']), true),
        ]);

        $this->assertSame(200, $done->getStatusCode(), (string) $done->getBody());
        $this->assertSame('passkey', self::json($done)['amr']);
        $this->assertSame(0, (int) $this->row()['passkey_enroll_open'], 'the minimum is reached');
    }

    public function testTheEnrolmentIsClosedAgainOnceTheKeysAreBack(): void {
        $this->requirement('passkey');
        $temporary = self::json($this->reset($this->adminSession()))['temporary_password'];
        $this->call('POST', '/v1/users/authenticate/password', [
            'username' => 'alice', 'password' => $temporary, 'new_password' => 'Brand-New-Passw0rd!',
        ]);
        $creds = ['username' => 'alice', 'password' => 'Brand-New-Passw0rd!'];
        $key = new SoftAuthenticator();
        $options = self::json($this->call('POST', '/v1/users/authenticate/passkey/enroll', $creds));
        $this->call('PUT', '/v1/users/authenticate/passkey/enroll', $creds + [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey']), true),
        ]);
        R::exec('DELETE FROM passkeys');

        $again = $this->call('POST', '/v1/users/authenticate/passkey/enroll', $creds);

        $this->assertSame(403, $again->getStatusCode());
        $this->assertSame('signin_reset', self::json($again)['required']);
    }

    // -----------------------------------------------------------------
    // who may
    // -----------------------------------------------------------------

    public function testAManagerResetsAUserOfTheirResellerWithAStrongSession(): void {
        $this->keys(1);

        $this->assertSame(200, $this->reset($this->managerSession())->getStatusCode());
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAnAdminResetsAManagerAndAManagerResetsAUserButNotTheOtherWayRound(): void {
        $this->assertSame(200, $this->reset($this->adminSession(), 2)->getStatusCode());
        $this->assertSame(403, $this->reset($this->sessionOf(7, 'pwd+otp'), 2)->getStatusCode(), 'a user resets no one');
    }

    public function testNobodyResetsTheirOwn(): void {
        foreach ([[$this->adminSession(), 1], [$this->managerSession(), 2]] as [$token, $id]) {
            $response = $this->reset($token, $id);

            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('you cannot reset your own sign-in', self::json($response)['error']);
        }
        $this->assertSame(0, (int) $this->row(1)['must_change_password']);
    }

    public function testAManagerDoesNotReachAnAdminOrAnotherReseller(): void {
        $this->keys(1);

        $this->assertSame(403, $this->reset($this->managerSession(), 1)->getStatusCode(), 'an admin');
        $this->assertSame(403, $this->reset($this->managerSession(), 6)->getStatusCode(), 'another reseller\'s user');
        $this->assertSame(403, $this->reset($this->sessionOf(4), 5)->getStatusCode(), 'the other reseller\'s manager');
        $this->assertSame(1, Passkeys::countForUser(5));
        $this->assertSame(0, (int) $this->row()['must_change_password']);
    }

    public function testAnAdminResetsAnAdminAndUsersOfAnyReseller(): void {
        $this->account(3, 'admin', 1, 'rootb');

        $this->assertSame(200, $this->reset($this->adminSession(), 3)->getStatusCode());
        $this->assertSame(200, $this->reset($this->adminSession(), 6)->getStatusCode());
    }

    public function testAPlainUserResetsNoOne(): void {
        $this->assertSame(403, $this->reset($this->sessionOf(7, 'pwd'), 5)->getStatusCode());
        $this->assertSame(0, (int) $this->row()['must_change_password']);
    }

    public function testItNeedsAStrongSession(): void {
        $this->withTotp(2);
        $this->withTotp(1);

        $this->assertSame(403, $this->reset($this->sessionOf(2, 'pwd'))->getStatusCode());
        $this->assertSame(403, $this->reset($this->sessionOf(1, 'pwd'))->getStatusCode());
        $this->assertSame(0, (int) $this->row()['must_change_password']);
    }

    public function testAnUnknownUserIsNotFoundAndNoSessionIsRefused(): void {
        $this->assertSame(404, $this->reset($this->adminSession(), 99)->getStatusCode());
        $this->assertSame(401, $this->call('POST', '/v1/users/5/signin-reset')->getStatusCode());
    }
}
