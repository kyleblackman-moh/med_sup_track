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
 * - Expiry rule, per batch, earliest expiry first: cover days = the quantity
 *   of this batch and every earlier usable batch / (AMC / 30). The batch is
 *   "at_risk" when cover days > days until it expires (strictly). A batch
 *   already past its expiry date is "expired"; it is not counted in the
 *   cover of later batches (expired stock is not used first). No warning at
 *   all when AMC is missing or 0, or "No stock on hand" is ticked.
 * - The rule is exact (compared as quantity x 30 against days x AMC); cover
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
        $cumulative = '0';
        $day = CarbonImmutable::parse($today->toDateString());

        foreach ($usable as $batch) {
            $daysUntil = (int) $day->diffInDays(CarbonImmutable::parse($batch->expiry_date->toDateString()), false);

            if ($daysUntil < 0) {
                $warnings[(int) $batch->getKey()] = [
                    'state' => self::EXPIRED,
                    'days_until_expiry' => $daysUntil,
                    'cover_days' => Decimal::round2(Decimal::div(Decimal::mul($batch->quantity, '30'), $amc)),
                ];

                continue; // not counted in the cover of later batches
            }

            $cumulative = Decimal::add($cumulative, $batch->quantity);

            // cover days > days until expiry  <=>  cumulative x 30 > days x AMC (exact).
            if (Decimal::compare(Decimal::mul($cumulative, '30'), Decimal::mul((string) $daysUntil, $amc)) > 0) {
                $warnings[(int) $batch->getKey()] = [
                    'state' => self::AT_RISK,
                    'days_until_expiry' => $daysUntil,
                    'cover_days' => Decimal::round2(Decimal::div(Decimal::mul($cumulative, '30'), $amc)),
                ];
            }
        }

        return ['days_on_hand' => $daysOnHand, 'expiry_warnings' => $warnings];
    }
}
