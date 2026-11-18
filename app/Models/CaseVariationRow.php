<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCase;
use Illuminate\Database\Eloquent\Model;

/**
 * Slice V2-5: one row of Details of Variation Requested (shown when the
 * previous-request answer is Yes). Mirrors CaseTenderAward: saved row by row
 * on the row id (SaveRequisition), ordered by sort_order.
 */
class CaseVariationRow extends Model
{
    use BelongsToCase;

    /** Applicant-supplied columns only. case_id is set through the case relation. */
    protected $fillable = [
        'sort_order',
        'variation_type',
        'existing_detail',
        'proposed_detail',
        'effective_detail', // "Effective Date / Application P.O.", free text (max 100)
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
