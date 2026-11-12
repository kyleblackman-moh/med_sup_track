<?php

namespace App\Workflow\Exceptions;

use DomainException;

/** The rule requires an audit reason and none (or only whitespace) was given. */
class ReasonRequired extends DomainException
{
    public function __construct(public readonly string $ruleCode)
    {
        parent::__construct("Transition {$ruleCode} requires a reason.");
    }
}
