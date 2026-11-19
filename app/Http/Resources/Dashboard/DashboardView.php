<?php

namespace App\Http\Resources\Dashboard;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\ProcurementCase;
use App\Models\Scopes\VisibleToUserScope;
use App\Models\User;
use App\Workflow\Actions\RecordHardcopy;
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
 */
final class DashboardView
{
    /** A tile links to the case list only where a matching filter already exists. */
    private const READY_FOR_FILE_FILTER = ['status' => 'READY_FOR_FILE'];

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

    /** @return array{key: string, label: string, value: string, description: string, href: string|null} */
    private static function tile(string $key, string $label, int $value, ?string $href = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => number_format($value), 'description' => self::TILE_DESCRIPTIONS[$key], 'href' => $href];
    }
}
