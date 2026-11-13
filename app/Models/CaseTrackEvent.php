<?php

namespace App\Models;

use App\Enums\ReviewDesk;
use App\Enums\ReviewOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One reviewer outcome on a gate track (slice 10b). Insert-only: the model
 * refuses updates and deletes, and the database trigger
 * trg_case_track_events_append_only backs that up. A changed outcome is a new row.
 */
class CaseTrackEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('case_track_events is insert-only'));
        static::deleting(fn () => throw new LogicException('case_track_events is insert-only'));
    }

    protected function casts(): array
    {
        return [
            'track' => ReviewDesk::class,
            'outcome' => ReviewOutcome::class,
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
}
