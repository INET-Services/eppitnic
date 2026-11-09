<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;

/**
 * The one place that knows each scheduled job's settings key, its editable
 * fields and their validators -- shared by every `config *-set` CLI command
 * and `PATCH /v1/cronjobs/{job}`, so neither reimplements the other's
 * validation or forgets to audit a change. `set()` is the only way either
 * caller writes one of these settings.
 *
 * @category    Net
 * @package     Eppitnic\Service\CronjobSettings
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class CronjobSettings
{
    /**
     * job => [settingsKey, [field => validator]]. `keepalive`'s settingsKey
     * is null: it is a bare bool, not an object, so get()/set() special-case
     * it rather than forcing it into a shape it was never given.
     *
     * validators: 'bool', 'positive-int', 'range:MIN,MAX', 'pdns-apis',
     * 'pdns-nameservers'.
     */
    private const JOBS = [
        'pdns' => ['pdns', [
            'enabled'          => 'bool',
            'apis'             => 'pdns-apis',
            'nameservers'      => 'pdns-nameservers',
            'ttl'              => 'positive-int',
            'delay_hours'      => 'positive-int',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'domain_sync' => ['domain_sync', [
            'enabled'          => 'bool',
            'batch_size'       => 'range:1,500',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'domain_reap_deletions' => ['domain_reap_deletions', [
            'enabled'          => 'bool',
            'frequency_minutes' => 'range:1,1440',
        ]],
        // 'enabled' defaults true (see the schema seed): disabling it stops
        // the registry password from auto-rotating on a passwdReminder, a
        // real foot-gun, but the operator's call to make -- see
        // PollProcessCommand and CronjobSettingsDialog's warning
        'poll_process' => ['poll_process', [
            'enabled'          => 'bool',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'passkey_challenge_purge' => ['passkey_challenge_purge', [
            'enabled'          => 'bool',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'passkey_metadata' => ['passkey_metadata', [
            'enabled'          => 'bool',
            'frequency_minutes' => 'range:1,1440',
        ]],
        'keepalive' => [null, [
            'enabled' => 'bool',
        ]],
    ];

    /**
     * What an unset field means, matching the schema seeds: setting a field to
     * null stores its default, and get() fills a missing one with it. A
     * list field's default is empty.
     */
    private const DEFAULTS = [
        'pdns' => [
            'enabled'           => false,
            'apis'              => [],
            'nameservers'       => [],
            'ttl'               => 3600,
            'delay_hours'       => 12,
            'frequency_minutes' => 15,
        ],
        'domain_sync' => [
            'enabled'           => true,
            'batch_size'        => 25,
            'frequency_minutes' => 5,
        ],
        'domain_reap_deletions' => [
            'enabled'           => true,
            'frequency_minutes' => 15,
        ],
        'poll_process' => [
            'enabled'           => true,
            'frequency_minutes' => 5,
        ],
        'passkey_challenge_purge' => [
            'enabled'           => true,
            'frequency_minutes' => 1440,
        ],
        'passkey_metadata' => [
            'enabled'           => true,
            'frequency_minutes' => 1440,
        ],
        'keepalive' => [
            'enabled'           => false,
        ],
    ];

    /**
     * validator => the command that edits that field directly. A field
     * whose validator is listed here takes more than one bare value (a
     * list, not a scalar), so `config <job>-set` won't offer it -- see
     * scalarFields()/listFieldCommand().
     */
    private const LIST_FIELD_COMMANDS = [
        'pdns-apis'        => 'config pdns-api',
        'pdns-nameservers' => 'config pdns-nameserver',
    ];

    /** @return string[] every job name, for CLI usage text and GET /v1/cronjobs */
    public static function jobs(): array {
        return array_keys(self::JOBS);
    }

    /** @return string[] the fields $job accepts, in declared order */
    public static function fields(string $job): array {
        return array_keys(self::job($job)[1]);
    }

    /**
     * fields(), minus any field `config <job>-set` cannot take a single
     * bare value for -- see LIST_FIELD_COMMANDS.
     *
     * @return string[]
     */
    public static function scalarFields(string $job): array {
        [, $fields] = self::job($job);
        return array_keys(array_filter(
            $fields,
            fn(string $validator) => ! array_key_exists($validator, self::LIST_FIELD_COMMANDS)
        ));
    }

    /**
     * @return string|null the command that edits $field directly, or null if
     *         $field takes a bare value (or does not exist -- the caller
     *         is expected to have checked that already)
     */
    public static function listFieldCommand(string $job, string $field): ?string {
        [, $fields] = self::job($job);
        return self::LIST_FIELD_COMMANDS[$fields[$field] ?? ''] ?? null;
    }

    /**
     * @return array the job's current settings, an unset field showing its
     *         default -- the bare bool for `keepalive`, wrapped as
     *         `['enabled' => bool]` for a uniform shape callers don't have to
     *         special-case
     */
    public static function get(string $job): array {
        [$key] = self::job($job);
        if ($key === null) {
            return ['enabled' => (bool) Config::get('keepalive')];
        }
        $settings = Config::get($key);
        foreach (self::DEFAULTS[$job] as $field => $default) {
            $settings[$field] ??= $default;
        }
        return $settings;
    }

    /**
     * get(), with any secret withheld -- `pdns.apis`'s api_key becomes
     * api_key_set. What every output path (GET /v1/cronjobs, `config show`,
     * CLI output, `history`) shows; get() stays raw for PdnsSyncCommand.
     */
    public static function publicView(string $job): array {
        $settings = self::get($job);
        if (array_key_exists('apis', $settings)) {
            $settings['apis'] = PowerDnsApis::redact((array) ($settings['apis'] ?? []));
        }
        return $settings;
    }

    /**
     * Validate and coerce $changes, and compute what the job's full settings
     * would be afterward -- without writing anything. What a caller uses to
     * show "already set to X" / a confirm prompt with the real, coerced
     * value before deciding whether to actually call set().
     *
     * @param array $changes field => new value, or null for its default
     * @return array{0: array, 1: array} [validated changes, resulting full settings]
     */
    public static function preview(string $job, array $changes): array {
        [$key, $fields] = self::job($job);

        $unknown = array_diff(array_keys($changes), array_keys($fields));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "unknown field(s) for '{$job}': " . implode(', ', $unknown) .
                ' -- valid fields: ' . implode(', ', array_keys($fields))
            );
        }

        $current = $key === null ? [] : self::get($job);

        $validated = [];
        foreach ($changes as $field => $value) {
            $validated[$field] = $value === null
                ? self::DEFAULTS[$job][$field]
                : self::validate($fields[$field], $field, $value, $current);
        }

        if ($key === null) {
            // keepalive: the only field is 'enabled', and it *is* the setting
            return [$validated, ['enabled' => $validated['enabled'] ?? (bool) Config::get('keepalive')]];
        }
        return [$validated, $validated + $current];
    }

    /**
     * preview(), then persist and record the change to `history`. Throws
     * \InvalidArgumentException (message safe to show the caller) on an
     * unknown job/field or a failed validator -- callers translate that
     * into a UsageError (CLI) or a 400 (API).
     *
     * @param array $changes field => new value, or null for its default.
     *              Only the given fields change; everything else is untouched.
     * @return array the job's full settings after the change
     */
    public static function set(string $job, array $changes, int $userId): array {
        [$key] = self::job($job);
        [$validated, $result] = self::preview($job, $changes);

        Config::set($key ?? 'keepalive', $key === null ? $result['enabled'] : $result);

        // audited redacted -- a real api_key must never reach `history`
        $audited = $validated;
        if (array_key_exists('apis', $audited)) {
            $audited['apis'] = PowerDnsApis::redact($audited['apis']);
        }
        History::record('cronjobs', 0, 'update', ['job' => $job, 'changes' => $audited], $userId);

        return $result;
    }

    /** Bookkeeping only, written by `cron run` -- never confirmed, never audited. */
    public static function markRun(string $job): void {
        [$key] = self::job($job);
        if ($key === null) {
            return; // keepalive tracks its own timing (SessionState)
        }
        $current = Config::get($key);
        $current['last_run_at'] = date('Y-m-d H:i:s');
        Config::set($key, $current);
    }

    /**
     * @return array{0: string|null, 1: array<string, string>}
     */
    private static function job(string $job): array {
        if ( ! array_key_exists($job, self::JOBS)) {
            throw new \InvalidArgumentException(
                "unknown job '{$job}' -- valid jobs: " . implode(', ', self::jobs())
            );
        }
        return self::JOBS[$job];
    }

    /** @param array $current the job's full settings before this change */
    private static function validate(string $validator, string $field, mixed $value, array $current): mixed {
        if ($validator === 'bool') {
            return self::parseBool($field, $value);
        }
        if ($validator === 'positive-int') {
            return self::parseInt($field, $value, 1, PHP_INT_MAX);
        }
        if (str_starts_with($validator, 'range:')) {
            [$min, $max] = array_map('intval', explode(',', substr($validator, 6)));
            return self::parseInt($field, $value, $min, $max);
        }
        if ($validator === 'pdns-apis') {
            return PowerDnsApis::validate((array) $value, (array) ($current['apis'] ?? []));
        }
        if ($validator === 'pdns-nameservers') {
            return PowerDnsNameservers::validate((array) $value);
        }
        throw new \LogicException("no validator implemented for '{$validator}'"); // unreachable, guards a typo in JOBS
    }

    private static function parseBool(string $field, mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower((string) $value);
        if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
            return false;
        }
        throw new \InvalidArgumentException("{$field} must be true or false");
    }

    private static function parseInt(string $field, mixed $value, int $min, int $max): int {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit(ltrim($value, '-'))) {
            $int = (int) $value;
        } else {
            throw new \InvalidArgumentException("{$field} must be a whole number");
        }
        if ($int < $min || $int > $max) {
            throw new \InvalidArgumentException("{$field} must be between {$min} and {$max}");
        }
        return $int;
    }
}
