<?php

namespace Eppitnic\Service;

use Eppitnic\Api\LoginRateLimit;
use Eppitnic\Config;
use Eppitnic\Persistence\History;
use Eppitnic\Support\PasswordGenerator;
use Eppitnic\Support\PasswordPolicy;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * Self-service password reset: a single-use link, valid for ten minutes, mailed
 * to the address on the account. The token is 32 random bytes of which only a
 * SHA-256 is stored. Nothing here may reveal whether an address has an account,
 * so request() has no result and every failure goes to the server log.
 *
 * @category    Net
 * @package     Eppitnic\Service\PasswordReset
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasswordReset
{
    public const LIFETIME = 600;

    public const LOCALES = ['en', 'it', 'de'];

    /** requests / confirmations one network, or one address, may make per window */
    private const WINDOW = 900;
    private const REQUESTS_PER_NETWORK = 10;
    private const REQUESTS_PER_ADDRESS = 3;
    private const CONFIRMS_PER_NETWORK = 10;

    public const INVALID = 'This link is invalid or has expired';

    private const MAIL = [
        'en' => [
            'subject' => 'Reset your password',
            'body'    => "Hello,\n\na password reset was requested for the account \"%s\".\n\n"
                . "Open this link within 10 minutes to choose a new password:\n%s\n\n"
                . "If you did not ask for this, ignore this message: your password stays as it is.\n",
        ],
        'it' => [
            'subject' => 'Reimposta la tua password',
            'body'    => "Buongiorno,\n\nè stata richiesta la reimpostazione della password per l'account \"%s\".\n\n"
                . "Apri questo link entro 10 minuti per scegliere una nuova password:\n%s\n\n"
                . "Se non l'hai richiesta tu, ignora questo messaggio: la password resta invariata.\n",
        ],
        'de' => [
            'subject' => 'Passwort zurücksetzen',
            'body'    => "Guten Tag,\n\nfür das Konto \"%s\" wurde das Zurücksetzen des Passworts angefordert.\n\n"
                . "Öffnen Sie diesen Link innerhalb von 10 Minuten, um ein neues Passwort zu wählen:\n%s\n\n"
                . "Wenn Sie das nicht angefordert haben, ignorieren Sie diese Nachricht: Ihr Passwort bleibt unverändert.\n",
        ],
    ];

    /** @var (callable(): int)|null test-only clock */
    private static $clock = null;

    /** Test-only: the time, as a unix timestamp, instead of the system's. */
    public static function useClock(?callable $clock): void {
        self::$clock = $clock;
    }

    private static function now(): int {
        return self::$clock ? (int) (self::$clock)() : time();
    }

    private static function stamp(int $time): string {
        return date('Y-m-d H:i:s', $time);
    }

    /** Whether a reset can work: mail is configured and logins are local. */
    public static function available(): bool {
        try {
            $smtp = (array) Config::get('smtp');
            $remote = (array) Config::get('remote_auth');
        } catch (\Throwable) {
            return false;
        }
        return ! empty($smtp['enabled']) && ($smtp['host'] ?? '') !== '' && ($smtp['sender'] ?? '') !== ''
            && empty($remote['enabled']);
    }

    // -----------------------------------------------------------------
    // requesting
    // -----------------------------------------------------------------

    /**
     * The origin a reset link may point at: the request's Origin, else its
     * Referer's, else the page's own say-so -- each only if it is listed in
     * `allowed_origins`. Never the Host, which a client chooses.
     */
    public static function linkOrigin(Request $request, mixed $claimed): ?string {
        $allowed = AllowedOrigins::get();
        $referer = parse_url($request->getHeaderLine('Referer'));
        $candidates = [
            $request->getHeaderLine('Origin'),
            isset($referer['scheme'], $referer['host'])
                ? $referer['scheme'] . '://' . $referer['host'] . (isset($referer['port']) ? ":{$referer['port']}" : '')
                : '',
            is_string($claimed) ? $claimed : '',
        ];
        foreach ($candidates as $candidate) {
            $origin = $candidate === '' ? null : AllowedOrigins::canonical($candidate);
            if ($origin !== null && in_array($origin, $allowed, true)) {
                return $origin;
            }
        }
        return null;
    }

    /**
     * Run $work after the response has gone out where the SAPI allows it, so
     * a slow mail server does not show in the response time; elsewhere at once.
     */
    public static function afterResponse(callable $work): void {
        if ( ! function_exists('fastcgi_finish_request')) {
            $work();
            return;
        }
        register_shutdown_function(static function () use ($work): void {
            fastcgi_finish_request();
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('password reset: ' . $e->getMessage());
            }
        });
    }

    /**
     * Mail a link to the active user with $email, if there is one and nothing
     * limits it. Never reports which: a refusal is only logged.
     *
     * @param string|null $origin see linkOrigin()
     */
    public static function request(string $email, string $locale, ?string $origin): void {
        $now = self::now();
        self::purge($now);

        $email = trim($email);
        $emailHash = hash('sha256', strtolower($email));
        $network = LoginRateLimit::network();

        if (self::attempts('request', $network, $emailHash, $now)) {
            error_log('password reset: rate limit reached, nothing sent');
            return;
        }
        self::recordAttempt('request', $network, $emailHash, $now);

        // the token is made for unknown addresses too, so that work is not
        // what tells them apart
        $token = PasswordGenerator::token(32);
        $tokenHash = hash('sha256', $token);

        $user = R::getRow(
            "SELECT u.id, u.username FROM users u JOIN resellers r ON r.id = u.reseller_id
             WHERE LOWER(u.email) = LOWER(:email) AND u.active = 1 AND r.active = 1",
            [':email' => $email]
        );
        if (empty($user)) {
            return;
        }
        if ($origin === null) {
            error_log('password reset: no allowed origin on the request, nothing sent');
            return;
        }

        R::exec('UPDATE password_resets SET used_at = :now WHERE user_id = :user AND used_at IS NULL', [
            ':now' => self::stamp($now), ':user' => (int) $user['id'],
        ]);
        R::exec(
            'INSERT INTO password_resets (user_id, token_hash, created_at, expires_at)
             VALUES (:user, :hash, :created, :expires)',
            [
                ':user'    => (int) $user['id'],
                ':hash'    => $tokenHash,
                ':created' => self::stamp($now),
                ':expires' => self::stamp($now + self::LIFETIME),
            ]
        );

        $mail = self::MAIL[in_array($locale, self::LOCALES, true) ? $locale : 'en'];
        $link = $origin . '/reset-password?token=' . $token;
        if ( ! Notifier::sendDirect($email, '[eppitnic] ' . $mail['subject'], sprintf($mail['body'], $user['username'], $link))) {
            error_log('password reset: the mail could not be sent');
        }
    }

    // -----------------------------------------------------------------
    // checking and using a token
    // -----------------------------------------------------------------

    /**
     * Whether $token may be used now, counting the attempt against the network.
     *
     * @return string 'ok', 'invalid' or 'limited'
     */
    public static function check(string $token): string {
        return self::guarded($token, static fn(array $row): string => 'ok');
    }

    /**
     * Set the password behind $token, spending it.
     *
     * @return array{0: string, 1?: string} ['ok'], ['invalid'], ['limited'] or
     *         ['weak', explanation]
     */
    public static function confirm(string $token, string $password): array {
        $weak = null;
        $outcome = self::guarded($token, static function (array $row) use ($password, &$weak): string {
            if ( ! PasswordPolicy::isAcceptable($password)) {
                $weak = PasswordPolicy::explain($password);
                return 'weak';
            }

            // spending it is the claim: of two requests, only one gets the row
            $now = self::now();
            $claimed = R::exec('UPDATE password_resets SET used_at = :now WHERE id = :id AND used_at IS NULL', [
                ':now' => self::stamp($now), ':id' => (int) $row['id'],
            ]);
            if ($claimed !== 1) {
                return 'invalid';
            }

            $userId = (int) $row['user_id'];
            R::exec('UPDATE users SET password = :password, must_change_password = 0 WHERE id = :id', [
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':id'       => $userId,
            ]);
            R::exec('DELETE FROM password_resets WHERE user_id = :user AND id <> :id', [
                ':user' => $userId, ':id' => (int) $row['id'],
            ]);
            History::record('users', $userId, 'update', ['password' => 'PASSWORD_CHANGED', 'reset' => 'email'], $userId);
            return 'ok';
        });

        return $outcome === 'weak' ? ['weak', (string) $weak] : [$outcome];
    }

    /**
     * Rate-limit, find the live token and its active user, then run $use on it.
     *
     * @param callable(array): string $use given the token row
     */
    private static function guarded(string $token, callable $use): string {
        $now = self::now();
        self::purge($now);

        $network = LoginRateLimit::network();
        if (self::attempts('confirm', $network, null, $now)) {
            return 'limited';
        }
        self::recordAttempt('confirm', $network, null, $now);

        $row = R::getRow(
            "SELECT p.id, p.user_id FROM password_resets p
             JOIN users u ON u.id = p.user_id JOIN resellers r ON r.id = u.reseller_id
             WHERE p.token_hash = :hash AND p.used_at IS NULL AND p.expires_at > :now
               AND u.active = 1 AND r.active = 1",
            [':hash' => hash('sha256', $token), ':now' => self::stamp($now)]
        );
        return empty($row) ? 'invalid' : $use($row);
    }

    // -----------------------------------------------------------------
    // housekeeping and rate limits
    // -----------------------------------------------------------------

    private static function purge(int $now): void {
        R::exec('DELETE FROM password_resets WHERE expires_at < :now', [':now' => self::stamp($now)]);
        R::exec('DELETE FROM password_reset_attempts WHERE created_at < :cutoff', [
            ':cutoff' => self::stamp($now - self::WINDOW),
        ]);
    }

    /** @return bool whether the allowance for this kind of attempt is spent */
    private static function attempts(string $kind, ?string $network, ?string $emailHash, int $now): bool {
        $since = self::stamp($now - self::WINDOW);
        $count = static fn(string $column, string $value): int => (int) R::getCell(
            "SELECT COUNT(*) FROM password_reset_attempts WHERE kind = :kind AND {$column} = :value AND created_at > :since",
            [':kind' => $kind, ':value' => $value, ':since' => $since]
        );

        $perNetwork = $kind === 'request' ? self::REQUESTS_PER_NETWORK : self::CONFIRMS_PER_NETWORK;
        return ($network !== null && $count('network', $network) >= $perNetwork)
            || ($emailHash !== null && $count('email_hash', $emailHash) >= self::REQUESTS_PER_ADDRESS);
    }

    private static function recordAttempt(string $kind, ?string $network, ?string $emailHash, int $now): void {
        R::exec(
            'INSERT INTO password_reset_attempts (kind, network, email_hash, created_at) VALUES (:kind, :network, :hash, :now)',
            [':kind' => $kind, ':network' => $network, ':hash' => $emailHash, ':now' => self::stamp($now)]
        );
    }
}
