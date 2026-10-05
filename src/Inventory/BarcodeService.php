<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use PDOException;
use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Linking barcodes to products (US-16): "An unrecognised barcode can be associated with an existing
 * product once and is then recognised at every site." Codes are global and stored in the canonical
 * form of Barcode. Coordinators link codes nobody has linked (catalog.barcode_link); only an
 * Administrator unlinks or moves a code (catalog.manage), with a reason. Distribution lines keep the
 * product, not the code, so moving a code never changes history. All writes run under the catalogue
 * lock. The same service is used by the web pages and, later, the Station's queued links.
 */
final class BarcodeService
{
    public const BAD_CODE = 'That is not a product barcode we can use. Type the digits under the bars, including the last one, or scan it again.';

    /**
     * Link a scanned or typed code to an active product. Linking a code the product already has
     * does nothing; a code on another product is refused with that product's name.
     * @return array{code: string, already: bool, store_specific: bool}
     * @throws ValidationException
     */
    public static function link(?string $raw, int $productId, int $actorId, ?string $formatHint = null): array
    {
        $candidates = self::readings($raw, $formatHint);
        if (!$candidates) {
            throw ValidationException::one('code', self::BAD_CODE);
        }
        if ($productId < 1) {
            throw ValidationException::one('product_id', 'Choose the product this barcode belongs to.');
        }
        return Locks::catalogue(function () use ($candidates, $productId, $actorId): array {
            try {
                return Db::transaction(function () use ($candidates, $productId, $actorId): array {
                    $product = ProductRepository::lock($productId) ?? throw ValidationException::one('product_id', 'That product no longer exists.');
                    if (!(int) $product['is_active']) {
                        throw ValidationException::one('product_id', 'Choose an active product: this one is no longer received.');
                    }
                    $owners = ProductRepository::owners($candidates);
                    foreach ($owners as $code => $owner) {
                        if ($owner !== $productId) {
                            $other = ProductRepository::find($owner);
                            throw ValidationException::one('code', 'This barcode already belongs to ' . ($other ? ProductRepository::label($other) : 'another product')
                                . '. An Administrator can move it if that is wrong.');
                        }
                    }
                    $code = $candidates[0];
                    if ($owners) {
                        return ['code' => (string) array_key_first($owners), 'already' => true, 'store_specific' => Barcode::storeSpecific((string) array_key_first($owners))];
                    }
                    ProductRepository::insertBarcode($code, $productId, $actorId, Clock::db());
                    Audit::record('barcode_link', 'product', $productId, details: ['barcode' => $code, 'display' => Barcode::display($code)]);
                    return ['code' => $code, 'already' => false, 'store_specific' => Barcode::storeSpecific($code)];
                });
            } catch (PDOException $e) {
                // Someone linked the same code a moment earlier.
                throw Db::isDuplicateKey($e) ? ValidationException::one('code', 'Someone has just linked this barcode. Look it up again.') : $e;
            }
        });
    }

    /**
     * Remove a wrongly linked code from a product (Administrator), with a reason.
     * @param int $fromProductId the product the person is looking at: the code must still be on it
     * @throws ValidationException
     */
    public static function unlink(string $code, int $fromProductId, ?string $reason, int $actorId): void
    {
        $reason = self::reason($reason);
        Locks::catalogue(fn() => Db::transaction(function () use ($code, $fromProductId, $reason): void {
            $owner = self::ownerIs($code, $fromProductId);
            ProductRepository::deleteBarcode($code);
            Audit::record('barcode_unlink', 'product', $owner, reason: $reason, details: ['barcode' => $code, 'display' => Barcode::display($code)]);
        }));
    }

    /**
     * Move a code from a product to the product it really belongs to (Administrator), with a reason.
     * @param int $fromProductId the product the person is looking at: the code must still be on it
     * @throws ValidationException
     */
    public static function move(string $code, int $fromProductId, int $toProductId, ?string $reason, int $actorId): void
    {
        $reason = self::reason($reason);
        Locks::catalogue(fn() => Db::transaction(function () use ($code, $fromProductId, $toProductId, $reason): void {
            $from = self::ownerIs($code, $fromProductId);
            $to = ProductRepository::lock($toProductId);
            if ($to === null || !(int) $to['is_active']) {
                throw ValidationException::one('to_product_id', 'Choose the active product this barcode belongs to.');
            }
            if ($from === $toProductId) {
                return;
            }
            ProductRepository::moveBarcode($code, $toProductId);
            $details = ['barcode' => $code, 'display' => Barcode::display($code), 'from_product_id' => $from, 'to_product_id' => $toProductId];
            Audit::record('barcode_move', 'product', $from, reason: $reason, details: $details);
            Audit::record('barcode_move', 'product', $toProductId, reason: $reason, details: $details);
        }));
    }

    /**
     * The product a scan stands for, active or not (an inactive product's remaining stock can still
     * be given out). Null when the code is unknown, not a usable code, or its readings belong to two
     * different products (then the person picks the product by hand).
     */
    public static function lookup(?string $raw, ?string $formatHint = null): ?array
    {
        $owners = array_unique(array_values(ProductRepository::owners(self::readings($raw, $formatHint))));
        return count($owners) === 1 ? ProductRepository::find((int) $owners[0]) : null;
    }

    /**
     * Every reading of a scan, the hinted one first: a code linked from a wedge scanner (no hint)
     * must still be found, and still block a second link, when a camera reports the format.
     * @return list<string>
     */
    private static function readings(?string $raw, ?string $formatHint): array
    {
        return array_values(array_unique(array_merge(Barcode::candidates($raw, $formatHint), Barcode::candidates($raw))));
    }

    /**
     * The code's owner, which must be the product the page showed (a page opened before someone else
     * moved the code must not change the product it moved to). @throws ValidationException
     */
    private static function ownerIs(string $code, int $productId): int
    {
        $owner = ProductRepository::owners([$code])[$code] ?? throw ValidationException::one('_form', 'That barcode is no longer linked.');
        if ($owner !== $productId) {
            $other = ProductRepository::find($owner);
            throw ValidationException::one('_form', 'That barcode is no longer on this product: it now belongs to '
                . ($other ? ProductRepository::label($other) : 'another product') . '. Nothing was changed.');
        }
        return $owner;
    }

    private static function reason(?string $reason): string
    {
        return Validator::text($reason, 255) ?? throw ValidationException::one('reason', 'Say why (up to 255 characters). It is kept in the audit log.');
    }
}
