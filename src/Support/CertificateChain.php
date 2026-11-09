<?php

namespace Eppitnic\Support;

use SpomkyLabs\Pki\CryptoEncoding\PEM;
use SpomkyLabs\Pki\X509\Certificate\Certificate;
use SpomkyLabs\Pki\X509\CertificationPath\CertificationPath;
use SpomkyLabs\Pki\X509\CertificationPath\PathValidation\PathValidationConfig;

/**
 * Whether a certificate chain leads to a given trust anchor at a given time.
 * RFC 5280 path validation, no network: revocation lists are not consulted.
 *
 * @category    Net
 * @package     Eppitnic\Support\CertificateChain
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class CertificateChain
{
    private const MAX_LENGTH = 6;

    /**
     * @param string[] $chain the certificates received, the end entity first;
     *        PEM, or base64 of the DER as in x5c
     * @param string $anchor the certificate trusted, in either form -- a root,
     *        an intermediate, or the end entity itself
     */
    public static function anchoredAt(array $chain, string $anchor, \DateTimeImmutable $at): bool {
        try {
            $anchorCert = self::parse($anchor);
            $certs = array_map(self::parse(...), array_values($chain));
            if ($certs === [] || count($certs) > self::MAX_LENGTH) {
                return false;
            }

            // a chain that carries the anchor ends there: nothing above is needed
            $anchorPem = $anchorCert->toPEM()->string();
            foreach ($certs as $i => $cert) {
                if ($cert->toPEM()->string() === $anchorPem) {
                    $certs = array_slice($certs, 0, $i);
                    break;
                }
            }
            if ($certs === []) {
                return self::valid($anchorCert, $at);
            }

            // the path runs from the certificate below the anchor to the end entity
            CertificationPath::create(...array_reverse($certs))
                ->validate(PathValidationConfig::create($at, self::MAX_LENGTH)->withTrustAnchor($anchorCert));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return string PEM, from PEM or base64 DER */
    public static function pem(string $certificate): string {
        if (str_contains($certificate, '-----BEGIN')) {
            return trim($certificate);
        }
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $certificate), 64, "\n") . '-----END CERTIFICATE-----';
    }

    private static function parse(string $certificate): Certificate {
        return Certificate::fromPEM(PEM::fromString(self::pem($certificate)));
    }

    private static function valid(Certificate $cert, \DateTimeImmutable $at): bool {
        $validity = $cert->tbsCertificate()->validity();
        return $validity->notBefore()->dateTime() <= $at && $at <= $validity->notAfter()->dateTime();
    }
}
