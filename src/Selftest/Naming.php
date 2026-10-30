<?php

namespace Eppitnic\Selftest;

/**
 * The names one self-test run gives its objects, all carrying the same base-36
 * timestamp: it keeps a run clear of earlier leftovers and dates those, so
 * `selftest reap` knows what to retry. Six characters until 2038.
 *
 * @category    Net
 * @package     Eppitnic\Selftest\Naming
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Naming
{
    /**
     * Marks every object a self-test made. Deliberately not a word that could
     * occur in a real handle: `selftest reap` deletes what matches it.
     */
    public const PREFIX = 'ST';

    /** how a handle made by this class looks, once the run's stamp is in it */
    private const HANDLE_PATTERN = '/^' . self::PREFIX . '([0-9A-Z]{6})[A-Z][0-9]$/';

    /** and how a domain does */
    private const DOMAIN_PATTERN = '/^' . self::PREFIX . '\-([0-9A-Z]{6})\-[0-9]+\.IT$/';

    /** the run's stamp, shared by every name it hands out */
    public readonly string $stamp;

    /**
     * @param int|null $at the moment to stamp names with; null for now
     */
    public function __construct(?int $at = null) {
        $this->stamp = strtoupper(base_convert((string) ($at ?? time()), 10, 36));
    }

    // -----------------------------------------------------------------
    // making names
    // -----------------------------------------------------------------

    /**
     * @param string $role one letter saying what the contact is for: R
     *                     registrant, D disposable
     * @param int $index distinguishes two contacts in the same role
     * @return string a contact handle, e.g. 'STTLJD1SR1'
     */
    public function handle(string $role, int $index = 1): string {
        return self::PREFIX . $this->stamp . strtoupper($role) . $index;
    }

    /**
     * @param int $index distinguishes two domains in the same run
     * @return string a domain name, e.g. 'st-tljd1s-1.it'
     */
    public function domain(int $index = 1): string {
        return strtolower(self::PREFIX . '-' . $this->stamp . '-' . $index) . '.it';
    }

    // -----------------------------------------------------------------
    // reading them back
    // -----------------------------------------------------------------

    /**
     * When the run that made $handle started.
     *
     * @param string $handle a contact handle, from the registry or a local row
     * @return int|null the Unix timestamp, or null if this was not ours
     */
    public static function madeAt(string $handle): ?int {
        return self::stampToTime(self::HANDLE_PATTERN, $handle);
    }

    /**
     * When the run that made $domain started.
     *
     * @param string $domain a domain name, from the registry or a local row
     * @return int|null the Unix timestamp, or null if this was not ours
     */
    public static function domainMadeAt(string $domain): ?int {
        return self::stampToTime(self::DOMAIN_PATTERN, $domain);
    }

    /**
     * When a run with this stamp started.
     *
     * @param string $stamp the six-character stamp on its own
     * @return int|null the Unix timestamp, or null if it is not a stamp
     */
    public static function timeOfStamp(string $stamp): ?int {
        return self::stampToTime('/^([0-9A-Z]{6})$/', $stamp);
    }

    /**
     * Whether $name -- a handle or a domain -- was made by a self-test run.
     */
    public static function isOurs(string $name): bool {
        return self::madeAt($name) !== null || self::domainMadeAt($name) !== null;
    }

    /**
     * @param string $pattern one of the two above, capturing the stamp
     * @return int|null the moment the stamp encodes, or null
     */
    private static function stampToTime(string $pattern, string $subject): ?int {
        if ( ! preg_match($pattern, strtoupper(trim($subject)), $match)) {
            return null;
        }
        $at = (int) base_convert($match[1], 36, 10);

        // A stamp from the future is not something this class produced: either
        // the clock moved or the name only happens to look like ours. Either
        // way it must not be treated as ripe for deletion.
        return $at > 0 && $at <= time() ? $at : null;
    }
}
