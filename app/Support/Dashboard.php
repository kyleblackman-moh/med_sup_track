<?php

namespace App\Support;

use App\Enums\CaseStatus as S;
use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Enums\TrackStatus;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Http\Resources\Requests\RequestSummaryResource;
use App\Models\CaseAssignment;
use App\Models\CaseTrackEvent;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\SendToLegal;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;

/**
 * Widgets for the one dashboard template (spec 12.3: the same widgets with
 * different filters serve every role). Every count runs through
 * ProcurementCase's visibility scope, so a reviewer's numbers cover the cases
 * they hold or were assigned and a view_all user's cover every case. Widgets
 * are chosen by permission, never by role name. Vendors get vendor-safe
 * labels and tones only (vendorLabel / vendorTone), no internal stages.
 */
final class Dashboard
{
    /** Rows in the staff activity feed and the gate list. */
    public const LIST_LIMIT = 6;

    /** Staff stage rail, in workflow order (open cases plus letters issued). */
    private const ROUTE = [
        S::SUBMITTED, S::CMO_INTAKE, S::IN_REVIEW, S::READY_FOR_FILE, S::FILE_PREPARED,
        S::WITH_LEGAL, S::WITH_PS, S::RETURNED_TO_CMO, S::NON_OBJECTION_GRANTED, S::LETTER_ISSUED,
    ];

    /** Vendor stage rail: vendor groups in the order a request moves. */
    private const VENDOR_ROUTE = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'LETTER_ISSUED', 'NOT_APPROVED', 'WITHDRAWN'];

    /**
     * @param  array{groups: list<array<string, mixed>>, items: list<array<string, mixed>>, truncated: bool}|null  $queue  WorkQueue::for($user)
     * @return array<string, mixed>
     */
    public static function for(User $user, ?array $queue): array
    {
        if ($user->isVendor()) {
            return ['kind' => 'vendor', ...self::vendor($user)];
        }

        return [
            'kind' => 'staff',
            'kpis' => self::staffKpis($user, $queue),
            'stages' => self::staffStages($user),
            // Gate tracker (spec 12.2 C) for the file-preparing desk.
            'gate' => $user->can(Permission::FilePrepare->value) ? self::gate() : null,
            'activity' => self::activity(),
        ];
    }

    /**
     * Two shared tiles from the queue, then two for the user's desk.
     *
     * @return list<array{key: string, label: string, value: int|null, format: string, hint: string}>
     */
    private static function staffKpis(User $user, ?array $queue): array
    {
        $items = $queue['items'] ?? [];
        $steps = count(array_unique(array_column($items, 'step')));
        $oldest = collect($items)->first(fn (array $i) => $i['days_waiting'] !== null);

        $kpis = [
            self::kpi('waiting', 'Waiting on you', count($items), 'count', match (true) {
                $items === [] => 'nothing waiting right now',
                $steps === 1 => strtolower((string) $items[0]['action']),
                default => "across {$steps} steps",
            }),
            self::kpi('oldest', 'Oldest wait', $oldest['days_waiting'] ?? null, 'days', $oldest !== null
                ? trim(($oldest['reference_no'] ?? '').', '.strtolower((string) $oldest['action']), ', ')
                : 'no items waiting'),
        ];

        return [...$kpis, ...self::deskKpis($user)];
    }

    /** @return list<array{key: string, label: string, value: int|null, format: string, hint: string}> */
    private static function deskKpis(User $user): array
    {
        $month = CarbonImmutable::now(StaffCaseSummary::TIMEZONE)->startOfMonth()->utc();

        if ($user->can(Permission::FilePrepare->value)) {
            return [
                self::kpi('in_review', 'In review', self::countStatus(S::IN_REVIEW), 'count', 'with Technical and CAB'),
                self::kpi('letters', 'Letters to issue', self::countStatus(S::NON_OBJECTION_GRANTED), 'count', 'non-objection granted'),
            ];
        }

        if ($user->can(Permission::LegalConfirm->value)) {
            return [
                self::kpi('with_you', 'With you', self::activeAssignments($user, SendToLegal::PURPOSE, S::WITH_LEGAL), 'count', 'files with Legal'),
                self::kpi('returned', 'Returned this month', self::transitionsBy($user, S::WITH_LEGAL, S::RETURNED_TO_CMO, $month), 'count', 'sent back to the CMO'),
            ];
        }

        if ($user->can(Permission::NonObjectionGrant->value)) {
            return [
                self::kpi('granted', 'Granted this month', self::transitionsBy($user, S::WITH_PS, S::NON_OBJECTION_GRANTED, $month), 'count', 'non-objections'),
                self::kpi('returned', 'Returned this month', self::transitionsBy($user, S::WITH_PS, S::RETURNED_TO_CMO, $month), 'count', 'sent back to the CMO'),
            ];
        }

        foreach (ReviewDesk::cases() as $desk) {
            if ($user->can($desk->permission()->value)) {
                $recorded = CaseTrackEvent::query()
                    ->where('actor_id', $user->getKey())
                    ->where('track', $desk->value)
                    ->where('created_at', '>=', $month)
                    ->count();

                return [
                    self::kpi('assigned', 'Assigned to you', self::activeAssignments($user, $desk->value, S::IN_REVIEW), 'count', 'cases in review'),
                    self::kpi('recorded', 'Recorded this month', $recorded, 'count', strtolower($desk->label()).' outcomes'),
                ];
            }
        }

        return [];
    }

    /** @return array{key: string, label: string, value: int|null, format: string, hint: string} */
    private static function kpi(string $key, string $label, ?int $value, string $format, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format, 'hint' => $hint];
    }

    /** Visible cases in one status. */
    private static function countStatus(S $status): int
    {
        return ProcurementCase::query()->where('cases.status', $status->value)->count();
    }

    /** The user's active assignments for one purpose on visible cases in one status. */
    private static function activeAssignments(User $user, string $purpose, S $status): int
    {
        return CaseAssignment::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->whereIn('case_id', ProcurementCase::query()->where('cases.status', $status->value)->select('cases.id'))
            ->count();
    }

    /** Transitions the user made (as actor) between two statuses since a moment. */
    private static function transitionsBy(User $user, S $from, S $to, CarbonImmutable $since): int
    {
        return CaseTransition::query()
            ->where('actor_id', $user->getKey())
            ->where('from_status', $from->value)
            ->where('to_status', $to->value)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Counts per stage for the cases this user can see. "all" when the user
     * sees every case, "assigned" when visibility is by assignment.
     *
     * @return array{scope: string, items: list<array{key: string, label: string, tone: string, count: int, mine: bool}>}
     */
    private static function staffStages(User $user): array
    {
        $counts = self::statusCounts();
        $desk = array_map(fn (S $s) => $s->value, WorkQueue::deskStatuses($user));

        return [
            'scope' => $user->can(Permission::CaseViewAll->value) ? 'all' : 'assigned',
            'items' => array_map(fn (S $s) => [
                'key' => $s->value,
                'label' => $s->label(),
                'tone' => $s->tone(),
                'count' => $counts[$s->value] ?? 0,
                'mine' => in_array($s->value, $desk, true),
            ], self::ROUTE),
        ];
    }

    /** @return array<string, int> status value => visible case count */
    private static function statusCounts(): array
    {
        return ProcurementCase::query()->toBase()
            ->selectRaw('cases.status AS status_value, COUNT(*) AS status_count')
            ->groupBy('cases.status')
            ->pluck('status_count', 'status_value')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * In-review cases with their three gate ticks, oldest submission first.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private static function gate(): array
    {
        $query = ProcurementCase::query()->where('cases.status', S::IN_REVIEW->value);
        $owner = ['technical' => 'Technical', 'funding' => 'CAB', 'hardcopy' => 'Registry'];

        return [
            'total' => (clone $query)->count(),
            'items' => $query->orderBy('cases.submitted_at')->limit(self::LIST_LIMIT)->get()
                ->map(fn (ProcurementCase $case) => [
                    'case_id' => $case->id,
                    'reference_no' => $case->reference_no,
                    'technical' => $case->technical_track === TrackStatus::FAVOURABLE,
                    'funding' => $case->funding_track === TrackStatus::CONFIRMED,
                    'hardcopy' => $case->hardcopyReceived(),
                    'waiting_on' => array_values(array_map(fn (string $m) => $owner[$m] ?? $m, TransitionRules::gateMissing($case))),
                ])->values()->all(),
        ];
    }

    /**
     * Latest status changes on visible cases. Labels and names only: no
     * reasons or other typed text.
     *
     * @return list<array<string, mixed>>
     */
    private static function activity(): array
    {
        return CaseTransition::query()
            ->with('actor:id,name')
            ->join('cases', 'cases.id', '=', 'case_transitions.case_id')
            ->whereIn('case_transitions.case_id', ProcurementCase::query()->select('cases.id'))
            ->orderByDesc('case_transitions.created_at')
            ->orderByDesc('case_transitions.id')
            ->limit(self::LIST_LIMIT)
            ->get(['case_transitions.*', 'cases.reference_no as case_reference_no'])
            ->map(fn (CaseTransition $t) => [
                'id' => $t->id,
                'case_id' => $t->case_id,
                'reference_no' => $t->getAttribute('case_reference_no'),
                'from_label' => $t->from_status?->label(),
                'to_label' => $t->to_status->label(),
                'to_tone' => $t->to_status->tone(),
                'actor' => $t->actor?->name,
                'at' => $t->created_at?->copy()->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }

    /**
     * The vendor's own drafts and submitted requests (organisation scope), with
     * the request groups as tiles and a stage rail by vendor group.
     *
     * @return array<string, mixed>
     */
    private static function vendor(User $user): array
    {
        $groups = RequestGroups::counts(ProcurementCase::query());
        $list = fn () => ProcurementCase::query()->with(['procurementType', 'requisition']);

        $byGroup = [];
        foreach (self::statusCounts() as $status => $count) {
            // Closed shows to the vendor as "Non-objection granted", the same as letter issued.
            $group = S::from($status)->vendorGroup();
            $group = $group === 'CLOSED' ? 'LETTER_ISSUED' : $group;
            $byGroup[$group] = ($byGroup[$group] ?? 0) + $count;
        }
        $sample = [
            'DRAFT' => S::DRAFT, 'SUBMITTED' => S::SUBMITTED, 'UNDER_REVIEW' => S::IN_REVIEW,
            'LETTER_ISSUED' => S::LETTER_ISSUED, 'NOT_APPROVED' => S::REJECTED_FINAL, 'WITHDRAWN' => S::WITHDRAWN,
        ];

        return [
            'kpis' => array_map(
                fn (string $key) => self::kpi($key, RequestGroups::LABELS[$key], $groups[$key], 'count', match ($key) {
                    RequestGroups::DRAFTS => 'not yet submitted',
                    RequestGroups::IN_PROGRESS => 'with the Ministry',
                    RequestGroups::HARDCOPY_DUE => 'submitted online, hardcopy not yet received',
                    default => 'non-objection granted',
                }),
                array_keys(RequestGroups::LABELS),
            ),
            'stages' => [
                'scope' => 'own',
                'items' => array_values(array_filter(array_map(fn (string $group) => [
                    'key' => $group,
                    'label' => $sample[$group]->vendorLabel(),
                    'tone' => $sample[$group]->vendorTone(),
                    'count' => $byGroup[$group] ?? 0,
                    'mine' => false,
                ], self::VENDOR_ROUTE), fn (array $row) => $row['count'] > 0 || in_array($row['key'], ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'LETTER_ISSUED'], true))),
            ],
            'drafts' => [
                'total' => $groups[RequestGroups::DRAFTS],
                'items' => $list()->where('cases.status', S::DRAFT->value)->orderByDesc('cases.updated_at')->limit(self::LIST_LIMIT)->get()
                    ->map(fn (ProcurementCase $c) => RequestSummaryResource::fields($c, $user))->values()->all(),
            ],
            'requests' => [
                'total' => ProcurementCase::query()->where('cases.status', '<>', S::DRAFT->value)->count(),
                'items' => $list()->where('cases.status', '<>', S::DRAFT->value)->orderByDesc('cases.updated_at')->limit(self::LIST_LIMIT)->get()
                    ->map(fn (ProcurementCase $c) => RequestSummaryResource::fields($c, $user))->values()->all(),
            ],
        ];
    }
}
