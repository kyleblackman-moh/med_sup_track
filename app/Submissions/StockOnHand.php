<?php

namespace App\Submissions;

use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Support\Decimal;

/**
 * Kyle, 2026-11-23: the request form's quantity on hand is the total of the
 * stock batches, and "no stock on hand" is no longer ticked by the vendor: the
 * server sets case_requisitions.no_stock_on_hand. It is true when the batch
 * total is 0 (no batches, or batches of 0) AND the average monthly consumption
 * has been entered; an untouched draft is not "no stock on hand" (its stock
 * total shows empty). Run by SaveRequisition after every save and by
 * SubmitCase under its lock, so the column always matches the data. Request
 * form types only; the CALLER has authorised access.
 */
final class StockOnHand
{
    /** The batch total, exact (bcmath); "0" without batches. */
    public static function total(string $caseId): string
    {
        return CaseStockExpiry::withoutGlobalScopes()->where('case_id', $caseId)->pluck('quantity')
            ->filter(fn ($q) => $q !== null)
            ->reduce(fn (string $sum, string $q) => Decimal::add($sum, $q), '0');
    }

    /** Sets no_stock_on_hand from the total and the consumption. Without a requisition row there is no consumption: nothing to set. */
    public static function sync(string $caseId): void
    {
        $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $caseId)->first();
        if ($requisition === null) {
            return;
        }

        $none = $requisition->average_monthly_consumption !== null
            && Decimal::compare(self::total($caseId), '0') === 0;

        if ((bool) $requisition->no_stock_on_hand !== $none) {
            $requisition->forceFill(['no_stock_on_hand' => $none])->save();
        }
    }
}
