<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Persistence\User;
use Eppitnic\Service\PasskeyPolicy;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Tests\Support\PasskeyTestCase;
use Eppitnic\Tests\Support\SoftAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * Which stored passkeys meet the policy, and how many of a user's do.
 */
final class PasskeyPolicyTest extends PasskeyTestCase
{
    private const AAGUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const OTHER  = '11111111-2222-4333-8444-555555555555';

    private static function settings(array $more = []): array {
        return $more + ['rp_id' => 'localhost', 'origins' => [], 'type' => 'any', 'models' => [], 'extra_aaguids' => []];
    }

    private static function key(array $more = []): array {
        return $more + ['aaguid' => self::AAGUID, 'attestation_verified' => 1, 'backup_eligible' => 0];
    }

    /** @return array<string, array{array, array, bool}> */
    public static function keys(): array {
        $bound = ['type' => 'device_bound'];
        return [
            'any accepts a synced key'            => [self::key(['backup_eligible' => 1, 'attestation_verified' => 0]), self::settings(), true],
            'device_bound accepts an attested one' => [self::key(), self::settings($bound), true],
            'device_bound refuses unattested'     => [self::key(['attestation_verified' => 0]), self::settings($bound), false],
            'device_bound refuses a synced one'   => [self::key(['backup_eligible' => 1]), self::settings($bound), false],
            'extra AAGUID accepts a listed one'   => [self::key(), self::settings($bound + ['extra_aaguids' => [self::AAGUID]]), true],
            'extra AAGUID refuses an unlisted one' => [self::key(), self::settings($bound + ['extra_aaguids' => [self::OTHER]]), false],
            'AAGUID case does not matter'         => [self::key(['aaguid' => strtoupper(self::AAGUID)]), self::settings($bound + ['extra_aaguids' => [self::AAGUID]]), true],
            'a listed one still must be attested' => [self::key(['attestation_verified' => 0]), self::settings($bound + ['extra_aaguids' => [self::AAGUID]]), false],
            'a model narrows to its AAGUIDs'      => [self::key(), self::settings($bound + ['models' => ['yubikey5']]), false],
            'models plus a matching extra'        => [self::key(), self::settings($bound + ['models' => ['yubikey5'], 'extra_aaguids' => [self::AAGUID]]), true],
        ];
    }

    #[DataProvider('keys')]
    public function testCompliance(array $key, array $settings, bool $complies): void {
        $this->assertSame($complies, PasskeyPolicy::complies($key, $settings));
    }

    public function testCompliantKeysAreCountedPerUser(): void {
        $this->storedKey(new SoftAuthenticator(aaguid: self::AAGUID), [], 5);
        $this->storedKey(new SoftAuthenticator(aaguid: self::OTHER), [], 5);
        $this->storedKey(new SoftAuthenticator(), [], 6);

        $this->assertSame(2, PasskeyPolicy::compliantCount(5));
        $this->assertSame(1, PasskeyPolicy::compliantCount(6));
        $this->assertSame(0, PasskeyPolicy::compliantCount(7));
        $this->assertSame([5 => 2, 6 => 1], PasskeyPolicy::countsFor([5, 6, 7]));
        $this->assertSame([], PasskeyPolicy::countsFor([]));
    }

    public function testTighteningThePolicyDropsTheKeysFromTheCount(): void {
        $this->storedKey(new SoftAuthenticator(aaguid: self::AAGUID), [], 5);
        $this->storedKey(new SoftAuthenticator(aaguid: self::OTHER), [], 5);
        R::exec('UPDATE passkeys SET attestation_verified = 1');
        R::exec('UPDATE passkeys SET backup_eligible = 1 WHERE aaguid = ?', [self::OTHER]);
        $this->assertSame(2, PasskeyPolicy::compliantCount(5));

        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertSame(1, PasskeyPolicy::compliantCount(5));
    }

    /** @return array<string, array{string, bool}> */
    public static function statuses(): array {
        return [
            'REVOKED'                      => ['REVOKED', false],
            'USER_VERIFICATION_BYPASS'     => ['USER_VERIFICATION_BYPASS', false],
            'ATTESTATION_KEY_COMPROMISE'   => ['ATTESTATION_KEY_COMPROMISE', false],
            'USER_KEY_REMOTE_COMPROMISE'   => ['USER_KEY_REMOTE_COMPROMISE', false],
            'USER_KEY_PHYSICAL_COMPROMISE' => ['USER_KEY_PHYSICAL_COMPROMISE', false],
            'FIDO_CERTIFIED'               => ['FIDO_CERTIFIED', true],
            'FIDO_CERTIFIED_L2'            => ['FIDO_CERTIFIED_L2', true],
            'NOT_FIDO_CERTIFIED'           => ['NOT_FIDO_CERTIFIED', true],
            'UPDATE_AVAILABLE'             => ['UPDATE_AVAILABLE', true],
            'SELF_ASSERTION_SUBMITTED'     => ['SELF_ASSERTION_SUBMITTED', true],
        ];
    }

    #[DataProvider('statuses')]
    public function testWhatMds3SaysOfTheModelDecidesDeviceBound(string $status, bool $complies): void {
        $this->metadata([self::AAGUID => []], [self::AAGUID => $status]);

        $this->assertSame($complies, PasskeyPolicy::complies(self::key(), self::settings(['type' => 'device_bound'])));
    }

    public function testAWrittenOffModelIsNotSavedByBeingListed(): void {
        $this->metadata([self::AAGUID => []], [self::AAGUID => 'REVOKED']);
        $settings = self::settings(['type' => 'device_bound', 'extra_aaguids' => [self::AAGUID]]);

        $this->assertFalse(PasskeyPolicy::complies(self::key(), $settings));
    }

    public function testOnlyTheModelsOwnStatusCounts(): void {
        $this->metadata([self::AAGUID => [], self::OTHER => []], [self::OTHER => 'REVOKED']);

        $this->assertTrue(PasskeyPolicy::complies(self::key(), self::settings(['type' => 'device_bound'])));
        $this->assertFalse(PasskeyPolicy::complies(self::key(['aaguid' => strtoupper(self::OTHER)]), self::settings(['type' => 'device_bound'])));
    }

    public function testAModelWithoutAnEntryIsNotWrittenOff(): void {
        $this->metadata();

        $this->assertTrue(PasskeyPolicy::complies(self::key(), self::settings(['type' => 'device_bound'])));
    }

    public function testTheStatusIsReadFromTheStoredTableAndNeedsNoFreshMetadata(): void {
        $this->metadata([self::AAGUID => []], [self::AAGUID => 'USER_KEY_REMOTE_COMPROMISE'], '2020-01-01');

        $this->assertFalse(PasskeyPolicy::complies(self::key(), self::settings(['type' => 'device_bound'])));
    }

    public function testTheStatusMeansNothingWhereAnyKeyWillDo(): void {
        $this->metadata([self::AAGUID => []], [self::AAGUID => 'REVOKED']);
        R::exec('DROP TABLE authenticator_metadata');

        $this->assertTrue(PasskeyPolicy::complies(self::key(), self::settings()), 'not even looked up');
    }

    public function testAWrittenOffModelTakesItsKeysOutOfEveryonesCount(): void {
        $this->storedKey(new SoftAuthenticator(aaguid: self::AAGUID), [], 5);
        $this->storedKey(new SoftAuthenticator(aaguid: self::AAGUID), [], 6);
        $this->storedKey(new SoftAuthenticator(aaguid: self::OTHER), [], 6);
        R::exec('UPDATE passkeys SET attestation_verified = 1');
        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));
        $this->metadata([self::AAGUID => [], self::OTHER => []]);
        $this->assertSame([5 => 1, 6 => 2], PasskeyPolicy::countsFor([5, 6]));

        R::exec("UPDATE authenticator_metadata SET status = 'REVOKED' WHERE aaguid = ?", [self::AAGUID]);

        $this->assertSame([6 => 1], PasskeyPolicy::countsFor([5, 6]));
        $this->assertSame(0, PasskeyPolicy::compliantCount(5));
    }

    public function testASelectedModelAcceptsItsAaguidsAndNoOthers(): void {
        $yubikey = 'fa2b99dc-9e39-4257-8f92-4a30d23c4118';
        $this->metadata();
        $settings = self::settings(['type' => 'device_bound', 'models' => ['yubikey5']]);

        $this->assertTrue(PasskeyPolicy::complies(self::key(['aaguid' => $yubikey]), $settings));
        $this->assertFalse(PasskeyPolicy::complies(self::key(), $settings));
        $this->assertFalse(PasskeyPolicy::complies(self::key(['aaguid' => $yubikey]), self::settings(['type' => 'device_bound', 'models' => ['windows_hello']])));
        $this->assertTrue(PasskeyPolicy::complies(self::key(), ['extra_aaguids' => [self::AAGUID]] + $settings), 'an extra one beside the model');
    }

    public function testNoKeysNeedNoSettings(): void {
        Config::loadForTesting([]);

        $this->assertSame(0, PasskeyPolicy::compliantCount(5));
        $this->assertFalse(PasskeySettings::enabled(), 'a missing setting reads as off');
    }

    public function testKeysAreOnlyLookedUpForTheModesThatCountThem(): void {
        $this->storedKey(new SoftAuthenticator(), [], 5);

        $this->assertSame(0, PasskeyPolicy::usableKeys(['id' => 5, 'signin_requirement' => 'totp']));
        $this->assertSame(1, PasskeyPolicy::usableKeys(['id' => 5, 'signin_requirement' => 'none']));
        $this->assertSame(1, PasskeyPolicy::usableKeys(['id' => 5, 'signin_requirement' => 'passkey']));
        $this->assertSame(1, PasskeyPolicy::usableKeys(['id' => 5, 'signin_requirement' => 'totp_or_passkey']));
    }

    public function testNoKeyIsUsableWhilePasskeysAreOff(): void {
        $this->storedKey(new SoftAuthenticator(), [], 5);
        Config::set('passkeys', ['rp_id' => '', 'origins' => []] + Config::get('passkeys'));

        $this->assertSame(0, PasskeyPolicy::usableKeys(['id' => 5, 'signin_requirement' => 'passkey']));
        $this->assertSame(1, PasskeyPolicy::compliantCount(5), 'they are still on file');
    }

    public function testRowsGetTheirCounts(): void {
        $this->storedKey(new SoftAuthenticator(), [], 5);

        $rows = User::withPasskeyCounts([['id' => 5, 'username' => 'alice'], ['id' => 6, 'username' => 'bob']]);

        $this->assertSame([1, true], [$rows[0]['passkeys'], $rows[0]['has_passkeys']]);
        $this->assertSame([0, false], [$rows[1]['passkeys'], $rows[1]['has_passkeys']]);
        $this->assertSame([], User::withPasskeyCounts([]));
    }
}
