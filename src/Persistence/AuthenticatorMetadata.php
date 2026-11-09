<?php

namespace Eppitnic\Persistence;

use Eppitnic\Config;
use Eppitnic\Service\MetadataBlob;
use RedBeanPHP\R;

/**
 * The `authenticator_metadata` table (one row per AAGUID of the last verified
 * MDS3 BLOB) and the `passkey_metadata_blob` setting naming that BLOB.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\AuthenticatorMetadata
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class AuthenticatorMetadata
{
    /** the latest status of a model that takes its keys out of device_bound */
    public const COMPROMISED = [
        'REVOKED',
        'USER_VERIFICATION_BYPASS',
        'ATTESTATION_KEY_COMPROMISE',
        'USER_KEY_REMOTE_COMPROMISE',
        'USER_KEY_PHYSICAL_COMPROMISE',
    ];

    private const BLOB = 'passkey_metadata_blob';
    private const CHUNK = 400;

    /**
     * Replace the table with the BLOB's entries, all or nothing.
     *
     * @return int entries stored
     */
    public static function replaceAll(MetadataBlob $blob, string $now): int {
        R::begin();
        try {
            $gone = array_diff(R::getCol('SELECT aaguid FROM authenticator_metadata'), array_keys($blob->entries));
            foreach (array_chunk($gone, self::CHUNK) as $chunk) {
                R::exec('DELETE FROM authenticator_metadata WHERE aaguid IN (' . self::marks($chunk) . ')', $chunk);
            }
            foreach ($blob->entries as $aaguid => $entry) {
                R::exec(
                    'REPLACE INTO authenticator_metadata (aaguid, description, status, entry, updated_at)
                     VALUES (:aaguid, :description, :status, :entry, :now)',
                    [
                        ':aaguid'      => $aaguid,
                        ':description' => $entry['description'],
                        ':status'      => $entry['status'],
                        ':entry'       => json_encode($entry['entry'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        ':now'         => $now,
                    ]
                );
            }
            R::commit();
        } catch (\Throwable $e) {
            R::rollback();
            throw $e;
        }
        return count($blob->entries);
    }

    /**
     * @return array{aaguid: string, description: ?string, status: ?string, entry: array}|null
     */
    public static function find(string $aaguid): ?array {
        $row = R::getRow('SELECT aaguid, description, status, entry FROM authenticator_metadata WHERE aaguid = :aaguid', [
            ':aaguid' => strtolower($aaguid),
        ]);
        if (empty($row)) {
            return null;
        }
        $row['entry'] = (array) json_decode((string) $row['entry'], true) + [
            'attestationRootCertificates' => [], 'attestationTypes' => [], 'keyProtection' => [], 'matcherProtection' => [], 'statusReports' => [],
        ];
        return $row;
    }

    /**
     * @param string[] $aaguids
     * @return string[] those whose latest status is one of COMPROMISED
     */
    public static function compromised(array $aaguids): array {
        $aaguids = array_values(array_unique(array_map('strtolower', $aaguids)));
        $found = [];
        foreach (array_chunk($aaguids, self::CHUNK) as $chunk) {
            $found = [...$found, ...R::getCol(
                'SELECT aaguid FROM authenticator_metadata WHERE status IN (' . self::marks(self::COMPROMISED) . ')
                 AND aaguid IN (' . self::marks($chunk) . ')',
                [...self::COMPROMISED, ...$chunk]
            )];
        }
        return $found;
    }

    /**
     * @param string[] $aaguids
     * @return array<string, string> AAGUID => description, for those known
     */
    public static function descriptions(array $aaguids): array {
        $aaguids = array_values(array_unique(array_map('strtolower', $aaguids)));
        $found = [];
        foreach (array_chunk($aaguids, self::CHUNK) as $chunk) {
            foreach (R::getAll('SELECT aaguid, description FROM authenticator_metadata WHERE aaguid IN (' . self::marks($chunk) . ')', $chunk) as $row) {
                if ($row['description'] !== null && $row['description'] !== '') {
                    $found[$row['aaguid']] = $row['description'];
                }
            }
        }
        return $found;
    }

    public static function count(): int {
        return (int) R::getCell('SELECT COUNT(*) FROM authenticator_metadata');
    }

    /**
     * @return array{serial: ?int, next_update: ?string, fetched_at: ?string}
     */
    public static function blob(): array {
        try {
            $stored = (array) Config::get(self::BLOB);
        } catch (\RuntimeException) {
            $stored = [];
        }
        return [
            'serial'      => isset($stored['serial']) ? (int) $stored['serial'] : null,
            'next_update' => $stored['next_update'] ?? null,
            'fetched_at'  => $stored['fetched_at'] ?? null,
        ];
    }

    public static function saveBlob(int $serial, string $nextUpdate, string $fetchedAt): void {
        Config::set(self::BLOB, ['serial' => $serial, 'next_update' => $nextUpdate, 'fetched_at' => $fetchedAt]);
    }

    /** @param array<int, string|int> $values */
    private static function marks(array $values): string {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
