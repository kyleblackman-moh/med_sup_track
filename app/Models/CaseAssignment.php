<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role (pool row, user_id null) or a specific user assigned to a case.
 * Active = completed_at and cancelled_at both null.
 */
class CaseAssignment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** The case, bypassing visibility: whoever holds the assignment already passed a check. */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcurementCase::class, 'case_id')->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reassignedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reassigned_from_id');
    }
}
