<?php

namespace App\Models;

use App\Enums\QueryStatus;
use App\Enums\QueryType;
use App\Models\Concerns\BelongsToCase;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * OVERNIGHT-2 (D1): one query on a case (table "queries"). An overlay on the
 * case, never a case status. Visibility follows the case (BelongsToCase);
 * who sees which message is decided by App\Queries\QueryAccess. Never deleted.
 */
class CaseQuery extends Model
{
    use BelongsToCase, HasUlids;

    protected $table = 'queries';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('queries are never deleted'));
    }

    protected function casts(): array
    {
        return [
            'type' => QueryType::class,
            'status' => QueryStatus::class,
            'via_cmo' => 'boolean',
            'answered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(QueryMessage::class, 'query_id')->orderBy('created_at')->orderBy('id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }
}
