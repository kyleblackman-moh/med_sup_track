<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who holds a case now (Kyle, 2026-11-24): the ACTIVE assignments for the work
 * of the current stage, with name and role, so a case in parallel review lists
 * both the Technocrat and the Cost and Budgeting officer. Without one, the
 * stage owner (cases.current_holder_role, or the status's owner when that is
 * null); a closed, withdrawn or finally rejected case has no holder.
 *
 * "For the work of the current stage" because an assignment stays active after
 * its desk is done (CaseAssignment rows also give visibility, VisibleToUserScope):
 * the intake assignment stays active through review, and a review assignment
 * stays active once its track is decided. So:
 *   SUBMITTED  intake (the registry pool, or the accepting user)
 *   IN_REVIEW  technical while the technical track is open, funding while the funding track is open
 *   WITH_LEGAL legal
 * Other stages are held by a desk, not an assignment: their owner role.
 *
 * Display only (staff pages). Reads $case->assignments when loaded (eager load
 * assignments.user to keep a list at one query), else queries.
 */
final class CaseHolders
{
    /** Status => the owner shown when no assignment holds it (current_holder_role is null for these). */
    private const STATUS_OWNERS = [
        'IN_REVIEW' => 'cmo',      // both tracks done: the CMO prepares the file
        'RETURNED_TO_CMO' => 'cmo',
    ];

    private const NO_HOLDER = [CaseStatus::CLOSED, CaseStatus::WITHDRAWN, CaseStatus::REJECTED_FINAL, CaseStatus::DRAFT];

    /** @return list<array{role: string, label: string, name: string|null}> */
    public static function for(ProcurementCase $case): array
    {
        if (in_array($case->status, self::NO_HOLDER, true)) {
            return [];
        }

        $purposes = self::purposes($case);
        $active = self::activeAssignments($case)
            ->filter(fn (CaseAssignment $a) => in_array($a->purpose, $purposes, true));

        // A user's row replaces the pool row of the same desk (the pool row is completed at intake, but be safe).
        $named = $active->whereNotNull('user_id')->pluck('role')->all();
        $holders = $active
            ->reject(fn (CaseAssignment $a) => $a->user_id === null && in_array($a->role, $named, true))
            ->sortBy(fn (CaseAssignment $a) => [array_search($a->purpose, $purposes, true), $a->getKey()])
            ->map(fn (CaseAssignment $a) => ['role' => $a->role, 'label' => self::roleLabel($a->role), 'name' => $a->user?->name])
            ->values()->all();

        if ($holders !== []) {
            return $holders;
        }

        $owner = $case->current_holder_role ?? self::STATUS_OWNERS[$case->status->value] ?? null;

        return $owner === null ? [] : [['role' => $owner, 'label' => self::roleLabel($owner), 'name' => null]];
    }

    /** "Technocrat (A. Name), Cost and Budgeting (B. Name)"; null without a holder. */
    public static function label(ProcurementCase $case): ?string
    {
        $parts = array_map(
            fn (array $h) => $h['name'] === null ? $h['label'] : "{$h['label']} ({$h['name']})",
            self::for($case),
        );

        return $parts === [] ? null : implode(', ', $parts);
    }

    public static function roleLabel(string $role): string
    {
        return Role::tryFrom($role)?->label() ?? Str::headline($role);
    }

    /** @return list<string> the assignment purposes that hold the case at its current stage, in display order */
    private static function purposes(ProcurementCase $case): array
    {
        return match ($case->status) {
            CaseStatus::SUBMITTED => ['intake'],
            // A track is held until decided: technical once Favourable or Not favourable,
            // funding once Confirmed (Not confirmed keeps it with Cost and Budgeting).
            CaseStatus::IN_REVIEW => array_values(array_filter([
                in_array($case->technical_track, [TrackStatus::FAVOURABLE, TrackStatus::NOT_FAVOURABLE], true) ? null : 'technical',
                $case->funding_track === TrackStatus::CONFIRMED ? null : 'funding',
            ])),
            CaseStatus::WITH_LEGAL => ['legal'],
            default => [],
        };
    }

    /** @return Collection<int, CaseAssignment> */
    private static function activeAssignments(ProcurementCase $case): Collection
    {
        $rows = $case->relationLoaded('assignments')
            ? $case->assignments
            : $case->assignments()->with('user:id,name')->get();

        return $rows->filter(fn (CaseAssignment $a) => $a->completed_at === null && $a->cancelled_at === null)->values();
    }
}
