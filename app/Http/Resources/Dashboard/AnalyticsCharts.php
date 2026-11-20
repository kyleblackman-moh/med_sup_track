<?php

namespace App\Http\Resources\Dashboard;

use App\Enums\CaseStatus;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\Scopes\VisibleToUserScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * ANALYTICS: the Analytics page's two charts. Read-only.
 *
 * - weekly: the last WEEKS weeks (Monday to Sunday, Port of Spain), oldest
 *   first: cases received (submitted_at) and non-objections granted.
 * - outcomes: the last MONTHS calendar months (Port of Spain), oldest first:
 *   granted, rejected (final) and withdrawn.
 *
 * Everything is read through VisibleToUserScope for $user: received counts
 * the visible cases, and the outcomes are case_transitions rows of visible
 * cases only. Only the timestamps in range are fetched; the bucketing is
 * done here in PHP (no database-specific date functions). Stored values are
 * UTC; weeks and months are local.
 */
final class AnalyticsCharts
{
    public const WEEKS = 12;

    public const MONTHS = 6;

    /** The outcome series, by the status a transition moves the case to. */
    private const OUTCOMES = [
        'granted' => CaseStatus::NON_OBJECTION_GRANTED,
        'rejected' => CaseStatus::REJECTED_FINAL,
        'withdrawn' => CaseStatus::WITHDRAWN,
    ];

    /**
     * @return array{
     *     weekly: array{labels: list<string>, received: list<int>, granted: list<int>},
     *     outcomes: array{labels: list<string>, granted: list<int>, rejected: list<int>, withdrawn: list<int>},
     * }
     */
    public static function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone(StaffCaseSummary::TIMEZONE);

        // Local bucket starts, oldest first.
        $firstWeek = $now->startOfWeek(CarbonInterface::MONDAY)->subWeeks(self::WEEKS - 1);
        $firstMonth = $now->startOfMonth()->subMonths(self::MONTHS - 1);

        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $firstWeek->addWeeks($i);
            $weeks[$start->format('Y-m-d')] = $start->format('j M');
        }
        $months = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $start = $firstMonth->addMonths($i);
            $months[$start->format('Y-m')] = $start->format('M Y');
        }

        $weekKey = fn (CarbonImmutable $local) => $local->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');
        $monthKey = fn (CarbonImmutable $local) => $local->format('Y-m');

        // Received: visible, non-draft cases submitted since the first week.
        $received = array_fill_keys(array_keys($weeks), 0);
        foreach (self::receivedSince($user, $firstWeek->utc()) as $at) {
            $key = $weekKey(self::local($at));
            if (isset($received[$key])) {
                $received[$key]++;
            }
        }

        // Outcomes: transitions of visible cases since the earlier of the two ranges.
        $weeklyGranted = array_fill_keys(array_keys($weeks), 0);
        $outcomes = array_map(fn () => array_fill_keys(array_keys($months), 0), self::OUTCOMES);
        $since = $firstWeek->lessThan($firstMonth) ? $firstWeek : $firstMonth;

        foreach (self::outcomesSince($user, $since->utc()) as $row) {
            $status = $row->to_status instanceof CaseStatus ? $row->to_status : CaseStatus::tryFrom((string) $row->to_status);
            $series = array_search($status, self::OUTCOMES, true);
            if ($series === false || $row->created_at === null) {
                continue;
            }

            $local = self::local($row->created_at);
            if (isset($outcomes[$series][$monthKey($local)])) {
                $outcomes[$series][$monthKey($local)]++;
            }
            if ($series === 'granted' && isset($weeklyGranted[$weekKey($local)])) {
                $weeklyGranted[$weekKey($local)]++;
            }
        }

        return [
            'weekly' => [
                'labels' => array_values($weeks),
                'received' => array_values($received),
                'granted' => array_values($weeklyGranted),
            ],
            'outcomes' => [
                'labels' => array_values($months),
                'granted' => array_values($outcomes['granted']),
                'rejected' => array_values($outcomes['rejected']),
                'withdrawn' => array_values($outcomes['withdrawn']),
            ],
        ];
    }

    /** @return list<mixed> the submitted_at values (UTC) of the visible cases received since $from */
    private static function receivedSince(User $user, CarbonImmutable $from): array
    {
        $query = ProcurementCase::withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($query, $user);

        return $query->toBase()
            ->where('cases.status', '<>', CaseStatus::DRAFT->value)
            ->whereNotNull('cases.submitted_at')
            ->where('cases.submitted_at', '>=', $from)
            ->pluck('cases.submitted_at')
            ->all();
    }

    /** @return list<CaseTransition> the outcome transitions (status and time only) of visible cases since $from */
    private static function outcomesSince(User $user, CarbonImmutable $from): array
    {
        $visible = ProcurementCase::withoutGlobalScopes();
        (new VisibleToUserScope)->applyFor($visible, $user);

        return CaseTransition::query()
            ->whereIn('to_status', array_map(fn (CaseStatus $s) => $s->value, array_values(self::OUTCOMES)))
            ->where('created_at', '>=', $from)
            ->whereIn('case_id', $visible->select('cases.id'))
            ->get(['to_status', 'created_at'])
            ->all();
    }

    /** A stored (UTC) timestamp in Port of Spain time. */
    private static function local(mixed $value): CarbonImmutable
    {
        $at = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');

        return $at->setTimezone(StaffCaseSummary::TIMEZONE);
    }
}
