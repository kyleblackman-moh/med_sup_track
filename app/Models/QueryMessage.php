<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * OVERNIGHT-2 (D1): one message in a query. Append-only: the body, author and
 * addressing never change and a message is never deleted. The one update
 * allowed is the CMO's forward of a held message (forwarded_by_cmo_id and
 * forwarded_at, once, from empty).
 */
class QueryMessage extends Model
{
    use HasUlids;

    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITY_EXTERNAL = 'external';

    private const FORWARD_COLUMNS = ['forwarded_by_cmo_id', 'forwarded_at', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (QueryMessage $message) {
            $changed = array_keys($message->getDirty());
            if (array_diff($changed, self::FORWARD_COLUMNS) !== [] || $message->getOriginal('forwarded_at') !== null) {
                throw new LogicException('query messages are append-only');
            }
        });
        static::deleting(fn () => throw new LogicException('query messages are append-only'));
    }

    protected function casts(): array
    {
        return [
            'requires_forward' => 'boolean',
            'forwarded_at' => 'datetime',
        ];
    }

    public function caseQuery(): BelongsTo
    {
        return $this->belongsTo(CaseQuery::class, 'query_id')->withoutGlobalScopes();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Held: waits for the CMO to forward it. */
    public function isHeld(): bool
    {
        return $this->requires_forward && $this->forwarded_at === null;
    }

    public function isExternal(): bool
    {
        return $this->visibility === self::VISIBILITY_EXTERNAL;
    }
}
