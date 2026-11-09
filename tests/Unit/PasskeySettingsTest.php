<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Config;
use Eppitnic\Persistence\Passkeys;
use Eppitnic\Service\PasskeySettings;
use Eppitnic\Service\PasskeysInUse;
use Eppitnic\Service\WebAuthnRegistration;
use Eppitnic\Tests\Support\EppTestCase;
use Eppitnic\Tests\Support\TestAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use RedBeanPHP\R;

/**
 * The `passkeys` setting: what may be stored, and that nothing weaker than a
 * host name, an https origin at or below it, or a real AAGUID gets in.
 */
final class PasskeySettingsTest extends EppTestCase
{
    protected function setUp(): void {
        parent::setUp();

        if ( ! R::hasDatabase('default')) {
            R::setup('sqlite::memory:');
        }
        R::exec('DROP TABLE IF EXISTS settings');
        R::exec('DROP TABLE IF EXISTS history');
        R::exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        R::exec('CREATE TABLE history (id INTEGER PRIMARY KEY, timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
                 user_id INTEGER, object TEXT, object_id INTEGER, action TEXT, network TEXT, data TEXT)');
        R::exec('DROP TABLE IF EXISTS users');
        R::exec('DROP TABLE IF EXISTS passkeys');
        TestAccounts::ensure(1, 'admin', 1, 'root');

        Config::loadForTesting(static::SETTINGS + [
            'passkeys' => ['rp_id' => '', 'origins' => [], 'type' => 'any', 'models' => [], 'extra_aaguids' => []],
        ]);
    }

    private function rejects(array $changes, string $containing = ''): void {
        try {
            PasskeySettings::set($changes, 1);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($containing, $e->getMessage());
            $this->assertSame('', Config::get('passkeys')['rp_id'], 'a refused change was stored');
            return;
        }
        $this->fail('accepted ' . json_encode($changes));
    }

    public function testItShipsOff(): void {
        $this->assertFalse(PasskeySettings::enabled());
        $this->assertSame(['rp_id', 'origins', 'type', 'models', 'extra_aaguids'], PasskeySettings::fields());
    }

    public function testAMissingFieldShowsItsDefault(): void {
        Config::set('passkeys', ['rp_id' => 'example.it']);

        $this->assertSame(
            ['rp_id' => 'example.it', 'origins' => [], 'type' => 'any', 'models' => [], 'extra_aaguids' => []],
            PasskeySettings::get()
        );
    }

    public function testAnRpIdMakesItEnabled(): void {
        PasskeySettings::set(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']], 1);

        $this->assertTrue(PasskeySettings::enabled());
    }

    public function testAnEmptyRpIdSwitchesItOffAgain(): void {
        PasskeySettings::set(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']], 1);
        PasskeySettings::set(['rp_id' => ''], 1);

        $this->assertFalse(PasskeySettings::enabled());
    }

    // -----------------------------------------------------------------
    // rp_id
    // -----------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function addresses(): array {
        return [
            'IPv4'                  => ['127.0.0.1'],
            'another IPv4'          => ['192.0.2.7'],
            'IPv6 loopback'         => ['::1'],
            'bracketed IPv6'        => ['[::1]'],
            'IPv6'                  => ['2001:db8::1'],
            'a number as a host'    => ['2130706433'],
            'a short dotted number' => ['127.1'],
            'five numeric labels'   => ['1.2.3.4.5'],
        ];
    }

    #[DataProvider('addresses')]
    public function testAnIpAddressIsNeverAnRpId(string $address): void {
        $this->rejects(['rp_id' => $address, 'origins' => ['https://eppitnic.example.it']], 'never an IP address');
    }

    /** @return array<string, array{string}> */
    public static function notHostNames(): array {
        return [
            'a scheme'     => ['https://example.it'],
            'a port'       => ['example.it:8443'],
            'a path'       => ['example.it/login'],
            'a single label' => ['intranet'],
            'a wildcard'   => ['*.example.it'],
            'an underscore' => ['my_host.example.it'],
            'a leading hyphen' => ['-a.example.it'],
            'a trailing dot' => ['example.it.'],
            'an empty label' => ['a..example.it'],
            'a space'      => ['example it'],
            'an IDN'       => ['bücher.example.it'],
            'a 64-letter label' => [str_repeat('a', 64) . '.it'],
        ];
    }

    #[DataProvider('notHostNames')]
    public function testOnlyAHostNameIsAnRpId(string $value): void {
        $this->rejects(['rp_id' => $value, 'origins' => ['https://eppitnic.example.it']], 'rp_id');
    }

    public function testAnRpIdThatIsNoStringIsRefusedNotTakenForOff(): void {
        PasskeySettings::set(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']], 1);

        foreach ([5, true, ['localhost'], 1.5] as $value) {
            try {
                PasskeySettings::set(['rp_id' => $value], 1);
                $this->fail('accepted ' . json_encode($value));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
            }
        }
    }

    public function testLocalhostIsAnRpIdAndHostNamesAreLowerCased(): void {
        $this->assertSame('localhost', PasskeySettings::set(
            ['rp_id' => ' LocalHost ', 'origins' => ['http://localhost:5173']], 1
        )['rp_id']);
        $this->assertSame('eppitnic.example.it', PasskeySettings::set(
            ['rp_id' => 'EPPITNIC.Example.IT', 'origins' => ['https://eppitnic.example.it']], 1
        )['rp_id']);
    }

    public function testAPunycodeNameIsFine(): void {
        $result = PasskeySettings::set(['rp_id' => 'xn--bcher-kva.example.it', 'origins' => ['https://xn--bcher-kva.example.it']], 1);

        $this->assertSame('xn--bcher-kva.example.it', $result['rp_id']);
    }

    // -----------------------------------------------------------------
    // origins
    // -----------------------------------------------------------------

    public function testOriginsAreCanonicalAndDeduplicated(): void {
        $result = PasskeySettings::set([
            'rp_id'   => 'example.it',
            'origins' => ['HTTPS://Eppitnic.Example.IT/', 'https://eppitnic.example.it:443', 'https://example.it'],
        ], 1);

        $this->assertSame(['https://eppitnic.example.it', 'https://example.it'], $result['origins']);
    }

    /** @return array<string, array{string}> */
    public static function badOrigins(): array {
        return [
            'plain http on a real host' => ['http://eppitnic.example.it'],
            'http on an address'        => ['http://192.0.2.7'],
            'a path'                    => ['https://eppitnic.example.it/app'],
            'no scheme'                 => ['eppitnic.example.it'],
            'another scheme'            => ['ftp://eppitnic.example.it'],
            'credentials in it'         => ['https://user@eppitnic.example.it'],
            'a query'                   => ['https://eppitnic.example.it?x=1'],
            'an Android facet'          => ['android:apk-key-hash:abc'],
        ];
    }

    #[DataProvider('badOrigins')]
    public function testOnlyAnHttpsOriginIsAccepted(string $origin): void {
        $this->rejects(['rp_id' => 'eppitnic.example.it', 'origins' => [$origin]]);
    }

    public function testHttpIsForLocalhostOnly(): void {
        $this->assertSame(
            ['http://localhost:5173', 'http://localhost'],
            PasskeySettings::set(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173', 'http://localhost']], 1)['origins']
        );
    }

    public function testAnOriginMustBeTheRpIdOrBelowIt(): void {
        $this->rejects(['rp_id' => 'eppitnic.example.it', 'origins' => ['https://other.example.it']], 'is not rp_id');
        $this->rejects(['rp_id' => 'eppitnic.example.it', 'origins' => ['https://example.it']], 'is not rp_id');
        $this->rejects(['rp_id' => 'it', 'origins' => ['https://eppitnic.example.it']], 'rp_id');
        $this->rejects(['rp_id' => 'example.it', 'origins' => ['https://evilexample.it']], 'is not rp_id');
        $this->rejects(['rp_id' => 'localhost', 'origins' => ['http://127.0.0.1:5173']], 'must be https');
        $this->rejects(['rp_id' => 'localhost', 'origins' => ['https://127.0.0.1:5173']], 'is not rp_id');
    }

    public function testASubdomainOriginUnderTheRpIdIsFine(): void {
        $result = PasskeySettings::set(['rp_id' => 'example.it', 'origins' => ['https://a.b.example.it', 'https://example.it']], 1);

        $this->assertCount(2, $result['origins']);
    }

    public function testAnRpIdNeedsAnOrigin(): void {
        $this->rejects(['rp_id' => 'example.it'], 'at least one origin');
    }

    public function testChangingTheRpIdChecksTheOriginsAlreadyStored(): void {
        PasskeySettings::set(['rp_id' => 'example.it', 'origins' => ['https://example.it']], 1);

        try {
            PasskeySettings::set(['rp_id' => 'other.example'], 1);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('example.it', Config::get('passkeys')['rp_id']);
        }
    }

    public function testOriginsMustBeAList(): void {
        $this->rejects(['origins' => 'https://example.it'], 'must be a list');
    }

    // -----------------------------------------------------------------
    // the rest
    // -----------------------------------------------------------------

    public function testTypeIsAnyOrDeviceBound(): void {
        $this->assertSame('device_bound', PasskeySettings::set(['type' => 'device_bound'], 1)['type']);
        $this->assertSame('any', PasskeySettings::set(['type' => 'any'], 1)['type']);
        $this->rejects(['type' => 'synced'], 'type must be one of');
    }

    public function testModelsAreKeysDeduplicatedAndLowerCased(): void {
        $result = PasskeySettings::set(['models' => ['YubiKey5', 'google_titan', 'yubikey5']], 1);

        $this->assertSame(['yubikey5', 'google_titan'], $result['models']);
    }

    public function testAModelThatIsNoKeyIsRefused(): void {
        $this->rejects(['models' => ['Yubi Key!']], 'not a known authenticator model');
        $this->rejects(['models' => [['array']]], 'not a known authenticator model');
    }

    public function testAaguidsAreCanonicalLowerCaseUuids(): void {
        $result = PasskeySettings::set(['extra_aaguids' => [
            ' 2FC0579F-8113-47EA-B116-BB5A8DB9202A ', '2fc0579f-8113-47ea-b116-bb5a8db9202a', 'cb69481e-8ff7-4039-93ec-0a2729a154a8',
        ]], 1);

        $this->assertSame(['2fc0579f-8113-47ea-b116-bb5a8db9202a', 'cb69481e-8ff7-4039-93ec-0a2729a154a8'], $result['extra_aaguids']);
    }

    public function testAaguidsThatAreNoUuidsAreRefused(): void {
        $this->rejects(['extra_aaguids' => ['2fc0579f811347eab116bb5a8db9202a']], 'not an AAGUID');
        $this->rejects(['extra_aaguids' => ['not-a-uuid']], 'not an AAGUID');
        $this->rejects(['extra_aaguids' => [5]], 'not an AAGUID');
    }

    public function testTheAllZeroAaguidIsRefusedBecauseEveryAnonymousKeyHasIt(): void {
        $this->rejects(['extra_aaguids' => ['00000000-0000-0000-0000-000000000000']], 'all-zero');
    }

    public function testNullResetsAFieldToItsDefault(): void {
        PasskeySettings::set(['type' => 'device_bound', 'extra_aaguids' => ['2fc0579f-8113-47ea-b116-bb5a8db9202a']], 1);

        $result = PasskeySettings::set(['type' => null, 'extra_aaguids' => null], 1);

        $this->assertSame('any', $result['type']);
        $this->assertSame([], $result['extra_aaguids']);
    }

    public function testAnUnknownFieldIsRefused(): void {
        $this->rejects(['bogus' => 1], 'unknown field');
    }

    public function testAChangeIsStoredAndRecorded(): void {
        PasskeySettings::set(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']], 7);

        $this->assertSame('localhost', Config::get('passkeys')['rp_id']);
        $row = R::getRow("SELECT * FROM history WHERE object = 'passkeys'");
        $this->assertSame('update', $row['action']);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame(
            ['changes' => ['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']]],
            json_decode($row['data'], true)
        );
    }

    public function testPreviewWritesNothing(): void {
        [$validated, $result] = PasskeySettings::preview(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']]);

        $this->assertSame('localhost', $validated['rp_id']);
        $this->assertSame('localhost', $result['rp_id']);
        $this->assertSame('', Config::get('passkeys')['rp_id']);
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    // -----------------------------------------------------------------
    // keys are bound to the rp_id
    // -----------------------------------------------------------------

    private function configured(string $rpId = 'localhost', array $origins = ['http://localhost:5173']): void {
        Config::set('passkeys', ['rp_id' => $rpId, 'origins' => $origins] + Config::get('passkeys'));
    }

    private function addKey(int $userId): void {
        Passkeys::add($userId, new WebAuthnRegistration(
            random_bytes(32), '{}', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 0, false, false, 'none', false, []
        ), 'Key');
    }

    private function mode(int $userId, string $mode): void {
        TestAccounts::ensure($userId, 'user', 1, "user{$userId}");
        R::exec('UPDATE users SET signin_requirement = ? WHERE id = ?', [$mode, $userId]);
    }

    private function rpId(): string {
        return Config::get('passkeys')['rp_id'];
    }

    public function testChangingTheRpIdWhileKeysExistNeedsConfirmAndChangesNothingWithout(): void {
        $this->configured();
        $this->addKey(1);
        $this->addKey(1);
        $this->mode(2, 'passkey');

        try {
            PasskeySettings::set(['rp_id' => 'example.it', 'origins' => ['https://example.it']], 1);
            $this->fail('accepted');
        } catch (PasskeysInUse $e) {
            $this->assertSame([2, 1], [$e->passkeys, $e->users]);
            $this->assertStringContainsString('confirm', $e->getMessage());
        }

        $this->assertSame('localhost', $this->rpId());
        $this->assertSame(2, Passkeys::countForUser(1));
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM history'));
    }

    public function testConfirmingDeletesTheKeysOpensEnrolmentForKeyUsersAndRecordsTheCount(): void {
        $this->configured();
        $this->addKey(1);
        $this->addKey(2);
        $this->mode(2, 'passkey');
        $this->mode(3, 'totp_or_passkey');
        $this->mode(4, 'totp');
        $this->mode(5, 'none');

        PasskeySettings::set(['rp_id' => 'example.it', 'origins' => ['https://example.it']], 1, true);

        $this->assertSame('example.it', $this->rpId());
        $this->assertSame(0, (int) R::getCell('SELECT COUNT(*) FROM passkeys'));
        $this->assertSame([2, 3], array_map('intval', R::getCol('SELECT id FROM users WHERE passkey_enroll_open = 1 ORDER BY id')));

        $event = json_decode((string) R::getCell("SELECT data FROM history WHERE object = 'security'"), true);
        $this->assertSame('passkeys_invalidated', $event['event']);
        $this->assertSame(2, $event['passkeys']);
        $this->assertSame([2, 3], $event['users']);
        $this->assertSame(1, (int) R::getCell("SELECT user_id FROM history WHERE object = 'security'"));

        $setting = json_decode((string) R::getCell("SELECT data FROM history WHERE object = 'passkeys'"), true);
        $this->assertSame(2, $setting['passkeys_deleted']);
        $this->assertSame(2, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'users' AND data LIKE '%passkey_enroll_open%'"));
    }

    public function testClearingTheRpIdWithKeyUsersAndNoKeysStillNeedsConfirm(): void {
        $this->configured();
        $this->mode(2, 'passkey');

        $this->expectException(PasskeysInUse::class);
        try {
            PasskeySettings::set(['rp_id' => ''], 1);
        } finally {
            $this->assertSame('localhost', $this->rpId());
        }
    }

    public function testClearingTheRpIdWithConfirmSwitchesPasskeysOffAndOpensEnrolment(): void {
        $this->configured();
        $this->mode(2, 'passkey');

        PasskeySettings::set(['rp_id' => '', 'origins' => []], 1, true);

        $this->assertFalse(PasskeySettings::enabled());
        $this->assertSame(1, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 2'));
    }

    public function testOnlyKeyUsersAndNoKeysNeedNoConfirmToMoveToAnotherRpId(): void {
        $this->configured();
        $this->mode(2, 'passkey');

        PasskeySettings::set(['rp_id' => 'example.it', 'origins' => ['https://example.it']], 1);

        $this->assertSame('example.it', $this->rpId());
        $this->assertSame(0, (int) R::getCell('SELECT passkey_enroll_open FROM users WHERE id = 2'));
    }

    public function testTheFirstRpIdAndOtherChangesNeedNoConfirm(): void {
        PasskeySettings::set(['rp_id' => 'localhost', 'origins' => ['http://localhost:5173']], 1);
        $this->addKey(1);

        PasskeySettings::set(['origins' => ['http://localhost:5173', 'http://localhost:5174']], 1);
        PasskeySettings::set(['type' => 'device_bound'], 1);

        $this->assertSame(1, Passkeys::countForUser(1));
        $this->assertSame(0, (int) R::getCell("SELECT COUNT(*) FROM history WHERE object = 'security'"));
    }

    public function testASameRpIdWithConfirmDeletesNothing(): void {
        $this->configured();
        $this->addKey(1);

        PasskeySettings::set(['rp_id' => 'LOCALHOST'], 1, true);

        $this->assertSame(1, Passkeys::countForUser(1));
    }

    public function testInUseCountsKeysAndKeyUsers(): void {
        $this->addKey(1);
        $this->mode(2, 'passkey');
        $this->mode(3, 'totp_or_passkey');
        $this->mode(4, 'totp');

        $this->assertSame(['passkeys' => 1, 'users' => 2], PasskeySettings::inUse());
    }

    public function testRegistrationAsksForAttestationOnlyForDeviceBound(): void {
        $this->assertSame('none', PasskeySettings::attestation());

        Config::set('passkeys', ['type' => 'device_bound'] + Config::get('passkeys'));

        $this->assertSame('direct', PasskeySettings::attestation());
    }
}
