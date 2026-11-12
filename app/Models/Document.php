<?php

namespace App\Models;

use App\Enums\DocumentPurpose;
use App\Enums\DocumentVisibility;
use App\Enums\ScanStatus;
use App\Models\Concerns\BelongsToCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * An uploaded case document. Visible only when its case is (VisibleViaCaseScope);
 * DocumentPolicy adds the per-document rules.
 *
 * Nothing is mass assignable: rows are created and changed only inside the
 * App\Documents services and the scan job, with forceFill. Rows and files are
 * never deleted (withdrawal is a flag).
 */
class Document extends Model
{
    use BelongsToCase, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'purpose' => DocumentPurpose::class,
            'version' => 'integer',
            'size_bytes' => 'integer',
            'scan_status' => ScanStatus::class,
            'scan_attempts' => 'integer',
            'scanned_at' => 'datetime',
            'released_at' => 'datetime',
            'visibility' => DocumentVisibility::class,
            'uploaded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id')->withoutGlobalScopes();
    }

    /** Not withdrawn, and no other row supersedes it. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('withdrawn_at'))
            ->whereNotExists(fn (QueryBuilder $successor) => $successor
                ->select(DB::raw(1))
                ->from('documents as successor')
                ->whereColumn('successor.supersedes_id', $this->qualifyColumn('id')));
    }

    public function isCurrent(): bool
    {
        return $this->withdrawn_at === null
            && ! self::withoutGlobalScopes()->where('supersedes_id', $this->getKey())->exists();
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }
}
