<?php

namespace Eppitnic\Service;

use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Persistence\Passkeys;
use RedBeanPHP\R;

/**
 * What a proposed passkey setting does to the registered keys and to their
 * users, before it is saved: 'ok' (a way in is left), 'needs_enrolment' (keys
 * to register, which the user can do) or 'locked_out' (only a reset helps).
 *
 * @category    Net
 * @package     Eppitnic\Service\PasskeyImpact
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasskeyImpact
{
    public const OK = 'ok';
    public const NEEDS_ENROLMENT = 'needs_enrolment';
    public const LOCKED_OUT = 'locked_out';

    private const SEVERITY = [self::LOCKED_OUT => 0, self::NEEDS_ENROLMENT => 1, self::OK => 2];

    /**
     * @param array $proposed the setting as it would be (PasskeySettings::preview()'s second element)
     * @return array{keys_total: int, keys_noncompliant: int, users: array<int, array>, admins_able_after: int}
     *         keys_noncompliant counts keys that comply now and would not; users
     *         the ones whose compliant keys change, worst outcome first
     */
    public static function of(array $proposed): array {
        $current = PasskeySettings::get();
        $keys = R::getAll('SELECT user_id, aaguid, attestation_verified, backup_eligible FROM passkeys');
        $users = R::getAll(
            "SELECT id, username, role, signin_requirement, passkey_enroll_open, totp_secret
             FROM users WHERE active = 1 AND (role = 'admin' OR id IN (SELECT user_id FROM passkeys))"
        );

        // a new or cleared relying party deletes every key (PasskeySettings::set)
        $inUse = PasskeySettings::inUse();
        $deletes = $proposed['rp_id'] !== $current['rp_id']
            && ($inUse['passkeys'] > 0 || ($proposed['rp_id'] === '' && $inUse['users'] > 0));

        $before = self::compliant($keys, $current, false);
        $after = self::compliant($keys, $proposed, $deletes);
        $total = array_count_values(array_column($keys, 'user_id'));

        $noncompliant = 0;
        foreach ($keys as $i => $key) {
            $noncompliant += $before[$i] && ! $after[$i] ? 1 : 0;
        }
        $countBefore = self::perUser($keys, $before);
        $countAfter = self::perUser($keys, $after);

        $listed = [];
        $able = 0;
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $mode = (string) $user['signin_requirement'];
            $open = (int) $user['passkey_enroll_open'] === 1 || ($deletes && SigninRequirement::needsKeys($mode));
            $outcome = self::outcome($user, $countAfter[$id] ?? 0, $deletes ? 0 : ($total[$id] ?? 0), $proposed, $open);

            if ($user['role'] === 'admin' && $outcome !== self::LOCKED_OUT) {
                $able++;
            }
            if (($countBefore[$id] ?? 0) !== ($countAfter[$id] ?? 0)) {
                $listed[] = [
                    'id'                 => $id,
                    'username'           => (string) $user['username'],
                    'role'               => (string) $user['role'],
                    'signin_requirement' => $mode,
                    'compliant_before'   => $countBefore[$id] ?? 0,
                    'compliant_after'    => $countAfter[$id] ?? 0,
                    'minimum'            => SigninRequirement::minimumKeys($mode),
                    'outcome'            => $outcome,
                ];
            }
        }
        usort($listed, static fn(array $a, array $b): int =>
            [self::SEVERITY[$a['outcome']], $a['username']] <=> [self::SEVERITY[$b['outcome']], $b['username']]);

        return [
            'keys_total'        => count($keys),
            'keys_noncompliant' => $noncompliant,
            'users'             => $listed,
            'admins_able_after' => $able,
        ];
    }

    /**
     * The counts the `passkey_policy_changed` event carries.
     *
     * @return array<string, int>
     */
    public static function counts(array $impact): array {
        $outcomes = array_count_values(array_column($impact['users'], 'outcome'));
        return [
            'keys_noncompliant' => $impact['keys_noncompliant'],
            'users_affected'    => count($impact['users']),
            'needs_enrolment'   => $outcomes[self::NEEDS_ENROLMENT] ?? 0,
            'locked_out'        => $outcomes[self::LOCKED_OUT] ?? 0,
            'admins_able_after' => $impact['admins_able_after'],
        ];
    }

    /**
     * @param array<int, array> $keys
     * @return array<int, bool> whether each key complies, by position
     */
    private static function compliant(array $keys, array $settings, bool $deleted): array {
        if ($deleted) {
            return array_fill(0, count($keys), false);
        }
        $compromised = $settings['type'] === 'device_bound'
            ? AuthenticatorMetadata::compromised(array_column($keys, 'aaguid'))
            : [];
        return array_map(static fn(array $key): bool => PasskeyPolicy::complies($key, $settings, $compromised), $keys);
    }

    /**
     * @param array<int, array> $keys
     * @param array<int, bool> $compliant
     * @return array<int, int> user id => compliant keys
     */
    private static function perUser(array $keys, array $compliant): array {
        $counts = [];
        foreach ($keys as $i => $key) {
            if ($compliant[$i]) {
                $counts[(int) $key['user_id']] = ($counts[(int) $key['user_id']] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Mirrors the login: a way in with what the user has, else the setup they
     * could finish alone. For registering the keys still owed, a passkey
     * sign-in or the authenticator app is the second factor the password lacks,
     * and the open flag stands in for it.
     *
     * @param int $keys the user's compliant keys under the proposal
     * @param int $total all their keys, which the limit counts
     * @param bool $open whether the password alone may register keys
     */
    private static function outcome(array $user, int $keys, int $total, array $proposed, bool $open): string {
        $available = $proposed['rp_id'] !== '';
        $hasTotp = ! empty($user['totp_secret']);
        $row = [
            'signin_requirement'   => $user['signin_requirement'],
            'must_change_password' => 0,
        ];
        $roomForKeys = $available && $total < Passkeys::LIMIT;

        $ways = [['pwd', SigninRequirement::evaluate($row, $keys, $hasTotp, false, 'pwd', $available)]];
        if ($keys >= 1 && $available) {
            $ways[] = ['passkey', SigninRequirement::evaluate($row, $keys, $hasTotp, false, 'passkey', true)];
        }

        $best = self::LOCKED_OUT;
        foreach ($ways as [$method, $way]) {
            $outcome = match (true) {
                ! $way->methodAllowed => self::LOCKED_OUT,
                $way->required === null => self::OK,
                $way->required === SigninRequirement::PASSKEY_ENROLLMENT
                    => $roomForKeys && ($method === 'passkey' || $open || $hasTotp) ? self::NEEDS_ENROLMENT : self::LOCKED_OUT,
                $way->required === SigninRequirement::MFA_ENROLLMENT
                    => self::afterTotpSetup($row, $keys, $roomForKeys, $open, $available) ? self::NEEDS_ENROLMENT : self::LOCKED_OUT,
                default => self::LOCKED_OUT,
            };
            if (self::SEVERITY[$outcome] > self::SEVERITY[$best]) {
                $best = $outcome;
            }
        }
        return $best;
    }

    private static function afterTotpSetup(array $row, int $keys, bool $roomForKeys, bool $open, bool $available): bool {
        $next = SigninRequirement::evaluate($row, $keys, true, false, 'pwd', $available);
        return $next->required === null || ($next->required === SigninRequirement::PASSKEY_ENROLLMENT && $roomForKeys && $open);
    }
}
