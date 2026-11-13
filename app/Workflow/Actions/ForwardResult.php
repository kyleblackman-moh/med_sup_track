<?php

namespace App\Workflow\Actions;

use App\Models\ProcurementCase;
use Carbon\CarbonInterface;

/** Outcome of ForwardForReview: sent now, or already sent (double click, or another user). */
final readonly class ForwardResult
{
    private function __construct(
        public ProcurementCase $case,
        public bool $alreadyForwarded,
        public ?string $forwardedBy = null,
        public ?CarbonInterface $forwardedAt = null,
    ) {}

    public static function forwarded(ProcurementCase $case): self
    {
        return new self($case, false);
    }

    public static function alreadyForwarded(ProcurementCase $case, ?string $by, ?CarbonInterface $at): self
    {
        return new self($case, true, $by, $at);
    }
}
