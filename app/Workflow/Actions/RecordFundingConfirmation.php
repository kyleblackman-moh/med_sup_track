<?php

namespace App\Workflow\Actions;

use App\Enums\ReviewDesk;
use App\Models\ProcurementCase;
use App\Models\User;

/** A CAB officer records confirmed | not_confirmed (slice 10b). See RecordReviewOutcome. */
final class RecordFundingConfirmation
{
    public function __construct(private readonly RecordReviewOutcome $record) {}

    public function handle(ProcurementCase $case, User $actor, string $outcome, ?string $reason = null): ProcurementCase
    {
        return $this->record->handle(ReviewDesk::Funding, $case, $actor, $outcome, $reason);
    }
}
