<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
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
 * round never counts). Then:
 *   - negative if ANY reviewer's latest outcome is negative;
 *   - positive only if EVERY reviewer's latest outcome is positive;
 *   - pending otherwise (including a track with no active reviewers).
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
