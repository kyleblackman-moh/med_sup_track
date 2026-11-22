<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FR-09: how a withdrawn or finally rejected case ended (one row per case).
 * Insert-only. vendor_message is external: the one outcome text the vendor sees.
 */
class CaseOutcome extends Model
{
    public const UPDATED_AT = null;

    public const WITHDRAWN = 'withdrawn';

    public const REJECTED = 'rejected';

    /** The vendor message limit (the form and the server agree). */
    public const MESSAGE_MAX = 1000;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('case_outcomes is insert-only'));
        static::deleting(fn () => throw new LogicException('case_outcomes is insert-only'));
    }

    protected function casts(): array
    {
        return ['ps_instructed' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
