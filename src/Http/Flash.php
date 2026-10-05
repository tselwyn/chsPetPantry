<?php
declare(strict_types=1);

namespace Pfpms\Http;

/** One-time messages shown on the next page (after a redirect). */
final class Flash
{
    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    /** @return list<array{type:string,message:string}> */
    public static function take(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($messages) ? $messages : [];
    }

    private static function add(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        }
    }
}
