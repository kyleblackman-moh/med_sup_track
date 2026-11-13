<?php

namespace App\Workflow\Actions;

use App\Models\ProcurementCase;
use Carbon\CarbonInterface;

/** Outcome of IntakeCase: accepted now, or already accepted (double click, or another user). */
final readonly class IntakeResult
{
    private function __construct(
        public ProcurementCase $case,
        public bool $alreadyAccepted,
        public ?string $acceptedBy = null,
        public ?CarbonInterface $acceptedAt = null,
    ) {}

    public static function accepted(ProcurementCase $case): self
    {
        return new self($case, false);
    }

    public static function alreadyAccepted(ProcurementCase $case, ?string $by, ?CarbonInterface $at): self
    {
        return new self($case, true, $by, $at);
    }
}
