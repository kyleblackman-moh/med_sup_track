<?php

namespace App\Models;

use App\Enums\DocumentPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document required (or accepted) for a procurement type. */
class ProcurementTypeDocument extends Model
{
    /** procurement_type_id is set through ProcurementType::documents(). */
    protected $fillable = [
        'purpose',
        'label',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => DocumentPurpose::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function procurementType(): BelongsTo
    {
        return $this->belongsTo(ProcurementType::class);
    }
}
