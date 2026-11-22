<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * IT-ADMIN: a READ-ONLY view of audit_logs for the audit log viewer. Rows are
 * written only by App\Support\Audit (one INSERT, in the caller's transaction);
 * this model refuses to create, update or delete, before any SQL is sent (the
 * table's append-only trigger refuses UPDATE and DELETE as well).
 *
 * @property int $id
 * @property string $action
 * @property string|null $metadata
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('audit_logs is append-only and written by App\Support\Audit only.');

        static::creating($refuse);
        static::updating($refuse);
        static::saving($refuse);
        static::deleting($refuse);
    }
}
