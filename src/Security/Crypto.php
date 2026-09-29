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

    public static function encrypt(string $plaintext, string $aad = ''): string
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

    public static function unb64url(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false) {
            throw new RuntimeException('Invalid base64url');
        }
        return $bytes;
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
