<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Models\Scopes\VisibleToUserScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One non-objection request (spec 9.2, table "cases").
 *
 * Every query is filtered by VisibleToUserScope; withoutGlobalScopes() is the
 * only bypass and is for trusted server-side code only.
 */
class ProcurementCase extends Model
{
    use HasUlids;

    protected $table = 'cases';

    /**
     * Deliberately narrow. status, current_holder_role, the track columns,
     * hardcopy_*, file_no, reference_no and the organisation/creator columns are
     * NOT mass assignable: every status change goes through the transition
     * service (slice 7), and ownership is set server-side.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'procurement_type_id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleToUserScope);
    }

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'hardcopy_received_at' => 'datetime',
            'technical_completed_at' => 'datetime',
            'funding_completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'file_prepared_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'org_id');
    }

    public function procurementType(): BelongsTo
    {
        return $this->belongsTo(ProcurementType::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CaseAssignment::class, 'case_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CaseTransition::class, 'case_id')->orderBy('created_at')->orderBy('id');
    }
}
