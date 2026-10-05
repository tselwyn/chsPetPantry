<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Db;
use Pfpms\Validation\ValidationException;

/**
 * Named locks (GET_LOCK, timeout 0) for tablets, taken outside any transaction and released
 * afterwards, like Inventory\Locks. Order is always site, then tablet, then the device row.
 *
 * - device(): adding codes, cancelling and renaming a tablet; P2B's registration takes it too, so a
 *   code is never redeemed while it is being replaced or cancelled. Retire and Erase now do not take
 *   it (they wait on the device row instead), so a tablet that keeps contacting the server can never
 *   hold them off; each P2B upload locks the device row before it records an item.
 * - site(): adding and renaming tablets at a site, so names stay unique and the cap on tablets
 *   waiting to be registered holds.
 */
final class DeviceLocks
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function device(int $deviceId, callable $fn): mixed
    {
        return self::with("device:$deviceId", 'Someone else is changing this tablet right now, or it is registering. Wait a few seconds and try again.', $fn);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function site(int $siteId, callable $fn): mixed
    {
        return self::with("devices:site:$siteId", 'Someone else is adding or renaming a tablet at this site right now. Wait a moment and try again.', $fn);
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
