<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCase;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;

/** A prior tender award for the item. Totals and percentages are computed. */
class CaseTenderAward extends Model
{
    use BelongsToCase;

    /** Applicant-supplied columns only. case_id is set through the case relation. */
    protected $fillable = [
        'sort_order',
        'supplier_name',
        'manufacturer',
        'brand',
        'quantity_awarded',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'quantity_awarded' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** unit_cost x quantity_awarded, 2 decimals. */
    public function total(): ?string
    {
        if ($this->unit_cost === null || $this->quantity_awarded === null) {
            return null;
        }

        return Decimal::round2(Decimal::mul($this->unit_cost, $this->quantity_awarded));
    }
}
