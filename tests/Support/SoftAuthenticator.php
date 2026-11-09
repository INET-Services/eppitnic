<?php

namespace Eppitnic\Tests\Support;

/**
 * A software authenticator (EC P-256, or RS256 / EdDSA) answering the options
 * WebAuthn produces in the JSON a browser sends. Every field a verifier checks
 * can be forged through $forge, so a test breaks one thing at a time:
 *
 *   origin, rpId, challenge (raw), type, crossOrigin, topOrigin, up, uv, be,
 *   bs, signCount, aaguid, alg, attestation ('none'|'packed'), credentialId,
 *   rawId, userHandle (raw, or null to omit), badSignature, transports
 *
 * 'packed' attests with the credential key (self) unless x5c (DER strings,
 * the attestation certificate first) and attestationKey (the private key of
 * that certificate) are given: full attestation.
 */
final class SoftAuthenticator
{
    private const ALGORITHMS = ['ES256' => -7, 'EdDSA' => -8, 'RS256' => -257];

    private \OpenSSLAsymmetricKey|string $key;

    private int $alg;

    /** raw credential id */
    public string $credentialId;

    /** raw user handle, learned at registration */
    public ?string $userHandle = null;

    /** the next assertion's counter is this plus one; false = always 0, as synced keys */
    public bool $countsSigns = true;

    public int $counter = 0;

    public function __construct(
        public string $origin = 'http://localhost:5173',
        public string $rpId = 'localhost',
        public string $aaguid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        string $algorithm = 'ES256',
    ) {
        $this->alg = self::ALGORITHMS[$algorithm];
        $this->key = match ($algorithm) {
            'ES256' => openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]),
            'RS256' => openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]),
            'EdDSA' => sodium_crypto_sign_keypair(),
        };
        $this->credentialId = random_bytes(32);
    }

    /**
     * @param array $options WebAuthn::registrationOptions()'s answer
     * @return string the JSON navigator.credentials.create() would return
     */
    public function register(array $options, array $forge = []): string {
        $this->userHandle = self::decode($options['user']['id']);
        $challenge = $forge['challenge'] ?? self::decode($options['challenge']);
        $credentialId = $forge['credentialId'] ?? $this->credentialId;
        $this->credentialId = $credentialId;

        $alg = $forge['alg'] ?? $this->alg;
        $authData = $this->authData($forge, $forge['signCount'] ?? 0, $credentialId, $alg);
        $clientData = $this->clientData($forge['type'] ?? 'webauthn.create', $challenge, $forge);

        $statement = self::map([]);
        $format = 'none';
        if (($forge['attestation'] ?? 'none') === 'packed') {
            $format = 'packed';
            $signed = $authData . hash('sha256', $clientData, true);
            $pairs = [
                [self::text('alg'), self::int($alg)],
                [self::text('sig'), self::bytes($this->sign($signed, $forge))],
            ];
            if (isset($forge['x5c'])) {
                openssl_sign($signed, $signature, $forge['attestationKey'], OPENSSL_ALGO_SHA256);
                $pairs[1] = [self::text('sig'), self::bytes($signature)];
                $pairs[] = [self::text('x5c'), self::list(array_map(self::bytes(...), $forge['x5c']))];
            }
            $statement = self::map($pairs);
        }
        $attestationObject = self::map([
            [self::text('fmt'), self::text($format)],
            [self::text('attStmt'), $statement],
            [self::text('authData'), self::bytes($authData)],
        ]);

        return $this->json($forge['rawId'] ?? $credentialId, [
            'clientDataJSON'    => self::encode($clientData),
            'attestationObject' => self::encode($attestationObject),
            'transports'        => $forge['transports'] ?? ['internal'],
        ]);
    }

    /**
     * @param array $options WebAuthn::requestOptions()'s answer
     * @return string the JSON navigator.credentials.get() would return
     */
    public function assert(array $options, array $forge = []): string {
        $challenge = $forge['challenge'] ?? self::decode($options['challenge']);
        $count = $forge['signCount'] ?? ($this->countsSigns ? ++$this->counter : 0);

        $authData = $this->authData($forge, $count);
        $clientData = $this->clientData($forge['type'] ?? 'webauthn.get', $challenge, $forge);
        $signed = $authData . hash('sha256', $clientData, true);

        $handle = array_key_exists('userHandle', $forge) ? $forge['userHandle'] : $this->userHandle;
        return $this->json($forge['rawId'] ?? $this->credentialId, [
            'clientDataJSON'    => self::encode($clientData),
            'authenticatorData' => self::encode($authData),
            'signature'         => self::encode($this->sign($signed, $forge)),
            'userHandle'        => $handle === null ? null : self::encode($handle),
        ]);
    }

    // -----------------------------------------------------------------
    // the bytes
    // -----------------------------------------------------------------

    private function authData(array $forge, int $count, ?string $credentialId = null, ?int $alg = null): string {
        $flags = (($forge['up'] ?? true) ? 0x01 : 0)
            | (($forge['uv'] ?? true) ? 0x04 : 0)
            | (($forge['be'] ?? false) ? 0x08 : 0)
            | (($forge['bs'] ?? false) ? 0x10 : 0)
            | ($credentialId !== null ? 0x40 : 0);

        $data = hash('sha256', $forge['rpId'] ?? $this->rpId, true) . chr($flags) . pack('N', $count);
        if ($credentialId !== null) {
            $aaguid = hex2bin(str_replace('-', '', $forge['aaguid'] ?? $this->aaguid));
            $data .= $aaguid . pack('n', strlen($credentialId)) . $credentialId . $this->coseKey($alg ?? $this->alg);
        }
        return $data;
    }

    /** @param int $alg the label written into the key, which may be forged */
    private function coseKey(int $alg): string {
        if (is_string($this->key)) {
            return self::map([
                [self::int(1), self::int(1)],
                [self::int(3), self::int($alg)],
                [self::int(-1), self::int(6)],
                [self::int(-2), self::bytes(sodium_crypto_sign_publickey($this->key))],
            ]);
        }

        $details = openssl_pkey_get_details($this->key);
        if (isset($details['rsa'])) {
            return self::map([
                [self::int(1), self::int(3)],
                [self::int(3), self::int($alg)],
                [self::int(-1), self::bytes($details['rsa']['n'])],
                [self::int(-2), self::bytes($details['rsa']['e'])],
            ]);
        }

        return self::map([
            [self::int(1), self::int(2)],
            [self::int(3), self::int($alg)],
            [self::int(-1), self::int(1)],
            [self::int(-2), self::bytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))],
            [self::int(-3), self::bytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))],
        ]);
    }

    private function clientData(string $type, string $challenge, array $forge): string {
        $data = [
            'type'        => $type,
            'challenge'   => self::encode($challenge),
            'origin'      => $forge['origin'] ?? $this->origin,
            'crossOrigin' => $forge['crossOrigin'] ?? false,
        ];
        if (isset($forge['topOrigin'])) {
            $data['topOrigin'] = $forge['topOrigin'];
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function sign(string $data, array $forge): string {
        if (is_string($this->key)) {
            $signature = sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($this->key));
        } else {
            openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);
        }
        if ($forge['badSignature'] ?? false) {
            $signature[strlen($signature) - 1] = $signature[strlen($signature) - 1] ^ "\x01";
        }
        return $signature;
    }

    private function json(string $rawId, array $response): string {
        return json_encode([
            'id'                     => self::encode($rawId),
            'rawId'                  => self::encode($rawId),
            'type'                   => 'public-key',
            'response'               => $response,
            'clientExtensionResults' => new \stdClass(),
        ]);
    }

    // -----------------------------------------------------------------
    // encodings
    // -----------------------------------------------------------------

    public static function encode(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string {
        return base64_decode(strtr($text, '-_', '+/'));
    }

    private static function head(int $major, int $n): string {
        return match (true) {
            $n < 24      => chr(($major << 5) | $n),
            $n < 256     => chr(($major << 5) | 24) . chr($n),
            $n < 65536   => chr(($major << 5) | 25) . pack('n', $n),
            default      => chr(($major << 5) | 26) . pack('N', $n),
        };
    }

    private static function int(int $n): string {
        return $n >= 0 ? self::head(0, $n) : self::head(1, -1 - $n);
    }

    private static function bytes(string $s): string {
        return self::head(2, strlen($s)) . $s;
    }

    private static function text(string $s): string {
        return self::head(3, strlen($s)) . $s;
    }

    /** @param string[] $items encoded */
    private static function list(array $items): string {
        return self::head(4, count($items)) . implode('', $items);
    }

    /** @param array<int, array{0: string, 1: string}> $pairs encoded key/value */
    private static function map(array $pairs): string {
        $out = self::head(5, count($pairs));
        foreach ($pairs as [$key, $value]) {
            $out .= $key . $value;
        }
        return $out;
    }
}
