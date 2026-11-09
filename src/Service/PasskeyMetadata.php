<?php

namespace Eppitnic\Service;

use Eppitnic\Persistence\AuthenticatorMetadata;
use Eppitnic\PowerDns\CurlHttpClient;
use Eppitnic\PowerDns\HttpClient;
use Psr\Clock\ClockInterface;

/**
 * The FIDO MDS3 metadata device_bound passkeys are held to: fetched or
 * imported, verified (MetadataBlob), never older than what is stored. Without
 * current metadata (usable()) no device_bound key can be registered.
 *
 * @category    Net
 * @package     Eppitnic\Service\PasskeyMetadata
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasskeyMetadata
{
    public const URL = 'https://mds3.fidoalliance.org/';
    public const ROOT_FILE = EPPITNIC_ROOT . '/config/certs/globalsign-root-r3.pem';

    /** the BLOB is about 11 MB; anything past this is not it */
    private const MAX_BYTES = 67108864;
    private const MEMORY = '256M';

    private static ?HttpClient $http = null;
    private static ?string $root = null;
    private static ?ClockInterface $clock = null;

    /** Test seam: the HTTP client, instead of curl. */
    public static function useHttpClient(?HttpClient $http): void {
        self::$http = $http;
    }

    /** Test seam: the PEM the signing chain must lead to, instead of the FIDO root. */
    public static function useRoot(?string $pem): void {
        self::$root = $pem;
    }

    /** Test seam: the time, instead of the system's. */
    public static function useClock(?ClockInterface $clock): void {
        self::$clock = $clock;
    }

    public static function now(): \DateTimeImmutable {
        return (self::$clock?->now() ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The system or test clock, read afresh each time it is asked. */
    public static function clock(): ClockInterface {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable {
                return PasskeyMetadata::now();
            }
        };
    }

    /**
     * Download the BLOB, then import() it.
     *
     * @return array{serial: int, next_update: string, entries: int}
     * @throws MetadataFailure
     */
    public static function refresh(): array {
        $http = self::$http ?? new CurlHttpClient(10, 180, true, self::MAX_BYTES);
        $response = $http->request('GET', self::URL, ['Accept: application/octet-stream'], null);

        if ($response['status'] !== 200 || $response['body'] === '') {
            $why = $response['status'] === 0 ? $response['error'] : 'HTTP ' . $response['status'];
            throw new MetadataFailure(MetadataFailure::FETCH, 'the BLOB could not be fetched: ' . ($why !== '' ? $why : 'an empty answer'));
        }
        return self::import($response['body']);
    }

    /**
     * Verify a BLOB and store it, unless its serial is lower than the stored
     * one.
     *
     * @return array{serial: int, next_update: string, entries: int}
     * @throws MetadataFailure
     */
    public static function import(string $jwt): array {
        self::roomForTheBlob();

        $now = self::now();
        $blob = MetadataBlob::verify($jwt, self::root(), $now);

        $stored = AuthenticatorMetadata::blob();
        if ($stored['serial'] !== null && $blob->serial < $stored['serial']) {
            throw new MetadataFailure(
                MetadataFailure::ROLLBACK,
                "the BLOB's serial {$blob->serial} is lower than the stored {$stored['serial']}"
            );
        }

        $stamp = $now->format('Y-m-d H:i:s');
        $entries = $blob->serial === $stored['serial'] && AuthenticatorMetadata::count() === count($blob->entries)
            ? count($blob->entries)
            : AuthenticatorMetadata::replaceAll($blob, $stamp);
        AuthenticatorMetadata::saveBlob($blob->serial, $blob->nextUpdate, $stamp);

        return ['serial' => $blob->serial, 'next_update' => $blob->nextUpdate, 'entries' => $entries];
    }

    /**
     * @return array{state: string, serial: ?int, next_update: ?string, fetched_at: ?string,
     *         entries: int, overdue: bool} state is 'ok' while the data is
     *         usable, 'stale' once past nextUpdate and its grace, else 'missing'
     */
    public static function status(): array {
        $blob = AuthenticatorMetadata::blob();
        $entries = AuthenticatorMetadata::count();
        $now = self::now();

        $state = match (true) {
            $blob['serial'] === null || $blob['next_update'] === null || $entries === 0 => 'missing',
            ! MetadataBlob::fresh($blob['next_update'], $now) => 'stale',
            default => 'ok',
        };
        $due = $blob['next_update'] === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $blob['next_update'], new \DateTimeZone('UTC'));
        $overdue = $due !== null && $due !== false && $now >= $due->modify('+1 day');

        return ['state' => $state, 'entries' => $entries, 'overdue' => $state !== 'missing' && $overdue] + $blob;
    }

    /** Whether device_bound registrations may be checked against what is stored. */
    public static function usable(): bool {
        return self::status()['state'] === 'ok';
    }

    private static function root(): string {
        $pem = self::$root ?? (string) @file_get_contents(self::ROOT_FILE);
        if ($pem === '') {
            throw new MetadataFailure(MetadataFailure::CHAIN, 'the FIDO root certificate is missing: ' . self::ROOT_FILE);
        }
        return $pem;
    }

    /** The BLOB, once decoded, is several times its size. */
    private static function roomForTheBlob(): void {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < ini_parse_quantity(self::MEMORY)) {
            ini_set('memory_limit', self::MEMORY);
        }
    }
}
