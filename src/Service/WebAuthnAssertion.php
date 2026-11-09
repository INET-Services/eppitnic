<?php

namespace Eppitnic\Service;

/**
 * What a verified assertion yields: the counter to store, and the flags the
 * authenticator reported (UV, BE, BS).
 *
 * @category    Net
 * @package     Eppitnic\Service\WebAuthnAssertion
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final readonly class WebAuthnAssertion
{
    public function __construct(
        public int $signCount,
        public bool $userVerified,
        public bool $backupEligible,
        public bool $backupState,
    ) {
    }
}
