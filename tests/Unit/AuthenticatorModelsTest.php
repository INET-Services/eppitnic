<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Support\AuthenticatorModels;
use PHPUnit\Framework\TestCase;

/**
 * The model catalogue the settings page offers and device_bound is narrowed by.
 */
final class AuthenticatorModelsTest extends TestCase
{
    private const SECURITY_KEYS = [
        'yubikey5', 'yubikey_bio', 'yubico_security_key', 'google_titan', 'feitian',
        'token2', 'swissbit', 'nitrokey', 'solokeys', 'thales',
    ];

    public function testTheCatalogueHasTheTwoGroupsAndTheirModels(): void {
        $catalogue = AuthenticatorModels::catalogue();

        $this->assertSame(['security_keys', 'builtin'], array_keys($catalogue));
        $this->assertSame(self::SECURITY_KEYS, array_column($catalogue['security_keys'], 'key'));
        $this->assertSame(['windows_hello'], array_column($catalogue['builtin'], 'key'));
        foreach (array_merge(...array_values($catalogue)) as $model) {
            $this->assertSame(['key', 'label'], array_keys($model));
            $this->assertNotSame('', $model['label']);
        }
        $this->assertSame([...self::SECURITY_KEYS, 'windows_hello'], AuthenticatorModels::keys());
    }

    public function testEveryModelListsAaguidsAndNoTwoModelsShareOne(): void {
        $seen = [];
        foreach (AuthenticatorModels::keys() as $key) {
            $aaguids = AuthenticatorModels::aaguids([$key]);
            $this->assertNotSame([], $aaguids, $key);
            foreach ($aaguids as $aaguid) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $aaguid, $key);
                $this->assertNotSame('00000000-0000-0000-0000-000000000000', $aaguid);
                $this->assertArrayNotHasKey($aaguid, $seen, "{$aaguid} is in {$key} and " . ($seen[$aaguid] ?? ''));
                $seen[$aaguid] = $key;
            }
        }
    }

    public function testKnownAaguidsLandInTheirModels(): void {
        $this->assertContains('fa2b99dc-9e39-4257-8f92-4a30d23c4118', AuthenticatorModels::aaguids(['yubikey5']));
        $this->assertContains('f8a011f3-8c0a-4d15-8006-17111f9edc7d', AuthenticatorModels::aaguids(['yubico_security_key']));
        $this->assertContains('08987058-cadc-4b81-b6e1-30de50dcbe96', AuthenticatorModels::aaguids(['windows_hello']));
        $this->assertNotContains('6028b017-b1d4-4c02-b4b3-afcdafc96bb2', AuthenticatorModels::aaguids(['windows_hello']), 'the software authenticator is no hardware');
    }

    public function testSeveralModelsMergeWithoutRepeats(): void {
        $both = AuthenticatorModels::aaguids(['yubikey5', 'google_titan', 'yubikey5']);

        $this->assertSame($both, array_values(array_unique($both)));
        $this->assertCount(
            count(AuthenticatorModels::aaguids(['yubikey5'])) + count(AuthenticatorModels::aaguids(['google_titan'])),
            $both
        );
        $this->assertSame([], AuthenticatorModels::aaguids([]));
        $this->assertSame([], AuthenticatorModels::aaguids(['no_such_model']));
    }

    public function testHasKnowsTheKeys(): void {
        $this->assertTrue(AuthenticatorModels::has('yubikey5'));
        $this->assertFalse(AuthenticatorModels::has('Yubikey5'));
        $this->assertFalse(AuthenticatorModels::has('icloud_keychain'));
        $this->assertFalse(AuthenticatorModels::has(''));
    }

    public function testALabelIsFoundByAaguidWhateverItsCase(): void {
        $this->assertSame('YubiKey 5 Series', AuthenticatorModels::labelFor('FA2B99DC-9E39-4257-8F92-4A30D23C4118'));
        $this->assertSame('Windows Hello', AuthenticatorModels::labelFor('08987058-cadc-4b81-b6e1-30de50dcbe96'));
        $this->assertNull(AuthenticatorModels::labelFor('00000000-0000-0000-0000-000000000000'));
    }
}
