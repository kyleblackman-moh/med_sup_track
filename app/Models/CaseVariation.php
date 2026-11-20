<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCase;
use Illuminate\Database\Eloquent\Model;

/**
 * Tender Variation details (slice V2-1): at most one row per case. Unused since
 * V2-5 (replaced by CaseVariationRow); the table is left as it was and only
 * CaseEraser still deletes its rows with a draft.
 */
class CaseVariation extends Model
{
    use BelongsToCase;

    /** Applicant-supplied columns only. case_id is set through the case relation. */
    protected $fillable = [
        'variation_type',
        'existing_detail',
        'proposed_detail',
        'effective_date',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }
}
