<?php
declare(strict_types=1);

namespace Pfpms\Security;

use Pfpms\Config;
use RuntimeException;

/**
 * Authenticated encryption (AES-256-GCM) with a key ring, so keys can be rotated:
 * new data uses crypto.active, and old data stays readable while its key is listed.
 *
 * config: 'crypto' => ['active' => 'k1', 'keys' => ['k1' => '<base64 of 32 random bytes>']]
 * Generate a key with: php bin/generate-key.php
 *
 * Output format: "g1.<key id>.<base64url(iv | tag | ciphertext)>".
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function encrypt(#[\SensitiveParameter] string $plaintext, string $aad = ''): string
    {
        [$id, $key] = self::activeKey();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }
        return 'g1.' . $id . '.' . self::b64url($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $token, string $aad = ''): string
    {
        $parts = explode('.', $token, 3);
        if (count($parts) !== 3 || $parts[0] !== 'g1') {
            throw new RuntimeException('Not an encrypted value');
        }
        $key = self::key($parts[1]);
        $raw = self::unb64url($parts[2]);
        if (strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Encrypted value is truncated');
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES), self::CIPHER, $key, OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES), substr($raw, self::IV_BYTES, self::TAG_BYTES), $aad
        );
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: wrong key, or the value was tampered with');
        }
        return $plaintext;
    }

    public static function newKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode a base64url value the server made (the g1. format). Lenient: base64_decode(…, true) accepts "="
     * padding and whitespace, and decodes "AA" and "AB" alike. Values a tablet sends use unb64urlStrict().
     */
    public static function unb64url(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false) {
            throw new RuntimeException('Invalid base64url');
        }
        return $bytes;
    }

    /**
     * Decode a base64url value a tablet sent (RFC 4648 §5, no padding), or null: only the alphabet, a possible
     * length, the canonical final character (it re-encodes to the same text), and exactly $bytes bytes when given
     * (50-design §3.2).
     */
    public static function unb64urlStrict(#[\SensitiveParameter] string $text, ?int $bytes = null): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/D', $text) || strlen($text) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false || self::b64url($decoded) !== $text || ($bytes !== null && strlen($decoded) !== $bytes)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Open a record the Station sealed with WebCrypto AES-256-GCM: $sealed is ciphertext‖16-byte tag, the IV kept apart.
     * @throws RuntimeException when the key, IV or tag has the wrong size, or the record was changed
     */
    public static function openRaw(#[\SensitiveParameter] string $key, string $iv, string $sealed, string $aad): string
    {
        if (strlen($key) !== 32 || strlen($iv) !== self::IV_BYTES || strlen($sealed) < self::TAG_BYTES) {
            throw new RuntimeException('Decryption failed: wrong key, IV or tag size');
        }
        $plaintext = openssl_decrypt(substr($sealed, 0, -self::TAG_BYTES), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv,
            substr($sealed, -self::TAG_BYTES), $aad);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed');
        }
        return $plaintext;
    }

    /** HKDF-SHA256 with an empty salt (the Station's sub-key derivation, 50-design §3.2). */
    public static function hkdf(#[\SensitiveParameter] string $ikm, string $info, int $length = 32): string
    {
        return hash_hkdf('sha256', $ikm, $length, $info, '');
    }

    /** HMAC-SHA256, raw 32 bytes. */
    public static function hmac(#[\SensitiveParameter] string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    /**
     * A key derived from a config ring key for one purpose (the PIN pepper, the device credential), never the ring
     * key itself.
     * @return array{0: string, 1: string} the key id and the derived key
     */
    public static function derivedKey(string $info, ?string $kid = null): array
    {
        $id = $kid ?? (string) Config::get('crypto.active', '');
        return [$id, self::hkdf(self::key($id), $info)];
    }

    /** @return array{0:string,1:string} */
    private static function activeKey(): array
    {
        $id = (string) Config::get('crypto.active', '');
        return [$id, self::key($id)];
    }

    private static function key(string $id): string
    {
        $encoded = Config::get("crypto.keys.$id");
        if (!is_string($encoded) || !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $id)) {
            throw new RuntimeException("Encryption key '$id' is not configured (crypto.keys in config)");
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException("Encryption key '$id' must be 32 random bytes, base64-encoded");
        }
        return $key;
    }
}
