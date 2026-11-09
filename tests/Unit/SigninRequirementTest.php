<?php

namespace Eppitnic\Tests\Unit;

use Eppitnic\Service\SigninRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a user's sign-in requirement asks, decided from plain facts.
 */
final class SigninRequirementTest extends TestCase
{
    private function evaluate(
        string $mode,
        int $keys = 0,
        bool $totp = false,
        bool $safe = false,
        string $method = 'pwd',
        bool $mustChange = false,
        bool $available = true
    ): SigninRequirement {
        return SigninRequirement::evaluate([
            'signin_requirement'   => $mode,
            'must_change_password' => (int) $mustChange,
        ], $keys, $totp, $safe, $method, $available);
    }

    // -----------------------------------------------------------------
    // minimum keys and full effect
    // -----------------------------------------------------------------

    /** @return array<string, array{string, int}> */
    public static function minimums(): array {
        return [
            'passkey'         => ['passkey', 1],
            'totp_or_passkey' => ['totp_or_passkey', 1],
            'totp'            => ['totp', 0],
            'none'            => ['none', 0],
        ];
    }

    #[DataProvider('minimums')]
    public function testMinimumKeys(string $mode, int $minimum): void {
        $this->assertSame($minimum, SigninRequirement::minimumKeys($mode));
        $this->assertSame($minimum, $this->evaluate($mode)->minimumKeys);
    }

    public function testFullEffectIsPasskeyModeWithEnoughKeys(): void {
        $this->assertFalse($this->evaluate('passkey', 0)->fullEffect);
        $this->assertTrue($this->evaluate('passkey', 1)->fullEffect);
        $this->assertTrue($this->evaluate('passkey', 3)->fullEffect);
        foreach (['totp_or_passkey', 'totp', 'none'] as $mode) {
            $this->assertFalse($this->evaluate($mode, 5)->fullEffect, $mode);
        }
    }

    // -----------------------------------------------------------------
    // which method may sign in
    // -----------------------------------------------------------------

    public function testAPasskeyMayAlwaysSignInWhenTheUserHasOne(): void {
        foreach (SigninRequirement::MODES as $mode) {
            $this->assertTrue($this->evaluate($mode, 1, method: 'passkey')->methodAllowed, $mode);
            $this->assertFalse($this->evaluate($mode, 0, method: 'passkey')->methodAllowed, $mode);
        }
    }

    public function testThePasswordIsRefusedOnlyByAKeyOnlyAccountInFullEffect(): void {
        foreach (['pwd', 'pwd+otp'] as $method) {
            $this->assertFalse($this->evaluate('passkey', 1, true, method: $method)->methodAllowed, $method);
            $this->assertFalse($this->evaluate('passkey', 1, true, safe: true, method: $method)->methodAllowed, "$method safe");
            $this->assertTrue($this->evaluate('passkey', 0, true, method: $method)->methodAllowed, "$method below minimum");
            foreach (['totp_or_passkey', 'totp', 'none'] as $mode) {
                $this->assertTrue($this->evaluate($mode, 3, true, method: $method)->methodAllowed, "$mode $method");
            }
        }
    }

    public function testAKeyOutranksTheBarePasswordOfModeNone(): void {
        foreach ([false, true] as $safe) {
            $this->assertFalse($this->evaluate('none', 1, safe: $safe)->methodAllowed, "key, no code, safe=$safe");
            $this->assertFalse($this->evaluate('none', 3, safe: $safe)->methodAllowed, "keys, no code, safe=$safe");
            $this->assertTrue($this->evaluate('none', 1, true, $safe)->methodAllowed, "a code stays a way in, safe=$safe");
            $this->assertTrue($this->evaluate('none', 0, safe: $safe)->methodAllowed, "no key, safe=$safe");
            $this->assertTrue($this->evaluate('none', 1, safe: $safe, method: 'passkey')->methodAllowed, "the key, safe=$safe");
            $this->assertTrue($this->evaluate('none', 1, safe: $safe, method: 'pwd+otp')->methodAllowed, "password and code, safe=$safe");
        }
        $this->assertTrue($this->evaluate('none', 1, available: false)->methodAllowed, 'no key counts while passkeys are off');
        $this->assertFalse($this->evaluate('none', 1)->fullEffect, 'only a new sign-in is refused, a session is not');
    }

    public function testTheTotpModesKeepThePasswordAsTheWayToTheirSetup(): void {
        foreach (['totp', 'totp_or_passkey'] as $mode) {
            $requirement = $this->evaluate($mode, 2);

            $this->assertTrue($requirement->methodAllowed, $mode);
            $this->assertSame('mfa_enrollment', $requirement->required, $mode);
            $this->assertTrue($this->evaluate($mode, 2, safe: true)->methodAllowed, "$mode safe");
        }
    }

    public function testAPendingPasswordChangeStillComesFirstForAKeyHolderInModeNone(): void {
        $requirement = $this->evaluate('none', 1, mustChange: true);

        $this->assertSame('password_change', $requirement->required);
        $this->assertFalse($requirement->methodAllowed);
    }

    // -----------------------------------------------------------------
    // what is still missing, in order
    // -----------------------------------------------------------------

    public function testThePasswordChangeComesFirstForEveryMode(): void {
        foreach (SigninRequirement::MODES as $mode) {
            foreach (['pwd', 'passkey'] as $method) {
                $requirement = $this->evaluate($mode, 1, method: $method, mustChange: true);
                $this->assertSame('password_change', $requirement->required, "$mode $method");
            }
        }
    }

    public function testMfaEnrolmentIsDueForTotpModesOutsideTheSafeNetworks(): void {
        $this->assertSame('mfa_enrollment', $this->evaluate('totp')->required);
        $this->assertSame('mfa_enrollment', $this->evaluate('totp_or_passkey', 1)->required);
        $this->assertNull($this->evaluate('totp', totp: true)->required);
        $this->assertNull($this->evaluate('totp', safe: true)->required);
        $this->assertNull($this->evaluate('totp_or_passkey', 1, safe: true)->required);
        $this->assertNull($this->evaluate('none')->required);
        $this->assertNull($this->evaluate('passkey', 1)->required, 'a key-only account never owes a code');
    }

    public function testMfaEnrolmentComesBeforeThePasskey(): void {
        $requirement = $this->evaluate('totp_or_passkey', 0);

        $this->assertSame('mfa_enrollment', $requirement->required);
    }

    public function testAPasskeyIsDueForBothKeyModesAndSafeNetworksNeverExcuseIt(): void {
        foreach ([false, true] as $safe) {
            $this->assertSame('passkey_enrollment', $this->evaluate('totp_or_passkey', 0, true, $safe)->required);
            $this->assertSame('passkey_enrollment', $this->evaluate('passkey', 0, false, $safe)->required);
        }
        $this->assertNull($this->evaluate('totp_or_passkey', 1, true)->required);
        $this->assertNull($this->evaluate('passkey', 1)->required);
    }

    public function testACodeDoesNotStandInForTheKey(): void {
        $this->assertSame('passkey_enrollment', $this->evaluate('passkey', 0, true)->required);
        $this->assertTrue($this->evaluate('passkey', 0, true)->methodAllowed, 'to enrol, not to get a token');
        $this->assertNull($this->evaluate('passkey', 1, true)->required);
    }

    public function testTheKeysStillNeeded(): void {
        $this->assertSame(1, $this->evaluate('passkey', 0)->passkeysNeeded);
        $this->assertSame(0, $this->evaluate('passkey', 1)->passkeysNeeded);
        $this->assertSame(0, $this->evaluate('passkey', 2)->passkeysNeeded);
        $this->assertSame(1, $this->evaluate('totp_or_passkey', 0, true)->passkeysNeeded);
        $this->assertSame(0, $this->evaluate('totp', 0)->passkeysNeeded);
    }

    public function testTheKeyCounterIsTheMinimumAndWhatIsThere(): void {
        $this->assertSame(
            ['passkeys_needed' => 0, 'passkeys_minimum' => 1, 'passkeys_have' => 1],
            $this->evaluate('passkey', 1)->keyCounter()
        );
        $this->assertSame(
            ['passkeys_needed' => 1, 'passkeys_minimum' => 1, 'passkeys_have' => 0],
            $this->evaluate('totp_or_passkey', 0, true)->keyCounter()
        );
        $this->assertSame(
            ['passkeys_needed' => 1, 'passkeys_minimum' => 1, 'passkeys_have' => 0],
            $this->evaluate('passkey', 3, available: false)->keyCounter(),
            'no key counts while passkeys are off'
        );
    }

    // -----------------------------------------------------------------
    // passkeys off
    // -----------------------------------------------------------------

    public function testNoKeyCountsWhilePasskeysAreOff(): void {
        $keyOnly = $this->evaluate('passkey', 3, false, true, available: false);
        $both = $this->evaluate('totp_or_passkey', 3, true, true, available: false);

        $this->assertFalse($keyOnly->fullEffect);
        $this->assertTrue($keyOnly->methodAllowed, 'the password is not refused, it only gets no token');
        $this->assertSame('passkey_enrollment', $keyOnly->required);
        $this->assertSame('passkey_enrollment', $both->required);
        $this->assertFalse($this->evaluate('none', 3, method: 'passkey', available: false)->methodAllowed);
    }

    // -----------------------------------------------------------------
    // loosening
    // -----------------------------------------------------------------

    /** @return array<string, array{string, string, bool}> */
    public static function changes(): array {
        return [
            'passkey to totp_or_passkey' => ['passkey', 'totp_or_passkey', true],
            'passkey to totp'            => ['passkey', 'totp', true],
            'totp_or_passkey to totp'    => ['totp_or_passkey', 'totp', true],
            'totp to none'               => ['totp', 'none', true],
            'passkey to none'            => ['passkey', 'none', true],
            'none to totp'               => ['none', 'totp', false],
            'totp to totp_or_passkey'    => ['totp', 'totp_or_passkey', false],
            'totp_or_passkey to passkey' => ['totp_or_passkey', 'passkey', false],
            'totp to passkey'            => ['totp', 'passkey', false],
            'unchanged'                  => ['totp_or_passkey', 'totp_or_passkey', false],
        ];
    }

    #[DataProvider('changes')]
    public function testLoosening(string $from, string $to, bool $loosens): void {
        $this->assertSame($loosens, SigninRequirement::loosens($from, $to));
    }

    public function testWhatTheModesWant(): void {
        $this->assertSame([true, false, true, false], array_map(SigninRequirement::needsTotp(...), ['totp', 'passkey', 'totp_or_passkey', 'none']));
        $this->assertSame([false, true, true, false], array_map(SigninRequirement::needsKeys(...), ['totp', 'passkey', 'totp_or_passkey', 'none']));
    }
}
