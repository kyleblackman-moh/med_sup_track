<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\ReturnKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per status change (spec 6.6, 9.2). Insert-only: the model refuses
 * updates and deletes, and the database trigger trg_case_transitions_append_only
 * backs that up.
 */
class CaseTransition extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('case_transitions is insert-only'));
        static::deleting(fn () => throw new LogicException('case_transitions is insert-only'));
    }

    protected function casts(): array
    {
        return [
            'from_status' => CaseStatus::class,
            'to_status' => CaseStatus::class,
            'return_kind' => ReturnKind::class, // slice 10d: PS returns only
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ProcurementCase::class, 'case_id')->withoutGlobalScopes();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_id');
    }
}
