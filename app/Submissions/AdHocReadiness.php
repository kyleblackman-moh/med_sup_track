<?php

namespace App\Submissions;

use App\Enums\TenderOutcome;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * What an AD_HOC request needs before it can be submitted (adhoc-form-spec,
 * slice F1). Every rule is here, so the interim list is easy to change once the
 * CMO's office confirms it. Documents (at least one CLEAN Bids file) are
 * checked by SubmissionReadiness from the type's document configuration.
 *
 * Optional: strength, dosage form, DIN, clinical note, stock location, the
 * price comparison, and the regulatory and evidence files.
 *
 * Read-only. Returns errors keyed like the payload, so the form can link to
 * the field.
 */
final class AdHocReadiness
{
    /** Request fields that must be filled in: field => message. */
    public const REQUIRED_FIELDS = [
        'item_description' => 'Describe the item.',
        'unit_of_measure' => 'Enter the unit.',
        'tender_reference' => 'Enter the tender period or reference.',
        'tender_outcome' => 'Choose the tender outcome.',
        'rfq_audience' => 'Choose who received the RFQ.',
    ];

    /** Request quantities that must be greater than zero: field => message. */
    public const POSITIVE_FIELDS = [
        'quantity_requested' => 'Enter a quantity requested greater than zero.',
        'average_monthly_consumption' => 'Enter an average monthly consumption greater than zero.',
        'quantity_to_purchase' => 'Enter a quantity to purchase greater than zero.',
    ];

    public const AWARD_ROW_NEEDED = 'Add at least one award row with supplier, quantity awarded and unit cost.';

    public const ORDER_AWARD = 'Choose the supplier for this order from the award rows.';

    public const ORDER_PO_NUMBER = 'Enter the PO number.';

    public const ORDER_QUANTITY = 'Enter the quantity ordered.';

    public const STOCK_NEEDED = 'Add a stock batch with quantity and expiry date, or tick "No stock on hand".';

    public const QUOTATION_NEEDED = 'Add at least one quotation with supplier, unit price and quantity offered.';

    public const RECOMMEND_ONE = 'Choose the recommended quotation.';

    public const RECOMMEND_ONLY_ONE = 'Recommend only one quotation.';

    public const REASON_NEEDED = 'Give the reason this quotation was not considered.';

    /**
     * @param  array{quotations: Collection<int, CaseQuotation>, tender_awards: Collection<int, CaseTenderAward>, purchase_orders: Collection<int, CasePurchaseOrder>, stock_expiries: Collection<int, CaseStockExpiry>}  $rows
     *                                                                                                                                                                                                                           rows re-indexed from 0 in saved order
     * @return list<array{key: string, message: string}>
     */
    public function errors(CaseRequisition $r, array $rows): array
    {
        $errors = [];
        $add = function (string $key, string $message) use (&$errors) {
            $errors[] = ['key' => $key, 'message' => $message];
        };

        // 1 Item, 2 Tender, 3 Stock (AMC), 4 Quotations (RFQ, quantity to purchase)
        foreach (self::REQUIRED_FIELDS as $field => $message) {
            if (self::blank($r->{$field})) {
                $add("requisition.{$field}", $message);
            }
        }

        foreach (self::POSITIVE_FIELDS as $field => $message) {
            if ($r->{$field} === null || Decimal::compare($r->{$field}, '0') <= 0) {
                $add("requisition.{$field}", $message);
            }
        }

        // 2a and 2b apply only when something was awarded (the form hides them otherwise).
        if ($r->tender_outcome !== null && $r->tender_outcome !== TenderOutcome::NOT_AWARDED) {
            if (! $rows['tender_awards']->contains(fn (CaseTenderAward $a) => self::completeAward($a))) {
                $add('tender_awards', self::AWARD_ROW_NEEDED);
            }

            // Orders are optional; each one that exists must be complete.
            foreach ($rows['purchase_orders'] as $i => $order) {
                if ($order->award_id === null) {
                    $add("purchase_orders.{$i}.award_id", self::ORDER_AWARD);
                }
                if (self::blank($order->po_number)) {
                    $add("purchase_orders.{$i}.po_number", self::ORDER_PO_NUMBER);
                }
                if ($order->quantity_ordered === null) {
                    $add("purchase_orders.{$i}.quantity_ordered", self::ORDER_QUANTITY);
                }
            }
        }

        // 3 Stock
        if (! $r->no_stock_on_hand
            && ! $rows['stock_expiries']->contains(fn (CaseStockExpiry $e) => $e->quantity !== null && $e->expiry_date !== null)) {
            $add('stock_expiries', self::STOCK_NEEDED);
        }

        // 4 Quotations
        if (! $rows['quotations']->contains(fn (CaseQuotation $q) => self::completeQuotation($q))) {
            $add('quotations', self::QUOTATION_NEEDED);
        }

        $recommended = $rows['quotations']->filter(fn (CaseQuotation $q) => $q->is_recommended)->count();
        if ($recommended !== 1) {
            $add('quotations.recommended', $recommended === 0 ? self::RECOMMEND_ONE : self::RECOMMEND_ONLY_ONE);
        }

        foreach ($rows['quotations'] as $i => $q) {
            if (! $q->considered && self::blank($q->reason_accepted_excluded)) {
                $add("quotations.{$i}.reason_accepted_excluded", self::REASON_NEEDED);
            }
        }

        return $errors;
    }

    private static function completeAward(CaseTenderAward $a): bool
    {
        return ! self::blank($a->supplier_name) && $a->quantity_awarded !== null && $a->unit_cost !== null;
    }

    private static function completeQuotation(CaseQuotation $q): bool
    {
        return ! self::blank($q->supplier_name) && $q->unit_price !== null && $q->quantity_offered !== null;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
