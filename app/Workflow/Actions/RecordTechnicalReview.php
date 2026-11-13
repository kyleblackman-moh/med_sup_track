<?php

namespace App\Workflow\Actions;

use App\Enums\ReviewDesk;
use App\Models\ProcurementCase;
use App\Models\User;

/** A technocrat records favourable | not_favourable (slice 10b). See RecordReviewOutcome. */
final class RecordTechnicalReview
{
    public function __construct(private readonly RecordReviewOutcome $record) {}

    public function handle(ProcurementCase $case, User $actor, string $outcome, ?string $reason = null): ProcurementCase
    {
        return $this->record->handle(ReviewDesk::Technical, $case, $actor, $outcome, $reason);
    }
}
