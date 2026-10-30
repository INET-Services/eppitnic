<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Support\Validate;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RedBeanPHP\R;

/**
 * Email notifications for `poll process` and `domain reap-deletions`: the
 * system-wide `smtp` setting (validate/persist/audit, same shape as
 * `EppSettings`/`CronjobSettings`), each user's own optional filter
 * (`users.notify_message_types`/`notify_fulltext`), and the actual sending.
 *
 * A message reaches a recipient class (system, or a domain's owning user)
 * only if `recipient_mode` includes that class *and* that class's own
 * filter accepts the message -- the system and a user never share one
 * filter, each judges independently. A mail failure is logged and
 * swallowed: it must never break the job that triggered it.
 *
 * @category    Net
 * @package     Eppitnic\Service\Notifier
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Notifier
{
    /**
     * Every type `Epp\Session::parsePollReq()` can produce, plus the synthetic
     * `scheduled_deletion` (`domain reap-deletions`) and `transfer_update_failed`
     * (`poll process`).
     */
    public const MESSAGE_TYPES = [
        'passwdReminder',
        'creditMsgData',
        'wrongNamespaceReminder',
        'delayedDebitAndRefundMsgData',
        'refundRenewsForBulkTransferMsgData',
        'remappedIdnData',
        'dnsWarningMsgData',
        'dnsErrorMsgData',
        'simpleMsgData',
        'chgStatusMsgData',
        'dlgMsgData',
        'clientApprovedTransfer',
        'clientRejectedTransfer',
        'clientCancelledTransfer',
        'serverCancelledTransfer',
        'serverApprovedTransfer',
        'pendingTransfer',
        'unknown',
        'scheduled_deletion',
        'transfer_update_failed',
    ];

    public const RECIPIENT_MODES = ['system', 'user', 'both', 'none'];
    public const AUTH_TYPES = ['plain', 'tls', 'starttls'];

    /** field => [validator, required]. 'enabled' is required in the sense
     *  that it always holds a concrete bool, never "unset". */
    private const FIELDS = [
        'enabled'        => ['bool', true],
        'host'           => ['host', true],
        'port'           => ['port', false],
        'sender'         => ['email', false],
        'recipient_mode' => ['recipient_mode', true],
        // optional even under recipient_mode system/both: a blank recipient
        // just means nothing is ever sent to it yet (checked in send(),
        // not here) -- saving an in-progress configuration must not throw
        'recipient'      => ['email', false],
        'username'       => ['plain', false],
        'password'       => ['plain', false],
        'auth_type'      => ['auth_type', true],
        'message_types'  => ['message_types', false],
        'fulltext'       => ['plain', false],
    ];

    /** @var (callable(): PHPMailer)|null test-only override -- see send() */
    private static $mailerFactory = null;

    /** Test-only: swap in a fake PHPMailer instead of a real SMTP one. */
    public static function useMailerFactory(?callable $factory): void {
        self::$mailerFactory = $factory;
    }

    // -----------------------------------------------------------------
    // system settings (the `smtp` key)
    // -----------------------------------------------------------------

    /** @return string[] every field this service knows, in declared order */
    public static function fields(): array {
        return array_keys(self::FIELDS);
    }

    /** @return array field => its current value */
    public static function get(): array {
        $smtp = Config::get('smtp');
        $result = [];
        foreach (self::FIELDS as $field => $spec) {
            $result[$field] = $smtp[$field] ?? ($field === 'message_types' ? [] : null);
        }
        return $result;
    }

    /**
     * @param array $changes field => new value; blank/null unsets an
     *              optional field
     * @return array{0: array, 1: array} [validated changes, resulting `smtp`]
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
            [$validator, $required] = self::FIELDS[$field];
            $blank = $value === null || $value === '' || ($field === 'message_types' && $value === []);
            if ($blank) {
                if ($required) {
                    throw new \InvalidArgumentException("{$field} is required and cannot be unset");
                }
                $validated[$field] = $field === 'message_types' ? [] : null;
                continue;
            }
            $validated[$field] = self::validate($validator, $field, $value);
        }

        $result = $validated + Config::get('smtp');

        return [$validated, $result];
    }

    /**
     * preview(), then persist and record the change to `history`. The
     * password itself is never written to the audit trail.
     *
     * @throws \InvalidArgumentException on an unknown field or a failed
     *         validator
     * @return array the settings after the change (self::get()'s shape)
     */
    public static function set(array $changes, int $userId): array {
        [$validated, $result] = self::preview($changes);

        Config::set('smtp', $result);

        $audited = $validated;
        if (array_key_exists('password', $audited)) {
            $audited['password'] = $audited['password'] === null ? null : '[redacted]';
        }
        History::record('smtp', 0, 'update', ['changes' => $audited], $userId);

        return self::get();
    }

    private static function validate(string $validator, string $field, mixed $value): mixed {
        return match ($validator) {
            'bool' => self::parseBool($field, $value),
            'host' => is_string($value) && trim($value) !== ''
                ? trim($value)
                : throw new \InvalidArgumentException("{$field} must not be blank"),
            'port' => self::parsePort($field, $value),
            'email' => Validate::isEmail((string) $value)
                ? (string) $value
                : throw new \InvalidArgumentException("{$field} must be a valid email address"),
            'recipient_mode' => in_array($value, self::RECIPIENT_MODES, true)
                ? $value
                : throw new \InvalidArgumentException('recipient_mode must be ' . implode('/', self::RECIPIENT_MODES)),
            'auth_type' => in_array($value, self::AUTH_TYPES, true)
                ? $value
                : throw new \InvalidArgumentException('auth_type must be ' . implode('/', self::AUTH_TYPES)),
            'message_types' => self::parseMessageTypes($field, $value),
            'plain' => trim((string) $value),
            default => throw new \LogicException("no validator implemented for '{$validator}'"), // unreachable
        };
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

    private static function parsePort(string $field, mixed $value): int {
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $port = (int) $value;
        } else {
            throw new \InvalidArgumentException("{$field} must be a whole number");
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("{$field} must be between 1 and 65535");
        }
        return $port;
    }

    /** @return string[] */
    private static function parseMessageTypes(string $field, mixed $value): array {
        if ( ! is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be a list of message types");
        }
        $types = array_values(array_unique(array_map('strval', $value)));
        $unknown = array_diff($types, self::MESSAGE_TYPES);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "{$field} has unknown type(s): " . implode(', ', $unknown)
            );
        }
        return $types;
    }

    // -----------------------------------------------------------------
    // per-user preferences (users.notify_enabled/_message_types/_fulltext)
    // -----------------------------------------------------------------

    /** @return array{enabled: bool, message_types: string[], fulltext: string}|null null if no such user */
    public static function loadUserPreferences(int $userId): ?array {
        $row = R::getRow('SELECT notify_enabled, notify_message_types, notify_fulltext FROM users WHERE id = ?', [$userId]);
        if (empty($row)) {
            return null;
        }
        $types = json_decode((string) ($row['notify_message_types'] ?? ''), true);
        return [
            'enabled'       => (int) $row['notify_enabled'] === 1,
            'message_types' => is_array($types) ? array_values(array_map('strval', $types)) : [],
            'fulltext'      => (string) ($row['notify_fulltext'] ?? ''),
        ];
    }

    /**
     * Change any of a user's own fields; what is not given stays.
     *
     * @return array{ok: bool, settings?: array, error?: string, status?: int}
     */
    public static function saveUserPreferences(int $userId, array $params): array {
        $current = self::loadUserPreferences($userId);
        if ($current === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'User not found'];
        }

        $fields = [];

        if (array_key_exists('enabled', $params)) {
            try {
                $fields['notify_enabled'] = self::parseBool('enabled', $params['enabled']) ? 1 : 0;
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'status' => 400, 'error' => $e->getMessage()];
            }
        }

        if (array_key_exists('message_types', $params)) {
            try {
                $types = self::parseMessageTypes('message_types', $params['message_types'] ?? []);
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'status' => 400, 'error' => $e->getMessage()];
            }
            $fields['notify_message_types'] = $types === [] ? null : json_encode($types);
        }

        if (array_key_exists('fulltext', $params)) {
            $text = trim((string) $params['fulltext']);
            $fields['notify_fulltext'] = $text === '' ? null : $text;
        }

        if ($fields !== []) {
            $set = [];
            $bind = [':id' => $userId];
            foreach ($fields as $column => $value) {
                $set[] = "{$column} = :{$column}";
                $bind[":{$column}"] = $value;
            }
            R::exec('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id', $bind);
        }

        return ['ok' => true, 'settings' => self::loadUserPreferences($userId)];
    }

    // -----------------------------------------------------------------
    // sending
    // -----------------------------------------------------------------

    /**
     * One poll message, just recorded by `poll process`. `$domain` is null
     * for an account-level message (e.g. `passwdReminder`) -- there is no
     * individual owner to notify for those, only the system recipient can
     * ever receive them.
     */
    public static function notifyPoll(string $type, ?string $domain, string $message): void {
        $smtp = Config::get('smtp');
        if ( ! $smtp['enabled']) {
            return;
        }

        $subject = '[eppitnic] ' . $type . ($domain !== null ? " — {$domain}" : '');

        if (in_array($smtp['recipient_mode'], ['system', 'both'], true)
            && ($smtp['recipient'] ?? '') !== ''
            && self::matches($smtp['message_types'], $smtp['fulltext'] ?? '', $type, $domain, $message)
        ) {
            self::send($smtp, $smtp['recipient'], $subject, $message);
        }

        if ($domain === null || ! in_array($smtp['recipient_mode'], ['user', 'both'], true)) {
            return;
        }

        $resellerId = R::getCell('SELECT reseller_id FROM domains WHERE domain = ?', [$domain]);
        if ($resellerId === null || $resellerId === false) {
            return;
        }
        foreach (self::recipientsOf((int) $resellerId) as $recipient) {
            if (self::matches($recipient['types'], $recipient['fulltext'], $type, $domain, $message)) {
                self::send($smtp, $recipient['email'], $subject, $message);
            }
        }
    }

    /**
     * A security notice for the system recipient alone, whenever mail is on
     * and a recipient is filled in: neither `recipient_mode` nor the message
     * filters can silence it.
     */
    public static function notifySystem(string $subject, string $body): void {
        $smtp = Config::get('smtp');
        if (empty($smtp['enabled']) || ($smtp['recipient'] ?? '') === '') {
            return;
        }
        self::send($smtp, $smtp['recipient'], '[eppitnic] ' . $subject, $body);
    }

    /**
     * The users of a reseller who receive its mail: active, notifications on,
     * an address on file -- each with their own filter.
     *
     * @return array<int, array{email: string, types: string[], fulltext: string}>
     */
    private static function recipientsOf(int $resellerId): array {
        $rows = R::getAll(
            "SELECT email, notify_message_types, notify_fulltext FROM users
             WHERE reseller_id = ? AND active = 1 AND notify_enabled = 1 AND email IS NOT NULL AND email <> ''",
            [$resellerId]
        );
        return array_map(static function (array $row): array {
            $types = json_decode((string) ($row['notify_message_types'] ?? ''), true);
            return [
                'email'    => (string) $row['email'],
                'types'    => is_array($types) ? array_map('strval', $types) : [],
                'fulltext' => (string) ($row['notify_fulltext'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * One email per run summarizing every deletion `domain reap-deletions`
     * attempted, rather than one per domain -- to the system recipient
     * (every outcome) and, separately, to each owning reseller's recipients
     * (its own domains only), each judged by their own filter.
     *
     * @param array<int, array{domain: string, reseller_id: int|null, ok: bool, message: string}> $outcomes
     */
    public static function notifyDeletions(array $outcomes): void {
        if ($outcomes === []) {
            return;
        }
        $smtp = Config::get('smtp');
        if ( ! $smtp['enabled']) {
            return;
        }

        $type = 'scheduled_deletion';
        $subject = '[eppitnic] scheduled deletions';

        if (in_array($smtp['recipient_mode'], ['system', 'both'], true) && ($smtp['recipient'] ?? '') !== '') {
            $summary = self::summarize($outcomes);
            if (self::matches($smtp['message_types'], $smtp['fulltext'] ?? '', $type, null, $summary)) {
                self::send($smtp, $smtp['recipient'], $subject, $summary);
            }
        }

        if ( ! in_array($smtp['recipient_mode'], ['user', 'both'], true)) {
            return;
        }

        $byReseller = [];
        foreach ($outcomes as $outcome) {
            if ($outcome['reseller_id'] !== null) {
                $byReseller[$outcome['reseller_id']][] = $outcome;
            }
        }
        foreach ($byReseller as $resellerId => $resellerOutcomes) {
            $summary = self::summarize($resellerOutcomes);
            foreach (self::recipientsOf((int) $resellerId) as $recipient) {
                if (self::matches($recipient['types'], $recipient['fulltext'], $type, null, $summary)) {
                    self::send($smtp, $recipient['email'], $subject, $summary);
                }
            }
        }
    }

    /**
     * A transfer-in whose nameserver update the registry kept refusing: to the
     * system recipient and to the owning reseller's recipients, each judged by
     * their own filter.
     */
    public static function notifyTransferUpdateFailed(string $domain, int $resellerId, string $body): void {
        $smtp = Config::get('smtp');
        if ( ! $smtp['enabled']) {
            return;
        }

        $type = 'transfer_update_failed';
        $subject = "[eppitnic] {$type} — {$domain}";

        if (in_array($smtp['recipient_mode'], ['system', 'both'], true)
            && ($smtp['recipient'] ?? '') !== ''
            && self::matches($smtp['message_types'], $smtp['fulltext'] ?? '', $type, $domain, $body)
        ) {
            self::send($smtp, $smtp['recipient'], $subject, $body);
        }

        if ( ! in_array($smtp['recipient_mode'], ['user', 'both'], true)) {
            return;
        }
        foreach (self::recipientsOf($resellerId) as $recipient) {
            if (self::matches($recipient['types'], $recipient['fulltext'], $type, $domain, $body)) {
                self::send($smtp, $recipient['email'], $subject, $body);
            }
        }
    }

    /** @param array<int, array{domain: string, ok: bool, message: string}> $outcomes */
    private static function summarize(array $outcomes): string {
        $lines = array_map(
            static fn(array $o): string => $o['domain'] . ': ' . ($o['ok'] ? 'deleted' : "FAILED — {$o['message']}"),
            $outcomes
        );
        return implode("\n", $lines);
    }

    /** @param string[] $types empty = unfiltered */
    private static function matches(array $types, string $fulltext, string $type, ?string $domain, string $message): bool {
        if ($types !== [] && ! in_array($type, $types, true)) {
            return false;
        }
        if ($fulltext !== '') {
            $haystack = strtolower($type . ' ' . ($domain ?? '') . ' ' . $message);
            if ( ! str_contains($haystack, strtolower($fulltext))) {
                return false;
            }
        }
        return true;
    }

    private static function makeMailer(): PHPMailer {
        return self::$mailerFactory ? (self::$mailerFactory)() : new PHPMailer(true);
    }

    private static function configureMailer(PHPMailer $mail, array $smtp): void {
        $mail->isSMTP();
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Host = $smtp['host'];
        if ($smtp['port'] !== null) {
            $mail->Port = (int) $smtp['port'];
        }
        if (($smtp['username'] ?? '') !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['username'];
            $mail->Password = (string) $smtp['password'];
        }
        $mail->SMTPSecure = match ($smtp['auth_type']) {
            'tls'      => PHPMailer::ENCRYPTION_SMTPS,
            'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            default    => '',
        };
        if (($smtp['sender'] ?? '') !== '') {
            $mail->setFrom($smtp['sender']);
        }
    }

    /**
     * A message to one address, outside the poll/deletion filters.
     *
     * @return bool whether it was handed to the mail server; false too when
     *         mail is off or has no host or sender to send from
     */
    public static function sendDirect(string $to, string $subject, string $body): bool {
        $smtp = Config::get('smtp');
        if (empty($smtp['enabled']) || ($smtp['host'] ?? '') === '' || ($smtp['sender'] ?? '') === '') {
            return false;
        }
        return self::send($smtp, $to, $subject, $body);
    }

    private static function send(array $smtp, string $to, string $subject, string $body): bool {
        try {
            $mail = self::makeMailer();
            self::configureMailer($mail, $smtp);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (MailException|\Throwable $e) {
            // A notification is never allowed to break the job that
            // triggered it -- poll process and domain reap-deletions have
            // real work to do regardless of whether mail delivery works.
            error_log('Notifier::send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send one test email with the given field overrides merged over
     * whatever is already saved (so a blank/omitted password reuses the
     * stored one, same as set()) -- without persisting anything. Unlike
     * send(), a failure is reported back rather than swallowed: the whole
     * point here is telling the admin why it did not work. Works
     * regardless of `enabled`, so a configuration can be tested before it
     * is turned on.
     *
     * @param array $overrides same field shape as preview()/set()
     * @throws \InvalidArgumentException on an unknown field or a failed
     *         validator
     * @return array{ok: bool, error?: string}
     */
    public static function sendTest(array $overrides): array {
        [, $merged] = self::preview($overrides);

        $to = trim((string) ($merged['recipient'] ?? ''));
        if ($to === '') {
            return ['ok' => false, 'error' => 'recipient is required to send a test email'];
        }

        try {
            $mail = self::makeMailer();
            self::configureMailer($mail, $merged);
            $mail->addAddress($to);
            $mail->Subject = '[eppitnic] SMTP test';
            $mail->Body = 'This is a test email from eppitnic, confirming the SMTP settings above work.';
            $mail->send();
            return ['ok' => true];
        } catch (MailException|\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
