<?php

namespace App\Workflow\Exceptions;

use DomainException;

/** The T4 gate (spec 6.4) is not satisfied. */
class GateNotPassed extends DomainException
{
    /** @param  list<'technical'|'funding'|'hardcopy'>  $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Gate not passed; missing: '.implode(', ', $missing).'.');
    }
}
