<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\ReviewOutcome;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Derives a gate track from its reviewers' outcomes (slice 10b).
 *
 * DECISION (flagged for review): the reviewers of a track are the ACTIVE user
 * assignments for that desk, found by assignment purpose (ReviewDesk value),
 * never by role name. For each of them only their LATEST event counts, and only
 * an event created at or after that reviewer's active assignment's assigned_at
 * (slice 10e: a new review round starts blank, so an outcome from an earlier
 * round never counts).
 *
 * Kyle, 2026-11-26: the case now goes to the whole desk (every eligible
 * officer), so ONE officer's outcome completes the desk: the most recent
 * outcome among the active reviewers decides the track (positive or
 * negative); pending while none has recorded (or with no active reviewers).
 * Was: negative if any was negative, positive only if every one was.
 * An event by someone who is no longer an active reviewer is ignored.
 *
 * Precision: case_assignments.assigned_at and case_track_events.created_at are
 * both SQL Server datetime (about 3 ms). An outcome is always recorded in a
 * later request than the assignment it belongs to.
 */
final class TrackCalculator
{
    /**
     * Active reviewer assignments of the desk, each with that reviewer's latest
     * event in the current assignment (or null), keyed by user id, in
     * assignment order. If a user somehow has several active rows, the latest
     * one is used.
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
            ->keyBy(fn (CaseAssignment $a) => (int) $a->user_id) // later rows overwrite earlier ones
            ->sortBy(fn (CaseAssignment $a) => (int) $a->getKey());

        $events = $assignments->isEmpty() ? collect() : CaseTrackEvent::query()
            ->where('case_id', $case->getKey())
            ->where('track', $desk->value)
            ->whereIn('actor_id', $assignments->keys()->all())
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CaseTrackEvent $e) => (int) $e->actor_id);

        return $assignments->map(function (CaseAssignment $a, int $userId) use ($events) {
            $since = $a->assigned_at;
            $current = ($events->get($userId) ?? collect())
                ->filter(fn (CaseTrackEvent $e) => $since === null || ($e->created_at !== null && $e->created_at->gte($since)));

            return ['assignment' => $a, 'latest' => $current->last()];
        });
    }

    /**
     * May $user record (or change) their outcome on this desk now? IN_REVIEW,
     * an active reviewer of the desk, and CasePolicy::act with the desk's
     * permission. The one definition, used by the case page's review forms
     * and the dashboard work queue.
     */
    public static function canRecordReview(ProcurementCase $case, User $user, ReviewDesk $desk): bool
    {
        return $case->status === CaseStatus::IN_REVIEW
            && self::reviewers($case, $desk)->has((int) $user->getKey())
            && Gate::forUser($user)->allows('act', [$case, $desk->permission()->value]);
    }

    /** Kyle, 2026-11-26: the desk's most recent outcome (the one that decides the track), or null while none is recorded. */
    public static function latestOutcome(ProcurementCase $case, ReviewDesk $desk): ?ReviewOutcome
    {
        return self::reviewers($case, $desk)->pluck('latest')->filter()->sortBy(fn ($e) => (int) $e->getKey())->last()?->outcome;
    }

    public static function compute(ProcurementCase $case, ReviewDesk $desk): TrackStatus
    {
        $reviewers = self::reviewers($case, $desk);

        if ($reviewers->isEmpty()) {
            return TrackStatus::PENDING;
        }

        // The most recent outcome of the desk (event ids only grow).
        $latest = $reviewers->pluck('latest')->filter()->sortBy(fn ($e) => (int) $e->getKey())->last();

        if ($latest === null || $latest->outcome === null) {
            return TrackStatus::PENDING;
        }

        return $latest->outcome->isPositive() ? $desk->positiveTrack() : $desk->negativeTrack();
    }
}
