<?php

namespace Eppitnic\Service;

use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Support\AuthenticatorModels;
use RedBeanPHP\R;

/**
 * Whether a stored passkey meets the `passkeys` setting, and how many of a
 * user's do. Only compliant keys count anywhere: for the minimum, for signing
 * in, and for keeping a session.
 *
 * @category    Net
 * @package     Eppitnic\Service\PasskeyPolicy
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasskeyPolicy
{
    /**
     * Whether a key meets the type, the models and what MDS3 says of its model.
     * Under 'any' it always does: the AAGUID is then only the key's own word.
     *
     * @param array $passkey a passkeys row, or any array with aaguid,
     *        attestation_verified and backup_eligible
     * @param array $settings PasskeySettings::get()'s shape
     * @param string[]|null $compromised the AAGUIDs MDS3 has written off, when
     *        the caller looked them up for many keys at once (countsFor)
     */
    public static function complies(array $passkey, array $settings, ?array $compromised = null): bool {
        if ($settings['type'] !== 'device_bound') {
            return true;
        }
        // without verified attestation the flags and the AAGUID are self-reported
        if ((int) $passkey['attestation_verified'] !== 1 || (int) $passkey['backup_eligible'] !== 0) {
            return false;
        }

        $aaguid = strtolower((string) $passkey['aaguid']);
        if (in_array($aaguid, $compromised ?? AuthenticatorMetadata::compromised([$aaguid]), true)) {
            return false;
        }

        $extra = $settings['extra_aaguids'];
        if ($settings['models'] === [] && $extra === []) {
            return true;
        }
        return in_array($aaguid, $extra, true) || in_array($aaguid, AuthenticatorModels::aaguids($settings['models']), true);
    }

    /**
     * @return int how many of the user's keys comply with the current setting
     */
    public static function compliantCount(int $userId): int {
        return self::countsFor([$userId])[$userId] ?? 0;
    }

    /**
     * One query for any number of users.
     *
     * @param int[] $userIds
     * @return array<int, int> user id => compliant keys; users without any are absent
     */
    public static function countsFor(array $userIds): array {
        if ($userIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($userIds), '?'));
        $rows = R::getAll(
            "SELECT user_id, aaguid, attestation_verified, backup_eligible FROM passkeys WHERE user_id IN ({$marks})",
            array_values($userIds)
        );
        if ($rows === []) {
            return [];
        }

        $settings = PasskeySettings::get();
        $compromised = $settings['type'] === 'device_bound'
            ? AuthenticatorMetadata::compromised(array_column($rows, 'aaguid'))
            : [];
        $counts = [];
        foreach ($rows as $row) {
            if (self::complies($row, $settings, $compromised)) {
                $uid = (int) $row['user_id'];
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * What the user's keys can do now: none while passkeys are off, and no
     * query for "totp", which never looks at keys ("none" does: a key
     * replaces its bare password).
     *
     * @param array $user a row with id and signin_requirement
     */
    public static function usableKeys(array $user): int {
        if ($user['signin_requirement'] === SigninRequirement::TOTP || ! PasskeySettings::enabled()) {
            return 0;
        }
        return self::compliantCount((int) $user['id']);
    }
}
