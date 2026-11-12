<?php

namespace App\Workflow\Exceptions;

use App\Enums\CaseStatus;
use DomainException;

/** No enabled transition rule exists for this (from, to) pair. */
class InvalidTransition extends DomainException
{
    public function __construct(public readonly ?CaseStatus $from, public readonly CaseStatus $to)
    {
        parent::__construct(sprintf('No transition from %s to %s.', $from?->value ?? '(none)', $to->value));
    }
}
