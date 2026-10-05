<?php
declare(strict_types=1);

namespace Pfpms\Allotment;

use RuntimeException;

/** The draft changed after the form was opened or reviewed; nothing was saved or published. */
final class StaleDraftException extends RuntimeException
{
}
