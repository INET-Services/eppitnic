<?php

namespace Eppitnic\Service;

/**
 * What a login must prove and what is still missing, from one user's
 * `signin_requirement`. Pure: the caller supplies the facts (compliant keys,
 * a TOTP secret, the network) and decides what to do with the answer.
 *
 * @category    Net
 * @package     Eppitnic\Service\SigninRequirement
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final readonly class SigninRequirement
{
    public const PASSKEY = 'passkey';
    public const TOTP = 'totp';
    public const TOTP_OR_PASSKEY = 'totp_or_passkey';
    public const NONE = 'none';

    public const MODES = [self::PASSKEY, self::TOTP, self::TOTP_OR_PASSKEY, self::NONE];

    public const PASSWORD_CHANGE = 'password_change';
    public const MFA_ENROLLMENT = 'mfa_enrollment';
    public const PASSKEY_ENROLLMENT = 'passkey_enrollment';

    /** answered where the account may not register keys on its password */
    public const SIGNIN_RESET = 'signin_reset';
    public const SIGNIN_RESET_MESSAGE = 'Ask an administrator to reset your sign-in';

    public const METHODS = ['pwd', 'pwd+otp', 'passkey'];

    /** higher is stricter */
    private const STRENGTH = [self::NONE => 0, self::TOTP => 1, self::TOTP_OR_PASSKEY => 2, self::PASSKEY => 3];

    /**
     * @param bool $fullEffect "passkey" with enough compliant keys: the
     *        password (and its code) no longer signs in
     * @param bool $methodAllowed whether the method just used may sign in;
     *        a session is judged by $fullEffect alone, so a password session
     *        opened before the first key stays valid
     * @param string|null $required the first setup still missing, one of the
     *        *_ENROLLMENT / PASSWORD_CHANGE constants; no token until done
     * @param int $passkeysNeeded keys still to register when $required is
     *        PASSKEY_ENROLLMENT
     * @param int $passkeysHave compliant keys counted; none while passkeys
     *        are off
     */
    private function __construct(
        public string $mode,
        public int $minimumKeys,
        public bool $fullEffect,
        public bool $methodAllowed,
        public ?string $required,
        public int $passkeysNeeded,
        public int $passkeysHave,
    ) {
    }

    /**
     * @param array $user users row: signin_requirement, must_change_password
     * @param int $keys the user's compliant passkeys
     * @param bool $hasTotp whether a TOTP secret is set up
     * @param bool $safeNetwork whether the client is in `safe_networks`, which
     *        skips the TOTP code and its enrolment but never a passkey
     * @param string $method what was just used: one of METHODS
     * @param bool $passkeysAvailable false while passkeys are switched off;
     *        no key then counts, and none can be used or registered
     */
    public static function evaluate(
        array $user,
        int $keys,
        bool $hasTotp,
        bool $safeNetwork,
        string $method,
        bool $passkeysAvailable = true
    ): self {
        $mode = (string) $user['signin_requirement'];
        $minimum = self::minimumKeys($mode);
        $keys = $passkeysAvailable ? $keys : 0;
        $enough = $keys >= $minimum;
        $fullEffect = $mode === self::PASSKEY && $enough;

        // below the minimum the password is no way in, only to the key setup;
        // a key set up beyond the mode (and no code) replaces the bare password
        $outranked = $method === 'pwd' && $keys >= 1 && ! $hasTotp && ! self::needsTotp($mode);
        $methodAllowed = $method === 'passkey' ? $keys >= 1 : ! $fullEffect && ! $outranked;

        $required = match (true) {
            (int) $user['must_change_password'] === 1
                => self::PASSWORD_CHANGE,
            self::needsTotp($mode) && ! $hasTotp && ! $safeNetwork
                => self::MFA_ENROLLMENT,
            ! $enough
                => self::PASSKEY_ENROLLMENT,
            default => null,
        };

        return new self($mode, $minimum, $fullEffect, $methodAllowed, $required, max(0, $minimum - $keys), $keys);
    }

    /**
     * The key counter an enrollment answer carries.
     *
     * @return array<string, int> passkeys_needed, _minimum and _have
     */
    public function keyCounter(): array {
        return [
            'passkeys_needed'  => $this->passkeysNeeded,
            'passkeys_minimum' => $this->minimumKeys,
            'passkeys_have'    => $this->passkeysHave,
        ];
    }

    public static function minimumKeys(string $mode): int {
        return self::needsKeys($mode) ? 1 : 0;
    }

    /** Whether the mode wants a TOTP secret set up. */
    public static function needsTotp(string $mode): bool {
        return $mode === self::TOTP || $mode === self::TOTP_OR_PASSKEY;
    }

    /** Whether the mode wants passkeys, so counting them matters. */
    public static function needsKeys(string $mode): bool {
        return $mode === self::PASSKEY || $mode === self::TOTP_OR_PASSKEY;
    }

    /**
     * Whether going from one mode to the other asks the user for less.
     */
    public static function loosens(string $fromMode, string $toMode): bool {
        return self::STRENGTH[$toMode] < self::STRENGTH[$fromMode];
    }
}
