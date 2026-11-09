<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * A user's passkeys over the API: who may list them, who may add one (only the
 * user), rename it, and remove it -- and the minimum no removal may go below.
 * Accounts: 1 admin, 2 manager (reseller 2), 5 alice (reseller 2), 7 bob
 * (reseller 2), 4 and 6 a manager and a user of reseller 3.
 */
final class PasskeyManagementTest extends PasskeyHttpTestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->account(7, 'user', 2, 'bob');
        $this->account(4, 'manager', 3, 'other');
        $this->account(6, 'user', 3, 'carol');
    }

    private function list(string $token, int $id = 5): ResponseInterface {
        return $this->call('GET', "/v1/users/{$id}/passkeys", [], $token);
    }

    /** @param mixed $stepup the proof; alice has neither key nor code, so her password */
    private function add(SoftAuthenticator $key, string $token, mixed $stepup, array $more = [], array $forge = [], int $id = 5): ResponseInterface {
        $options = self::json($this->call('POST', "/v1/users/{$id}/passkeys/options", [], $token));
        $this->assertArrayHasKey('publicKey', $options, json_encode($options));

        return $this->call('POST', "/v1/users/{$id}/passkeys", [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey'], $forge), true),
            'stepup'       => $stepup,
        ] + $more, $token);
    }

    private function unlimited(): void {
        Config::set('login_ratelimit', ['max_failures' => 0] + Config::get('login_ratelimit'));
    }

    private function remove(string $token, int $pid, mixed $stepup = null, int $id = 5): ResponseInterface {
        return $this->call('DELETE', "/v1/users/{$id}/passkeys/{$pid}", $stepup === null ? [] : ['stepup' => $stepup], $token);
    }

    // -----------------------------------------------------------------
    // reading
    // -----------------------------------------------------------------

    public function testAUserListsTheirOwnKeysWithoutTheSecrets(): void {
        $this->keys(2);
        R::exec("UPDATE passkeys SET last_used_at = '2026-11-01 10:00:00', backup_eligible = 1 WHERE id = 1");

        $response = $this->list($this->sessionOf(5, 'pwd'));
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['passkeys']);
        $first = $body['passkeys'][0];
        $this->assertSame(
            ['id', 'name', 'aaguid', 'model', 'backup_eligible', 'backup_state', 'attestation_format', 'attestation_verified', 'compliant', 'created', 'last_used'],
            array_keys($first)
        );
        $this->assertSame([1, 'Test key', null, true, false, 'none', false, true], [
            $first['id'], $first['name'], $first['model'], $first['backup_eligible'], $first['backup_state'],
            $first['attestation_format'], $first['attestation_verified'], $first['compliant'],
        ]);
        $this->assertSame('2026-11-01 10:00:00', $first['last_used']);
        $this->assertNull($body['passkeys'][1]['last_used']);
        $this->assertNotEmpty($first['created']);

        $raw = (string) $response->getBody();
        foreach (Passkeys::listForUser(5) as $row) {
            $this->assertStringNotContainsString(base64_encode($row['credential_id']), $raw);
            $this->assertStringNotContainsString(rtrim(strtr(base64_encode($row['credential_id']), '+/', '-_'), '='), $raw);
        }
        $this->assertStringNotContainsString('"source"', $raw);
        $this->assertStringNotContainsString('credential_id', $raw);
    }

    public function testTheOverviewStatesTheRequirementTheMinimumAndTheOpenEnrolment(): void {
        $this->requirement('passkey', open: true);

        $body = self::json($this->list($this->adminSession()));

        $this->assertSame('passkey', $body['signin_requirement']);
        $this->assertSame(1, $body['minimum']);
        $this->assertSame(0, $body['compliant_count']);
        $this->assertTrue($body['enroll_open']);
        $this->assertTrue($body['passkeys_available']);
    }

    public function testTheUserIsToldWhatProvesTheirStepUp(): void {
        $token = $this->sessionOf(5, 'pwd');
        $this->assertSame('password', self::json($this->list($token))['stepup']);

        $this->withTotp();
        $this->assertSame('totp', self::json($this->list($token))['stepup']);

        $this->keys(1);
        $this->assertSame('passkey', self::json($this->list($token))['stepup']);
    }

    public function testTheMinimumFollowsTheMode(): void {
        foreach (['passkey' => 1, 'totp_or_passkey' => 1, 'totp' => 0, 'none' => 0] as $mode => $minimum) {
            $this->requirement($mode);
            $this->assertSame($minimum, self::json($this->list($this->adminSession()))['minimum'], $mode);
        }
    }

    public function testAKeyThatBreaksThePolicyIsMarkedAndNotCounted(): void {
        $this->keys(2);
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $body = self::json($this->list($this->sessionOf(5, 'pwd')));

        $this->assertSame([false, false], array_column($body['passkeys'], 'compliant'));
        $this->assertSame(0, $body['compliant_count']);
    }

    public function testThePasskeysAreReportedOffWhileDisabled(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $this->assertFalse(self::json($this->list($this->sessionOf(5, 'pwd')))['passkeys_available']);
    }

    public function testAManagerAndAnAdminListTheKeysOfAUserInScope(): void {
        $this->keys(2);

        foreach ([$this->managerSession(), $this->adminSession()] as $token) {
            $response = $this->list($token);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertCount(2, self::json($response)['passkeys']);
            $this->assertArrayNotHasKey('stepup', self::json($response), 'the proof is the owner\'s business');
        }
    }

    public function testNobodyElseListsThem(): void {
        $this->keys(1);
        $this->account(3, 'admin', 1, 'rootb');

        $refused = [
            'another user of the reseller' => $this->sessionOf(7, 'pwd'),
            'the manager of another reseller' => $this->sessionOf(4),
            'a user of another reseller' => $this->sessionOf(6, 'pwd'),
        ];
        foreach ($refused as $who => $token) {
            $this->assertSame(403, $this->list($token)->getStatusCode(), $who);
        }
        $this->assertSame(403, $this->list($this->managerSession(), 1)->getStatusCode(), 'a manager does not reach an admin');
        $this->assertSame(200, $this->list($this->sessionOf(3))->getStatusCode(), 'an admin reaches everyone');
        $this->assertSame(200, $this->list($this->sessionOf(3), 2)->getStatusCode());
    }

    public function testAnAdminAsksForAnUnknownUser(): void {
        $this->assertSame(404, $this->list($this->adminSession(), 99)->getStatusCode());
    }

    public function testAManagerNeedsAStrongSession(): void {
        $this->withTotp(2);

        $this->assertSame(403, $this->list($this->sessionOf(2, 'pwd'))->getStatusCode());
        $this->assertSame(200, $this->list($this->sessionOf(2))->getStatusCode());
    }

    public function testThereIsNoListWithoutASession(): void {
        $this->assertSame(401, $this->call('GET', '/v1/users/5/passkeys')->getStatusCode());
    }

    // -----------------------------------------------------------------
    // adding: the user's own, with a step-up
    // -----------------------------------------------------------------

    public function testTheOptionsExcludeTheKeysAlreadyRegisteredAndNameTheChallenge(): void {
        $this->keys(2);
        $token = $this->sessionOf(5, 'pwd');

        $response = $this->call('POST', '/v1/users/5/passkeys/options', [], $token);
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('alice', $body['publicKey']['user']['name']);
        $this->assertSame('none', $body['publicKey']['attestation']);
        $this->assertCount(2, $body['publicKey']['excludeCredentials']);
        $row = R::getRow('SELECT purpose, user_id, context FROM webauthn_challenges WHERE id = ?', [$body['challenge_id']]);
        $this->assertSame(['register', 5, 'session'], [$row['purpose'], (int) $row['user_id'], $row['context']]);
    }

    public function testTheOptionsAskForAttestationWhenTheTypeIsDeviceBound(): void {
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->metadata();

        $body = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $this->sessionOf(5, 'pwd')));

        $this->assertSame('direct', $body['publicKey']['attestation']);
    }

    public function testOnlyTheUserGetsOptionsForTheirOwnAccount(): void {
        foreach ([$this->managerSession(), $this->adminSession(), $this->sessionOf(7, 'pwd')] as $token) {
            $this->assertSame(403, $this->call('POST', '/v1/users/5/passkeys/options', [], $token)->getStatusCode());
        }
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'register'"));
    }

    public function testNoOptionsWhilePasskeysAreOff(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $response = $this->call('POST', '/v1/users/5/passkeys/options', [], $this->sessionOf(5, 'pwd'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(self::json($response)['passkeys_available']);
    }

    public function testAKeyIsAddedAfterTheStepUpWithThePassword(): void {
        $key = new SoftAuthenticator();

        $response = $this->add($key, $this->sessionOf(5, 'pwd'), ['password' => self::PASSWORD], ['name' => 'Laptop']);
        $body = self::json($response);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('Laptop', $body['passkey']['name']);
        $this->assertCount(1, $body['passkeys']);
        $this->assertSame(1, $body['compliant_count']);
        $this->assertSame('Laptop', Passkeys::listForUser(5)[0]['name']);
        $this->assertSame((int) Passkeys::listForUser(5)[0]['id'], $body['passkey']['id']);
        $this->assertSame(5, (int) R::getCell("SELECT user_id FROM history WHERE object = 'users' AND object_id = 5 AND data LIKE '%passkey_registered%'"));
        $this->assertSame($body['passkey']['id'], $this->events('passkey_registered')[0]['credential']);
        $this->assertSame(0, $this->denied());
    }

    public function testTheKeyGetsADefaultNameAndAnotherCanFollow(): void {
        $token = $this->sessionOf(5, 'pwd');
        $first = new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001');
        $this->add($first, $token, ['password' => self::PASSWORD]);

        // with a key, the key is the proof
        $this->add(new SoftAuthenticator(aaguid: 'aaaaaaaa-bbbb-4ccc-8ddd-000000000002'), $token, $this->stepupProof($first, $token));

        $this->assertSame(['Passkey 1', 'Passkey 2'], array_column(Passkeys::listForUser(5), 'name'));
    }

    public function testTheStepUpIsRequiredAndMustBeRight(): void {
        $this->unlimited();
        $token = $this->sessionOf(5, 'pwd');

        foreach ([null, [], ['password' => 'wrong'], ['password' => ''], ['totp' => '123456'], 'text'] as $proof) {
            $response = $this->add(new SoftAuthenticator(), $token, $proof);

            $this->assertSame(401, $response->getStatusCode(), json_encode($proof));
            $this->assertSame('password', self::json($response)['stepup']);
        }
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testOnlyTheUserAddsAKey(): void {
        $user = $this->sessionOf(5, 'pwd');
        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $user));
        $body = [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode((new SoftAuthenticator())->register($options['publicKey']), true),
            'stepup'       => ['password' => self::PASSWORD],
        ];

        foreach ([$this->managerSession(), $this->adminSession(), $this->sessionOf(7, 'pwd')] as $token) {
            $this->assertSame(403, $this->call('POST', '/v1/users/5/passkeys', $body, $token)->getStatusCode());
        }
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAKeyThatBreaksThePolicyIsRefused(): void {
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->metadata();

        $response = $this->add(new SoftAuthenticator(), $this->sessionOf(5, 'pwd'), ['password' => self::PASSWORD]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('policy', self::json($response)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testTheSameKeyCannotBeAddedTwiceNorAnotherUsersKey(): void {
        [$mine] = $this->keys(1);
        $token = $this->sessionOf(5, 'pwd');
        $this->withTotp();

        $again = $this->add($mine, $token, $this->stepupProof($mine, $token));

        $this->assertSame(400, $again->getStatusCode());
        $this->assertSame('This passkey is already registered', self::json($again)['error']);
        $this->assertCount(1, Passkeys::listForUser(5));
    }

    public function testAChallengeOfAnotherUserOrPurposeIsRefused(): void {
        $token = $this->sessionOf(5, 'pwd');
        $key = new SoftAuthenticator();
        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $token));

        $others = [
            \Eppitnic\Service\WebAuthnChallenges::issue('register', 7),
            \Eppitnic\Service\WebAuthnChallenges::issue('stepup', 5),
        ];
        foreach ($others as [$id, $challenge]) {
            $options['publicKey']['challenge'] = SoftAuthenticator::encode($challenge);
            $response = $this->call('POST', '/v1/users/5/passkeys', [
                'challenge_id' => $id,
                'credential'   => json_decode($key->register($options['publicKey']), true),
                'stepup'       => ['password' => self::PASSWORD],
            ], $token);

            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('challenge', self::json($response)['reason']);
        }
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAChallengeWorksOnce(): void {
        $token = $this->sessionOf(5, 'pwd');
        $key = new SoftAuthenticator();
        $options = self::json($this->call('POST', '/v1/users/5/passkeys/options', [], $token));
        $body = [
            'challenge_id' => $options['challenge_id'],
            'credential'   => json_decode($key->register($options['publicKey']), true),
            'stepup'       => ['password' => self::PASSWORD],
        ];

        $this->assertSame(201, $this->call('POST', '/v1/users/5/passkeys', $body, $token)->getStatusCode());
        R::exec('DELETE FROM passkeys');
        $replay = $this->call('POST', '/v1/users/5/passkeys', $body, $token);

        $this->assertSame(400, $replay->getStatusCode());
        $this->assertSame('challenge', self::json($replay)['reason']);
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAMalformedBodyIsRefusedBeforeAnyProof(): void {
        $token = $this->sessionOf(5, 'pwd');

        foreach ([[], ['challenge_id' => 1], ['challenge_id' => 1, 'credential' => 'x', 'stepup' => ['password' => self::PASSWORD]]] as $body) {
            $this->assertSame(400, $this->call('POST', '/v1/users/5/passkeys', $body, $token)->getStatusCode());
        }
        $this->assertSame(0, $this->denied());
    }

    public function testATooLongNameIsRefused(): void {
        $response = $this->add(new SoftAuthenticator(), $this->sessionOf(5, 'pwd'), ['password' => self::PASSWORD], ['name' => str_repeat('n', 65)]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAddingWhilePasskeysAreOffIsRefused(): void {
        $token = $this->sessionOf(5, 'pwd');
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $response = $this->call('POST', '/v1/users/5/passkeys', ['challenge_id' => 1, 'credential' => 'x'], $token);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(self::json($response)['passkeys_available']);
    }

    public function testASessionBelowTheMinimumRegistersNoKeyHere(): void {
        $this->requirement('passkey', open: true);
        $this->withTotp();
        $token = $this->sessionOf(5, 'pwd+otp');

        $response = $this->call('POST', '/v1/users/5/passkeys/options', [], $token);

        $this->assertSame(403, $response->getStatusCode(), 'the first key goes through the sign-in, not a session');
        $this->assertSame('passkey_enrollment', self::json($response)['required']);
        $this->assertCount(0, Passkeys::listForUser(5));
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 5'));
    }

    // -----------------------------------------------------------------
    // renaming
    // -----------------------------------------------------------------

    public function testAUserRenamesTheirKeyWithoutAStepUp(): void {
        $this->keys(1);

        $response = $this->call('PATCH', '/v1/users/5/passkeys/1', ['name' => '  Phone  '], $this->sessionOf(5, 'pwd'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Phone', self::json($response)['passkey']['name']);
        $this->assertSame('Phone', Passkeys::find(1)['name']);
        $this->assertSame(5, (int) R::getCell("SELECT user_id FROM history WHERE object = 'users' AND data LIKE '%passkey_renamed%'"));
    }

    public function testARenameIsCheckedAndOnlyTheUsersOwn(): void {
        $this->keys(1);
        $token = $this->sessionOf(5, 'pwd');

        $this->assertSame(400, $this->call('PATCH', '/v1/users/5/passkeys/1', ['name' => str_repeat('n', 65)], $token)->getStatusCode());
        $this->assertSame(400, $this->call('PATCH', '/v1/users/5/passkeys/1', ['name' => '   '], $token)->getStatusCode());
        $this->assertSame(400, $this->call('PATCH', '/v1/users/5/passkeys/1', [], $token)->getStatusCode());
        $this->assertSame(404, $this->call('PATCH', '/v1/users/5/passkeys/99', ['name' => 'x'], $token)->getStatusCode());
        foreach ([$this->managerSession(), $this->adminSession(), $this->sessionOf(7, 'pwd')] as $other) {
            $this->assertSame(403, $this->call('PATCH', '/v1/users/5/passkeys/1', ['name' => 'x'], $other)->getStatusCode());
        }
        $this->assertSame('Test key', Passkeys::find(1)['name']);
    }

    public function testAKeyOfAnotherUserCannotBeRenamedThroughMyId(): void {
        $this->keys(1);
        $this->account(7, 'user', 2, 'bob');

        $response = $this->call('PATCH', '/v1/users/7/passkeys/1', ['name' => 'mine now'], $this->sessionOf(7, 'pwd'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Test key', Passkeys::find(1)['name']);
    }

    // -----------------------------------------------------------------
    // removing: the user's own, with a step-up
    // -----------------------------------------------------------------

    public function testAUserRemovesAKeyAfterTheStepUp(): void {
        $keys = $this->keys(2);
        $token = $this->sessionOf(5, 'pwd');

        $response = $this->remove($token, 2, $this->stepupProof($keys[0], $token));
        $body = self::json($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(1, $body['passkeys']);
        $this->assertNull(Passkeys::find(2));
        $this->assertNotNull(Passkeys::find(1));
        $this->assertSame(5, (int) R::getCell("SELECT user_id FROM history WHERE object = 'users' AND data LIKE '%passkey_removed%'"));
        $event = $this->events('passkey_removed')[0];
        $this->assertSame([5, 2], [$event['target_id'], $event['credential']]);
    }

    public function testRemovingNeedsTheStepUp(): void {
        $this->unlimited();
        $this->keys(2);
        $token = $this->sessionOf(5, 'pwd');

        foreach ([null, [], ['password' => self::PASSWORD], ['challenge_id' => 1, 'credential' => 'x']] as $proof) {
            $response = $this->remove($token, 2, $proof);

            $this->assertSame(401, $response->getStatusCode(), json_encode($proof));
            $this->assertSame('passkey', self::json($response)['stepup'], 'a user with a key proves it with a key');
        }
        $this->assertCount(2, Passkeys::listForUser(5));
    }

    public function testTheMinimumHoldsForTheUserHerself(): void {
        $this->withTotp();
        foreach (['passkey', 'totp_or_passkey'] as $mode) {
            R::exec('DELETE FROM passkeys');
            $keys = $this->keys(1);
            $this->requirement($mode);
            $first = (int) Passkeys::listForUser(5)[0]['id'];
            $token = $this->session('passkey', ['cred' => $first]);

            $response = $this->remove($token, $first, $this->stepupProof($keys[0], $token));

            $this->assertSame(400, $response->getStatusCode(), $mode);
            $this->assertSame(1, self::json($response)['minimum'], $mode);
            $this->assertCount(1, Passkeys::listForUser(5), $mode);
        }
    }

    public function testAboveTheMinimumOrWithNoneAKeyGoes(): void {
        $this->withTotp();
        $keys = $this->keys(2);
        $this->requirement('passkey');
        $token = $this->session('passkey', ['cred' => 1]);
        $this->assertSame(200, $this->remove($token, 2, $this->stepupProof($keys[0], $token))->getStatusCode());

        $this->requirement('totp');
        $this->assertSame(200, $this->remove($token, 1, $this->stepupProof($keys[0], $token))->getStatusCode(), 'no minimum');
        $this->assertSame([], Passkeys::listForUser(5));
    }

    public function testAKeyThatDoesNotCountCanGoEvenAtTheMinimum(): void {
        $keys = $this->keys(2);
        $this->requirement('passkey');
        R::exec('UPDATE passkeys SET attestation_verified = 1, backup_eligible = 0 WHERE id = 1');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $token = $this->session('passkey', ['cred' => 1]);

        // key 2 is the one that does not comply: the single compliant key stays
        $response = $this->remove($token, 2, $this->stepupProof($keys[0], $token));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertNotNull(Passkeys::find(1));
    }

    public function testAKeyOfAnotherUserIsNotFound(): void {
        $keys = $this->keys(1);
        $this->account(7, 'user', 2, 'bob');

        $response = $this->call('DELETE', '/v1/users/7/passkeys/1', [], $this->sessionOf(7, 'pwd'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull(Passkeys::find(1));
    }

    public function testASessionOpenedWithARemovedKeyEnds(): void {
        [$key] = $this->keys(2);
        $session = self::json($this->passkey($key));
        $token = $session['token'];

        $this->assertSame(200, $this->remove($token, (int) $session['cred'], $this->stepupProof($key, $token))->getStatusCode());

        $this->assertSame(401, $this->call('GET', '/v1/users/me', [], $token)->getStatusCode());
    }

    // -----------------------------------------------------------------
    // removing: a manager or admin in scope
    // -----------------------------------------------------------------

    public function testAnAdminAndAManagerRemoveAKeyInScope(): void {
        $this->keys(3);

        $byAdmin = $this->remove($this->adminSession(), 1);
        $byManager = $this->remove($this->managerSession(), 2);

        $this->assertSame([200, 200], [$byAdmin->getStatusCode(), $byManager->getStatusCode()]);
        $this->assertSame(['3'], array_column(Passkeys::listForUser(5), 'id'));
        $rows = R::getAll("SELECT user_id FROM history WHERE object = 'users' AND object_id = 5 AND data LIKE '%passkey_removed%' ORDER BY id");
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'user_id')));
        $events = $this->events('passkey_removed');
        $this->assertSame([5, 5], array_column($events, 'target_id'));
    }

    public function testNoStepUpIsAskedOfWhoeverManages(): void {
        $this->keys(1);

        $this->assertSame(200, $this->remove($this->adminSession(), 1, null)->getStatusCode());
    }

    public function testTheMinimumHoldsForAdminsAndManagersToo(): void {
        $this->keys(1);
        $this->requirement('passkey');

        foreach ([$this->adminSession(), $this->managerSession()] as $token) {
            $response = $this->remove($token, 1);

            $this->assertSame(400, $response->getStatusCode());
            $this->assertStringContainsString('reset the sign-in', self::json($response)['error']);
        }
        $this->assertCount(1, Passkeys::listForUser(5));
    }

    public function testNobodyOutOfScopeRemovesAKey(): void {
        $this->keys(2);

        $refused = [
            'the manager of another reseller' => $this->sessionOf(4),
            'a user of the reseller' => $this->sessionOf(7, 'pwd'),
        ];
        foreach ($refused as $who => $token) {
            $this->assertSame(403, $this->remove($token, 1)->getStatusCode(), $who);
        }
        $this->assertCount(2, Passkeys::listForUser(5));
    }

    public function testAManagerDoesNotRemoveAnAdminsKey(): void {
        $keys = $this->keys(1, []);
        R::exec('UPDATE passkeys SET user_id = 1');

        $this->assertSame(403, $this->remove($this->managerSession(), 1, null, 1)->getStatusCode());
        $this->assertNotNull(Passkeys::find(1));
    }

    public function testAnAdminRemovesAnAdminsKey(): void {
        $this->keys(1);
        R::exec('UPDATE passkeys SET user_id = 1');
        $this->account(3, 'admin', 1, 'rootb');

        $this->assertSame(200, $this->remove($this->sessionOf(3), 1, null, 1)->getStatusCode());
    }

    public function testManagingAnotherUsersKeysNeedsAStrongSession(): void {
        $this->keys(1);
        $this->withTotp(2);

        $response = $this->remove($this->sessionOf(2, 'pwd'), 1);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull(Passkeys::find(1));
    }

    public function testAnUnknownKeyOrUserIsNotFound(): void {
        $this->keys(1);

        $this->assertSame(404, $this->remove($this->adminSession(), 99)->getStatusCode());
        $this->assertSame(404, $this->remove($this->adminSession(), 1, null, 99)->getStatusCode());
    }
}
