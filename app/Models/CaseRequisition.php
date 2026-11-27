<?php

namespace App\Models;

use App\Enums\NeedClassification;
use App\Enums\RfqAudience;
use App\Enums\TenderOutcome;
use App\Models\Concerns\BelongsToCase;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;

/**
 * The request content, 1:1 with a case. Decimals are strings; derived values
 * are computed, never stored.
 */
class CaseRequisition extends Model
{
    use BelongsToCase;

    /** Applicant-supplied columns only. case_id is set through the case relation. */
    protected $fillable = [
        'vendor_reference',
        'request_date',
        'need_classification',
        'item_description',
        'din',
        'item_code',
        'unit_of_measure',
        'purpose',
        'quantity_requested',
        'average_monthly_consumption',
        'central_stores_stock',
        'rha_stock',
        'stock_verified_on',
        'stockout_date',
        'urgency_explanation',
        'tender_reference',
        'tender_outcome',
        'tender_issue_explanation',
        'rfq_date',
        'rfq_audience',
        'sample_evaluation_summary',
        'applicant_comments',
        // Slice F1: the single Ad Hoc form.
        'strength',
        'dosage_form',
        'clinical_note',
        'stock_location',
        'no_stock_on_hand',
        'quantity_to_purchase',
        'tender_price',
        'previous_price',
        'quoted_price_acceptable',
        'price_note',
        // Slice V2-1: the five request types.
        'tender_cycle_start',
        'tender_cycle_end',
        'quantity_on_order',
        'quantity_on_hand', // Kyle, 2026-11-24: typed by the vendor (was the batch total)
        // Kyle, 2026-11-27: printed by the request letter (sections 1, 5, 6 and the annex).
        'purpose_of_request',
        'purpose_summary',
        'outstanding_order_note',
        'basis_of_recommendation',
        'rfq_number',
    ];

    /** Mirrors the database default. */
    protected $attributes = [
        'no_stock_on_hand' => false,
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'stock_verified_on' => 'date',
            'stockout_date' => 'date',
            'rfq_date' => 'date',
            'need_classification' => NeedClassification::class,
            'tender_outcome' => TenderOutcome::class,
            'rfq_audience' => RfqAudience::class,
            'quantity_requested' => 'decimal:2',
            'average_monthly_consumption' => 'decimal:2',
            'central_stores_stock' => 'decimal:2',
            'rha_stock' => 'decimal:2',
            'no_stock_on_hand' => 'boolean',
            'quantity_to_purchase' => 'decimal:2',
            'tender_price' => 'decimal:4',
            'previous_price' => 'decimal:4',
            'quoted_price_acceptable' => 'boolean',
            'tender_cycle_start' => 'date',
            'tender_cycle_end' => 'date',
            'quantity_on_order' => 'decimal:2',
            'quantity_on_hand' => 'decimal:2',
        ];
    }

    /**
     * Old types: central_stores_stock + rha_stock; null if either is missing.
     * The request form uses ProcurementCase::quantityOnHand() instead.
     */
    public function stockOnHand(): ?string
    {
        if ($this->central_stores_stock === null || $this->rha_stock === null) {
            return null;
        }

        return Decimal::round2(Decimal::add($this->central_stores_stock, $this->rha_stock));
    }

    /** Months of stock: stock on hand / average monthly consumption (null if not computable). */
    public function coverageMonths(): ?string
    {
        $stock = $this->stockOnHand();
        $consumption = $this->average_monthly_consumption;

        if ($stock === null || $consumption === null || Decimal::compare($consumption, '0') <= 0) {
            return null;
        }

        return Decimal::round2(Decimal::div($stock, $consumption));
    }
}
