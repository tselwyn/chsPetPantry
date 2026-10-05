<?php
declare(strict_types=1);

namespace Pfpms\Http;

/**
 * One-time keys for forms whose post must not happen twice (posting a receipt or a count): a
 * double tap, a retry after "try again", or Back and post again must not add the stock twice.
 * The review page issues a key; after a successful post the key is remembered with the page the
 * post led to, and the same key posted again goes there instead. PHP's session file lock makes
 * a second request of the same session wait until the first has finished and saved the key.
 */
final class FormOnce
{
    private const KEEP = 20;

    public static function issue(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Where an earlier post with this key led, or null if it has not been posted. */
    public static function done(?string $key): ?string
    {
        return self::valid($key) ? ($_SESSION['form_once'][$key] ?? null) : null;
    }

    public static function remember(?string $key, string $path): void
    {
        if (!self::valid($key)) {
            return;
        }
        $keys = is_array($_SESSION['form_once'] ?? null) ? $_SESSION['form_once'] : [];
        $keys[$key] = $path;
        $_SESSION['form_once'] = array_slice($keys, -self::KEEP, null, true);
    }

    private static function valid(?string $key): bool
    {
        return $key !== null && preg_match('/^[0-9a-f]{32}$/', $key) === 1;
    }
}
