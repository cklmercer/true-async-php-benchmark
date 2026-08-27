<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Laravel's cookie encryption, near enough to cost the same: AES-256-CBC with
 * a random IV, authenticated by an HMAC-SHA256 over the IV and ciphertext.
 *
 * Encrypt-then-MAC, and the MAC is checked in constant time before anything
 * is decrypted. The cipher, the key schedule and the MAC are unchanged from
 * Laravel's, so the cryptographic work per request is the same.
 *
 * The *envelope* is not Laravel's, and that is deliberate. Laravel ships
 * base64(json(base64(iv), base64(ct), hex(mac))) — three base64 passes, a JSON
 * document and a hex expansion wrapped around 240 bytes of payload, which
 * arrived on the wire as a 561-byte cookie. Every one of those bytes is sent
 * back by the client on every subsequent request and re-parsed by the load
 * generator, and at 33k rps that was 28 MB/s in each direction.
 *
 * Here the three fields are concatenated fixed-width — iv(16) || mac(32) || ct
 * — and encoded once, base64url without padding so that rawurlencode() on the
 * way into a Set-Cookie header is a no-op rather than a 30% expansion. Same
 * crypto, roughly half the bytes.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    /** iv(16) + mac(32); anything shorter cannot carry a block of ciphertext. */
    private const OVERHEAD = 48;

    /** @param string $key 32 raw bytes */
    public function __construct(private readonly string $key) {}

    public static function fromEnv(): self
    {
        $secret = getenv('APP_KEY') ?: 'benchmark-key-not-a-secret-value';

        return new self(hash('sha256', $secret, true));
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        $mac = hash_hmac('sha256', $iv.$ciphertext, $this->key, true);

        // base64url, unpadded: the output is [A-Za-z0-9_-] only, so it survives
        // rawurlencode() unchanged instead of growing by a third.
        return rtrim(strtr(base64_encode($iv.$mac.$ciphertext), '+/', '-_'), '=');
    }

    /** @return string|null null when the payload is absent, malformed or unauthentic */
    public function decrypt(string $payload): ?string
    {
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($raw === false || strlen($raw) <= self::OVERHEAD) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $ciphertext = substr($raw, self::OVERHEAD);

        if (! hash_equals(hash_hmac('sha256', $iv.$ciphertext, $this->key, true), $mac)) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }
}
