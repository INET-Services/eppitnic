<?php

namespace Eppitnic\Service;

/**
 * What a verified registration yields, ready for Persistence\Passkeys::add().
 *
 * @category    Net
 * @package     Eppitnic\Service\WebAuthnRegistration
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final readonly class WebAuthnRegistration
{
    /**
     * @param string $credentialId binary, as the authenticator made it
     * @param string $source the library's credential record, as JSON
     * @param string $aaguid canonical lower-case UUID
     * @param string[] $transports
     * @param bool $attestationVerified whether the attestation was checked
     *        and anchored to a trusted root; false for 'none' and self
     */
    public function __construct(
        public string $credentialId,
        public string $source,
        public string $aaguid,
        public int $signCount,
        public bool $backupEligible,
        public bool $backupState,
        public string $attestationFormat,
        public bool $attestationVerified,
        public array $transports,
    ) {
    }
}
