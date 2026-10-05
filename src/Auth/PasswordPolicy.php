<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Closure;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Settings;

/**
 * Password rules (UC-01 §4.1): configurable minimum length, common and breached passwords
 * refused, optional maximum age. Hashing uses Argon2id where available, else bcrypt.
 */
final class PasswordPolicy
{
    public const MAX_LENGTH = 128;

    /**
     * Tests only (honoured when Config::env() === 'test'): runs after every hash(), so a test can place the ≈0.4 s of Argon2
     * among a request's statements (QueryLog::mark()) and see that none runs while the transaction holds a row lock.
     */
    public static ?Closure $afterHash = null;

    /**
     * @param array $user optional user row: its username, email and names may not be the password
     * @return list<string> problems in plain language; empty means the password is acceptable
     */
    public static function check(#[\SensitiveParameter] string $password, array $user = []): array
    {
        $problems = [];
        $min = max(8, Settings::int('password_min_length', 12));
        $length = mb_strlen($password);
        if ($length < $min) {
            $problems[] = "Use at least $min characters. A short sentence you can remember works well.";
        }
        if ($length > self::MAX_LENGTH) {
            $problems[] = 'Use at most ' . self::MAX_LENGTH . ' characters.';
        }
        $lower = mb_strtolower($password);
        if ($length >= 1 && (count(array_unique(mb_str_split($lower))) <= 2 || self::isSequence($lower))) {
            $problems[] = 'Avoid repeated or sequential characters.';
        }
        if (in_array($lower, self::commonPasswords(), true)) {
            $problems[] = 'That password is too common. Choose something less predictable.';
        }
        foreach (['username', 'first_name', 'last_name'] as $field) {
            $part = mb_strtolower(trim((string) ($user[$field] ?? '')));
            if (mb_strlen($part) >= 4 && str_contains($lower, $part)) {
                $problems[] = 'Do not include your name or username in your password.';
                break;
            }
        }
        $local = mb_strtolower(strstr((string) ($user['email'] ?? ''), '@', true) ?: '');
        if (mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            $problems[] = 'Do not include your email address in your password.';
        }
        return array_values(array_unique($problems));
    }

    public static function hash(#[\SensitiveParameter] string $password): string
    {
        $hash = defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if (self::$afterHash !== null && Config::env() === 'test') {
            (self::$afterHash)();
        }
        return $hash;
    }

    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID)
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** True when password_max_age_days is set and the password is older than that. */
    public static function isExpired(?string $changedAt): bool
    {
        $maxAge = Settings::int('password_max_age_days', 0);
        if ($maxAge <= 0) {
            return false;
        }
        $changed = Clock::fromDb($changedAt);
        return $changed === null || $changed->modify("+$maxAge days") <= Clock::now();
    }

    /** A random temporary password that satisfies the policy (shown once, changed at first login). */
    public static function temporary(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if ($i % 4 === 3 && $i < 15) {
                $out .= '-';
            }
        }
        return $out;
    }

    private static function isSequence(string $s): bool
    {
        $digitsOrLetters = preg_replace('/[^a-z0-9]/', '', $s);
        foreach (['0123456789012345678901234567890', 'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz'] as $run) {
            if (strlen($digitsOrLetters) >= 8 && (str_contains($run, $digitsOrLetters) || str_contains(strrev($run), $digitsOrLetters))) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function commonPasswords(): array
    {
        static $list = null;
        if ($list === null) {
            $lines = file(__DIR__ . '/data/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $list = array_values(array_filter(array_map(fn($l) => mb_strtolower(trim($l)), $lines),
                fn($l) => $l !== '' && !str_starts_with($l, '#')));
        }
        return $list;
    }
}
