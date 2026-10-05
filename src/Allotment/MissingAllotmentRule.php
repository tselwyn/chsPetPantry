<?php
declare(strict_types=1);

namespace Pfpms\Allotment;

use RuntimeException;

/** A pet's species and size band has no rule in the version in force: the allotment cannot be worked out. */
final class MissingAllotmentRule extends RuntimeException
{
    public function __construct(public readonly int $speciesId, public readonly int $sizeBandId)
    {
        parent::__construct("No allotment rule covers species $speciesId, size band $sizeBandId. Publish an allotment version that includes this size band.");
    }
}
