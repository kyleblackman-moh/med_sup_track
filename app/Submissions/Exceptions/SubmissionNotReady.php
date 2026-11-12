<?php

namespace App\Submissions\Exceptions;

use App\Submissions\ReadinessReport;
use DomainException;

/** The draft failed the readiness check at submission; the report holds vendor-safe reasons. */
class SubmissionNotReady extends DomainException
{
    public function __construct(public readonly ReadinessReport $report)
    {
        parent::__construct('This request is not ready to submit.');
    }
}
