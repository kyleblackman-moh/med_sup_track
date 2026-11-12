<?php

namespace App\Submissions\Exceptions;

use App\Enums\CaseStatus;
use DomainException;

/** The case is no longer a DRAFT, so its request content cannot change. */
class DraftNotEditable extends DomainException
{
    public function __construct(public readonly ?CaseStatus $status)
    {
        parent::__construct(sprintf('Only a draft can be edited; this case is %s.', $status?->value ?? 'unknown'));
    }
}
