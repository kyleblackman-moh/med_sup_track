<?php

namespace App\Models;

use App\Enums\DeliveryState;
use App\Enums\OrderKind;
use App\Models\Concerns\BelongsToCase;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchase order already placed. Outstanding quantity is computed. */
class CasePurchaseOrder extends Model
{
    use BelongsToCase;

    /** Mirrors the database default. */
    protected $attributes = [
        'quantity_delivered' => '0',
        'order_kind' => 'ORIGINAL_TENDER',
    ];

    /**
     * Applicant-supplied columns only. case_id is set through the case relation;
     * order_kind (Kyle, 2026-11-27) by the payload section the row was saved in.
     */
    protected $fillable = [
        'sort_order',
        'award_id', // AD_HOC: checked against the case's award rows by SaveRequisition
        'supplier_name',
        'po_number',
        'quantity_ordered',
        'ordered_on',
        'quantity_delivered',
        'delivery_eta',    // kept; no longer written by the request form (2026-11-23)
        'delivery_status', // free text; kept, no longer written by the request form (2026-11-23)
        'delivery_state',  // Kyle, 2026-11-23: the Delivery Status dropdown
        'delivery_date',
        'pack_size',     // Kyle, 2026-11-25: optional free text, e.g. "Boxes of 28 Tablets"
        'delivery_note', // Kyle, 2026-11-25: optional free text, e.g. "Variation of tender in progress"
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'award_id' => 'integer',
            'quantity_ordered' => 'decimal:2',
            'ordered_on' => 'date',
            'quantity_delivered' => 'decimal:2',
            'delivery_eta' => 'date',
            'delivery_state' => DeliveryState::class,
            'delivery_date' => 'date',
            'order_kind' => OrderKind::class,
        ];
    }

    /** The award row this order was placed against (AD_HOC). */
    public function award(): BelongsTo
    {
        return $this->belongsTo(CaseTenderAward::class, 'award_id')->withoutGlobalScopes();
    }

    /** quantity_ordered - quantity_delivered (blank delivered counts as 0); negative (over-delivery) is not clamped. */
    public function quantityOutstanding(): ?string
    {
        if ($this->quantity_ordered === null) {
            return null;
        }

        return Decimal::round2(Decimal::sub($this->quantity_ordered, $this->quantity_delivered ?? '0'));
    }
}
