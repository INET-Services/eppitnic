<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Service\WebAuthnChallenges;
use Eppitnic\Tests\Support\PasskeyTestCase;
use RedBeanPHP\R;

/**
 * Challenges are single-use, short-lived, and bound to a purpose and, where
 * known, a user -- the part of a ceremony that stops replay.
 */
final class WebAuthnChallengesTest extends PasskeyTestCase
{
    private int $now = 1_800_000_000;

    protected function setUp(): void {
        parent::setUp();
        $this->now = 1_800_000_000;
        WebAuthnChallenges::useClock(fn(): int => $this->now);
    }

    public function testIssueGives32RandomBytesAndTheRowToSpendThemOn(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');
        [, $other] = WebAuthnChallenges::issue('stepup');

        $this->assertSame(32, strlen($challenge));
        $this->assertNotSame($challenge, $other);
        $this->assertGreaterThan(0, $id);
    }

    public function testOnlyAHashOfTheChallengeIsStored(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');

        $row = R::getRow('SELECT * FROM webauthn_challenges WHERE id = ?', [$id]);

        $this->assertSame(hash('sha256', $challenge), $row['challenge_hash']);
        foreach ($row as $value) {
            $this->assertStringNotContainsString($challenge, (string) $value);
            $this->assertStringNotContainsString(bin2hex($challenge), (string) $value);
        }
    }

    public function testItExpiresFiveMinutesAfterIssue(): void {
        [$id] = WebAuthnChallenges::issue('stepup');

        $row = R::getRow('SELECT created_at, expires_at FROM webauthn_challenges WHERE id = ?', [$id]);

        $this->assertSame(300, strtotime($row['expires_at']) - strtotime($row['created_at']));
        $this->assertSame(300, WebAuthnChallenges::LIFETIME);
    }

    public function testAChallengeIsSpentOnce(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');

        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', null), 'a replay');
        $this->assertNotNull(R::getCell('SELECT used_at FROM webauthn_challenges WHERE id = ?', [$id]));
    }

    public function testAWrongGuessIsRefusedAndDoesNotUseTheChallengeUp(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');

        $this->assertFalse(WebAuthnChallenges::consume($id, random_bytes(32), 'stepup', null));
        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));
    }

    public function testAChallengeIsOnlyGoodFor300Seconds(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');
        [$late, $lateChallenge] = WebAuthnChallenges::issue('stepup');

        $this->now += 299;
        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));

        $this->now += 1;
        $this->assertFalse(WebAuthnChallenges::consume($late, $lateChallenge, 'stepup', null));
    }

    public function testAnExpiredChallengeStaysRefusedLongAfter(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');

        $this->now += 86400;

        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));
    }

    public function testAChallengeIsBoundToItsPurpose(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');

        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'register', null));
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'login', null));
        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));
    }

    public function testAChallengeIsBoundToItsUser(): void {
        $this->user(5);
        $this->user(6, 'bob');
        [$id, $challenge] = WebAuthnChallenges::issue('stepup', 5);

        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', 6), 'another user');
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', null), 'nobody in particular');
        $this->assertTrue(WebAuthnChallenges::consume($id, $challenge, 'stepup', 5));
    }

    public function testAChallengeWithNoUserIsNotSpendableAsOneUsersOwn(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');

        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', 5));
    }

    public function testAnUnknownIdIsRefused(): void {
        $this->assertFalse(WebAuthnChallenges::consume(999, random_bytes(32), 'stepup', null));
    }

    public function testAnotherChallengesBytesDoNotSpendThisOne(): void {
        [$first] = WebAuthnChallenges::issue('stepup');
        [, $second] = WebAuthnChallenges::issue('stepup');

        $this->assertFalse(WebAuthnChallenges::consume($first, $second, 'stepup', null));
    }

    public function testAnUnknownPurposeCannotBeIssued(): void {
        $this->expectException(\InvalidArgumentException::class);
        WebAuthnChallenges::issue('anything');
    }

    public function testALoginChallengeIsNotStoredOnIssue(): void {
        $this->expectException(\InvalidArgumentException::class);
        WebAuthnChallenges::issue('login');
    }

    public function testTheContextIsReadBackFromTheRow(): void {
        [$withContext] = WebAuthnChallenges::issue('register', 5, 'pwd+otp');
        [$without] = WebAuthnChallenges::issue('register', 5);

        $this->assertSame('pwd+otp', WebAuthnChallenges::contextOf($withContext));
        $this->assertNull(WebAuthnChallenges::contextOf($without));
        $this->assertNull(WebAuthnChallenges::contextOf(9999));
    }

    // -----------------------------------------------------------------
    // login challenges: signed, not stored
    // -----------------------------------------------------------------

    public function testIssuingALoginChallengeWritesNothing(): void {
        for ($i = 0; $i < 10; $i++) {
            WebAuthnChallenges::issueLogin();
        }

        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testALoginChallengeIsFreshBytesAndASignedId(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();
        [, $other] = WebAuthnChallenges::issueLogin();

        $this->assertSame(32, strlen($challenge));
        $this->assertNotSame($challenge, $other);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $id);
        $this->assertStringNotContainsString(rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='), explode('.', $id)[1]);
    }

    public function testALoginChallengeIsSpentOnceAndLeavesAUsedRow(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();

        $this->assertTrue(WebAuthnChallenges::consumeLogin($id, $challenge));
        $this->assertFalse(WebAuthnChallenges::consumeLogin($id, $challenge), 'a replay');

        $row = R::getRow('SELECT * FROM webauthn_challenges');
        $this->assertSame('login', $row['purpose']);
        $this->assertNull($row['user_id']);
        $this->assertSame(hash('sha256', $challenge), $row['challenge_hash']);
        $this->assertNotNull($row['used_at']);
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testAWrongGuessLeavesALoginChallengeUnspent(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();

        $this->assertFalse(WebAuthnChallenges::consumeLogin($id, random_bytes(32)));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
        $this->assertTrue(WebAuthnChallenges::consumeLogin($id, $challenge));
    }

    public function testATamperedLoginIdIsRefused(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();
        [$payload, $mac] = explode('.', $id);
        $flip = static fn(string $text): string => ($text[0] === 'A' ? 'B' : 'A') . substr($text, 1);

        foreach ([$flip($payload) . '.' . $mac, $payload . '.' . $flip($mac), $payload, $id . '.x', '', '.', 'a.b'] as $forged) {
            $this->assertFalse(WebAuthnChallenges::consumeLogin($forged, $challenge), $forged);
        }
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
        $this->assertTrue(WebAuthnChallenges::consumeLogin($id, $challenge));
    }

    public function testTheExpiryInsideAnIdCannotBeExtended(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();
        [$payload, $mac] = explode('.', $id);
        $bytes = base64_decode(strtr($payload, '-_', '+/'));
        $later = pack('J', unpack('J', $bytes)[1] + 86400) . substr($bytes, 8);
        $forged = rtrim(strtr(base64_encode($later), '+/', '-_'), '=') . '.' . $mac;

        $this->now += 400;

        $this->assertFalse(WebAuthnChallenges::consumeLogin($forged, $challenge));
    }

    public function testALoginChallengeIsOnlyGoodFor300Seconds(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();
        [$late, $lateChallenge] = WebAuthnChallenges::issueLogin();

        $this->now += 299;
        $this->assertTrue(WebAuthnChallenges::consumeLogin($id, $challenge));

        $this->now += 1;
        $this->assertFalse(WebAuthnChallenges::consumeLogin($late, $lateChallenge));
        $this->assertSame(1, (int) R::getCell('SELECT COUNT(*) FROM webauthn_challenges'));
    }

    public function testALoginIdIsSignedWithAKeyOfTheInstallation(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();

        Config::set('jwt_psk', 'a-different-installation');

        $this->assertFalse(WebAuthnChallenges::consumeLogin($id, $challenge));
    }

    public function testThePurgeKeepsASpentLoginRowUntilItsTimeIsUp(): void {
        [$id, $challenge] = WebAuthnChallenges::issueLogin();
        WebAuthnChallenges::consumeLogin($id, $challenge);

        $this->assertSame(0, WebAuthnChallenges::purge());
        $this->assertFalse(WebAuthnChallenges::consumeLogin($id, $challenge));

        $this->now += 301;
        $this->assertSame(1, WebAuthnChallenges::purge());
    }

    // -----------------------------------------------------------------
    // purge
    // -----------------------------------------------------------------

    public function testPurgeRemovesTheExpiredAndOnlyThose(): void {
        [$old] = WebAuthnChallenges::issue('stepup');
        [$spent, $spentChallenge] = WebAuthnChallenges::issue('stepup');
        WebAuthnChallenges::consume($spent, $spentChallenge, 'stepup', null);

        $this->now += 200;
        [$live] = WebAuthnChallenges::issue('stepup');
        $this->now += 101; // the first two are over, the third has 199 s left

        $this->assertSame(2, WebAuthnChallenges::purge());
        $this->assertSame([$live], array_map('intval', R::getCol('SELECT id FROM webauthn_challenges')));
        $this->assertNull(R::getCell('SELECT id FROM webauthn_challenges WHERE id = ?', [$old]));
    }

    public function testPurgeLeavesALiveSpentChallengeSoItStaysSpent(): void {
        [$id, $challenge] = WebAuthnChallenges::issue('stepup');
        WebAuthnChallenges::consume($id, $challenge, 'stepup', null);

        $this->assertSame(0, WebAuthnChallenges::purge());
        $this->assertFalse(WebAuthnChallenges::consume($id, $challenge, 'stepup', null));
    }

    public function testPurgeOnAnEmptyTableIsZero(): void {
        $this->assertSame(0, WebAuthnChallenges::purge());
    }
}
