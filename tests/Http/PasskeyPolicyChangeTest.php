<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Support\AuthenticatorModels;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;
use Symfony\Component\Clock\MockClock;

/**
 * Tightening the passkey policy: what the preview says, that the login does
 * as it said, that the change is refused when no admin could sign in
 * afterwards, and what it leaves alone. Alice (5) has no second factor; bob
 * (7), carol (8) and erin (9) have the authenticator app; dave (10) needs no
 * key; frank (11) is passkey-only with the enrolment open.
 */
final class PasskeyPolicyChangeTest extends PasskeyHttpTestCase
{
    private const BOUND = ['type' => 'device_bound'];

    /** @var array<int, SoftAuthenticator[]> */
    private array $keysOf = [];

    private function person(int $id, string $name, string $mode, int $keys, bool $totp = false, bool $open = false, string $role = 'user', int $reseller = 2): void {
        $this->account($id, $role, $reseller, $name);
        for ($i = 0; $i < $keys; $i++) {
            $this->keysOf[$id][] = $key = new SoftAuthenticator(aaguid: sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%08d%04d', $id, $i));
            $this->storedKey($key, [], $id);
        }
        $this->account($id, $role, $reseller, $name);
        $this->requirement($mode, $id, $open);
        if ($totp) {
            $this->withTotp($id);
        }
    }

    private function crowd(): void {
        $this->person(5, 'alice', 'passkey', 1);
        $this->person(7, 'bob', 'passkey', 1, totp: true);
        $this->person(8, 'carol', 'totp_or_passkey', 1, totp: true);
        $this->person(9, 'erin', 'passkey', 2, totp: true);
        $this->person(10, 'dave', 'totp', 1, totp: true);
        $this->person(11, 'frank', 'passkey', 1, open: true);
    }

    private function impact(array $body, ?string $token = null): ResponseInterface {
        return $this->call('POST', '/v1/settings/passkeys/impact', $body, $token ?? $this->adminSession());
    }

    private function put(array $body, ?string $token = null): ResponseInterface {
        return $this->call('PUT', '/v1/settings/passkeys', $body, $token ?? $this->adminSession());
    }

    /** @return array<string, array> by username */
    private function byName(array $impact): array {
        $users = [];
        foreach ($impact['users'] as $user) {
            $users[$user['username']] = $user;
        }
        return $users;
    }

    /** The session of admin 1 who signed in with their first key. */
    private function adminKey(): string {
        return $this->sessionOf(1, 'passkey', ['cred' => (int) Passkeys::listForUser(1)[0]['id']]);
    }

    private function login(string $name, bool $withCode): ResponseInterface {
        $id = (int) R::getCell('SELECT id FROM users WHERE username = ?', [$name]);
        $this->totpSecret = (string) R::getCell('SELECT totp_secret FROM users WHERE id = ?', [$id]) ?: $this->totpSecret;
        return $this->call('POST', '/v1/users/authenticate', ['username' => $name, 'password' => self::PASSWORD] + ($withCode ? ['totp' => $this->code()] : []));
    }

    // -----------------------------------------------------------------
    // the preview
    // -----------------------------------------------------------------

    public function testThePreviewSaysWhoLosesWhatAndChangesNothing(): void {
        $this->crowd();

        $response = $this->impact(self::BOUND);
        $impact = self::json($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(['keys_total', 'keys_noncompliant', 'users', 'admins_able_after'], array_keys($impact));
        $this->assertSame(7, $impact['keys_total']);
        $this->assertSame(7, $impact['keys_noncompliant']);
        $users = $this->byName($impact);
        $this->assertSame(
            ['id', 'username', 'role', 'signin_requirement', 'compliant_before', 'compliant_after', 'minimum', 'outcome'],
            array_keys($users['alice'])
        );
        $outcomes = array_map(static fn(array $u): string => $u['outcome'], $users);
        ksort($outcomes);
        $this->assertSame(
            ['alice' => 'locked_out', 'bob' => 'needs_enrolment', 'carol' => 'needs_enrolment', 'dave' => 'ok', 'erin' => 'needs_enrolment', 'frank' => 'needs_enrolment'],
            $outcomes
        );
        $this->assertSame(['locked_out', 'needs_enrolment', 'needs_enrolment', 'needs_enrolment', 'needs_enrolment', 'ok'], array_column($impact['users'], 'outcome'));
        $this->assertSame([1, 0, 1], [$users['alice']['compliant_before'], $users['alice']['compliant_after'], $users['alice']['minimum']]);
        $this->assertSame([2, 0, 1], [$users['erin']['compliant_before'], $users['erin']['compliant_after'], $users['erin']['minimum']]);
        $this->assertSame([0, 'user', 'totp'], [$users['dave']['minimum'], $users['dave']['role'], $users['dave']['signin_requirement']]);
        $this->assertSame('locked_out', $impact['users'][0]['outcome'], 'the worst first');
        $this->assertSame(1, $impact['admins_able_after']);

        $this->assertSame('any', Config::get('passkeys')['type']);
        $this->assertSame(7, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'passkeys'"));
    }

    public function testAKeyThatStillCompliesIsNotAffected(): void {
        $this->person(5, 'alice', 'passkey', 2);
        $this->person(7, 'bob', 'passkey', 1);
        R::exec('UPDATE passkeys SET attestation_verified = 1 WHERE user_id = 5 AND id = 1');
        $this->metadata([sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%08d%04d', 5, 0) => []]);

        $impact = self::json($this->impact(self::BOUND));

        $this->assertSame(3, $impact['keys_total']);
        $this->assertSame(2, $impact['keys_noncompliant']);
        $users = $this->byName($impact);
        $this->assertSame([2, 1, 'ok'], [$users['alice']['compliant_before'], $users['alice']['compliant_after'], $users['alice']['outcome']]);
        $this->assertSame('locked_out', $users['bob']['outcome']);
    }

    public function testNarrowingTheModelsCountsToo(): void {
        $this->person(5, 'alice', 'passkey', 1, totp: true);
        R::exec('UPDATE passkeys SET attestation_verified = 1');
        $this->metadata();
        Config::set('passkeys', self::BOUND + Config::get('passkeys'));
        $this->assertSame(1, PasskeyPolicy::compliantCount(5));

        $impact = self::json($this->impact(['models' => ['yubikey5']]));

        $this->assertSame(1, $impact['keys_noncompliant']);
        $this->assertSame('needs_enrolment', $impact['users'][0]['outcome']);
    }

    public function testNothingToChangeAffectsNobody(): void {
        $this->crowd();

        foreach ([[], ['type' => 'any'], ['confirm' => true]] as $body) {
            $impact = self::json($this->impact($body));

            $this->assertSame(['keys_total' => 7, 'keys_noncompliant' => 0, 'users' => [], 'admins_able_after' => 1], $impact);
        }
    }

    public function testAnInvalidProposalIsA400WithTheReason(): void {
        foreach ([['type' => 'synced'], ['models' => ['nosuch']], ['rp_id' => '127.0.0.1'], ['colour' => 'red']] as $body) {
            $response = $this->impact($body);

            $this->assertSame(400, $response->getStatusCode(), json_encode($body));
            $this->assertNotEmpty(self::json($response)['error']);
        }
    }

    public function testOnlyAnAdminPreviews(): void {
        foreach ([$this->managerSession(), $this->sessionOf(5, 'pwd')] as $token) {
            $this->assertSame(403, $this->impact(self::BOUND, $token)->getStatusCode());
        }
        $this->assertSame(401, $this->call('POST', '/v1/settings/passkeys/impact', self::BOUND)->getStatusCode());
    }

    public function testThePreviewNeedsAStrongSessionLikeTheChange(): void {
        $this->withTotp(1);

        $this->assertSame(403, $this->impact(self::BOUND, $this->sessionOf(1, 'pwd'))->getStatusCode());
    }

    // -----------------------------------------------------------------
    // the login does as the preview said
    // -----------------------------------------------------------------

    public function testTheLoginDoesAsThePreviewSaid(): void {
        $this->crowd();
        $preview = $this->byName(self::json($this->impact(self::BOUND)));

        $this->assertSame(200, $this->put(self::BOUND)->getStatusCode());

        $counts = PasskeyPolicy::countsFor([5, 7, 8, 9, 10, 11]);
        foreach ($preview as $name => $user) {
            $this->assertSame($user['compliant_after'], $counts[$user['id']] ?? 0, $name);
        }
        // alice has nothing to prove herself with, and the password cannot register a key for her
        $alice = $this->login('alice', false);
        $this->assertSame([403, 'signin_reset'], [$alice->getStatusCode(), self::json($alice)['required']]);
        // bob, carol and erin have their code: it lets them register one
        foreach (['bob', 'carol', 'erin'] as $name) {
            $response = $this->login($name, true);
            $this->assertSame([403, 'passkey_enrollment'], [$response->getStatusCode(), self::json($response)['required']], $name);
        }
        // frank's enrolment is open
        $frank = $this->login('frank', false);
        $this->assertSame([403, 'passkey_enrollment'], [$frank->getStatusCode(), self::json($frank)['required']]);
        // dave never needed a key
        $this->assertSame(200, $this->login('dave', true)->getStatusCode());
    }

    public function testTighteningNeverOpensTheEnrolmentOfAnyone(): void {
        $this->crowd();

        $this->put(self::BOUND);

        $this->assertSame(
            [5 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 1],
            array_map('intval', array_column(R::getAll('SELECT id, passkey_enroll_open FROM users WHERE id > 4'), 'passkey_enroll_open', 'id'))
        );
    }

    public function testTighteningEndsTheSessionAndTheSignInOfAKeyThatNoLongerComplies(): void {
        [$key] = $this->keys(1);
        $this->requirement('passkey', open: false);
        $token = self::json($this->passkey($key))['token'];
        $this->assertSame(200, $this->call('GET', '/v1/users/me', [], $token)->getStatusCode());

        $this->assertSame(200, $this->put(self::BOUND)->getStatusCode());

        $session = $this->call('GET', '/v1/users/5/passkeys', [], $token);
        $this->assertSame([401, 'Session ended: passkey no longer valid'], [$session->getStatusCode(), self::json($session)['error']]);
        $signIn = $this->passkey($key);
        $this->assertSame(401, $signIn->getStatusCode());
        $this->assertCount(1, Passkeys::listForUser(5), 'the key stays on file');
        $this->assertSame('key does not meet the passkey policy', $this->events('login_failed')[0]['reason']);
    }

    // -----------------------------------------------------------------
    // the guard
    // -----------------------------------------------------------------

    public function testTheChangeIsRefusedWhenNoAdminCouldSignInAfterwards(): void {
        $this->person(1, 'admin', 'passkey', 1, role: 'admin', reseller: 1);

        $response = $this->put(self::BOUND + ['models' => ['yubikey5']], $this->adminKey());
        $body = self::json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('No active admin could sign in after this change; nothing was saved', $body['error']);
        $this->assertSame(0, $body['impact']['admins_able_after']);
        $this->assertSame('locked_out', $body['impact']['users'][0]['outcome']);
        $this->assertSame('admin', $body['impact']['users'][0]['username']);
        $this->assertSame('any', Config::get('passkeys')['type']);
        $this->assertSame([], Config::get('passkeys')['models']);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'passkeys'"));
        $this->assertSame([], $this->events('passkey_policy_changed'));
    }

    public function testAnotherAdminWhoCanSignInLetsItThrough(): void {
        $this->person(1, 'admin', 'passkey', 1, role: 'admin', reseller: 1);
        $this->person(3, 'second', 'none', 0, role: 'admin', reseller: 1);

        $response = $this->put(self::BOUND, $this->adminKey());

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, self::json($response)['impact']['admins_able_after']);
        $this->assertSame('device_bound', Config::get('passkeys')['type']);
    }

    public function testAnAdminWhoCanRegisterAKeyHimselfCountsAsAble(): void {
        $this->person(1, 'admin', 'passkey', 1, totp: true, role: 'admin', reseller: 1);

        $response = $this->put(self::BOUND, $this->adminKey());

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('needs_enrolment', self::json($response)['impact']['users'][0]['outcome']);
    }

    public function testAnInactiveAdminIsNoOneToCountOn(): void {
        $this->person(1, 'admin', 'passkey', 1, role: 'admin', reseller: 1);
        $this->person(3, 'second', 'none', 0, role: 'admin', reseller: 1);
        R::exec('UPDATE users SET active = 0 WHERE id = 3');

        $this->assertSame(400, $this->put(self::BOUND, $this->adminKey())->getStatusCode());
    }

    public function testManagersAndUsersAreNoSubstituteForAnAdmin(): void {
        $this->person(1, 'admin', 'passkey', 1, role: 'admin', reseller: 1);

        $this->assertSame(0, self::json($this->impact(self::BOUND, $this->adminKey()))['admins_able_after']);
    }

    public function testClearingTheRelyingPartyWouldLockOutAPasskeyOnlyAdmin(): void {
        $this->person(1, 'admin', 'passkey', 1, role: 'admin', reseller: 1);

        $response = $this->put(['rp_id' => '', 'origins' => [], 'confirm' => true], $this->adminKey());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, self::json($response)['impact']['admins_able_after']);
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
        $this->assertSame(1, Passkeys::countForUser(1));
    }

    public function testANewRelyingPartyLeavesEveryoneAWayToRegisterAgain(): void {
        $this->crowd();
        $this->person(1, 'admin', 'passkey', 1, role: 'admin', reseller: 1);

        $preview = self::json($this->impact(['rp_id' => 'example.it', 'origins' => ['https://example.it']], $this->adminKey()));

        $this->assertSame(8, $preview['keys_noncompliant'], 'every key goes');
        $outcomes = array_map(static fn(array $u): string => $u['outcome'], $this->byName($preview));
        $this->assertSame('needs_enrolment', $outcomes['alice'], 'the enrolment opens for everyone who needs keys');
        $this->assertSame('needs_enrolment', $outcomes['admin']);
        $this->assertSame('ok', $outcomes['dave']);
        $this->assertSame(1, $preview['admins_able_after']);

        $this->assertSame(200, $this->put(['rp_id' => 'example.it', 'origins' => ['https://example.it'], 'confirm' => true], $this->adminKey())->getStatusCode());
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
    }

    public function testTheConfirmationIsStillAskedForAfterTheGuard(): void {
        $this->crowd();

        $response = $this->put(['rp_id' => 'example.it', 'origins' => ['https://example.it']]);
        $body = self::json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertTrue($body['confirm_required']);
        $this->assertSame(7, $body['impact']['keys_noncompliant']);
        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
    }

    // -----------------------------------------------------------------
    // the record
    // -----------------------------------------------------------------

    public function testTheChangeIsRecordedAsASecurityEventWithCounts(): void {
        $this->crowd();

        $this->put(self::BOUND + ['models' => ['yubikey5']]);

        $events = $this->events('passkey_policy_changed');
        $this->assertCount(1, $events);
        $this->assertSame(['type', 'models'], $events[0]['changes']);
        $this->assertSame(
            ['keys_noncompliant' => 7, 'users_affected' => 6, 'needs_enrolment' => 4, 'locked_out' => 1, 'admins_able_after' => 1],
            array_intersect_key($events[0], array_flip(['keys_noncompliant', 'users_affected', 'needs_enrolment', 'locked_out', 'admins_able_after']))
        );
        $this->assertSame('secread', $events[0]['action']);
        $this->assertSame(1, (int) R::getCell("SELECT user_id FROM history WHERE object = 'security' AND data LIKE '%passkey_policy_changed%'"));
    }

    public function testARefusedValueRecordsNoEvent(): void {
        $this->put(['type' => 'synced']);

        $this->assertSame([], $this->events('passkey_policy_changed'));
    }

    // -----------------------------------------------------------------
    // the page
    // -----------------------------------------------------------------

    public function testTheSettingsCarryTheCatalogueAndTheStateOfTheMetadata(): void {
        $body = self::json($this->call('GET', '/v1/settings/passkeys', [], $this->adminSession()));

        $this->assertSame(AuthenticatorModels::catalogue(), $body['catalogue']);
        $this->assertSame('YubiKey 5 Series', $body['catalogue']['security_keys'][0]['label']);
        $this->assertSame('missing', $body['metadata']['state']);
        $this->assertSame(['state', 'entries', 'overdue', 'serial', 'next_update', 'fetched_at'], array_keys($body['metadata']));
    }

    public function testTheMetadataStateFollowsTheData(): void {
        $this->metadata([], [], date('Y-m-d', time() + 5 * 86400), 42);
        $get = fn(): array => self::json($this->call('GET', '/v1/settings/passkeys', [], $this->adminSession()))['metadata'];

        $this->assertSame(['ok', 42, 1, false], [$get()['state'], $get()['serial'], $get()['entries'], $get()['overdue']]);

        PasskeyMetadata::useClock(new MockClock(new \DateTimeImmutable('+7 days')));
        $this->assertSame(['ok', true], [$get()['state'], $get()['overdue']]);

        PasskeyMetadata::useClock(new MockClock(new \DateTimeImmutable('+14 days')));
        $this->assertSame('stale', $get()['state']);
    }

    public function testAKnownModelIsAcceptedAndAnUnknownOneIsNot(): void {
        $this->assertSame(200, $this->put(['models' => ['windows_hello', 'yubikey_bio']])->getStatusCode());
        $this->assertSame(['windows_hello', 'yubikey_bio'], Config::get('passkeys')['models']);

        $response = $this->put(['models' => ['icloud_keychain']]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('not a known authenticator model', self::json($response)['error']);
    }

    public function testASavedChangeAnswersWithTheSettingsAndTheImpact(): void {
        $body = self::json($this->put(self::BOUND));

        $this->assertSame('device_bound', $body['passkeys']['type']);
        $this->assertSame(['passkeys', 'users'], array_keys($body['in_use']));
        $this->assertArrayHasKey('catalogue', $body);
        $this->assertSame('missing', $body['metadata']['state']);
        $this->assertSame(0, $body['impact']['keys_noncompliant']);
    }
}
