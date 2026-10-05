<?php
declare(strict_types=1);

namespace Pfpms\Account;

use RuntimeException;

/** Someone else saved the account after it was loaded (optimistic locking, UC-11). */
final class StaleAccountException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Someone else changed this account while you were editing it.');
    }
}
