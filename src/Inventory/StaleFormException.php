<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use RuntimeException;

/** The record changed after the form was opened; nothing was saved. The page reloads the latest version. */
final class StaleFormException extends RuntimeException
{
}
