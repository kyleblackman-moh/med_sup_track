<?php

namespace App\Http\Resources\Dashboard;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\Scopes\VisibleToUserScope;
use App\Models\User;
use App\Submissions\ProcurementTypeProfile;
use App\Support\SqlLike;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\NextStep;
use App\Workflow\StageWording;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The dashboard's display data beside the work queue (design.md section 6:
 * one page for every staff role). Read-only. Everything here is formatted on
 * the server; the page shows it as is.
 *
 * - header(): the page title (greeting) and one subtitle line, in local time.
 * - stats(): up to four stat tiles from ONE grouped COUNT over the cases the
 *   viewer can see (VisibleToUserScope, applied for $user explicitly). No
 *   targets, trends or SLA values.
 * - requestTypes(): case id => request type name for the queue's rows, so the
 *   queue (WorkQueue) itself is unchanged.
 * - queue() (DASH-1): the queue card: the filter chips with their counts, the
 *   search, and the rows after both. WorkQueue is not changed: the chips are
 *   WorkQueue steps and their counts are its own per-step counts.
 */
final class DashboardView
{
    /** A tile links to the case list only where a matching filter already exists. */
    private const READY_FOR_FILE_FILTER = ['status' => 'READY_FOR_FILE'];

    /**
     * DASH-1: the queue card's filters, each one WorkQueue step (same key and
     * label). A chip shows only when the user holds that step. "All" is every
     * item. Other WorkQueue steps are reached through "All".
     */
    public const FILTERS = [
        'intake' => 'New Submissions',
        'hardcopy' => 'Hardcopy Pending',
        'returned' => 'Returned to CMO',
        'letter' => 'Letters to Draft',
        'queries' => 'Queries',
    ];

    /**
     * FIX-2 (2): a filter that covers more than one WorkQueue step. Any other
     * filter is the one step with its own key. "Queries" is the Forward Query
     * and Answer Query rows; it shows when the user has either group (the CMO's
     * desk always has Query to Forward), and its count is the sum of theirs.
     */
    public const FILTER_STEPS = [
        'queries' => ['query_forward', 'query_answer'],
    ];

    /** @return list<string> the WorkQueue steps a filter shows */
    public static function filterSteps(string $filter): array
    {
        return self::FILTER_STEPS[$filter] ?? [$filter];
    }

    /** DASH-1: the queue card's empty states (server wording). */
    public const EMPTY_QUEUE = 'No cases need your action.';

    public const EMPTY_MATCH = 'No cases match your search or filter.';

    /** DASH-1: the longest queue search; longer input is cut (as the vendor Requests search). */
    public const SEARCH_MAX = 100;

    /** DASH-1: one short sentence-case description per tile (shown in the tile's footer strip). No targets or trends. */
    public const TILE_DESCRIPTIONS = [
        'needs_action' => 'Cases waiting for you to act',
        'open' => 'Cases that are not yet closed',
        'awaiting_hardcopy' => 'Submitted, signed copy not yet received',
        'ready_for_file' => 'All three conditions are met',
    ];

    /**
     * @param  array{items: list<array<string, mixed>>}|null  $queue
     * @return array{title: string, subtitle: string}
     */
    public static function header(User $user, ?array $queue, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(StaffCaseSummary::TIMEZONE);
        $local = $now->setTimezone(StaffCaseSummary::TIMEZONE);
        $greeting = match (true) {
            $local->hour < 12 => 'Good morning',
            $local->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
        $firstName = Str::of($user->name)->trim()->explode(' ')->first() ?: $user->name;

        $subtitle = $local->format('l j F Y');
        if ($queue !== null && $queue['items'] !== []) {
            $n = count($queue['items']);
            $subtitle .= ' · '.number_format($n).' '.($n === 1 ? 'item' : 'items').' waiting on you';
        }

        return ['title' => "{$greeting}, {$firstName}", 'subtitle' => $subtitle];
    }

    /**
     * The tiles for $user, or null when there are none (vendors; staff with
     * neither a queue nor the staff case list).
     *
     * - needs_action: the queue's own count (no query of its own).
     * - open: visible cases that are not closed, withdrawn or rejected.
     * - awaiting_hardcopy and ready_for_file: case.view_all holders only.
     *
     * @param  array{items: list<array<string, mixed>>}|null  $queue
     * @return list<array{key: string, label: string, value: string, description: string, href: string|null}>|null
     */
    public static function stats(User $user, ?array $queue): ?array
    {
        if ($user->isVendor()) {
            return null;
        }

        $tiles = [];
        if ($queue !== null) {
            $tiles[] = self::tile('needs_action', 'Needs My Action', count($queue['items']));
        }

        if (Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)) {
            $counts = self::counts($user);
            $tiles[] = self::tile('open', 'Open Cases', $counts['open']);

            if ($user->can(Permission::CaseViewAll->value)) {
                $tiles[] = self::tile('awaiting_hardcopy', 'Awaiting Hardcopy', $counts['awaiting_hardcopy']);
                $tiles[] = self::tile('ready_for_file', 'Ready for File', $counts['ready_for_file'],
                    route('cases.index', self::READY_FOR_FILE_FILTER, false));
            }
        }

        return $tiles === [] ? null : $tiles;
    }

    /**
     * One grouped COUNT over the cases $user can see (drafts are never visible
     * to staff): per status, the total and how many have no hardcopy recorded.
     *
     * @return array{open: int, awaiting_hardcopy: int, ready_for_file: int}
     */
    public static function counts(User $user): array
    {
        $query = ProcurementCase::withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($query, $user);

        $rows = $query->toBase()
            ->selectRaw('cases.status AS status, COUNT(*) AS total, SUM(CASE WHEN cases.hardcopy_received_on IS NULL THEN 1 ELSE 0 END) AS no_hardcopy')
            ->groupBy('cases.status')
            ->get();

        $hardcopyStatuses = array_map(fn (CaseStatus $s) => $s->value, RecordHardcopy::STATUSES);
        $out = ['open' => 0, 'awaiting_hardcopy' => 0, 'ready_for_file' => 0];

        foreach ($rows as $row) {
            $status = CaseStatus::tryFrom((string) $row->status);
            if ($status === null || $status === CaseStatus::DRAFT) {
                continue;
            }
            if (! $status->isTerminal()) {
                $out['open'] += (int) $row->total;
            }
            if (in_array($status->value, $hardcopyStatuses, true)) {
                $out['awaiting_hardcopy'] += (int) $row->no_hardcopy;
            }
            if ($status === CaseStatus::READY_FOR_FILE) {
                $out['ready_for_file'] += (int) $row->total;
            }
        }

        return $out;
    }

    /**
     * Request type name per queue case, read through the visibility scope.
     *
     * @param  array{items: list<array<string, mixed>>}|null  $queue
     * @return array<string, string>
     */
    public static function requestTypes(?array $queue): array
    {
        $ids = collect($queue['items'] ?? [])->pluck('case_id')->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return ProcurementCase::query()
            ->with('procurementType:id,name')
            ->whereIn('cases.id', $ids)
            ->get(['cases.id', 'cases.procurement_type_id'])
            ->mapWithKeys(fn (ProcurementCase $case) => [$case->id => (string) $case->procurementType?->name])
            ->filter()
            ->all();
    }

    /**
     * DASH-1: the queue card for $user, or null without a queue.
     *
     * - filters: "All" plus each FILTERS step the user holds, with WorkQueue's
     *   own count (never changed by the search), a link carrying the current
     *   search, and whether it is applied. An unknown filter, or one the user
     *   does not hold, is "all".
     * - items: the queue's rows after the filter and then the search. The
     *   search matches the reference, the item description and the vendor
     *   organisation's name of the cases ALREADY in the user's queue (LIKE,
     *   wildcards literal), so it can never reach another user's cases.
     *
     * @param  array{groups: list<array{key: string, label: string, count: int}>, items: list<array<string, mixed>>, truncated: bool}|null  $queue
     */
    public static function queue(User $user, ?array $queue, string $filter, string $search): ?array
    {
        if ($queue === null) {
            return null;
        }

        $groupCounts = array_column($queue['groups'], 'count', 'key');
        // A filter's count: its steps' WorkQueue counts, for the filters whose steps the user holds.
        $counts = [];
        foreach (array_keys(self::FILTERS) as $key) {
            $held = array_intersect_key($groupCounts, array_flip(self::filterSteps($key)));
            if ($held !== []) {
                $counts[$key] = array_sum($held);
            }
        }
        $filter = array_key_exists($filter, $counts) ? $filter : 'all';

        $steps = self::filterSteps($filter);
        $items = $filter === 'all'
            ? $queue['items']
            : array_values(array_filter($queue['items'], fn (array $item) => in_array($item['step'], $steps, true)));

        if ($search !== '' && $items !== []) {
            $matching = array_flip(self::matchingCaseIds($user, array_values(array_unique(array_column($items, 'case_id'))), $search));
            $items = array_values(array_filter($items, fn (array $item) => isset($matching[$item['case_id']])));
        }

        $href = fn (string $key) => route('dashboard', array_filter([
            'filter' => $key === 'all' ? null : $key,
            'q' => $search === '' ? null : $search,
        ]), false);

        $filters = [['key' => 'all', 'label' => 'All', 'count' => count($queue['items']), 'href' => $href('all'), 'active' => $filter === 'all']];
        foreach (self::FILTERS as $key => $label) {
            if (array_key_exists($key, $counts)) {
                $filters[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key], 'href' => $href($key), 'active' => $filter === $key];
            }
        }

        $total = count($queue['items']);

        return [
            'filter' => $filter,
            'search' => $search,
            'filters' => $filters,
            'items' => self::rows($user, $items),
            'total' => $total,
            // Which empty state, if any: nothing in the queue, or nothing matching the filter and search.
            'empty' => match (true) {
                $total === 0 => ['message' => self::EMPTY_QUEUE, 'clear' => false],
                $items === [] => ['message' => self::EMPTY_MATCH, 'clear' => true],
                default => null,
            },
            'clear_href' => route('dashboard', [], false),
            'truncated' => $queue['truncated'],
        ];
    }

    /**
     * DASH-1: the queue rows as the table shows them. Each WorkQueue item keeps
     * its fields and gains, from ONE read of its cases (through the visibility
     * scope) and one read of their latest return:
     *
     * - href: the case page (the row link and the Action button);
     * - type_label: the request type name;
     * - waiting: the elapsed days as plain text ("Today", "1 day", "12 days"),
     *   never coloured, no target;
     * - tags: factual text tags, each with an icon: "Returned" (naming Legal or
     *   the PS office when the return says so), "Hardcopy Pending" (hardcopy not
     *   yet received in a status that needs it) and "Emergency" (the request
     *   type, not a priority).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function rows(User $user, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_values(array_unique(array_column($items, 'case_id')));
        $query = ProcurementCase::withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($query, $user);
        $cases = $query->with('procurementType:id,code,name')
            ->whereIn('cases.id', $ids)
            ->get(['cases.id', 'cases.status', 'cases.procurement_type_id', 'cases.hardcopy_received_on'])
            ->keyBy(fn (ProcurementCase $case) => (string) $case->id);

        $returnedIds = $cases->filter(fn (ProcurementCase $case) => $case->status === CaseStatus::RETURNED_TO_CMO)->keys()->all();
        $returnedFrom = $returnedIds === [] ? collect() : CaseTransition::query()
            ->whereIn('case_id', $returnedIds)
            ->where('to_status', CaseStatus::RETURNED_TO_CMO->value)
            ->orderBy('id')
            ->get(['case_id', 'from_status'])
            ->keyBy(fn (CaseTransition $t) => (string) $t->case_id); // the latest return wins

        return array_map(function (array $item) use ($cases, $returnedFrom) {
            $case = $cases->get($item['case_id']);

            return [
                ...$item,
                'href' => route('cases.show', $item['case_id'], false),
                'type_label' => $case?->procurementType?->name,
                'waiting' => self::waiting($item['days_waiting']),
                'tags' => $case === null ? [] : self::tags($case, $returnedFrom->get($item['case_id'])),
            ];
        }, $items);
    }

    /** Elapsed whole days as plain text (no target, no colour). */
    public static function waiting(?int $days): string
    {
        return match (true) {
            $days === null => '—',
            $days === 0 => 'Today',
            $days === 1 => '1 day',
            default => number_format($days).' days',
        };
    }

    /** @return list<array{key: string, label: string, tone: string, icon: string}> */
    private static function tags(ProcurementCase $case, ?CaseTransition $return): array
    {
        $tags = [];

        if ($case->status === CaseStatus::RETURNED_TO_CMO) {
            $from = $return?->from_status instanceof CaseStatus ? $return->from_status->returnedBySource() : null;
            $tags[] = ['key' => 'returned', 'label' => $from === null ? 'Returned' : 'Returned by '.ucwords($from), 'tone' => 'warning', 'icon' => 'undo'];
        }

        if (in_array($case->status, RecordHardcopy::STATUSES, true) && ! $case->hardcopyReceived()) {
            $tags[] = ['key' => 'hardcopy', 'label' => 'Hardcopy Pending', 'tone' => 'neutral', 'icon' => 'file-clock'];
        }

        if ($case->procurementType?->code === ProcurementTypeProfile::AD_HOC_EMERGENCY) {
            $tags[] = ['key' => 'emergency', 'label' => 'Emergency', 'tone' => 'neutral', 'icon' => 'siren'];
        }

        return $tags;
    }

    /**
     * Ids among $caseIds (the user's queue) whose reference, item description or
     * vendor organisation name contains $search. Read through the visibility
     * scope for $user as well.
     *
     * @param  list<string>  $caseIds
     * @return list<string>
     */
    public static function matchingCaseIds(User $user, array $caseIds, string $search): array
    {
        if ($caseIds === [] || $search === '') {
            return [];
        }

        $query = ProcurementCase::withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($query, $user);

        return $query->whereIn('cases.id', $caseIds)
            ->where(fn ($any) => $any->whereRaw(...SqlLike::contains('cases.reference_no', $search))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('case_requisitions')
                    ->whereColumn('case_requisitions.case_id', 'cases.id')
                    ->whereRaw(...SqlLike::contains('case_requisitions.item_description', $search)))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('organisations')
                    ->whereColumn('organisations.id', 'cases.org_id')
                    ->whereRaw(...SqlLike::contains('organisations.name', $search))))
            ->pluck('cases.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** OVERNIGHT-2 (C2): the Gate Tracker's short item labels, keyed by the Next Step checklist key. */
    public const GATE_LABELS = ['technical' => 'Technical', 'funding' => 'Funding', 'hardcopy' => 'Hardcopy'];

    /** OVERNIGHT-2 (C2): at most this many Gate Tracker rows; the rest are one link away. */
    public const GATE_TRACKER_MAX = 50;

    /**
     * OVERNIGHT-2 (C1): a plain count per stage, in state-machine order, over
     * the cases $user can see (VisibleToUserScope: the CMO and PS all cases,
     * others their own). Each stage links to the case list filtered by that
     * status. Null for users without the staff case list. No time colouring.
     *
     * @return list<array{status: string, label: string, count: int, href: string}>|null
     */
    public static function pipeline(User $user): ?array
    {
        if ($user->isVendor() || ! Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)) {
            return null;
        }

        $query = ProcurementCase::withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($query, $user);

        $counts = $query->toBase()
            ->selectRaw('cases.status AS status, COUNT(*) AS total')
            ->groupBy('cases.status')
            ->pluck('total', 'status');

        $stages = [];
        foreach (CaseStatus::cases() as $status) {
            if ($status === CaseStatus::DRAFT) {
                continue;
            }

            $stages[] = [
                'status' => $status->value,
                'label' => StageWording::STAGES[$status->value]['stage'],
                'count' => (int) ($counts[$status->value] ?? 0),
                'href' => route('cases.index', ['status' => $status->value], false),
            ];
        }

        return $stages;
    }

    /**
     * OVERNIGHT-2 (C2): In Review cases, one row each with the three gate
     * items (Technical, Funding, Hardcopy) taken from the Next Step checklist
     * (NextStep::checklistFor, which reads TransitionRules::gateMissing), and
     * who holds each open item. case.view_all holders only; null otherwise.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, more_href: string|null}|null
     */
    public static function gateTracker(User $user): ?array
    {
        if ($user->isVendor() || ! $user->can(Permission::CaseViewAll->value)
            || ! Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)) {
            return null;
        }

        $query = ProcurementCase::withoutGlobalScopes()->with('organisation:id,name');
        (new VisibleToUserScope)->applyFor($query, $user);
        $query->where('cases.status', CaseStatus::IN_REVIEW->value);

        $total = (clone $query)->count();
        $cases = $query->orderBy('cases.submitted_at')->orderBy('cases.id')->limit(self::GATE_TRACKER_MAX)->get();

        $rows = $cases->map(function (ProcurementCase $case) use ($user) {
            $items = collect(NextStep::checklistFor($case, $user))
                ->keyBy('key')
                ->only(array_keys(self::GATE_LABELS));

            return [
                'id' => (string) $case->getKey(),
                'reference' => (string) ($case->reference_no ?? ''),
                'vendor' => (string) ($case->organisation?->name ?? ''),
                'href' => route('cases.show', $case, false),
                'items' => array_values(array_map(function (string $key) use ($items) {
                    $item = $items->get($key);
                    $done = (bool) ($item['done'] ?? false);

                    return [
                        'key' => $key,
                        'label' => self::GATE_LABELS[$key],
                        'done' => $done,
                        'state' => (string) ($item['state'] ?? ''),
                        // Who holds it, only while it is open.
                        'held_by' => $done ? null : trim(($item['holder'] ?? '').(($item['people'] ?? []) !== [] ? ': '.implode(', ', $item['people']) : '')),
                    ];
                }, array_keys(self::GATE_LABELS))),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'more_href' => $total > count($rows) ? route('cases.index', ['status' => CaseStatus::IN_REVIEW->value], false) : null,
        ];
    }

    /** @return array{key: string, label: string, value: string, description: string, href: string|null} */
    private static function tile(string $key, string $label, int $value, ?string $href = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => number_format($value), 'description' => self::TILE_DESCRIPTIONS[$key], 'href' => $href];
    }
}
