<?php

namespace Eppitnic\Service;

use Eppitnic\Config;
use RedBeanPHP\R;

/**
 * WebAuthn challenges: 32 random bytes, only a SHA-256 stored, good once within
 * five minutes for one purpose and, where known, one user. A login challenge
 * is signed, not stored (issueLogin): its row is written when it is spent, and
 * the unique hash refuses a second use.
 *
 * @category    Net
 * @package     Eppitnic\Service\WebAuthnChallenges
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class WebAuthnChallenges
{
    public const LIFETIME = 300;

    /** the purposes issue() stores; `login` is issueLogin()'s */
    public const PURPOSES = ['register', 'stepup'];

    private const LOGIN_KEY = 'eppitnic/webauthn/login-challenge/v1';

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

    /**
     * @param string $purpose one of PURPOSES
     * @param int|null $userId whom the ceremony is for
     * @param string|null $context what the issuing request had verified, for
     *        the one spending the challenge to read back (contextOf)
     * @return array{0: int, 1: string} [challenge id, raw challenge bytes]
     */
    public static function issue(string $purpose, ?int $userId = null, ?string $context = null): array {
        if ( ! in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('purpose must be one of: ' . implode(', ', self::PURPOSES));
        }

        $challenge = random_bytes(32);
        $now = self::now();
        R::exec(
            'INSERT INTO webauthn_challenges (purpose, user_id, challenge_hash, context, created_at, expires_at)
             VALUES (:purpose, :user, :hash, :context, :created, :expires)',
            [
                ':purpose' => $purpose,
                ':user'    => $userId,
                ':hash'    => hash('sha256', $challenge),
                ':context' => $context,
                ':created' => self::stamp($now),
                ':expires' => self::stamp($now + self::LIFETIME),
            ]
        );
        return [(int) R::getInsertID(), $challenge];
    }

    /** @return string|null the context issue() was given, once consume() succeeded */
    public static function contextOf(int $id): ?string {
        $context = R::getCell('SELECT context FROM webauthn_challenges WHERE id = :id', [':id' => $id]);
        return $context === null || $context === false ? null : (string) $context;
    }

    /**
     * A login challenge that costs nothing to hand out: nothing is written.
     *
     * @return array{0: string, 1: string} [challenge id, raw challenge bytes];
     *         the id is the expiry and the challenge, signed
     */
    public static function issueLogin(): array {
        $challenge = random_bytes(32);
        $payload = pack('J', self::now() + self::LIFETIME) . $challenge;
        $mac = hash_hmac('sha256', $payload, self::loginKey(), true);

        return [self::encode($payload) . '.' . self::encode($mac), $challenge];
    }

    /**
     * Spend a login challenge: genuine, unexpired, this very challenge, and
     * never spent before. A wrong guess leaves it unspent.
     *
     * @param string $id issueLogin()'s id
     * @param string $challenge the raw bytes the client signed
     */
    public static function consumeLogin(string $id, string $challenge): bool {
        $parts = explode('.', $id);
        $payload = count($parts) === 2 ? self::decode($parts[0]) : null;
        $mac = count($parts) === 2 ? self::decode($parts[1]) : null;
        if ($payload === null || $mac === null || strlen($payload) !== 40
            || ! hash_equals(hash_hmac('sha256', $payload, self::loginKey(), true), $mac)) {
            return false;
        }

        $expires = (int) unpack('J', $payload)[1];
        if ($expires <= self::now() || ! hash_equals(substr($payload, 8), $challenge)) {
            return false;
        }

        try {
            R::exec(
                "INSERT INTO webauthn_challenges (purpose, user_id, challenge_hash, created_at, expires_at, used_at)
                 VALUES ('login', NULL, :hash, :created, :expires, :used)",
                [
                    ':hash'    => hash('sha256', $challenge),
                    ':created' => self::stamp($expires - self::LIFETIME),
                    ':expires' => self::stamp($expires),
                    ':used'    => self::stamp(self::now()),
                ]
            );
        } catch (\Throwable) {
            // the hash is unique: this challenge was spent already
            return false;
        }
        return true;
    }

    private static function loginKey(): string {
        return hash_hmac('sha256', self::LOGIN_KEY, (string) Config::get('jwt_psk'), true);
    }

    private static function encode(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        return $bytes === false || $text === '' ? null : $bytes;
    }

    /**
     * Spend a challenge. A wrong guess leaves it alone, so nobody can use up
     * someone else's by its id.
     *
     * @param string $challenge the raw bytes the client signed
     * @return bool whether it was unspent, unexpired, of this purpose and
     *         user, and this very challenge
     */
    public static function consume(int $id, string $challenge, string $purpose, ?int $userId): bool {
        $now = self::now();
        $params = [
            ':now'     => self::stamp($now),
            ':id'      => $id,
            ':purpose' => $purpose,
            ':hash'    => hash('sha256', $challenge),
            ':expires' => self::stamp($now),
        ];
        if ($userId !== null) {
            $params[':user'] = $userId;
        }

        return R::exec(
            'UPDATE webauthn_challenges SET used_at = :now
             WHERE id = :id AND purpose = :purpose AND challenge_hash = :hash
               AND ' . ($userId === null ? 'user_id IS NULL' : 'user_id = :user') . '
               AND used_at IS NULL AND expires_at > :expires',
            $params
        ) === 1;
    }

    /**
     * Delete what can no longer be used. A spent challenge is expired within
     * five minutes anyway, so expiry alone is the rule.
     *
     * @return int how many rows went
     */
    public static function purge(): int {
        return (int) R::exec('DELETE FROM webauthn_challenges WHERE expires_at < :now', [
            ':now' => self::stamp(self::now()),
        ]);
    }
}
