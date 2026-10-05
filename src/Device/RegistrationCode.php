<?php
declare(strict_types=1);

namespace Pfpms\Device;

/**
 * The single-use code that registers a tablet (plan P2A admin_devices): printed on a sheet as a
 * QR code and as text, and redeemed once by the installed Station in Phase 2B. No database access:
 * the Station's JavaScript does exactly the same (tests/fixtures/registration_code.json).
 *
 * - 16 Crockford Base32 symbols (80 random bits) and a Luhn mod 32 check symbol, so a typing
 *   mistake is caught on the tablet before it spends an attempt. Printed XXXX-XXXX-XXXX-XXXX-C.
 * - Typed input is forgiving: case, spaces, tabs, line breaks and hyphens do not matter; O is read
 *   as 0, I and L as 1. Anything else (other Unicode spaces, control characters) is refused, and only
 *   ASCII letters change case, so the Station's JavaScript gives exactly the same answers.
 * - Parameters holding a code are #[SensitiveParameter], so a code never reaches an error log.
 * - The QR holds PFPMS-DEVICE:1:<code>, which is not a web address: scanning it with a camera app
 *   opens no browser tab (the wrong storage on an iPad) and the code never reaches a server log.
 * - Only the SHA-256 of the canonical 17 symbols is stored. That is enough only because the code
 *   has 80 bits, works once and expires within the day: do not shorten it.
 */
final class RegistrationCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford Base32: no I, L, O, U
    public const DATA_SYMBOLS = 16;                               // 80 bits
    public const QR_PREFIX = 'PFPMS-DEVICE:1:';

    /** A new code: 17 canonical symbols. */
    public static function generate(): string
    {
        return self::fromBytes(random_bytes(10));
    }

    /** 10 bytes read 5 bits at a time, most significant first, then the check symbol. */
    public static function fromBytes(#[\SensitiveParameter] string $bytes): string
    {
        if (strlen($bytes) !== 10) {
            throw new \InvalidArgumentException('A registration code is made from 10 bytes.');
        }
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $data = '';
        foreach (str_split($bits, 5) as $chunk) {
            $data .= self::ALPHABET[bindec($chunk)];
        }
        return $data . self::checkSymbol($data);
    }

    /**
     * Luhn mod 32 over ALPHABET: from the rightmost data symbol leftwards the factor is 2, 1, 2, 1 …;
     * each addend (factor × value) adds its base-32 digits to the sum; the check makes the total a
     * multiple of 32.
     */
    public static function checkSymbol(#[\SensitiveParameter] string $data): string
    {
        $sum = 0;
        $factor = 2;
        for ($i = strlen($data) - 1; $i >= 0; $i--) {
            $value = strpos(self::ALPHABET, $data[$i]);
            if ($value === false) {
                throw new \InvalidArgumentException('Not a registration code symbol.');
            }
            $addend = $factor * $value;
            $sum += intdiv($addend, 32) + $addend % 32;
            $factor = $factor === 2 ? 1 : 2;
        }
        return self::ALPHABET[(32 - $sum % 32) % 32];
    }

    /** What was typed or scanned → the canonical 17 symbols, or null when it is not a valid code. */
    public static function normalise(#[\SensitiveParameter] ?string $typed): ?string
    {
        if ($typed === null || strlen($typed) > 100) {
            return null;
        }
        $s = str_replace([' ', "\t", "\r", "\n"], '', strtoupper($typed)); // strtoupper changes ASCII letters only
        if (str_starts_with($s, self::QR_PREFIX)) {
            $s = substr($s, strlen(self::QR_PREFIX));
        }
        $s = strtr(str_replace('-', '', $s), ['O' => '0', 'I' => '1', 'L' => '1']);
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{17}$/', $s)) {
            return null;
        }
        return self::checkSymbol(substr($s, 0, self::DATA_SYMBOLS)) === $s[self::DATA_SYMBOLS] ? $s : null;
    }

    /** XXXX-XXXX-XXXX-XXXX-C, as printed. */
    public static function format(#[\SensitiveParameter] string $canonical): string
    {
        return implode('-', str_split(substr($canonical, 0, self::DATA_SYMBOLS), 4)) . '-' . substr($canonical, self::DATA_SYMBOLS);
    }

    /** What the QR code holds. */
    public static function qrPayload(#[\SensitiveParameter] string $canonical): string
    {
        return self::QR_PREFIX . $canonical;
    }

    /**
     * RESERVED for a possible pairing step (plan open question): six symbols the tablet and its admin
     * page would both show, from the first 30 bits of SHA-256('pfpms-pair:' . the credential's stored
     * hash). The tablet computes the same from its own credential. Not used by any page yet.
     */
    public static function pairingCheck(string $tokenHashHex): string
    {
        $bits = '';
        foreach (str_split(substr(hash('sha256', 'pfpms-pair:' . $tokenHashHex, true), 0, 4)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split(substr($bits, 0, 30), 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }
}
