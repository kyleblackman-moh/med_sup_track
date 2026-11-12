<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\TrackStatus;
use App\Models\Scopes\VisibleToUserScope;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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

    /** Depth of nested allowStatusWrite() blocks; status writes are allowed while > 0. */
    private static int $statusWriteDepth = 0;

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleToUserScope);

        // Only CaseTransitionService may change status or current_holder_role, and
        // it does so inside allowStatusWrite(). The initial insert is not blocked.
        //
        // NOTE: raw query-builder updates (DB::table('cases')->update(...)) bypass
        // model events, so they are not caught here. Slice 7 does not close that.
        static::updating(function (self $case) {
            if (self::$statusWriteDepth === 0 && $case->isDirty(['status', 'current_holder_role'])) {
                throw new LogicException('Case status and holder change only through CaseTransitionService.');
            }
        });
    }

    /**
     * Runs $callback with status writes allowed. Re-entrant: nested calls keep the
     * permission until the outermost block ends, and it is restored on exceptions.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function allowStatusWrite(callable $callback): mixed
    {
        self::$statusWriteDepth++;

        try {
            return $callback();
        } finally {
            self::$statusWriteDepth--;
        }
    }

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'technical_track' => TrackStatus::class,
            'funding_track' => TrackStatus::class,
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

    public function requisition(): HasOne
    {
        return $this->hasOne(CaseRequisition::class, 'case_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(CaseQuotation::class, 'case_id')->orderBy('sort_order')->orderBy('id');
    }

    public function tenderAwards(): HasMany
    {
        return $this->hasMany(CaseTenderAward::class, 'case_id')->orderBy('sort_order')->orderBy('id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(CasePurchaseOrder::class, 'case_id')->orderBy('sort_order')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }

    public function stockExpiries(): HasMany
    {
        return $this->hasMany(CaseStockExpiry::class, 'case_id')->orderBy('expiry_date')->orderBy('id');
    }

    /**
     * Each award's share of the total awarded quantity, as a 2-decimal percentage
     * string keyed by award id. Empty when there are no awards or they sum to zero.
     * Computed from the awards the current user can see (VisibleViaCaseScope).
     *
     * @return array<int, string>
     */
    public function tenderAwardPercentages(): array
    {
        $awards = $this->tenderAwards;
        $sum = $awards->reduce(fn (string $carry, CaseTenderAward $a) => Decimal::add($carry, $a->quantity_awarded), '0');

        if (Decimal::compare($sum, '0') <= 0) {
            return [];
        }

        return $awards->mapWithKeys(fn (CaseTenderAward $a) => [
            $a->id => Decimal::round2(Decimal::div(Decimal::mul($a->quantity_awarded, '100'), $sum)),
        ])->all();
    }
}
