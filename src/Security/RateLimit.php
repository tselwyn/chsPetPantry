<?php
declare(strict_types=1);

namespace Pfpms\Security;

use Pfpms\Clock;
use Pfpms\Db;

/**
 * Fixed-window rate limiting stored in rate_limit_bucket (login, password reset,
 * clinic portal, self-registration). Runs in its own transaction.
 */
final class RateLimit
{
    /** Record one hit; true if the caller is still within $limit hits per $windowSeconds. */
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $key = self::key($bucket);
        return Db::transaction(static function ($pdo) use ($key, $limit, $windowSeconds): bool {
            $now = Clock::now();
            $pdo->prepare('INSERT INTO rate_limit_bucket (bucket, window_start, hits) VALUES (?, ?, 0)
                           ON DUPLICATE KEY UPDATE bucket = bucket')->execute([$key, Clock::db($now)]);
            $st = $pdo->prepare('SELECT window_start, hits FROM rate_limit_bucket WHERE bucket = ? FOR UPDATE');
            $st->execute([$key]);
            $row = $st->fetch();
            $start = Clock::fromDb($row['window_start']);
            $hits = (int) $row['hits'];
            if ($start->getTimestamp() + $windowSeconds <= $now->getTimestamp()) {
                $start = $now;
                $hits = 0;
            }
            $hits++;
            $pdo->prepare('UPDATE rate_limit_bucket SET window_start = ?, hits = ? WHERE bucket = ?')
                ->execute([Clock::db($start), $hits, $key]);
            return $hits <= $limit;
        });
    }

    public static function clear(string $bucket): void
    {
        Db::pdo()->prepare('DELETE FROM rate_limit_bucket WHERE bucket = ?')->execute([self::key($bucket)]);
    }

    private static function key(string $bucket): string
    {
        return strlen($bucket) <= 128 ? $bucket : substr($bucket, 0, 87) . ':' . sha1($bucket);
    }
}
