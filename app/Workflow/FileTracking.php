<?php

namespace App\Workflow;

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\CaseAssignment;
use App\Models\CaseQuery;
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
     * Kyle, 2026-11-24: the four units a file passes through, in route order.
     * Every open status belongs to one of them (UNIT_OF); the closed ones to
     * none. In Review is the Technocrats' unit (CAB's funding check runs beside
     * it). [Assumption] to confirm with the CMO's Office: Non-Objection Granted
     * and Letter Issued sit with the CMO's Office (it sends the letter).
     */
    public const UNITS = [
        'cmo' => "CMO's Office",
        'technocrats' => 'Technocrats',
        'legal' => 'Legal Unit',
        'ps' => 'PS Office',
    ];

    private const UNIT_OF = [
        'SUBMITTED' => 'cmo',
        'CMO_INTAKE' => 'cmo',
        'IN_REVIEW' => 'technocrats',
        'READY_FOR_FILE' => 'cmo',
        'FILE_PREPARED' => 'cmo',
        'WITH_LEGAL' => 'legal',
        'WITH_PS' => 'ps',
        'NON_OBJECTION_GRANTED' => 'cmo',
        'LETTER_ISSUED' => 'cmo',
        'RETURNED_TO_CMO' => 'cmo',
    ];

    /**
     * Kyle, 2026-11-26: the lanes of "Where Is The File", top to bottom. The
     * vendor lane is named after the case's organisation (never a fixed name);
     * CAB sits beside the Technocrats and is visited once (the funding check
     * runs with the first technical review).
     */
    public const LANES = [
        'vendor' => null,
        'cmo' => "CMO's Office",
        'technocrats' => 'Technocrats',
        'cab' => 'CAB',
        'legal' => 'Legal Unit',
        'ps' => 'PS Office',
    ];

    /** At most this many rows on the dashboard board; the rest are on the case list. */
    public const BOARD_MAX = 50;

    /** The unit key that holds a case at $status, or null when nobody does (closed, withdrawn, rejected, draft). */
    public static function unitOf(CaseStatus $status): ?string
    {
        return self::UNIT_OF[$status->value] ?? null;
    }

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
                // Kyle, 2026-11-26: the pill names who acts next (ActionOwner).
                'stage' => ($owner = ActionOwner::staff($case))['label'],
                'status_tone' => $owner['tone'],
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

            $unit = self::unitOf($status);
            $stages[] = [
                'status' => $status->value,
                'unit' => $unit,
                'unit_label' => $unit === null ? null : self::UNITS[$unit],
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

    /**
     * Kyle, 2026-11-26: the file's path through the lanes, left to right, for
     * "Where Is The File". One step per stage visit (timeline()) in its unit's
     * lane, so a file that goes back and forth shows every visit. Added to it:
     *
     * - the start: the vendor sending the request (the vendor lane);
     * - CAB, once: beside the first In Review visit, in the same column (the
     *   funding check runs at the same time as the technical review);
     * - a branch to the vendor for each query raised to the vendor, from when
     *   it was raised until it was answered (or still open). Cancelled
     *   queries are left out. The file itself stays where it was.
     *
     * A closed, withdrawn or rejected case ends in the CMO's lane. Every step
     * names the stage it belongs to (stage), so choosing it shows that stage.
     *
     * @param  list<array<string, mixed>>  $stages  timeline($case)
     * @return array{lanes: list<array{key: string, label: string}>, steps: list<array<string, mixed>>, columns: int}
     */
    public static function path(ProcurementCase $case, array $stages, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        // The vendor's short name where it has one, as on the status pill (ActionOwner).
        $vendor = trim((string) ($case->organisation?->short_name ?: $case->organisation?->name)) ?: 'Vendor';
        $lanes = array_map(fn (string $key) => ['key' => $key, 'label' => self::LANES[$key] ?? $vendor], array_keys(self::LANES));

        // [sort time, order, step]: the start first, then stages and query branches by time.
        $events = [];
        $first = $stages[0] ?? null;
        if ($first !== null) {
            $events[] = [$first['entered_at_iso'], 0, [
                'lane' => 'vendor', 'kind' => 'start', 'label' => 'Request sent', 'stage' => 0,
                'entered_at' => $first['entered_at'], 'left_at' => null, 'duration' => null, 'open' => false,
            ]];
        }
        $cabShown = false;
        foreach ($stages as $i => $stage) {
            $lane = $stage['unit'] ?? 'cmo';
            $events[] = [$stage['entered_at_iso'], 1, [
                'lane' => $lane, 'kind' => $stage['unit'] === null ? 'end' : 'stage', 'label' => $stage['label'], 'stage' => $i,
                'entered_at' => $stage['entered_at'], 'left_at' => $stage['left_at'], 'duration' => $stage['duration'], 'open' => $stage['open'],
                'with_cab' => $stage['status'] === CaseStatus::IN_REVIEW->value && ! $cabShown,
            ]];
            if ($stage['status'] === CaseStatus::IN_REVIEW->value) {
                $cabShown = true;
            }
        }

        $stageAt = function (CarbonImmutable $at) use ($stages): int {
            $index = 0;
            foreach ($stages as $i => $stage) {
                if (CarbonImmutable::parse($stage['entered_at_iso'])->lte($at)) {
                    $index = $i;
                }
            }

            return $index;
        };
        $queries = CaseQuery::query()->where('case_id', $case->getKey())
            ->where('raised_to_role', Role::VendorApplicant->value)
            ->whereNull('cancelled_at')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'created_at', 'answered_at', 'resolved_at']);
        foreach ($queries as $query) {
            $raised = self::utc($query->created_at);
            if ($raised === null) {
                continue;
            }
            $back = self::utc($query->answered_at ?? $query->resolved_at);
            $events[] = [$raised->setTimezone(self::TIMEZONE)->toIso8601String(), 2, [
                'lane' => 'vendor', 'kind' => 'query', 'label' => $back === null ? 'Query to answer' : 'Query answered', 'stage' => $stageAt($raised),
                'entered_at' => self::local($raised), 'left_at' => $back === null ? null : self::local($back),
                'duration' => self::duration(self::seconds($raised, $back ?? $now)), 'open' => $back === null,
            ]];
        }

        usort($events, fn (array $a, array $b) => [CarbonImmutable::parse($a[0])->getTimestamp(), $a[1]] <=> [CarbonImmutable::parse($b[0])->getTimestamp(), $b[1]]);

        $steps = [];
        $column = 0;
        foreach ($events as [, , $step]) {
            $withCab = $step['with_cab'] ?? false;
            unset($step['with_cab']);
            $steps[] = [...$step, 'column' => $column, 'number' => count($steps) + 1];
            if ($withCab) {
                $steps[] = [...$step, 'lane' => 'cab', 'kind' => 'beside', 'label' => 'Funding check', 'column' => $column, 'number' => count($steps) + 1];
            }
            $column++;
        }

        return ['lanes' => $lanes, 'steps' => $steps, 'columns' => $column];
    }

    /**
     * Kyle, 2026-11-26: "they [the role] can see how long the file is there
     * for but not others". For a viewer limited to some lanes (ViewerScope),
     * every stage, step and unit outside them loses its times and holders
     * here, on the server, so they never reach the page. A stage or step is
     * the viewer's own when its lane is theirs; CAB's own part is the first
     * review (the funding check runs beside it). Every stage and step gets
     * `mine`; `restricted` says whether anything was held back.
     *
     * @param  array<string, mixed>  $tracking  units() plus stages and path
     * @param  list<string>|null  $lanes  null: everything
     * @return array<string, mixed>
     */
    public static function forViewer(array $tracking, ?array $lanes): array
    {
        $stageMine = function (array $stage) use ($lanes): bool {
            if ($lanes === null) {
                return true;
            }

            return in_array($stage['unit'], $lanes, true)
                || (in_array('cab', $lanes, true) && $stage['status'] === CaseStatus::IN_REVIEW->value && $stage['visit'] === 1);
        };

        $stages = [];
        foreach ($tracking['stages'] as $stage) {
            $mine = $stageMine($stage);
            $stages[] = $mine ? [...$stage, 'mine' => true] : [
                ...$stage,
                'mine' => false,
                'holders' => [],
                'entered_at' => null, 'entered_at_iso' => null,
                'left_at' => null, 'left_at_iso' => null,
                'duration_seconds' => 0, 'duration' => null,
            ];
        }

        $steps = [];
        foreach ($tracking['path']['steps'] as $step) {
            $mine = $lanes === null || in_array($step['lane'], $lanes, true);
            $steps[] = $mine ? [...$step, 'mine' => true] : [...$step, 'mine' => false, 'entered_at' => null, 'left_at' => null, 'duration' => null];
        }

        $current = $tracking['current'];
        $last = $stages === [] ? null : $stages[count($stages) - 1];
        if ($current !== null && $lanes !== null && ! ($last['mine'] ?? false)) {
            $current = [...$current, 'received_at' => null, 'received_at_iso' => null, 'seconds' => null, 'days' => null];
        }

        $units = array_map(fn (array $unit) => $lanes === null || in_array($unit['key'], $lanes, true)
            ? $unit
            : [...$unit, 'seconds' => 0, 'days' => '—'], $tracking['units']);

        return [
            ...$tracking,
            'restricted' => $lanes !== null,
            'current' => $current,
            'units' => $units,
            'stages' => $stages,
            'path' => [...$tracking['path'], 'steps' => $steps],
        ];
    }

    /**
     * Kyle, 2026-11-24: where the file is and how long it spent at each unit,
     * from case_transitions (the open stage runs to now). For the dashboard
     * board and the case page's File Tracking tab.
     *
     * - current: the unit holding it now (null once closed), with received_at:
     *   when it last arrived there (the start of the latest unbroken run of
     *   stages at that unit), and how long it has been there since.
     * - units: every unit in route order with its total time (all visits),
     *   whether it was ever reached, and whether it holds the file now.
     *
     * @param  Collection<int, CaseTransition>  $transitions  of one case, oldest first
     * @return array<string, mixed>
     */
    public static function units(Collection $transitions, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $transitions = $transitions->filter(fn (CaseTransition $t) => $t->to_status !== CaseStatus::DRAFT)->values();

        $seconds = array_fill_keys(array_keys(self::UNITS), 0);
        $visited = array_fill_keys(array_keys(self::UNITS), false);
        $runUnit = null;
        $runStart = null;

        foreach ($transitions as $i => $t) {
            $unit = self::unitOf($t->to_status);
            $entered = self::utc($t->created_at);
            $next = $transitions->get($i + 1);
            $left = $next === null ? $now : self::utc($next->created_at);

            if ($unit !== null) {
                $seconds[$unit] += self::seconds($entered, $left);
                $visited[$unit] = true;
            }
            if ($unit !== $runUnit) {
                $runUnit = $unit;
                $runStart = $entered;
            }
        }

        $current = $runUnit;
        $atUnit = $current === null || $runStart === null ? null : self::seconds($runStart, $now);

        return [
            'current' => $current === null ? null : [
                'key' => $current,
                'label' => self::UNITS[$current],
                'received_at' => self::localDate($runStart),
                'received_at_iso' => $runStart->setTimezone(self::TIMEZONE)->toIso8601String(),
                'seconds' => $atUnit,
                'days' => self::days($atUnit),
            ],
            'units' => array_values(array_map(fn (string $key) => [
                'key' => $key,
                'label' => self::UNITS[$key],
                'seconds' => $seconds[$key],
                'days' => $visited[$key] ? self::days($seconds[$key]) : '—',
                'visited' => $visited[$key],
                'current' => $key === $current,
            ], array_keys(self::UNITS))),
        ];
    }

    /**
     * Kyle, 2026-11-24: the dashboard's File Tracking board: the open cases
     * $user can see (the caller passes them through the visibility scope),
     * each with where it is, when it last arrived there and the days at every
     * unit. Longest at its current unit first; at most BOARD_MAX rows.
     *
     * @param  Collection<int, ProcurementCase>  $cases  with transitions and requisition loaded
     * @return array{rows: list<array<string, mixed>>, total: int, units: array<string, string>, more_href: string|null}
     */
    public static function board(Collection $cases, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        $rows = $cases
            ->filter(fn (ProcurementCase $case) => self::unitOf($case->status) !== null)
            ->map(function (ProcurementCase $case) use ($now) {
                $tracking = self::units($case->transitions->sortBy([['created_at', 'asc'], ['id', 'asc']])->values(), $now);

                return [
                    'id' => (string) $case->getKey(),
                    'reference' => $case->reference_no,
                    'item' => $case->requisition?->item_description !== null ? Str::limit($case->requisition->item_description, 80) : null,
                    'din' => $case->requisition?->din,
                    'href' => route('cases.show', $case, false),
                    'stage' => ($owner = ActionOwner::staff($case))['label'],
                    'status_tone' => $owner['tone'],
                    'holder_label' => CaseHolders::label($case),
                    ...$tracking,
                ];
            })
            ->sortBy([fn ($a, $b) => ($b['current']['seconds'] ?? 0) <=> ($a['current']['seconds'] ?? 0), ['reference', 'asc']])
            ->values();

        return [
            'rows' => $rows->take(self::BOARD_MAX)->all(),
            'total' => $rows->count(),
            'units' => self::UNITS,
            'more_href' => $rows->count() > self::BOARD_MAX ? route('cases.index', [], false) : null,
        ];
    }

    /** Whole days for the board: "Under 1 day", "1 day", "12 days"; the dash for null. */
    public static function days(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $days = intdiv($seconds, 86400);

        return match (true) {
            $days === 0 => 'Under 1 day',
            $days === 1 => '1 day',
            default => number_format($days).' days',
        };
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

    private static function localDate(CarbonImmutable $at): string
    {
        return $at->setTimezone(self::TIMEZONE)->format('j M Y');
    }

    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }
}
