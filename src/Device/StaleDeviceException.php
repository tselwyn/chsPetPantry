<?php
declare(strict_types=1);

namespace Pfpms\Device;

use RuntimeException;

/** The tablet changed since the page was opened (someone else acted on it); nothing was changed. */
final class StaleDeviceException extends RuntimeException
{
    public function __construct(string $message = 'This tablet changed since you opened the page (someone else may have acted on it). Its current state is shown: check it before trying again.')
    {
        parent::__construct($message);
    }
}
