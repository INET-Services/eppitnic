<?php

namespace Eppitnic\Service;

use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use RedBeanPHP\R;

/**
 * Re-read contacts from the registry and store them, so the stored status
 * ("linked") follows an operation that changed which domains reference them.
 * Best effort: a failure is logged and never fails the operation it follows.
 * Domain sync does the same for the contacts it finds on domains.
 *
 * @category    Net
 * @package     Eppitnic\Service\ContactRefresh
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ContactRefresh
{
    /**
     * @param Client $nic a logged-in client
     * @param array<int, string|null> $handles contacts to refresh; those not
     *        stored locally are left alone
     * @param int $actorId the acting user, for history
     */
    public static function run(Client $nic, array $handles, int $actorId): void {
        foreach (array_unique(array_filter($handles)) as $handle) {
            try {
                if ( ! R::getCell('SELECT id FROM contacts WHERE handle = ?', [$handle])) {
                    continue;
                }
                $contact = new Contact($nic);
                if ( ! $contact->fetch($handle) || ! $contact->storeDB(0, $actorId)) {
                    error_log("eppitnic: contact '{$handle}' not refreshed: " . $contact->getError());
                }
            } catch (\Throwable $e) {
                error_log("eppitnic: contact '{$handle}' not refreshed: " . $e->getMessage());
            }
        }
    }
}
