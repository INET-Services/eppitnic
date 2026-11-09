<?php

namespace Eppitnic\Tests\Support;

use Eppitnic\Support\CertificateChain;

/**
 * Throw-away certificate authorities, attestation certificates and signed MDS3
 * BLOBs, so no test depends on the real FIDO root or on a recorded chain. A
 * "party" is ['key' => OpenSSLAsymmetricKey, 'pem' => the certificate].
 */
final class TestPki
{
    private const CONFIG = <<<'CNF'
        [req]
        distinguished_name = dn
        [dn]
        [v3_ca]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash
        [v3_leaf]
        basicConstraints = CA:FALSE
        [v3_att]
        basicConstraints = CA:FALSE
        %s
        CNF;

    /** @return array{key: \OpenSSLAsymmetricKey, pem: string} a self-signed CA */
    public static function root(string $name = 'Test Root'): array {
        return self::issue(null, ['commonName' => $name], 'v3_ca');
    }

    /** @return array{key: \OpenSSLAsymmetricKey, pem: string} */
    public static function intermediate(array $parent, string $name = 'Test Intermediate'): array {
        return self::issue($parent, ['commonName' => $name], 'v3_ca');
    }

    /**
     * An attestation certificate as FIDO's packed format wants it.
     *
     * @param string|null $aaguid put in the certificate's AAGUID extension
     * @return array{key: \OpenSSLAsymmetricKey, pem: string}
     */
    public static function attestation(array $parent, ?string $aaguid = null, string $name = 'Test Authenticator'): array {
        return self::issue($parent, [
            'countryName' => 'SE', 'organizationName' => 'Test Vendor',
            'organizationalUnitName' => 'Authenticator Attestation', 'commonName' => $name,
        ], 'v3_att', $aaguid);
    }

    /** @return array{key: \OpenSSLAsymmetricKey, pem: string} an RSA end entity, to sign a BLOB with */
    public static function signer(array $parent, string $name = 'Test MDS Signer'): array {
        return self::issue($parent, ['commonName' => $name], 'v3_leaf', null, true);
    }

    /** @return string the certificate as base64 of its DER, as x5c carries it */
    public static function der(array $party): string {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $party['pem']);
    }

    /**
     * A signed JWT, as the MDS server sends its BLOB.
     *
     * @param array $x5c certificates, the signer first
     */
    public static function jwt(array $payload, array $signer, array $x5c, string $alg = 'RS256'): string {
        $header = self::b64(json_encode(['alg' => $alg, 'typ' => 'JWT', 'x5c' => array_map(self::der(...), $x5c)]));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        openssl_sign("{$header}.{$body}", $signature, $signer['key'], OPENSSL_ALGO_SHA256);
        return "{$header}.{$body}." . self::b64($signature);
    }

    /**
     * A BLOB payload of authenticators, each AAGUID => [description, roots (PEMs),
     * statuses (status strings, oldest first), attestationTypes].
     *
     * @return array the payload
     */
    public static function payload(array $authenticators, int $serial = 1, ?string $nextUpdate = null): array {
        $entries = [];
        foreach ($authenticators as $aaguid => $a) {
            $entries[] = [
                'aaguid'            => $aaguid,
                'metadataStatement' => [
                    'aaguid'                      => $aaguid,
                    'description'                 => $a['description'] ?? 'Test Authenticator',
                    'icon'                        => 'data:image/png;base64,' . str_repeat('AAAA', 50),
                    'attestationTypes'            => $a['attestationTypes'] ?? ['basic_full'],
                    'keyProtection'               => ['hardware'],
                    'attestationRootCertificates' => array_map(static fn(string $pem): string => preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), $a['roots'] ?? []),
                ],
                'statusReports'     => array_map(
                    static fn(string $status, int $i): array => ['status' => $status, 'effectiveDate' => sprintf('2024-01-%02d', $i + 1)],
                    $a['statuses'] ?? ['FIDO_CERTIFIED'],
                    array_keys($a['statuses'] ?? ['FIDO_CERTIFIED'])
                ),
            ];
        }
        return ['legalHeader' => 'test', 'no' => $serial, 'nextUpdate' => $nextUpdate ?? date('Y-m-d', time() + 30 * 86400), 'entries' => $entries];
    }

    /** @return array{key: \OpenSSLAsymmetricKey, pem: string} */
    private static function issue(?array $parent, array $subject, string $section, ?string $aaguid = null, bool $rsa = false): array {
        $key = $rsa
            ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        $extra = $aaguid === null ? '' : '1.3.6.1.4.1.45724.1.1.4 = DER:0410' . str_replace('-', '', $aaguid);
        $config = tempnam(sys_get_temp_dir(), 'pki');
        file_put_contents($config, sprintf(self::CONFIG, $extra));
        try {
            $csr = openssl_csr_new($subject, $key, ['config' => $config, 'digest_alg' => 'sha256']);
            $certificate = openssl_csr_sign(
                $csr,
                $parent === null ? null : CertificateChain::pem($parent['pem']),
                $parent === null ? $key : $parent['key'],
                30,
                ['config' => $config, 'x509_extensions' => $section, 'digest_alg' => 'sha256'],
                random_int(1, PHP_INT_MAX)
            );
            openssl_x509_export($certificate, $pem);
        } finally {
            unlink($config);
        }
        return ['key' => $key, 'pem' => $pem];
    }

    private static function b64(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
