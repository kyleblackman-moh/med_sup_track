<?php

namespace App\Models;

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
    ];

    /** Applicant-supplied columns only. case_id is set through the case relation. */
    protected $fillable = [
        'sort_order',
        'award_id', // AD_HOC: checked against the case's award rows by SaveRequisition
        'supplier_name',
        'po_number',
        'quantity_ordered',
        'ordered_on',
        'quantity_delivered',
        'delivery_eta',
        'delivery_status',
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
