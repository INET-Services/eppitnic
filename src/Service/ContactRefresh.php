<?php

namespace Eppitnic\Service;

use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use RedBeanPHP\R;

/**
 * Contacts a domain operation needs stored locally, fetched from the registry.
 *
 * @category    Net
 * @package     Eppitnic\Service\ContactRefresh
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ContactRefresh
{
    /**
     * Make sure a contact is stored locally, fetching it from the registry
     * if not. A new row belongs to $resellerId, a stored one is untouched.
     *
     * @param Client $nic a logged-in client
     * @param int|null $actorId the acting user, for history; null for none
     * @return string|null why it is not stored, null when it is
     */
    public static function storeMissing(Client $nic, string $handle, int $resellerId, ?int $actorId): ?string {
        if ($handle === '' || R::getCell('SELECT id FROM contacts WHERE handle = ?', [$handle])) {
            return null;
        }

        $contact = new Contact($nic);
        if ( ! $contact->fetch($handle) || ! $contact->storeDB($resellerId, $actorId)) {
            return $contact->getError();
        }
        return null;
    }
}
