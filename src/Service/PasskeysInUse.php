<?php

namespace Eppitnic\Service;

/**
 * A change to the relying party would make registered passkeys useless, or
 * leave users who must have one with none; PasskeySettings::set() wants
 * `confirm` for that.
 *
 * @category    Net
 * @package     Eppitnic\Service\PasskeysInUse
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasskeysInUse extends \InvalidArgumentException
{
    /**
     * @param int $passkeys how many registered keys the change would delete
     * @param int $users how many users have a requirement that wants keys
     */
    public function __construct(public readonly int $passkeys, public readonly int $users) {
        parent::__construct(
            "this would delete {$passkeys} registered passkey(s); {$users} user(s) have a sign-in requirement that "
            . 'needs passkeys and would have to register again -- repeat with confirm to proceed'
        );
    }
}
