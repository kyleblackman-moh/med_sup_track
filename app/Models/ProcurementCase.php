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
use Illuminate\Support\Collection;
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
            // V2-5: the request form's previous-request answer; null = not answered.
            'previous_request_in_cycle' => 'boolean',
            'technical_track' => TrackStatus::class,
            'funding_track' => TrackStatus::class,
            'hardcopy_received_at' => 'datetime',
            'hardcopy_received_on' => 'date',
            'technical_completed_at' => 'datetime',
            'funding_completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'file_prepared_at' => 'datetime',
            'closed_at' => 'datetime',
            // Slice 10f: the issued letter (calendar dates, Port of Spain).
            'letter_date' => 'date',
            'letter_emailed_on' => 'date',
            'letter_hardcopy_sent_on' => 'date',
            'letter_recorded_at' => 'datetime',
        ];
    }

    /**
     * The one definition of "hardcopy received" (slice 10c): the received date is
     * set. The gate, the staff page and the vendor page all read this. The CHECK
     * constraint ck_cases_hardcopy_all_or_none keeps the recorder and recorded-at
     * columns in step with it.
     */
    public function hardcopyReceived(): bool
    {
        return $this->hardcopy_received_on !== null;
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

    /** Tender Variation details (slice V2-1); at most one row. */
    /** V2-5: Details of Variation Requested, in saved order (as the award rows). */
    public function variationRows(): HasMany
    {
        return $this->hasMany(CaseVariationRow::class, 'case_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Each award's share of the total awarded quantity, as a 2-decimal percentage
     * string keyed by award id. Empty when there are no awards or they sum to zero.
     * Rows without a quantity (a partial draft row) are left out of the sum and
     * get no percentage. Computed from the awards the current user can see
     * (VisibleViaCaseScope).
     *
     * @return array<int, string>
     */
    public function tenderAwardPercentages(): array
    {
        $awards = $this->tenderAwards->filter(fn (CaseTenderAward $a) => $a->quantity_awarded !== null);
        $sum = $awards->reduce(fn (string $carry, CaseTenderAward $a) => Decimal::add($carry, $a->quantity_awarded), '0');

        if (Decimal::compare($sum, '0') <= 0) {
            return [];
        }

        return $awards->mapWithKeys(fn (CaseTenderAward $a) => [
            $a->id => Decimal::round2(Decimal::div(Decimal::mul($a->quantity_awarded, '100'), $sum)),
        ])->all();
    }

    /*
     * AD_HOC figures (slice F1). Computed on the server from the saved rows,
     * never stored and never computed in React. Expect the relations loaded
     * (they load lazily otherwise).
     */

    /** Distinct supplier names on the award rows (trimmed, case-insensitive). */
    public function suppliersAwarded(): int
    {
        return $this->tenderAwards
            ->map(fn (CaseTenderAward $a) => mb_strtolower(trim((string) $a->supplier_name)))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->count();
    }

    /**
     * Stock on hand for AD_HOC: 0 when "no stock on hand" is ticked, else the
     * sum of the batch quantities; null when neither is given.
     */
    public function batchStockOnHand(): ?string
    {
        if ($this->requisition?->no_stock_on_hand) {
            return '0.00';
        }

        $quantities = $this->stockExpiries->pluck('quantity')->filter(fn ($q) => $q !== null);

        return $quantities->isEmpty()
            ? null
            : Decimal::round2($quantities->reduce(fn (string $carry, string $q) => Decimal::add($carry, $q), '0'));
    }

    /** batchStockOnHand / average monthly consumption, in months; null if not computable. Staff only. */
    public function batchCoverageMonths(): ?string
    {
        $stock = $this->batchStockOnHand();
        $consumption = $this->requisition?->average_monthly_consumption;

        if ($stock === null || $consumption === null || Decimal::compare($consumption, '0') <= 0) {
            return null;
        }

        return Decimal::round2(Decimal::div($stock, $consumption));
    }

    /** Quotation rows that name a supplier (blank starter rows are not "received"). */
    public function quotationsReceived(): int
    {
        return $this->receivedQuotations()->count();
    }

    public function quotationsConsidered(): int
    {
        return $this->receivedQuotations()->filter(fn (CaseQuotation $q) => $q->considered)->count();
    }

    /** The recommended quotation; null when none or more than one is marked. */
    public function recommendedQuotation(): ?CaseQuotation
    {
        $recommended = $this->quotations->filter(fn (CaseQuotation $q) => $q->is_recommended);

        return $recommended->count() === 1 ? $recommended->first() : null;
    }

    /** Recommended unit price x quantity to purchase, 2 decimals; null if either is missing. */
    public function totalCost(): ?string
    {
        $price = $this->recommendedQuotation()?->unit_price;
        $quantity = $this->requisition?->quantity_to_purchase;

        return $price === null || $quantity === null ? null : Decimal::round2(Decimal::mul($price, $quantity));
    }

    /**
     * (quoted - reference) / reference x 100, 2 decimals, where the quoted price
     * is the recommended quotation's unit price. Null when the reference price
     * is not entered (or is zero) or there is no single recommended quotation.
     */
    public function priceChangePercent(?string $reference): ?string
    {
        $quoted = $this->recommendedQuotation()?->unit_price;

        if ($quoted === null || $reference === null || Decimal::compare($reference, '0') <= 0) {
            return null;
        }

        return Decimal::round2(Decimal::div(Decimal::mul(Decimal::sub($quoted, $reference), '100'), $reference));
    }

    /** @return Collection<int, CaseQuotation> */
    private function receivedQuotations()
    {
        return $this->quotations->filter(fn (CaseQuotation $q) => trim((string) $q->supplier_name) !== '');
    }
}
