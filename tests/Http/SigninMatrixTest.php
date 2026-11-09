<?php

namespace Eppitnic\Tests\Http;

use Eppitnic\Tests\Support\PasskeyHttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every sign-in requirement against every way of signing in: with the
 * password (and its code, where one is due), or with a passkey; from inside
 * or outside the safe networks; with 0, 1 or 2 keys, TOTP or not, and the
 * account's enrolment open or not. The expectations are written out per mode,
 * not derived from the code under test.
 */
final class SigninMatrixTest extends PasskeyHttpTestCase
{
    /** @return iterable<string, array> */
    public static function cases(): iterable {
        foreach (['passkey', 'totp_or_passkey', 'totp', 'none'] as $mode) {
            foreach ([0, 1, 2] as $keys) {
                foreach ([false, true] as $totp) {
                    foreach ([false, true] as $safe) {
                        foreach (['password', 'passkey'] as $method) {
                            foreach (in_array($mode, ['passkey', 'totp_or_passkey'], true) ? [false, true] : [false] as $open) {
                                if ($method === 'passkey' && $keys === 0) {
                                    continue;
                                }
                                $name = sprintf(
                                    '%s, %d key(s), %s, %s, %s, enrolment %s', $mode, $keys,
                                    $totp ? 'TOTP' : 'no TOTP', $safe ? 'safe network' : 'outside', $method, $open ? 'open' : 'closed'
                                );
                                yield $name => [$mode, $keys, $totp, $safe, $method, $open, self::expected($mode, $keys, $totp, $safe, $method, $open)];
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array{status: int, required?: string, needed?: int, minimum?: int, have?: int, amr?: string}
     */
    private static function expected(string $mode, int $keys, bool $totp, bool $safe, string $method, bool $open): array {
        $minimum = in_array($mode, ['passkey', 'totp_or_passkey'], true) ? 1 : 0;

        // a key-only account has no password sign-in once it has its key, and
        // with no code set up neither has "none": a key beyond the mode
        // replaces the bare password
        if ($method === 'password' && $keys >= 1 && ($mode === 'passkey' || ($mode === 'none' && ! $totp))) {
            return ['status' => 403, 'required' => 'passkey'];
        }
        // the code is skipped on a safe network, and so is setting it up
        if (in_array($mode, ['totp', 'totp_or_passkey'], true) && ! $totp && ! $safe) {
            return ['status' => 403, 'required' => 'mfa_enrollment'];
        }
        // a safe network never excuses a key, and no code stands in for it
        if ($minimum > $keys) {
            // registering one takes the code just checked, or an open enrolment
            $mayEnrol = $open || ($totp && ! $safe);
            return $mayEnrol
                ? ['status' => 403, 'required' => 'passkey_enrollment', 'needed' => $minimum - $keys, 'minimum' => $minimum, 'have' => $keys]
                : ['status' => 403, 'required' => 'signin_reset'];
        }
        return ['status' => 200, 'amr' => $method === 'passkey' ? 'passkey' : ($totp && ! $safe ? 'pwd+otp' : 'pwd')];
    }

    #[DataProvider('cases')]
    public function testSigningIn(string $mode, int $keyCount, bool $totp, bool $safe, string $method, bool $open, array $expected): void {
        $keys = $this->keys($keyCount);
        $this->requirement($mode, 5, $open);
        if ($totp) {
            $this->withTotp();
        }
        $this->from($safe ? self::SAFE : self::OUTSIDE);

        $response = $method === 'passkey'
            ? $this->passkey($keys[0])
            : $this->password($totp && ! $safe ? ['totp' => $this->code()] : []);
        $body = self::json($response);

        $this->assertSame($expected['status'], $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->denied(), 'a requirement is not a failed login');
        if ($expected['status'] === 200) {
            $this->assertSame($expected['amr'], $body['amr']);
            $this->assertSame($expected['amr'] !== 'pwd', $body['strong_auth']);
            $this->assertSame($method === 'passkey', isset($body['cred']));
            $this->assertSame($totp && ! $safe && $method === 'password', $body['totp_verified']);
            $this->assertSame($keyCount > 0, $body['has_passkeys']);
            $this->assertSame($expected['amr'] === 'pwd' && ($totp || $keyCount > 0) && ! $safe, $body['needs_second_factor']);
            return;
        }
        $this->assertSame($expected['required'], $body['required']);
        $this->assertArrayNotHasKey('token', $body);
        if (isset($expected['needed'])) {
            $this->assertSame($expected['needed'], $body['passkeys_needed']);
            $this->assertSame($expected['minimum'], $body['passkeys_minimum']);
            $this->assertSame($expected['have'], $body['passkeys_have']);
            $this->assertTrue($body['passkeys_available']);
            $this->assertArrayNotHasKey('enroll', $body);
        }
        if ($expected['required'] === 'signin_reset') {
            $this->assertSame('Ask an administrator to reset your sign-in', $body['error']);
        }
    }
}
