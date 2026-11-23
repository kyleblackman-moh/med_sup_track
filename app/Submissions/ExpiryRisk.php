<?php

namespace App\Submissions;

use App\Enums\CaseStatus;
use App\Models\CaseStockExpiry;
use App\Models\ProcurementCase;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Slice V2-1: days on hand and the expiry warning for the request form's stock
 * batches. Computed on the server, never stored. Advisory only: readiness
 * reports it as warnings and never blocks a submit on it.
 *
 * - Days on hand = stock total / (AMC / 30), in whole days (rounded down).
 *   Null when AMC is missing or 0, "No stock on hand" is ticked, or there is
 *   no stock.
 * - Expiry rule (Kyle, 2026-11-23; replaces the per-batch rule): the request
 *   is "at_risk" when days on hand (the whole stock total / (AMC / 30), as
 *   shown) > the days until the earliest expiry date that has not passed
 *   (strictly). The warning sits on that earliest batch, with cover days =
 *   days on hand. A batch already past its expiry date is "expired" (its own
 *   warning); like every batch, it still counts in the stock total. No
 *   warning at all when AMC is missing or 0, or "No stock on hand" is ticked.
 * - The rule is exact (compared as total x 30 against days x AMC); cover
 *   days are reported to 2 decimal places.
 * - Reference date: today (Trinidad time) while the case is a DRAFT; the
 *   submission date afterwards.
 *
 * A batch with no expiry date is left out of the expiry rule (it still counts
 * in the stock total); a batch with no quantity is left out of both.
 */
final class ExpiryRisk
{
    private const LOCAL_TIMEZONE = 'America/Port_of_Spain';

    public const AT_RISK = 'at_risk';

    public const EXPIRED = 'expired';

    /**
     * @return array{days_on_hand: int|null, expiry_warnings: array<int, array{state: string, days_until_expiry: int, cover_days: string}>}
     */
    public static function for(ProcurementCase $case): array
    {
        $requisition = $case->requisition;

        return self::compute(
            $requisition?->average_monthly_consumption,
            (bool) $requisition?->no_stock_on_hand,
            $case->stockExpiries,
            self::referenceDate($case),
        );
    }

    /** Columns the flag needs when a list eager loads the requisition and the batches. */
    public const REQUISITION_COLUMNS = ['average_monthly_consumption', 'no_stock_on_hand'];

    public const BATCH_COLUMNS = ['id', 'case_id', 'quantity', 'expiry_date'];

    /**
     * Kyle, 2026-11-23: the flag staff see on the case page, the Cases list
     * and the work queue (CMO, Technocrats, PS), so a request whose stock may
     * expire is noticed without opening its Stock section. Advisory only;
     * never blocks. Null when there is nothing to flag. Expects requisition
     * and stockExpiries loaded (lists eager load them).
     *
     * @return array{label: string, tone: string, detail: string}|null
     */
    public static function flag(ProcurementCase $case): ?array
    {
        $risk = self::for($case);
        $warnings = collect($risk['expiry_warnings']);
        $atRisk = $warnings->firstWhere('state', self::AT_RISK);
        $expired = $warnings->where('state', self::EXPIRED)->count();

        if ($atRisk === null && $expired === 0) {
            return null;
        }

        $parts = [];
        if ($atRisk !== null) {
            $parts[] = 'About '.$risk['days_on_hand'].' days of stock on hand; the earliest batch expires in '.$atRisk['days_until_expiry'].' days.';
        }
        if ($expired > 0) {
            $parts[] = $expired === 1 ? '1 batch is past its expiry date.' : "{$expired} batches are past their expiry date.";
        }

        return [
            'label' => $atRisk !== null ? 'Stock may expire before use' : 'Expired stock reported',
            'tone' => 'warning',
            'detail' => implode(' ', $parts),
        ];
    }

    /**
     * The warnings as a list for the page (the same JSON shape when empty).
     *
     * @param  array<int, array{state: string, days_until_expiry: int, cover_days: string}>  $warnings
     * @return list<array{batch_id: int, state: string, days_until_expiry: int, cover_days: string}>
     */
    public static function asList(array $warnings): array
    {
        $list = [];
        foreach ($warnings as $batchId => $warning) {
            $list[] = ['batch_id' => $batchId, ...$warning];
        }

        return $list;
    }

    /** Today while a DRAFT, else the submission date (both in Trinidad time). */
    public static function referenceDate(ProcurementCase $case): CarbonImmutable
    {
        if ($case->status !== CaseStatus::DRAFT && $case->submitted_at !== null) {
            return CarbonImmutable::parse($case->submitted_at)->setTimezone(self::LOCAL_TIMEZONE)->startOfDay();
        }

        return CarbonImmutable::now(self::LOCAL_TIMEZONE)->startOfDay();
    }

    /**
     * @param  Collection<int, CaseStockExpiry>  $batches
     * @return array{days_on_hand: int|null, expiry_warnings: array<int, array{state: string, days_until_expiry: int, cover_days: string}>}
     */
    public static function compute(?string $amc, bool $noStockOnHand, Collection $batches, CarbonInterface $today): array
    {
        $none = ['days_on_hand' => null, 'expiry_warnings' => []];

        if ($noStockOnHand || $amc === null || Decimal::compare($amc, '0') <= 0) {
            return $none;
        }

        $usable = $batches
            ->filter(fn (CaseStockExpiry $b) => $b->quantity !== null && $b->expiry_date !== null)
            ->sortBy([fn ($a, $b) => $a->expiry_date <=> $b->expiry_date, fn ($a, $b) => $a->getKey() <=> $b->getKey()])
            ->values();

        // The stock total is every batch's quantity (as ProcurementCase::batchStockOnHand).
        $total = $batches->filter(fn (CaseStockExpiry $b) => $b->quantity !== null)
            ->reduce(fn (string $sum, CaseStockExpiry $b) => Decimal::add($sum, $b->quantity), '0');
        $daysOnHand = Decimal::compare($total, '0') > 0
            ? (int) bcdiv(Decimal::mul($total, '30'), $amc, 0) // whole days, rounded down
            : null;

        $warnings = [];
        $earliest = null;
        $day = CarbonImmutable::parse($today->toDateString());

        foreach ($usable as $batch) {
            $daysUntil = (int) $day->diffInDays(CarbonImmutable::parse($batch->expiry_date->toDateString()), false);

            if ($daysUntil < 0) {
                $warnings[(int) $batch->getKey()] = [
                    'state' => self::EXPIRED,
                    'days_until_expiry' => $daysUntil,
                    'cover_days' => Decimal::round2(Decimal::div(Decimal::mul($batch->quantity, '30'), $amc)),
                ];

                continue;
            }

            $earliest ??= [$batch, $daysUntil];
        }

        // Kyle, 2026-11-23: days on hand (the whole stock total) > days until the earliest
        // expiry that has not passed  <=>  total x 30 > days x AMC (exact).
        if ($earliest !== null && Decimal::compare($total, '0') > 0) {
            [$batch, $daysUntil] = $earliest;

            if (Decimal::compare(Decimal::mul($total, '30'), Decimal::mul((string) $daysUntil, $amc)) > 0) {
                $warnings[(int) $batch->getKey()] = [
                    'state' => self::AT_RISK,
                    'days_until_expiry' => $daysUntil,
                    'cover_days' => Decimal::round2(Decimal::div(Decimal::mul($total, '30'), $amc)),
                ];
            }
        }

        return ['days_on_hand' => $daysOnHand, 'expiry_warnings' => $warnings];
    }
}
