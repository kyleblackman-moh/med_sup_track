<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Kyle, 2026-11-24: File Tracking, server side (the page comes with the UI).
 *
 * The list: every case the user can see (the visibility scope; staff never see
 * drafts), with its current stage and holder(s) (CaseHolders), the time at the
 * current stage, the total time since submission and how many times it was
 * returned to the CMO. Longest at the current stage first.
 *
 * The timeline of one case: every stage from submission in order, INCLUDING
 * repeats ("With Legal (2nd time)"), from case_transitions (one stage per
 * transition: entered at it, left at the next). The open stage runs to now.
 * Holders of a past stage: the assignments for that stage's work that were
 * active at some point during it (CaseHolders::stagePurposes), else the holder
 * role of the transition that entered it.
 *
 * Plain elapsed time (working hours come with the SLAs, Phase 3). Times are
 * stored UTC and shown in Port of Spain. Read-only; the CALLER scopes the
 * cases (list) or authorises the case (timeline).
 */
final class FileTracking
{
    public const TIMEZONE = 'America/Port_of_Spain';

    /**
     * @param  Collection<int, ProcurementCase>  $cases  with transitions, assignments.user and requisition loaded
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $cases, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return $cases->map(function (ProcurementCase $case) use ($now) {
            $transitions = $case->transitions;
            $since = self::utc($transitions->last()?->created_at) ?? self::utc($case->status_changed_at) ?? self::utc($case->submitted_at);
            $submitted = self::utc($case->submitted_at);
            $atStage = $since === null ? null : self::seconds($since, $now);
            $total = $submitted === null ? null : self::seconds($submitted, $now);

            return [
                'id' => (string) $case->getKey(),
                'reference' => $case->reference_no,
                'item' => $case->requisition?->item_description !== null ? Str::limit($case->requisition->item_description, 80) : null,
                'stage' => $case->status->label(),
                'status_tone' => $case->status->tone(),
                'holders' => CaseHolders::for($case),
                'holder_label' => CaseHolders::label($case),
                'at_stage_seconds' => $atStage,
                'at_stage' => self::duration($atStage),
                'since_submission_seconds' => $total,
                'since_submission' => self::duration($total),
                'times_returned' => $transitions->where('to_status', CaseStatus::RETURNED_TO_CMO)->count(),
            ];
        })
            ->sortBy([['at_stage_seconds', 'desc'], ['reference', 'asc']])
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function timeline(ProcurementCase $case, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $transitions = CaseTransition::query()->where('case_id', $case->getKey())->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (CaseTransition $t) => $t->to_status !== CaseStatus::DRAFT)->values();
        $assignments = CaseAssignment::query()->where('case_id', $case->getKey())->with('user:id,name')->orderBy('id')->get();

        $visits = [];
        $stages = [];
        foreach ($transitions as $i => $t) {
            $status = $t->to_status;
            $visits[$status->value] = ($visits[$status->value] ?? 0) + 1;
            $entered = self::utc($t->created_at);
            $next = $transitions->get($i + 1);
            $left = $next === null ? null : self::utc($next->created_at);
            $seconds = self::seconds($entered, $left ?? $now);

            $stages[] = [
                'status' => $status->value,
                'label' => $status->label().($visits[$status->value] > 1 ? ' ('.self::ordinal($visits[$status->value]).' time)' : ''),
                'visit' => $visits[$status->value],
                'holders' => self::stageHolders($t, $assignments, $entered, $left),
                'entered_at' => self::local($entered),
                'entered_at_iso' => $entered->setTimezone(self::TIMEZONE)->toIso8601String(),
                'left_at' => $left === null ? null : self::local($left),
                'left_at_iso' => $left?->setTimezone(self::TIMEZONE)->toIso8601String(),
                'open' => $left === null,
                'duration_seconds' => $seconds,
                'duration' => self::duration($seconds),
            ];
        }

        return $stages;
    }

    /** "3 d 4 h", "5 h 12 min", "8 min", "under a minute"; the dash for null. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days} d {$hours} h",
            $hours > 0 => "{$hours} h {$minutes} min",
            $minutes > 0 => "{$minutes} min",
            default => 'under a minute',
        };
    }

    /**
     * @param  Collection<int, CaseAssignment>  $assignments
     * @return list<array{role: string, label: string, name: string|null}>
     */
    private static function stageHolders(CaseTransition $entry, Collection $assignments, CarbonImmutable $entered, ?CarbonImmutable $left): array
    {
        $purposes = CaseHolders::stagePurposes($entry->to_status);

        $holders = $assignments
            ->filter(fn (CaseAssignment $a) => in_array($a->purpose, $purposes, true) && $a->user_id !== null)
            ->filter(function (CaseAssignment $a) use ($entered, $left) {
                $start = self::utc($a->assigned_at);
                $end = self::utc($a->completed_at ?? $a->cancelled_at);

                return ($left === null || $start === null || $start->lt($left)) && ($end === null || $end->gt($entered));
            })
            ->sortBy(fn (CaseAssignment $a) => [array_search($a->purpose, $purposes, true), $a->getKey()])
            ->map(fn (CaseAssignment $a) => ['role' => $a->role, 'label' => CaseHolders::roleLabel($a->role), 'name' => $a->user?->name])
            ->unique(fn (array $h) => $h['role'].'|'.$h['name'])
            ->values()->all();

        if ($holders !== []) {
            return $holders;
        }

        $role = TransitionRules::find($entry->from_status, $entry->to_status)?->holderRole;

        return $role === null ? [] : [['role' => $role, 'label' => CaseHolders::roleLabel($role), 'name' => null]];
    }

    private static function seconds(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }

    private static function utc(?CarbonInterface $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc();
    }

    private static function local(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::TIMEZONE)->format('j M Y, g:i a');
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }
}
