<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * IT-ADMIN: the reason an administrator typed for an account action. The audit
 * row points here (reason_id, reason_length) and never holds the text.
 * Insert-only: an existing row is never updated or deleted.
 */
class AdminActionReason extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'admin_action_reasons';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('admin_action_reasons is insert-only.'));
        static::deleting(fn () => throw new LogicException('admin_action_reasons is insert-only.'));
    }

    /**
     * Store a reason and return what the audit row may carry about it.
     *
     * @return array{reason_id: string, reason_length: int}|array{}
     */
    public static function store(string $action, ?User $actor, ?User $target, ?string $reason): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return [];
        }

        $row = new self;
        $row->forceFill([
            'action' => $action,
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'reason' => $reason,
        ])->save();

        return ['reason_id' => $row->id, 'reason_length' => mb_strlen($reason)];
    }
}
