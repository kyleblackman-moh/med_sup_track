<?php

namespace App\Submissions;

use App\Models\CaseRequisition;
use App\Support\Decimal;

/**
 * "No stock on hand" is not ticked by the vendor: the server sets
 * case_requisitions.no_stock_on_hand from the quantity on hand.
 *
 * Kyle, 2026-11-24: the quantity on hand is one quantity the vendor types
 * (quantity_on_hand), no longer the total of the stock batches. It is "no
 * stock on hand" when that quantity has been entered and is 0; an empty field
 * is not (it shows empty). Run by SaveRequisition after every save and by
 * SubmitCase under its lock, so the column always matches the data. Request
 * form types only; the CALLER has authorised access.
 */
final class StockOnHand
{
    /** True when the quantity on hand is entered and 0. */
    public static function none(?string $quantityOnHand): bool
    {
        return $quantityOnHand !== null && Decimal::compare($quantityOnHand, '0') === 0;
    }

    /** Sets no_stock_on_hand from the quantity on hand. Without a requisition row there is nothing to set. */
    public static function sync(string $caseId): void
    {
        $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $caseId)->first();
        if ($requisition === null) {
            return;
        }

        $none = self::none($requisition->quantity_on_hand);

        if ((bool) $requisition->no_stock_on_hand !== $none) {
            $requisition->forceFill(['no_stock_on_hand' => $none])->save();
        }
    }
}
