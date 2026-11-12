<?php

namespace App\Submissions;

use App\Models\ProcurementCase;

/** Outcome of SubmitCase: submitted now, or already past DRAFT (double click). */
final readonly class SubmitResult
{
    private function __construct(public ProcurementCase $case, public bool $alreadySubmitted) {}

    public static function submitted(ProcurementCase $case): self
    {
        return new self($case, false);
    }

    public static function alreadySubmitted(ProcurementCase $case): self
    {
        return new self($case, true);
    }
}
