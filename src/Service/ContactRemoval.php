<?php

namespace Eppitnic\Service;

use Eppitnic\Epp\Contact;
use RedBeanPHP\R;

/**
 * Deleting one contact at the registry and, on success, deactivating it
 * locally -- shared by `contact delete` and `contact cleanup`.
 *
 * @category    Net
 * @package     Eppitnic\Service
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ContactRemoval
{
    public const DELETED = 'deleted';
    /** EPP 2303: the registry has no such contact */
    public const GONE    = 'gone';
    /** EPP 2305 / reason 8005: still referenced at the registry */
    public const LINKED  = 'linked';
    public const FAILED  = 'failed';

    /**
     * @param bool $deactivate whether to deactivate locally on success
     * @return string one of the outcome constants; on GONE, LINKED and FAILED
     *                the reason is in $contact->getError()
     */
    public static function delete(Contact $contact, string $handle, bool $deactivate = true): string {
        if ($contact->delete($handle)) {
            if ($deactivate) {
                self::deactivate($handle);
            }
            return self::DELETED;
        }

        return match (true) {
            $contact->svCode === '2303' => self::GONE,
            $contact->svCode === '2305', $contact->extValueReasonCode === '8005' => self::LINKED,
            default => self::FAILED,
        };
    }

    /**
     * Deactivated rather than removed: domains.registrant is a foreign key
     * onto contacts.handle, so the row has to stay.
     */
    public static function deactivate(string $handle): void {
        R::exec("UPDATE contacts SET active = 0 WHERE handle = ?", [$handle]);
    }
}
