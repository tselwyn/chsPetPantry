<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;
use Pfpms\Validation\ValidationException;

/**
 * Named locks (GET_LOCK, timeout 0) that serialise the stock and catalogue writes which must not
 * interleave. They are taken outside any transaction and released afterwards, so a request never
 * waits on a named lock while holding row locks.
 *
 * - catalogue(): every catalogue write (categories, products, barcodes) and site creation, so the
 *   plain re-checks inside see a settled catalogue and every site × product gets its stock row.
 * - site(): stock counts, receipts and voids at one site, so goods are never received while a
 *   count is being posted. P3's "open an event" must take the same lock, so a count and an event
 *   opening can never overlap.
 */
final class Locks
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function catalogue(callable $fn): mixed
    {
        return self::with('catalogue', 'Someone else is changing the product catalogue right now. Wait a moment and try again.', $fn);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function site(int $siteId, callable $fn): mixed
    {
        return self::with("stock:site:$siteId", 'Stock is being recorded at this site right now (goods received, a void or a count, perhaps your own). '
            . 'Wait a moment, then check the receipts or the recent counts before trying again.', $fn);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function with(string $key, string $busy, callable $fn): mixed
    {
        $pdo = Db::pdo();
        $name = Db::lockName($pdo, $key);
        $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$name]);
        if ((int) $st->fetchColumn() !== 1) {
            throw ValidationException::one('_form', $busy);
        }
        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }
}
