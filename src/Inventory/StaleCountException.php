<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use RuntimeException;

/** A count no longer matches its review (stock, case sizes or products changed); nothing was posted. */
final class StaleCountException extends RuntimeException
{
    /** @param list<int> $productIds the products whose stock changed (empty when the sheet itself changed) */
    public function __construct(public readonly array $productIds, string $message = 'Stock changed while you were counting. Check the figures again before posting.')
    {
        parent::__construct($message);
    }
}
