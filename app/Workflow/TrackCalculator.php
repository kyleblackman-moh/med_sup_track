<?php

namespace App\Workflow;

use App\Enums\ReviewDesk;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\ProcurementCase;
use Illuminate\Support\Collection;

/**
 * Derives a gate track from its reviewers' outcomes (slice 10b).
 *
 * DECISION (flagged for review): the reviewers of a track are the ACTIVE user
 * assignments created at T3 for that desk, found by assignment purpose
 * (ReviewDesk value), never by role name. For each of them only their LATEST
 * event counts. Then:
 *   - negative if ANY reviewer's latest outcome is negative;
 *   - positive only if EVERY reviewer's latest outcome is positive;
 *   - pending otherwise (including a track with no active reviewers).
 * An event by someone who is no longer an active reviewer is ignored.
 */
final class TrackCalculator
{
    /**
     * Active reviewer assignments of the desk, each with that reviewer's latest
     * event (or null), keyed by user id, in assignment order.
     *
     * @return Collection<int, array{assignment: CaseAssignment, latest: CaseTrackEvent|null}>
     */
    public static function reviewers(ProcurementCase $case, ReviewDesk $desk): Collection
    {
        $assignments = CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('purpose', $desk->value)
            ->whereNotNull('user_id')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->unique('user_id');

        $latest = $assignments->isEmpty() ? collect() : CaseTrackEvent::query()
            ->where('case_id', $case->getKey())
            ->where('track', $desk->value)
            ->whereIn('actor_id', $assignments->pluck('user_id')->all())
            ->orderBy('id')
            ->get()
            ->keyBy(fn (CaseTrackEvent $e) => (int) $e->actor_id); // later rows overwrite earlier ones

        return $assignments->mapWithKeys(fn (CaseAssignment $a) => [
            (int) $a->user_id => ['assignment' => $a, 'latest' => $latest->get((int) $a->user_id)],
        ]);
    }

    public static function compute(ProcurementCase $case, ReviewDesk $desk): TrackStatus
    {
        $reviewers = self::reviewers($case, $desk);

        if ($reviewers->isEmpty()) {
            return TrackStatus::PENDING;
        }

        $outcomes = $reviewers->map(fn (array $r) => $r['latest']?->outcome);

        if ($outcomes->contains(fn ($o) => $o !== null && ! $o->isPositive())) {
            return $desk->negativeTrack();
        }

        if ($outcomes->every(fn ($o) => $o !== null && $o->isPositive())) {
            return $desk->positiveTrack();
        }

        return TrackStatus::PENDING;
    }
}
