<?php

namespace App\Models\Concerns;

use App\Models\ProcurementCase;
use App\Models\Scopes\VisibleViaCaseScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared by the case child tables: visibility follows the case, and case_id is
 * set through the case's relation (never mass assignable).
 */
trait BelongsToCase
{
    public static function bootBelongsToCase(): void
    {
        static::addGlobalScope(new VisibleViaCaseScope);
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcurementCase::class, 'case_id');
    }
}
