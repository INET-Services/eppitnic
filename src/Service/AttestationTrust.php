<?php

namespace Eppitnic\Service;

use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\Support\CertificateChain;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\TrustPath\CertificateTrustPath;

/**
 * Whether a well-formed, signed attestation statement leads to a root FIDO MDS3
 * lists for the AAGUID it claims. Only then is the AAGUID, and the backup flag
 * with it, more than the key's word.
 *
 * @category    Net
 * @package     Eppitnic\Service\AttestationTrust
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class AttestationTrust
{
    private const NIL_AAGUID = '00000000-0000-0000-0000-000000000000';

    /** library attestation type => the name MDS3 gives it */
    private const MDS_TYPES = [
        AttestationStatement::TYPE_BASIC  => 'basic_full',
        AttestationStatement::TYPE_ATTCA  => 'attca',
        AttestationStatement::TYPE_ANONCA => 'anonca',
    ];

    /**
     * 'none' and self attestation prove nothing, and fido-u2f has no AAGUID to
     * look up (a U2F key cannot verify the user or hold a passkey anyway).
     */
    public static function anchored(AttestationStatement $statement, string $aaguid, \DateTimeImmutable $at): bool {
        $type = self::MDS_TYPES[$statement->type] ?? null;
        $path = $statement->trustPath;
        $aaguid = strtolower($aaguid);
        if ($type === null || ! $path instanceof CertificateTrustPath || $statement->fmt === 'fido-u2f' || $aaguid === self::NIL_AAGUID) {
            return false;
        }

        $metadata = AuthenticatorMetadata::find($aaguid);
        if ($metadata === null) {
            return false;
        }
        $types = $metadata['entry']['attestationTypes'];
        if ($types !== [] && ! in_array($type, $types, true)) {
            return false;
        }
        foreach ($metadata['entry']['attestationRootCertificates'] as $root) {
            if (CertificateChain::anchoredAt($path->certificates, $root, $at)) {
                return true;
            }
        }
        return false;
    }
}
