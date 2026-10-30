<?php

namespace Eppitnic\Support;

/**
 * Request-shape checks, run before anything reaches the registry.
 *
 * Deliberately shallow: enough to reject obvious nonsense early and cheaply.
 * The registry's schemas are the authority on what is actually acceptable.
 *
 * @category    Net
 * @package     Eppitnic\Support\Validate
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Validate
{
    /**
     * contacts table column widths; mirrors config/mariadb-schema.sql, which a
     * unit test enforces
     */
    public const CONTACT_FIELD_MAX_LENGTHS = [
        'handle'          => 32,
        'name'            => 256,
        'org'             => 256,
        'street'          => 256,
        'street2'         => 128,
        'street3'         => 128,
        'city'            => 128,
        'province'        => 128,
        'postalcode'      => 16,
        'countrycode'     => 2,
        'voice'           => 64,
        'fax'             => 64,
        'email'           => 256,
        'authinfo'        => 64,
        'nationalitycode' => 2,
        'regcode'         => 36,
        'schoolcode'      => 32,
    ];

    /**
     * users table column widths; mirrors config/mariadb-schema.sql, which a
     * unit test enforces
     */
    public const USER_FIELD_MAX_LENGTHS = [
        'email' => 256,
    ];

    /**
     * domains table column widths; mirrors config/mariadb-schema.sql, which a
     * unit test enforces
     */
    public const DOMAIN_FIELD_MAX_LENGTHS = [
        'domain'     => 255,
        'authinfo'   => 64,
        'registrant' => 32,
        'admin'      => 32,
    ];

    // -----------------------------------------------------------------
    // request validation
    // -----------------------------------------------------------------

    /**
     * @param array $params request parameters
     * @param array $fields required field names
     * @return string|null error message listing every missing field, or null if
     *                     none are missing
     */
    public static function requireFields(array $params, array $fields): ?string {
        $missing = [];
        foreach ($fields as $field) {
            if (empty($params[$field])) {
                $missing[] = $field;
            }
        }
        if ( ! empty($missing)) {
            return 'required field(s) missing or empty: ' . implode(', ', $missing);
        }
        return null;
    }

    /**
     * @param array $params request parameters
     * @param array $maxLengths field => max character length
     * @return string|null error message for the first field exceeding its
     *                     limit, or null if none do
     */
    public static function maxLength(array $params, array $maxLengths): ?string {
        foreach ($maxLengths as $field => $max) {
            if (isset($params[$field]) && is_string($params[$field]) && mb_strlen($params[$field]) > $max) {
                return "{$field} exceeds the maximum length of {$max} characters";
            }
        }
        return null;
    }

    /**
     * @param string $email address to check
     * @return bool status
     */
    public static function isEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * A real moment in `YYYY-MM-DD HH:MM:SS`, not just that shape: month 13 is
     * refused, and so is anything PHP would quietly roll over.
     */
    public static function isDatetime(string $value): bool {
        $parsed = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value;
    }

    /**
     * A real calendar day in `YYYY-MM-DD` form that is today or later.
     *
     * @return string|null the problem, or null when $value is acceptable
     */
    public static function futureDateError(mixed $value): ?string {
        if ( ! is_string($value)) {
            return 'date must be a day in YYYY-MM-DD form';
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return 'date must be a day in YYYY-MM-DD form';
        }
        return $value < date('Y-m-d') ? 'date must not be in the past' : null;
    }

    /**
     * basic .it domain name shape check -- not a full RFC-1035 validator,
     * just enough to reject obvious garbage before it reaches the registry
     */
    public static function isDomain(string $domain): bool {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.it$/i', $domain);
    }

    // -----------------------------------------------------------------
    // `epp` setting fields
    // -----------------------------------------------------------------

    /**
     * The registry's own schema rules, so they hold wherever a value is
     * accepted -- first-run setup and both `config epp-*` verbs validate here.
     * In the CLI alone, the web installer could seed what the CLI refused.
     *
     * @param string $field one of the FIELD_VALIDATORS keys
     * @return string|null a validation error, or null if $value is acceptable
     */
    public static function eppField(string $field, string $value): ?string {
        if (trim($value) !== $value || preg_match('/\s/', $value) === 1) {
            return "{$field} must not contain whitespace";
        }

        return match ($field) {
            // Client's transport only ever speaks TLS to the registry
            'server', 'server_deleted' => (str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false)
                ? null
                : "{$field} must be a valid https:// URL",

            // CURLOPT_PORT -- see Client::__construct()/Transport\Curl::setPort()
            'port' => (ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535)
                ? null
                : 'port must be between 1 and 65535',

            'interface' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                ? 'interface must be an IPv4 address'
                : null,

            'lang' => in_array($value, ['it', 'en'], true)
                ? null
                : "lang must be 'it' or 'en'",

            // set_clTRID() appends 17 characters; 32 keeps the clTRID well
            // inside epp:trIDStringType's 64. ASCII A-Z and 0-9 only:
            // stricter than the schema's token
            'cl_trid_prefix' => preg_match('/^[A-Z0-9]{1,32}$/', $value) === 1
                ? null
                : 'cl_trid_prefix must be 1 to 32 characters, A-Z and 0-9 only',

            // the registrar's own ID, as poll messages name it (acID, reID);
            // EPP users log in under other names
            'registrar_tag' => preg_match('/^[A-Z0-9][A-Z0-9._-]{0,59}-REG$/', $value) === 1
                ? null
                : "registrar_tag must end in '-REG', in upper case, at most 64 characters",

            // 3 to 64: eppcom:clIDType's 16 is too short for real nic.it
            // accounts. No suffix rule: EPP users need not end in -REG or -MNT
            'username' => (strlen($value) < 3 || strlen($value) > 64)
                ? 'username must be 3 to 64 characters'
                : null,

            // epp:pwType: 6 to 16 characters, for <pw> and <newPW> alike. The
            // registry's ceiling, not PasswordPolicy, which governs local
            // admin passwords only
            'password' => (strlen($value) < 6 || strlen($value) > 16)
                ? 'password must be 6 to 16 characters (EPP pwType)'
                : null,

            default => null,
        };
    }

    /**
     * $raw reduced to what eppField() accepts as a cl_trid_prefix, for values
     * nobody typed as one (derived from a username, carried over from 6.x).
     * Falls back to the schema's placeholder when nothing usable is left.
     */
    public static function toClTridPrefix(string $raw): string {
        $prefix = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($raw)), 0, 32);
        return $prefix !== '' ? $prefix : 'EPPITNIC';
    }
}
