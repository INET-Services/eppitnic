<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Persistence\UserRules;
use Eppitnic\Support\AuthenticatorModels;
use RedBeanPHP\R;

/**
 * The `passkeys` setting: the relying party WebAuthn is bound to and the
 * authenticators it accepts; an empty `rp_id` means passkeys are off. Written
 * only through set(), which validates and records to `history`. Keys are bound
 * to the rp_id they were made for, so changing it deletes them (with confirm).
 *
 * @category    Net
 * @package     Eppitnic\Service\PasskeySettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasskeySettings
{
    public const TYPES = ['any', 'device_bound'];

    private const DEFAULTS = [
        'rp_id'         => '',
        'origins'       => [],
        'type'          => 'any',
        'models'        => [],
        'extra_aaguids' => [],
    ];

    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/';
    private const MODEL_KEY = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /** @return string[] every field this service knows, in declared order */
    public static function fields(): array {
        return array_keys(self::DEFAULTS);
    }

    /** @return array field => its current value, a missing one as its default */
    public static function get(): array {
        // an installation not upgraded yet has no row: passkeys are off
        try {
            $stored = (array) Config::get('passkeys');
        } catch (\RuntimeException) {
            $stored = [];
        }
        $settings = [];
        foreach (self::DEFAULTS as $field => $default) {
            $settings[$field] = $stored[$field] ?? $default;
        }
        return $settings;
    }

    public static function enabled(): bool {
        return self::get()['rp_id'] !== '';
    }

    /**
     * @param array $changes field => new value; null resets a field to its
     *        default. A blank rp_id switches passkeys off.
     * @return array{0: array, 1: array} [validated changes, resulting setting]
     * @throws \InvalidArgumentException on an unknown field or a refused value
     */
    public static function preview(array $changes): array {
        $unknown = array_diff(array_keys($changes), self::fields());
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'unknown field(s): ' . implode(', ', $unknown) .
                ' -- valid fields: ' . implode(', ', self::fields())
            );
        }

        $validated = [];
        foreach ($changes as $field => $value) {
            $validated[$field] = $value === null ? self::DEFAULTS[$field] : self::parse($field, $value);
        }

        $result = array_replace(self::get(), $validated);
        self::checkOriginsFit($result);
        return [$validated, $result];
    }

    /** The attestation registration asks an authenticator for. */
    public static function attestation(): string {
        return self::get()['type'] === 'device_bound' ? 'direct' : 'none';
    }

    /**
     * @return array{passkeys: int, users: int} the keys registered, and the
     *         users whose sign-in requirement wants keys
     */
    public static function inUse(): array {
        return [
            'passkeys' => (int) R::getCell('SELECT COUNT(*) FROM passkeys'),
            'users'    => count(self::keyUserIds()),
        ];
    }

    /**
     * preview(), then persist and record the change to `history`. A new or
     * cleared rp_id outdates every registered key; with $confirm they are
     * deleted and users who need one may register again.
     *
     * @param bool $confirm accept the deletion of keys that no longer work
     * @return array the setting after the change (get()'s shape)
     * @throws PasskeysInUse if keys would go, or users who need one are left
     *         without the means to register, and $confirm is not given
     * @throws \InvalidArgumentException see preview()
     */
    public static function set(array $changes, int $userId, bool $confirm = false): array {
        [$validated, $result] = self::preview($changes);

        $deleted = 0;
        if ($result['rp_id'] !== self::get()['rp_id']) {
            $inUse = self::inUse();
            $affected = $inUse['passkeys'] > 0 || ($result['rp_id'] === '' && $inUse['users'] > 0);
            if ($affected && ! $confirm) {
                throw new PasskeysInUse($inUse['passkeys'], $inUse['users']);
            }
            if ($affected) {
                $deleted = self::invalidate($userId, $inUse['passkeys']);
            }
        }

        Config::set('passkeys', $result);
        History::record('passkeys', 0, 'update', ['changes' => $validated] + ($deleted > 0 ? ['passkeys_deleted' => $deleted] : []), $userId);

        return $result;
    }

    /** @return int how many keys went */
    private static function invalidate(int $actorId, int $passkeys): int {
        $users = self::keyUserIds();
        $deleted = (int) R::exec('DELETE FROM passkeys');
        foreach ($users as $id) {
            UserRules::setEnrollOpen($id, true, $actorId, 'the relying party changed');
        }

        History::record('security', $actorId, 'secread', [
            'event'    => 'passkeys_invalidated',
            'passkeys' => max($passkeys, $deleted),
            'users'    => $users,
        ], $actorId);
        return $deleted;
    }

    /** @return int[] */
    private static function keyUserIds(): array {
        return array_map('intval', R::getCol(
            "SELECT id FROM users WHERE signin_requirement IN ('" . SigninRequirement::PASSKEY . "', '" . SigninRequirement::TOTP_OR_PASSKEY . "')"
        ));
    }

    private static function parse(string $field, mixed $value): mixed {
        return match ($field) {
            'rp_id'         => self::parseRpId($value),
            'origins'       => self::parseOrigins($value),
            'type'          => self::parseType($value),
            'models'        => self::parseModels($value),
            'extra_aaguids' => self::parseAaguids($value),
        };
    }

    private static function parseRpId(mixed $value): string {
        if ( ! is_string($value)) {
            throw new \InvalidArgumentException('rp_id must be a host name');
        }
        $id = strtolower(trim($value));
        if ($id === '' || $id === 'localhost') {
            return $id;
        }
        // browsers refuse an IP address as an RP ID; the numeric forms too
        if (filter_var($id, FILTER_VALIDATE_IP) !== false || preg_match('/^[0-9.]+$|[:\[\]]/', $id) === 1) {
            throw new \InvalidArgumentException('rp_id must be a host name, never an IP address');
        }
        if (preg_match(self::HOSTNAME, $id) !== 1) {
            throw new \InvalidArgumentException(
                'rp_id must be a host name such as eppitnic.example.it, without scheme, port or path'
            );
        }
        return $id;
    }

    /** @return string[] */
    private static function parseOrigins(mixed $value): array {
        $origins = [];
        foreach (self::asList('origins', $value) as $item) {
            $origin = is_string($item) ? AllowedOrigins::canonical($item) : null;
            if ($origin === null) {
                throw new \InvalidArgumentException(
                    "'" . self::show($item) . "' is not an origin -- give scheme, host and optional port"
                );
            }
            if (strtolower((string) parse_url($origin, PHP_URL_SCHEME)) === 'http'
                && parse_url($origin, PHP_URL_HOST) !== 'localhost') {
                throw new \InvalidArgumentException("'{$origin}' must be https (http is for localhost only)");
            }
            if ( ! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }
        return $origins;
    }

    private static function parseType(mixed $value): string {
        if ( ! in_array($value, self::TYPES, true)) {
            throw new \InvalidArgumentException('type must be one of: ' . implode(', ', self::TYPES));
        }
        return $value;
    }

    /** @return string[] */
    private static function parseModels(mixed $value): array {
        $models = [];
        foreach (self::asList('models', $value) as $item) {
            $key = is_string($item) ? strtolower(trim($item)) : '';
            if (preg_match(self::MODEL_KEY, $key) !== 1 || ! self::knownModel($key)) {
                throw new \InvalidArgumentException("'" . self::show($item) . "' is not a known authenticator model");
            }
            if ( ! in_array($key, $models, true)) {
                $models[] = $key;
            }
        }
        return $models;
    }

    /** @return string[] */
    private static function parseAaguids(mixed $value): array {
        $aaguids = [];
        foreach (self::asList('extra_aaguids', $value) as $item) {
            $aaguid = is_string($item) ? strtolower(trim($item)) : '';
            if (preg_match(self::UUID, $aaguid) !== 1) {
                throw new \InvalidArgumentException("'" . self::show($item) . "' is not an AAGUID (a UUID)");
            }
            // authenticators that do not identify themselves all send it
            if ($aaguid === self::NIL_UUID) {
                throw new \InvalidArgumentException('the all-zero AAGUID names no authenticator model');
            }
            if ( ! in_array($aaguid, $aaguids, true)) {
                $aaguids[] = $aaguid;
            }
        }
        return $aaguids;
    }

    private static function knownModel(string $key): bool {
        return AuthenticatorModels::has($key);
    }

    /** With an rp_id set there must be origins, and each at or below it. */
    private static function checkOriginsFit(array $settings): void {
        $rpId = $settings['rp_id'];
        if ($rpId === '') {
            return;
        }
        if ($settings['origins'] === []) {
            throw new \InvalidArgumentException('give at least one origin while rp_id is set');
        }
        foreach ($settings['origins'] as $origin) {
            $host = (string) parse_url($origin, PHP_URL_HOST);
            if ($host !== $rpId && ! str_ends_with($host, '.' . $rpId)) {
                throw new \InvalidArgumentException("origin '{$origin}' is not rp_id '{$rpId}' or below it");
            }
        }
    }

    private static function asList(string $field, mixed $value): array {
        if ( ! is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be a list");
        }
        return array_values($value);
    }

    private static function show(mixed $value): string {
        return is_scalar($value) ? (string) $value : gettype($value);
    }
}
