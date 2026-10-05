<?php
declare(strict_types=1);

namespace Pfpms\Device;

use RuntimeException;

/**
 * A registration refused inside its transaction. Its reason goes only into the Denied audit row, written
 * after the transaction has rolled back; the tablet always gets the one generic answer.
 */
final class DeviceRefusal extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
