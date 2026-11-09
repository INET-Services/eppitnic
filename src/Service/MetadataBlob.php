<?php

namespace Eppitnic\Service;

use Eppitnic\Support\CertificateChain;

/**
 * A verified FIDO MDS3 BLOB: an RS256 JWT whose x5c chain leads to the FIDO
 * root, not past its nextUpdate plus the grace. Keeps of each entry only what
 * attestation checks need; nothing is read before signature and chain hold.
 *
 * @category    Net
 * @package     Eppitnic\Service\MetadataBlob
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final readonly class MetadataBlob
{
    /** days after nextUpdate that the BLOB, and the data taken from it, is still used */
    public const GRACE_DAYS = 7;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MAX_CHAIN = 6;

    /**
     * @param array<string, array{description: ?string, status: ?string, entry: array}> $entries by AAGUID
     */
    private function __construct(
        public int $serial,
        public string $nextUpdate,
        public array $entries,
    ) {
    }

    /**
     * @param string $jwt the BLOB as the server sends it
     * @param string $rootPem the certificate the signing chain must lead to
     * @throws MetadataFailure
     */
    public static function verify(string $jwt, string $rootPem, \DateTimeImmutable $now): self {
        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            throw new MetadataFailure(MetadataFailure::MALFORMED, 'not a signed JWT');
        }
        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode((string) self::decode($header64), true);
        $x5c = is_array($header) ? ($header['x5c'] ?? null) : null;
        if ( ! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            throw new MetadataFailure(MetadataFailure::MALFORMED, 'the JWT is not signed with RS256');
        }
        if ( ! is_array($x5c) || $x5c === [] || count($x5c) > self::MAX_CHAIN || array_filter($x5c, 'is_string') !== $x5c) {
            throw new MetadataFailure(MetadataFailure::MALFORMED, 'the JWT carries no certificate chain');
        }

        $signature = self::decode($signature64);
        $key = openssl_pkey_get_public(CertificateChain::pem($x5c[0]));
        if ($signature === null || $key === false
            || openssl_verify($header64 . '.' . $payload64, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new MetadataFailure(MetadataFailure::SIGNATURE, 'the signature does not verify');
        }
        if ( ! CertificateChain::anchoredAt($x5c, $rootPem, $now)) {
            throw new MetadataFailure(MetadataFailure::CHAIN, 'the signing certificate does not chain to the FIDO root');
        }

        $payload = json_decode((string) self::decode($payload64), true);
        $serial = is_array($payload) ? ($payload['no'] ?? null) : null;
        $next = is_array($payload) ? ($payload['nextUpdate'] ?? null) : null;
        if ( ! is_int($serial) || $serial < 0 || ! is_string($next) || self::day($next) === null
            || ! is_array($payload['entries'] ?? null)) {
            throw new MetadataFailure(MetadataFailure::MALFORMED, 'the payload lacks no, nextUpdate or entries');
        }

        if ( ! self::fresh($next, $now)) {
            throw new MetadataFailure(MetadataFailure::STALE, "the BLOB is past its nextUpdate {$next}");
        }

        return new self($serial, $next, self::entries($payload['entries'], $now));
    }

    /** Whether data fetched with this nextUpdate may still be used at $now. */
    public static function fresh(string $nextUpdate, \DateTimeImmutable $now): bool {
        $day = self::day($nextUpdate);
        return $day !== null && $now < $day->modify('+' . (self::GRACE_DAYS + 1) . ' days');
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<string, array{description: ?string, status: ?string, entry: array}>
     */
    private static function entries(array $raw, \DateTimeImmutable $now): array {
        $entries = [];
        foreach ($raw as $item) {
            $aaguid = is_array($item) && is_string($item['aaguid'] ?? null) ? strtolower($item['aaguid']) : '';
            $statement = $item['metadataStatement'] ?? null;
            if (preg_match(self::UUID, $aaguid) !== 1 || ! is_array($statement)) {
                continue;
            }

            $reports = self::reports($item['statusReports'] ?? [], $now);
            $description = $statement['description'] ?? null;
            $entries[$aaguid] = [
                'description' => is_string($description) ? mb_substr($description, 0, 255) : null,
                'status'      => $reports === [] ? null : end($reports)['status'],
                'entry'       => [
                    'attestationRootCertificates' => self::strings($statement['attestationRootCertificates'] ?? []),
                    'attestationTypes'            => self::strings($statement['attestationTypes'] ?? []),
                    'keyProtection'               => self::strings($statement['keyProtection'] ?? []),
                    'matcherProtection'           => self::strings($statement['matcherProtection'] ?? []),
                    'statusReports'               => $reports,
                ],
            ];
        }
        return $entries;
    }

    /**
     * The reports in force at $now, oldest first; of two on one day the one
     * listed later is the newer.
     *
     * @return array<int, array{status: string, effectiveDate: string}>
     */
    private static function reports(mixed $raw, \DateTimeImmutable $now): array {
        $reports = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $i => $report) {
            $status = is_array($report) ? ($report['status'] ?? null) : null;
            $date = is_array($report) ? ($report['effectiveDate'] ?? '') : '';
            if ( ! is_string($status) || ! is_string($date) || ($day = self::day($date)) === null || $day > $now) {
                continue;
            }
            $reports[] = ['status' => mb_substr($status, 0, 64), 'effectiveDate' => $date, 'order' => $i];
        }
        usort($reports, static fn(array $a, array $b): int => [$a['effectiveDate'], $a['order']] <=> [$b['effectiveDate'], $b['order']]);
        return array_map(static fn(array $r): array => ['status' => $r['status'], 'effectiveDate' => $r['effectiveDate']], $reports);
    }

    /** @return string[] */
    private static function strings(mixed $value): array {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function day(string $date): ?\DateTimeImmutable {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    private static function decode(string $text): ?string {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        return $bytes === false ? null : $bytes;
    }
}
