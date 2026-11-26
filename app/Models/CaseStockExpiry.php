<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCase;
use Illuminate\Database\Eloquent\Model;

/** Stock on hand by expiry date. */
class CaseStockExpiry extends Model
{
    use BelongsToCase;

    /** Applicant-supplied columns only. case_id is set through the case relation. */
    protected $fillable = [
        'quantity',
        'expiry_date',
        'item_name', // Kyle, 2026-11-26: the drug the row is for
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }
}
