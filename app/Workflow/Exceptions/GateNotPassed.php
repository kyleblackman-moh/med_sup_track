<?php

namespace App\Workflow\Exceptions;

use DomainException;

/**
 * A transition guard is not satisfied: the T4 gate (spec 6.4: technical, funding,
 * hardcopy) or the T1 submit guard (reference_no and readiness error keys).
 */
class GateNotPassed extends DomainException
{
    /** @param  list<string>  $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Gate not passed; missing: '.implode(', ', $missing).'.');
    }
}
