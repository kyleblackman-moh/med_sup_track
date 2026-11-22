<?php

namespace App\Events;

use App\Enums\ReviewDesk;
use App\Enums\ReviewOutcome;
use App\Enums\TrackStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * NOTIF-2: a Technocrat or CAB officer recorded a review outcome (dispatched
 * after commit by RecordReviewOutcome). Ids and enum values only, never the
 * reason text. $previousTrack lets a listener tell whether this outcome just
 * completed the gate.
 */
class ReviewOutcomeRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly string $caseId,
        public readonly ReviewDesk $desk,
        public readonly ReviewOutcome $outcome,
        public readonly ?TrackStatus $previousTrack,
    ) {}
}
