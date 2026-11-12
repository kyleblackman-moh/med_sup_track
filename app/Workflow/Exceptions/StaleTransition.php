<?php

namespace App\Workflow\Exceptions;

use App\Enums\CaseStatus;
use DomainException;

/** The case's status changed between choosing the rule and locking the row. */
class StaleTransition extends DomainException
{
    public function __construct(public readonly CaseStatus $expected, public readonly ?CaseStatus $actual)
    {
        parent::__construct(sprintf('Case status is %s, expected %s.', $actual?->value ?? '(none)', $expected->value));
    }
}
