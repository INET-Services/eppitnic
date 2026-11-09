<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Config;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use Eppitnic\Tests\Support\TestAccounts;
use Psr\Http\Message\ResponseInterface;
use RedBeanPHP\R;

/**
 * Setting a user's sign-in requirement through POST/PUT /v1/users, and
 * reading it back with the passkeys the user has.
 */
final class UserSigninRequirementTest extends PasskeyHttpTestCase
{
    private function put(int $id, array $body, ?string $token = null): ResponseInterface {
        return $this->call('PUT', "/v1/users/{$id}", $body, $token ?? $this->adminSession());
    }

    private function create(array $body, ?string $token = null): ResponseInterface {
        return $this->call('POST', '/v1/users', $body + [
            'username' => 'newcomer', 'email' => 'newcomer@example.test', 'password' => 'Str0ng-Enough-Pw!',
        ], $token ?? $this->adminSession());
    }

    private function row(int $id): array {
        return R::getRow('SELECT signin_requirement FROM users WHERE id = ?', [$id]);
    }

    // -----------------------------------------------------------------
    // creating
    // -----------------------------------------------------------------

    public function testANewUserStartsWithTotp(): void {
        $response = $this->create([]);

        $this->assertSame(201, $response->getStatusCode());
        $user = self::json($response)['users'][0];
        $this->assertSame('totp', $user['signin_requirement']);
        $this->assertSame(0, $user['passkeys']);
        $this->assertFalse($user['has_passkeys']);
    }

    public function testAnAdminCreatesAUserWithAnyRequirement(): void {
        foreach (['passkey', 'totp_or_passkey', 'totp', 'none'] as $i => $mode) {
            $response = $this->create(['username' => "user{$i}", 'email' => "u{$i}@example.test", 'signin_requirement' => $mode]);

            $this->assertSame(201, $response->getStatusCode(), $mode);
            $this->assertSame($mode, self::json($response)['users'][0]['signin_requirement']);
        }
    }

    public function testAnUnknownRequirementIsRefused(): void {
        $this->assertSame(400, $this->create(['signin_requirement' => 'sms'])->getStatusCode());
        $this->assertSame(400, $this->create(['signin_requirement' => ''])->getStatusCode());
        $this->assertSame(400, $this->put(5, ['signin_requirement' => 'sms'])->getStatusCode());
        $this->assertSame('none', $this->row(5)['signin_requirement']);
    }

    public function testAManagerCreatesWithAnyRequirementButNone(): void {
        $manager = $this->managerSession();

        foreach (['passkey', 'totp_or_passkey', 'totp'] as $i => $mode) {
            $response = $this->create(['username' => "user{$i}", 'email' => "u{$i}@example.test", 'signin_requirement' => $mode], $manager);
            $this->assertSame(201, $response->getStatusCode(), $mode);
        }
        $none = $this->create(['username' => 'weak', 'email' => 'weak@example.test', 'signin_requirement' => 'none'], $manager);

        $this->assertSame(403, $none->getStatusCode());
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = 'weak'"));
    }

    // -----------------------------------------------------------------
    // changing
    // -----------------------------------------------------------------

    public function testAnAdminSetsAnotherUsersRequirement(): void {
        foreach (['passkey', 'totp_or_passkey', 'totp', 'none'] as $mode) {
            $response = $this->put(5, ['signin_requirement' => $mode]);

            $this->assertSame(200, $response->getStatusCode(), $mode);
            $this->assertSame($mode, self::json($response)['users'][0]['signin_requirement']);
            $this->assertSame($mode, $this->row(5)['signin_requirement']);
        }
    }

    public function testTheChangeIsInTheUsersHistory(): void {
        $this->put(5, ['signin_requirement' => 'passkey']);

        $history = R::getRow("SELECT user_id, data FROM history WHERE object = 'users' AND object_id = 5 AND action = 'update'");
        $data = json_decode($history['data'], true);
        $this->assertSame(1, (int) $history['user_id']);
        $this->assertSame('passkey', $data['signin_requirement']);
    }

    public function testAnOmittedRequirementStaysAsItWas(): void {
        $this->put(5, ['signin_requirement' => 'passkey']);

        $this->put(5, ['description' => 'edited']);

        $this->assertSame('passkey', $this->row(5)['signin_requirement']);
    }

    public function testAManagerSetsEveryRequirementButNoneForTheirUser(): void {
        $manager = $this->managerSession();

        foreach (['passkey', 'totp_or_passkey', 'totp'] as $mode) {
            $this->assertSame(200, $this->put(5, ['signin_requirement' => $mode], $manager)->getStatusCode(), $mode);
        }
        $response = $this->put(5, ['signin_requirement' => 'none'], $manager);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('totp', $this->row(5)['signin_requirement']);
        $this->assertSame([], $this->events('signin_requirement_none'));
    }

    public function testAManagerMayResendNoneForAUserWhoseRequirementItAlreadyIs(): void {
        $manager = $this->managerSession();

        $response = $this->put(5, ['signin_requirement' => 'none', 'description' => 'edited'], $manager);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->events('signin_requirement_none'), 'nothing was set');
    }

    public function testAManagerCannotReachAnotherResellersUser(): void {
        TestAccounts::ensureReseller(3, 'Three');
        TestAccounts::ensure(7, 'user', 3, 'elsewhere');

        $this->assertSame(403, $this->put(7, ['signin_requirement' => 'totp'], $this->managerSession())->getStatusCode());
    }

    public function testAPlainUserSetsNobodysRequirement(): void {
        $this->assertSame(403, $this->put(5, ['signin_requirement' => 'passkey'], $this->session('pwd'))->getStatusCode());
    }

    public function testSettingNoneIsRecordedWithWhoAndForWhom(): void {
        R::exec("UPDATE users SET signin_requirement = 'totp' WHERE id = 5");

        $this->put(5, ['signin_requirement' => 'none']);

        $events = $this->events('signin_requirement_none');
        $this->assertCount(1, $events);
        $this->assertSame(['admin_id' => 1, 'target_id' => 5, 'target' => 'alice'], [
            'admin_id' => (int) R::getCell("SELECT user_id FROM history WHERE object = 'security' ORDER BY id DESC LIMIT 1"),
            'target_id' => $events[0]['target_id'],
            'target' => $events[0]['target'],
        ]);
        $this->assertSame('secread', $events[0]['action']);
    }

    public function testNoneIsNotRecordedAgainWhenItWasAlreadySet(): void {
        $this->put(5, ['signin_requirement' => 'none']);

        $this->assertSame([], $this->events('signin_requirement_none'));
    }

    public function testCreatingAUserWithNoneIsRecordedToo(): void {
        $this->create(['signin_requirement' => 'none']);

        $events = $this->events('signin_requirement_none');
        $this->assertCount(1, $events);
        $this->assertSame('newcomer', $events[0]['target']);
    }

    // -----------------------------------------------------------------
    // loosening one's own: an admin with a step-up, nobody else
    // -----------------------------------------------------------------

    /** @var SoftAuthenticator[] the keys fullyAuthenticated() gave the account */
    private array $ownKeys = [];

    /** @return array<string, array{string, string, int}> from, to, 400 = it loosens */
    public static function ownChanges(): array {
        return [
            'passkey to totp'            => ['passkey', 'totp', 400],
            'passkey to totp_or_passkey' => ['passkey', 'totp_or_passkey', 400],
            'totp_or_passkey to totp'    => ['totp_or_passkey', 'totp', 400],
            'totp to none'               => ['totp', 'none', 400],
            'passkey to none'            => ['passkey', 'none', 400],
            'totp to passkey'            => ['totp', 'passkey', 200],
            'totp to totp_or_passkey'    => ['totp', 'totp_or_passkey', 200],
            'none to totp'               => ['none', 'totp', 200],
            'unchanged'                  => ['totp', 'totp', 200],
            'unchanged passkey'          => ['passkey', 'passkey', 200],
        ];
    }

    /** @return array<string, array{string, string, int}> only the ones that loosen */
    public static function ownLoosenings(): array {
        return array_filter(self::ownChanges(), static fn(array $row): bool => $row[2] === 400);
    }

    /**
     * A session that meets every requirement there is: TOTP, compliant
     * keys, and a passkey sign-in.
     */
    private function fullyAuthenticated(int $id, string $role, int $resellerId, string $username): string {
        R::exec('UPDATE users SET totp_secret = ? WHERE id = ?', [$this->totpSecret, $id]);
        $rows = [];
        $this->ownKeys = [];
        foreach ([0, 1] as $i) {
            $this->ownKeys[] = $key = new SoftAuthenticator(aaguid: sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $i));
            $rows[] = $this->storedKey($key, [], $id);
        }
        TestAccounts::ensure($id, $role, $resellerId, $username);

        return $this->session('passkey', [
            'id' => $id, 'username' => $username, 'role' => $role, 'reseller_id' => $resellerId, 'cred' => (int) $rows[0]['id'],
        ]);
    }

    private function ownRow(int $id = 1): string {
        return $this->row($id)['signin_requirement'];
    }

    private function assertStepUpRequired(ResponseInterface $response, string $method = 'passkey'): void {
        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $body = self::json($response);
        $this->assertTrue($body['stepup_required']);
        $this->assertSame($method, $body['stepup']);
        $this->assertNotSame('', $body['error']);
        $this->assertSame([], $this->events('stepup_failed'), 'a missing proof is not a failed one');
        $this->assertSame(0, $this->denied());
    }

    private function assertStepUpRefused(ResponseInterface $response, string $method, string $reason, int $loosened = 0): void {
        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('Step-up verification failed', self::json($response)['error']);
        $this->assertSame($method, self::json($response)['stepup']);
        $logged = $this->events('stepup_failed');
        $this->assertSame($reason, end($logged)['reason']);
        $this->assertCount($loosened, $this->events('signin_requirement_loosened'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownChanges')]
    public function testAnAdminsOwnRequirementGetsLooserOnlyWithAStepUp(string $from, string $to, int $status): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement($from, 1);

        $response = $this->put(1, ['signin_requirement' => $to], $token);

        if ($status === 400) {
            $this->assertStepUpRequired($response);
            $this->assertSame($from, $this->ownRow());
            $this->assertSame([], $this->events('signin_requirement_loosened'));
            $this->assertSame([], $this->events('signin_requirement_none'));
        } else {
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $this->assertSame($to, $this->ownRow());
            $this->assertSame([], $this->events('signin_requirement_loosened'), 'tightening is not loosening');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownLoosenings')]
    public function testAnAdminLoosensTheirOwnWithAFreshPasskeyAssertion(string $from, string $to): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement($from, 1);

        $response = $this->put(1, [
            'signin_requirement' => $to,
            'stepup' => $this->stepupProof($this->ownKeys[0], $token, 1),
        ], $token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($to, $this->ownRow());

        $loosened = $this->events('signin_requirement_loosened');
        $this->assertCount(1, $loosened);
        $this->assertSame(
            ['target_id' => 1, 'target' => 'admin', 'from' => $from, 'to' => $to, 'action' => 'secread'],
            array_intersect_key($loosened[0], array_flip(['target_id', 'target', 'from', 'to', 'action']))
        );
        $this->assertSame(1, (int) R::getCell("SELECT user_id FROM history WHERE object = 'security' AND data LIKE '%signin_requirement_loosened%'"));
        $this->assertCount($to === 'none' ? 1 : 0, $this->events('signin_requirement_none'));
        $this->assertSame(
            1,
            (int) R::getCell("SELECT user_id FROM history WHERE object = 'users' AND object_id = 1 AND action = 'update'"),
            'the ordinary history row is written too'
        );
        $this->assertSame(0, $this->denied());
    }

    public function testLoosenedAndNoneAreTwoEventsOfTheSameChange(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('totp', 1);

        $this->put(1, ['signin_requirement' => 'none', 'stepup' => $this->stepupProof($this->ownKeys[0], $token, 1)], $token);

        $this->assertCount(1, $this->events('signin_requirement_loosened'));
        $this->assertCount(1, $this->events('signin_requirement_none'));
        $this->assertSame(
            [1, 1],
            array_map('intval', R::getCol("SELECT user_id FROM history WHERE object = 'security' ORDER BY id"))
        );
        $none = $this->events('signin_requirement_none')[0];
        $this->assertSame([1, 'admin'], [$none['target_id'], $none['target']]);
    }

    public function testAnAdminConfirmsWithTheirTotpCodeWhenTheyHaveNoKey(): void {
        $this->withTotp(1);
        $this->requirement('totp', 1);
        $token = $this->adminSession();

        $missing = $this->put(1, ['signin_requirement' => 'none'], $token);
        $this->assertStepUpRequired($missing, 'totp');
        $this->assertSame('totp', $this->ownRow());

        $response = $this->put(1, ['signin_requirement' => 'none', 'stepup' => ['totp' => $this->code()]], $token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('none', $this->ownRow());
        $this->assertCount(1, $this->events('signin_requirement_loosened'));
        $this->assertCount(1, $this->events('signin_requirement_none'));
    }

    public function testAnAdminConfirmsWithTheirPasswordWhenTheyHaveNeitherKeyNorCode(): void {
        $this->from(self::SAFE);
        $this->requirement('totp', 1);
        $token = $this->sessionOf(1, 'pwd');

        $missing = $this->put(1, ['signin_requirement' => 'none'], $token);
        $this->assertStepUpRequired($missing, 'password');

        $response = $this->put(1, ['signin_requirement' => 'none', 'stepup' => ['password' => self::PASSWORD]], $token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('none', $this->ownRow());
        $this->assertCount(1, $this->events('signin_requirement_loosened'));
    }

    public function testAnEmptyProofIsAMissingProof(): void {
        $this->withTotp(1);
        $this->requirement('totp', 1);
        $token = $this->adminSession();

        foreach ([null, [], ''] as $proof) {
            $this->assertStepUpRequired($this->put(1, ['signin_requirement' => 'none', 'stepup' => $proof], $token), 'totp');
        }
        $this->assertSame('totp', $this->ownRow());
    }

    public function testAWrongProofIsRefusedCountedAndChangesNothing(): void {
        Config::set('login_ratelimit', ['max_failures' => 0] + Config::get('login_ratelimit'));
        $this->withTotp(1);
        $this->requirement('totp', 1);
        $token = $this->adminSession();

        foreach ([
            'wrong code'  => [['totp' => '000000'], 'wrong code'],
            'no code'     => [['password' => self::PASSWORD], 'no code'],
            'not an array' => ['123456', 'no code'],
        ] as [$proof, $reason]) {
            $response = $this->put(1, ['signin_requirement' => 'none', 'stepup' => $proof], $token);
            $this->assertStepUpRefused($response, 'totp', $reason);
        }
        $this->assertSame('totp', $this->ownRow());
        $this->assertSame(3, $this->denied());
        $this->assertSame([], $this->events('signin_requirement_none'));
    }

    public function testAWrongPasswordIsRefused(): void {
        $this->from(self::SAFE);
        $this->requirement('totp', 1);

        $response = $this->put(1, ['signin_requirement' => 'none', 'stepup' => ['password' => 'wrong']], $this->sessionOf(1, 'pwd'));

        $this->assertStepUpRefused($response, 'password', 'wrong password');
        $this->assertSame('totp', $this->ownRow());
    }

    public function testAForgedAssertionIsRefused(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('passkey', 1);

        $proof = $this->stepupProof($this->ownKeys[0], $token, 1, ['badSignature' => true]);
        $response = $this->put(1, ['signin_requirement' => 'totp', 'stepup' => $proof], $token);

        $this->assertStepUpRefused($response, 'passkey', 'signature');
        $this->assertSame('passkey', $this->ownRow());
        $this->assertSame(1, $this->denied());
    }

    public function testAPasswordDoesNotStandInForTheKeyTheAdminHas(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('passkey', 1);

        $response = $this->put(1, ['signin_requirement' => 'totp', 'stepup' => ['password' => self::PASSWORD]], $token);

        $this->assertStepUpRefused($response, 'passkey', 'no assertion');
    }

    public function testAnAssertionIsGoodOnce(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('passkey', 1);
        $proof = $this->stepupProof($this->ownKeys[0], $token, 1);

        $this->assertSame(200, $this->put(1, ['signin_requirement' => 'totp', 'stepup' => $proof], $token)->getStatusCode());
        $this->requirement('passkey', 1);
        $replay = $this->put(1, ['signin_requirement' => 'totp', 'stepup' => $proof], $token);

        $this->assertStepUpRefused($replay, 'passkey', 'challenge unknown, spent or expired', 1);
        $this->assertSame('passkey', $this->ownRow());
    }

    public function testAnotherUsersKeyOnTheAdminsChallengeIsRefused(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('passkey', 1);
        [$alices] = $this->keys(1);

        $proof = $this->stepupProof($alices, $token, 1);
        $response = $this->put(1, ['signin_requirement' => 'totp', 'stepup' => $proof], $token);

        $this->assertStepUpRefused($response, 'passkey', 'credential of another user');
        $this->assertSame('passkey', $this->ownRow());
        $this->assertSame(0, (int) R::getCell('SELECT sign_count FROM passkeys WHERE user_id = 5'), 'alice\'s key was not used');
    }

    public function testAnotherUsersChallengeIsRefused(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('passkey', 1);
        [$alices] = $this->keys(1);
        $this->requirement('passkey', 1);

        $proof = $this->stepupProof($alices, $this->sessionOf(5, 'pwd'), 5);
        $response = $this->put(1, ['signin_requirement' => 'totp', 'stepup' => $proof], $token);

        $this->assertStepUpRefused($response, 'passkey', 'challenge unknown, spent or expired');
        $this->assertSame('passkey', $this->ownRow());
    }

    public function testFailedProofsBlockTheNetworkLikeFailedSignIns(): void {
        $this->withTotp(1);
        $this->requirement('totp', 1);
        $token = $this->adminSession();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $this->put(1, ['signin_requirement' => 'none', 'stepup' => ['totp' => '000000']], $token)->getStatusCode());
        }
        $blocked = $this->put(1, ['signin_requirement' => 'none', 'stepup' => ['totp' => $this->code()]], $token);

        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertArrayHasKey('retry_after', self::json($blocked));
        $this->assertSame('totp', $this->ownRow());
    }

    public function testARequestRefusedForAnotherReasonLeavesTheProofUnspent(): void {
        $token = $this->fullyAuthenticated(1, 'admin', 1, 'admin');
        $this->requirement('passkey', 1);
        $proof = $this->stepupProof($this->ownKeys[0], $token, 1);

        $weak = $this->put(1, ['signin_requirement' => 'totp', 'password' => 'x', 'stepup' => $proof], $token);

        $this->assertSame(400, $weak->getStatusCode());
        $this->assertArrayNotHasKey('stepup_required', self::json($weak));
        $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'stepup' AND used_at IS NULL"));
        $this->assertSame(200, $this->put(1, ['signin_requirement' => 'totp', 'stepup' => $proof], $token)->getStatusCode());
    }

    public function testAStepUpSentWithATighteningIsIgnored(): void {
        $this->withTotp(1);
        $this->requirement('none', 1);

        $response = $this->put(1, ['signin_requirement' => 'totp', 'stepup' => ['totp' => '000000']], $this->adminSession());

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('totp', $this->ownRow());
        $this->assertSame([], $this->events('stepup_failed'));
        $this->assertSame([], $this->events('signin_requirement_loosened'));
    }

    public function testEditingOtherFieldsNeedsNoStepUp(): void {
        $this->requirement('totp', 1);
        $this->withTotp(1);

        $response = $this->put(1, ['description' => 'edited', 'signin_requirement' => 'totp'], $this->adminSession());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->events('signin_requirement_loosened'));
    }

    // fixed API tokens and remote auth have no session to confirm with

    public function testAFixedApiTokenCannotLoosenTheAdminsOwn(): void {
        // SQLite has no UNIX_TIMESTAMP(), which the token lookup calls
        @R::getDatabaseAdapter()->getDatabase()->getPDO()->sqliteCreateFunction('UNIX_TIMESTAMP', 'time', 0);
        R::exec('UPDATE users SET api_token = ? WHERE id = 1', [hash('sha256', 'automation-token')]);
        $this->requirement('totp', 1);

        $response = $this->put(1, ['signin_requirement' => 'none', 'stepup' => ['password' => self::PASSWORD]], 'automation-token');

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(UserRules::SELF_LOOSENING_SESSION, self::json($response)['error']);
        $this->assertSame('totp', $this->ownRow());
        $this->assertSame([], $this->events('signin_requirement_loosened'));

        $tightening = $this->put(1, ['signin_requirement' => 'passkey'], 'automation-token');
        $this->assertSame(200, $tightening->getStatusCode(), (string) $tightening->getBody());
    }

    public function testRemoteAuthCannotLoosenTheAdminsOwn(): void {
        Config::set('remote_auth', ['enabled' => true, 'header' => null]);
        $this->requirement('totp', 1);

        $response = $this->call('PUT', '/v1/users/1', [
            'signin_requirement' => 'none', 'stepup' => ['password' => self::PASSWORD],
        ], null, ['REMOTE_USER' => 'admin']);

        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(UserRules::SELF_LOOSENING_SESSION, self::json($response)['error']);
        $this->assertSame('totp', $this->ownRow());
    }

    public function testAnAdminMayStillLoosenSomeoneElseWithoutAStepUp(): void {
        $this->requirement('passkey', 5);

        $this->assertSame(200, $this->put(5, ['signin_requirement' => 'totp'])->getStatusCode());
        $this->assertSame([], $this->events('signin_requirement_loosened'));
    }

    /**
     * Managers (like users, who cannot reach this route at all) keep the
     * refusal, step-up or not; only 'none' is a 403 first.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ownChanges')]
    public function testAManagersOwnRequirementOnlyGetsStricter(string $from, string $to, int $status): void {
        $token = $this->fullyAuthenticated(2, 'manager', 2, 'boss');
        $this->requirement($from, 2);

        $response = $this->put(2, [
            'signin_requirement' => $to,
            'stepup' => $this->stepupProof($this->ownKeys[0], $token, 2),
        ], $token);

        if ($status === 400) {
            $this->assertSame($to === 'none' ? 403 : 400, $response->getStatusCode(), (string) $response->getBody());
            if ($to !== 'none') {
                $this->assertSame('you cannot loosen your own sign-in requirement', self::json($response)['error']);
            }
            $this->assertSame($from, $this->ownRow(2));
            $this->assertSame(1, (int) R::getCell("SELECT COUNT(*) FROM webauthn_challenges WHERE purpose = 'stepup' AND used_at IS NULL"), 'the proof was not even looked at');
        } else {
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $this->assertSame($to, $this->ownRow(2));
        }
        $this->assertSame([], $this->events('signin_requirement_loosened'));
    }

    public function testAManagerCannotLoosenTheirOwnEither(): void {
        $token = $this->fullyAuthenticated(2, 'manager', 2, 'boss');
        $this->requirement('passkey', 2);

        $response = $this->put(2, ['signin_requirement' => 'totp'], $token);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('you cannot loosen your own sign-in requirement', self::json($response)['error']);
        $this->assertSame('passkey', $this->row(2)['signin_requirement']);
    }

    public function testAPlainUserCannotEditTheirOwnRequirementAtAll(): void {
        $this->requirement('passkey');

        $response = $this->put(5, ['signin_requirement' => 'totp'], $this->session('pwd'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('passkey', $this->row(5)['signin_requirement']);
    }

    public function testAnAdminMayLoosenSomeoneElse(): void {
        $this->requirement('passkey', 5);

        $this->assertSame(200, $this->put(5, ['signin_requirement' => 'totp'])->getStatusCode());
    }

    // -----------------------------------------------------------------
    // reading
    // -----------------------------------------------------------------

    public function testTheListCarriesTheRequirementAndTheCompliantKeys(): void {
        $this->requirement('passkey', 5);
        $this->keys(2);

        $users = self::json($this->call('GET', '/v1/users', [], $this->adminSession()))['users'];
        $byName = array_column($users, null, 'username');

        $this->assertSame('passkey', $byName['alice']['signin_requirement']);
        $this->assertSame(2, $byName['alice']['passkeys']);
        $this->assertTrue($byName['alice']['has_passkeys']);
        $this->assertSame(0, $byName['admin']['passkeys']);
        $this->assertFalse($byName['admin']['has_passkeys']);
    }

    public function testOnlyKeysThatMeetThePolicyAreCounted(): void {
        $this->keys(2);
        R::exec("UPDATE passkeys SET backup_eligible = 1 WHERE id = (SELECT MIN(id) FROM passkeys)");
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        R::exec('UPDATE passkeys SET attestation_verified = 1');

        $user = self::json($this->call('GET', '/v1/users/5', [], $this->adminSession()))['users'][0];

        $this->assertSame(1, $user['passkeys']);
    }

    public function testAPlainUserSeesTheirOwnRequirementAndKeys(): void {
        $this->requirement('totp_or_passkey');
        $this->withTotp();
        $this->keys(1);

        $users = self::json($this->call('GET', '/v1/users', [], $this->session('passkey', ['cred' => (int) R::getCell('SELECT id FROM passkeys')])))['users'];

        $this->assertCount(1, $users);
        $this->assertSame('totp_or_passkey', $users[0]['signin_requirement']);
        $this->assertSame(1, $users[0]['passkeys']);
        $this->assertArrayNotHasKey('must_enroll_mfa', $users[0]);
    }

    public function testTheListCountsKeysInOneQuery(): void {
        $this->keys(2);
        for ($i = 0; $i < 5; $i++) {
            R::exec("INSERT INTO users (username, email, role, reseller_id) VALUES (?, ?, 'user', 2)", ["extra{$i}", "extra{$i}@example.test"]);
        }
        $token = $this->adminSession();

        $queries = function (string $path) use ($token): int {
            $logger = R::debug(true, 1);
            $this->call('GET', $path, [], $token);
            R::debug(false);
            return count($logger->grep('FROM passkeys'));
        };

        // what the session itself costs, plus one for the whole list
        $this->assertSame($queries('/v1/users/me') + 1, $queries('/v1/users'));
    }

    // -----------------------------------------------------------------
    // the password-only enrolment flag
    // -----------------------------------------------------------------

    private function open(int $id = 5): int {
        return (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = ?', [$id]);
    }

    public function testAUserCreatedWhoNeedsAKeyStartsWithTheEnrolmentOpen(): void {
        foreach (['passkey' => 1, 'totp_or_passkey' => 1, 'totp' => 0, 'none' => 0] as $mode => $open) {
            $id = (int) self::json($this->create([
                'username' => "u-{$mode}", 'email' => "{$mode}@example.test", 'signin_requirement' => $mode,
            ]))['users'][0]['id'];

            $this->assertSame($open, $this->open($id), $mode);
        }
    }

    public function testAskingForKeysOpensItAndTheHistoryRowSaysSo(): void {
        $this->put(5, ['signin_requirement' => 'totp_or_passkey']);

        $this->assertSame(1, $this->open());
        $data = json_decode((string) R::getCell("SELECT data FROM history WHERE object = 'users' AND object_id = 5 AND action = 'update' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(1, $data['passkey_enroll_open']);
    }

    public function testChangesThatAskForNoMoreKeysLeaveItAlone(): void {
        $this->put(5, ['signin_requirement' => 'passkey']);
        R::exec('UPDATE users SET passkey_enroll_open = 0 WHERE id = 5');

        $this->put(5, ['signin_requirement' => 'totp_or_passkey']);
        $this->assertSame(0, $this->open(), 'the same minimum, and it was closed');

        $this->put(5, ['description' => 'edited']);
        $this->assertSame(0, $this->open());
    }

    public function testEditingOtherFieldsOfAUserWhoseEnrolmentIsOpenKeepsItOpen(): void {
        $this->put(5, ['signin_requirement' => 'passkey']);

        $this->put(5, ['description' => 'edited', 'signin_requirement' => 'passkey']);

        $this->assertSame(1, $this->open());
    }

    public function testAskingForNoKeyClosesIt(): void {
        $this->put(5, ['signin_requirement' => 'passkey']);

        $this->put(5, ['signin_requirement' => 'totp']);

        $this->assertSame(0, $this->open());
    }

    public function testAUserWhoAlreadyHasTheKeysIsNotOpened(): void {
        $this->keys(2);

        $this->put(5, ['signin_requirement' => 'passkey']);

        $this->assertSame(0, $this->open());
    }

    public function testAManagerOpensItToo(): void {
        $this->put(5, ['signin_requirement' => 'passkey'], $this->managerSession());

        $this->assertSame(1, $this->open());
    }

    // -----------------------------------------------------------------
    // keys cannot be required while passkeys are off
    // -----------------------------------------------------------------

    private function switchOff(): void {
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));
    }

    public function testAKeyRequirementIsRefusedOnCreationAndChangeWhilePasskeysAreOff(): void {
        $this->switchOff();

        foreach (['passkey', 'totp_or_passkey'] as $mode) {
            $created = $this->create(['signin_requirement' => $mode]);
            $this->assertSame(400, $created->getStatusCode(), $mode);
            $this->assertStringContainsString('passkeys are not enabled', self::json($created)['error']);

            $changed = $this->put(5, ['signin_requirement' => $mode]);
            $this->assertSame(400, $changed->getStatusCode(), $mode);
        }
        $this->assertSame('none', $this->row(5)['signin_requirement']);
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM users WHERE username = 'newcomer'"));
    }

    public function testTheOtherRequirementsAreFineWhilePasskeysAreOff(): void {
        $this->switchOff();

        $this->assertSame(201, $this->create(['signin_requirement' => 'totp'])->getStatusCode());
        $this->assertSame(200, $this->put(5, ['signin_requirement' => 'none'])->getStatusCode());
    }

    public function testAUserAlreadyOnAKeyRequirementCanStillBeEditedWhilePasskeysAreOff(): void {
        $this->put(5, ['signin_requirement' => 'passkey']);
        $this->switchOff();

        $response = $this->put(5, ['description' => 'edited', 'signin_requirement' => 'passkey']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passkey', $this->row(5)['signin_requirement']);
    }
}
