<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use RuntimeException;

/**
 * A movement would take stock below zero (UC-06 §3.2.3 then offers the quantity available and
 * substitutes). Lists every short product at once, so a multi-line issue or void is not refused
 * one product at a time.
 */
final class InsufficientStockException extends RuntimeException
{
    /** @param list<array{product_id: int, requested: string, available: string}> $shortages */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Not enough stock for ' . count($shortages) . ' product(s).');
    }
}
