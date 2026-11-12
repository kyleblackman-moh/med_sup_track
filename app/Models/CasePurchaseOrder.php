<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCase;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;

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
            'quantity_ordered' => 'decimal:2',
            'ordered_on' => 'date',
            'quantity_delivered' => 'decimal:2',
            'delivery_eta' => 'date',
        ];
    }

    /** quantity_ordered - quantity_delivered; negative (over-delivery) is not clamped. */
    public function quantityOutstanding(): ?string
    {
        if ($this->quantity_ordered === null) {
            return null;
        }

        return Decimal::round2(Decimal::sub($this->quantity_ordered, $this->quantity_delivered ?? '0'));
    }
}
