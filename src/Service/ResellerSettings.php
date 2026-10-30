<?php

namespace Eppitnic\Service;

use RedBeanPHP\R;

/**
 * What a reseller has on file for filling in new contacts and domains: a
 * default country and contact type, default technical contacts, named sets of
 * nameservers with one of them as the default, and the DNSSEC algorithm and
 * digest type for new DS records. Stored on the `resellers` row (countrycode,
 * entitytype, techc, nssets, dnsset, dnssec_algorithm, dnssec_digesttype);
 * every method returns data and leaves reporting to the caller.
 *
 * @category    Net
 * @package     Eppitnic\Service\ResellerSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class ResellerSettings
{
    /** What the .it registry accepts for a DS record. */
    public const DNSSEC_ALGORITHMS = [3, 5, 6, 7, 8, 10, 12, 13, 14];
    public const DNSSEC_DIGEST_TYPES = [1, 2, 3, 4];

    public const MAX_TECH = 6;
    public const MIN_NAMESERVERS = 2;
    public const MAX_NAMESERVERS = 6;
    public const MAX_SET_NAME = 64;

    private const HANDLE = '/^[\w.-]{1,32}$/';

    // labels of 1-63 characters, at least two of them, the last not all digits
    // -- that is what keeps an IPv4 address out, which only a single domain's
    // glue records ever need
    private const HOSTNAME = '/^(?=.{1,253}$)(?!.*\.[0-9]+$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';

    /**
     * techc holds a JSON list; rows from before that hold one bare handle.
     *
     * @return string[]
     */
    public static function decodeTech(?string $raw): array {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] !== '[') {
            return [$raw];
        }
        $list = json_decode($raw, true);

        return is_array($list)
            ? array_values(array_filter(array_map('strval', $list), 'strlen'))
            : [];
    }

    /**
     * @return array<int, array{name: string, ns: string[]}>
     */
    public static function decodeSets(?string $raw): array {
        $sets = json_decode((string) $raw, true);
        if ( ! is_array($sets)) {
            return [];
        }

        $out = [];
        foreach ($sets as $set) {
            if (is_array($set) && isset($set['name'], $set['ns']) && is_array($set['ns'])) {
                $out[] = ['name' => (string) $set['name'], 'ns' => array_values(array_map('strval', $set['ns']))];
            }
        }
        return $out;
    }

    /**
     * @return array{countrycode: string, entitytype: int|null, techc: string[], dnsset: string, nssets: array, dnssec_algorithm: int|null, dnssec_digesttype: int|null}|null
     *         null if there is no such reseller
     */
    public static function load(int $resellerId): ?array {
        $row = R::getRow("SELECT countrycode, entitytype, techc, nssets, dnsset, dnssec_algorithm, dnssec_digesttype FROM resellers WHERE id = ?", [$resellerId]);
        if (empty($row)) {
            return null;
        }

        return [
            'countrycode' => (string) ($row['countrycode'] ?? ''),
            'entitytype'  => isset($row['entitytype']) ? (int) $row['entitytype'] : null,
            'techc'       => self::decodeTech($row['techc'] ?? null),
            'dnsset'      => (string) ($row['dnsset'] ?? ''),
            'nssets'      => self::decodeSets($row['nssets'] ?? null),
            'dnssec_algorithm'  => isset($row['dnssec_algorithm']) ? (int) $row['dnssec_algorithm'] : null,
            'dnssec_digesttype' => isset($row['dnssec_digesttype']) ? (int) $row['dnssec_digesttype'] : null,
        ];
    }

    /**
     * Change any of countrycode, entitytype, techc, dnsset, dnssec_algorithm and
     * dnssec_digesttype; what is not given stays.
     *
     * @return array{ok: bool, settings?: array, error?: string, status?: int}
     */
    public static function saveDefaults(int $resellerId, array $params): array {
        $current = self::load($resellerId);
        if ($current === null) {
            return self::failure(404, 'Reseller not found');
        }

        $fields = [];

        if (array_key_exists('countrycode', $params)) {
            $code = strtoupper(trim((string) $params['countrycode']));
            if ($code !== '' && preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                return self::failure(400, 'countrycode must be a two-letter ISO 3166-1 code');
            }
            $fields['countrycode'] = $code === '' ? null : $code;
        }

        if (array_key_exists('entitytype', $params)) {
            $type = $params['entitytype'];
            if ($type === null || $type === '' || $type === 0 || $type === '0') {
                $fields['entitytype'] = null;
            } elseif (is_scalar($type) && preg_match('/^[1-7]$/', (string) $type) === 1) {
                $fields['entitytype'] = (int) $type;
            } else {
                return self::failure(400, 'entitytype must be 1 to 7');
            }
        }

        foreach ([
            'dnssec_algorithm'  => self::DNSSEC_ALGORITHMS,
            'dnssec_digesttype' => self::DNSSEC_DIGEST_TYPES,
        ] as $key => $allowed) {
            if ( ! array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[$key];
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                $fields[$key] = null;
            } elseif (is_scalar($value) && ! is_bool($value) && preg_match('/^\d+$/', (string) $value) === 1
                && in_array((int) $value, $allowed, true)) {
                $fields[$key] = (int) $value;
            } else {
                return self::failure(400, "{$key} must be one of " . implode(', ', $allowed));
            }
        }

        if (array_key_exists('techc', $params)) {
            $tech = self::normalizeTech($params['techc']);
            if (is_string($tech)) {
                return self::failure(400, $tech);
            }
            $fields['techc'] = $tech === [] ? null : json_encode($tech);
        }

        if (array_key_exists('dnsset', $params)) {
            $name = trim((string) $params['dnsset']);
            $index = $name === '' ? null : self::indexOf($current['nssets'], $name);
            if ($name !== '' && $index === null) {
                return self::failure(400, "no NS set named '{$name}'");
            }
            $fields['dnsset'] = $index === null ? null : $current['nssets'][$index]['name'];
        }

        self::write($resellerId, $fields);

        return ['ok' => true, 'settings' => self::load($resellerId)];
    }

    /**
     * @param array $params name and ns[] of the new set
     * @return array{ok: bool, settings?: array, error?: string, status?: int}
     */
    public static function addSet(int $resellerId, array $params): array {
        $current = self::load($resellerId);
        if ($current === null) {
            return self::failure(404, 'Reseller not found');
        }

        $set = self::normalizeSet($params, $current['nssets'], null, null);
        if (isset($set['error'])) {
            return self::failure(400, $set['error']);
        }

        $sets = $current['nssets'];
        $sets[] = $set;
        self::write($resellerId, ['nssets' => self::encodeSets($sets)]);

        return ['ok' => true, 'settings' => self::load($resellerId)];
    }

    /**
     * Replace a set's nameservers, and rename it if the body says so; the
     * default follows the rename.
     *
     * @return array{ok: bool, settings?: array, error?: string, status?: int}
     */
    public static function replaceSet(int $resellerId, string $name, array $params): array {
        $current = self::load($resellerId);
        if ($current === null) {
            return self::failure(404, 'Reseller not found');
        }
        $index = self::indexOf($current['nssets'], $name);
        if ($index === null) {
            return self::failure(404, "NS set '{$name}' not found");
        }

        $set = self::normalizeSet($params, $current['nssets'], $index, $current['nssets'][$index]['name']);
        if (isset($set['error'])) {
            return self::failure(400, $set['error']);
        }

        $fields = [];
        if (strcasecmp($current['dnsset'], $current['nssets'][$index]['name']) === 0 && $current['dnsset'] !== '') {
            $fields['dnsset'] = $set['name'];
        }

        $sets = $current['nssets'];
        $sets[$index] = $set;
        $fields['nssets'] = self::encodeSets($sets);
        self::write($resellerId, $fields);

        return ['ok' => true, 'settings' => self::load($resellerId)];
    }

    /**
     * Remove a set; if it was the default, there is no default any more.
     *
     * @return array{ok: bool, settings?: array, error?: string, status?: int}
     */
    public static function removeSet(int $resellerId, string $name): array {
        $current = self::load($resellerId);
        if ($current === null) {
            return self::failure(404, 'Reseller not found');
        }
        $index = self::indexOf($current['nssets'], $name);
        if ($index === null) {
            return self::failure(404, "NS set '{$name}' not found");
        }

        $fields = [];
        if ($current['dnsset'] !== '' && strcasecmp($current['dnsset'], $current['nssets'][$index]['name']) === 0) {
            $fields['dnsset'] = null;
        }

        $sets = $current['nssets'];
        unset($sets[$index]);
        $fields['nssets'] = self::encodeSets($sets);
        self::write($resellerId, $fields);

        return ['ok' => true, 'settings' => self::load($resellerId)];
    }

    /**
     * @return string[]|string the cleaned list, or an error message
     */
    private static function normalizeTech(mixed $value): array|string {
        if ( ! is_array($value)) {
            return 'techc must be a list of contact handles';
        }

        $handles = [];
        foreach ($value as $handle) {
            $handle = trim((string) $handle);
            if ($handle === '') {
                continue;
            }
            if (preg_match(self::HANDLE, $handle) !== 1) {
                return "'{$handle}' is not a valid contact handle";
            }
            if ( ! in_array($handle, $handles, true)) {
                $handles[] = $handle;
            }
        }
        if (count($handles) > self::MAX_TECH) {
            return 'at most ' . self::MAX_TECH . ' technical contacts';
        }

        return $handles;
    }

    /**
     * @param int|null $ownIndex the set being replaced, so it does not clash
     *                 with its own name
     * @return array{name: string, ns: string[]}|array{error: string}
     */
    private static function normalizeSet(array $params, array $sets, ?int $ownIndex, ?string $fallbackName): array {
        $name = trim((string) ($params['name'] ?? $fallbackName ?? ''));
        if ($name === '') {
            return ['error' => 'name is required'];
        }
        if (mb_strlen($name) > self::MAX_SET_NAME) {
            return ['error' => 'name must be at most ' . self::MAX_SET_NAME . ' characters'];
        }
        // it travels in a URL path, so a slash cannot be part of it
        if (preg_match('#[/\x00-\x1f\x7f]#u', $name) !== 0) {
            return ['error' => 'name must not contain a slash or control characters'];
        }
        foreach ($sets as $i => $set) {
            if ($i !== $ownIndex && mb_strtolower($set['name']) === mb_strtolower($name)) {
                return ['error' => "an NS set named '{$name}' already exists"];
            }
        }

        $ns = $params['ns'] ?? null;
        if ( ! is_array($ns)) {
            return ['error' => 'ns must be a list of nameserver hostnames'];
        }
        $hosts = [];
        foreach ($ns as $host) {
            $host = strtolower(trim((string) $host));
            if (preg_match(self::HOSTNAME, $host) !== 1) {
                return ['error' => "'{$host}' is not a valid nameserver hostname"];
            }
            if (in_array($host, $hosts, true)) {
                return ['error' => "nameserver '{$host}' is listed twice"];
            }
            $hosts[] = $host;
        }
        if (count($hosts) < self::MIN_NAMESERVERS || count($hosts) > self::MAX_NAMESERVERS) {
            return ['error' => 'a set needs between ' . self::MIN_NAMESERVERS . ' and ' . self::MAX_NAMESERVERS . ' nameservers'];
        }

        return ['name' => $name, 'ns' => $hosts];
    }

    private static function indexOf(array $sets, string $name): ?int {
        foreach ($sets as $i => $set) {
            if (mb_strtolower($set['name']) === mb_strtolower(trim($name))) {
                return $i;
            }
        }
        return null;
    }

    private static function encodeSets(array $sets): ?string {
        return $sets === [] ? null : json_encode(array_values($sets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $fields column => value
     */
    private static function write(int $resellerId, array $fields): void {
        if ($fields === []) {
            return;
        }

        $set = [];
        $bind = [':id' => $resellerId];
        foreach ($fields as $column => $value) {
            $set[] = "{$column} = :{$column}";
            $bind[":{$column}"] = $value;
        }
        R::exec("UPDATE resellers SET " . implode(', ', $set) . " WHERE id = :id", $bind);
    }

    /**
     * @return array{ok: false, status: int, error: string}
     */
    private static function failure(int $status, string $error): array {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
