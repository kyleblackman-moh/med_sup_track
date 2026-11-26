<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\TrackStatus;
use App\Models\Scopes\VisibleToUserScope;
use App\Support\Decimal;
use App\Support\QuotationPrice;
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
    /** V2-5: Subsequently Approved by MoH, in saved order (as the award rows). */
    public function variationRows(): HasMany
    {
        return $this->hasMany(CaseVariationRow::class, 'case_id')->orderBy('sort_order')->orderBy('id');
    }

    /*
     * Kyle, 2026-11-24: an award's % awarded is no longer calculated; the vendor
     * types it (case_tender_awards.percentage_awarded, optional).
     */

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
     * Stock on hand for the request form. Kyle, 2026-11-24: the quantity the
     * vendor types (case_requisitions.quantity_on_hand), no longer the total of
     * the stock batches; null until entered.
     */
    public function quantityOnHand(): ?string
    {
        $quantity = $this->requisition?->quantity_on_hand;

        return $quantity === null ? null : Decimal::round2($quantity);
    }

    /** quantityOnHand / average monthly consumption, in months; null if not computable. Staff only. */
    public function batchCoverageMonths(): ?string
    {
        $stock = $this->quantityOnHand();
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

    /**
     * Kyle, 2026-11-23: the recommended row(s)' Total Price (Subtotal + VAT,
     * CaseQuotation::totalPrice), summed; null while no recommended row has one.
     */
    public function totalCost(): ?string
    {
        $totals = $this->quotations
            ->filter(fn (CaseQuotation $q) => $q->is_recommended)
            ->map(fn (CaseQuotation $q) => $q->totalPrice())
            ->filter(fn (?string $t) => $t !== null);

        return $totals->isEmpty() ? null : Decimal::round2($totals->reduce(fn (string $sum, string $t) => Decimal::add($sum, $t), '0'));
    }

    /**
     * (quoted - reference) / reference x 100, 2 decimals, where the quoted price
     * is the recommended quotation's unit price. Null when the reference price
     * is not entered (or is zero) or there is no single recommended quotation.
     */
    public function priceChangePercent(?string $reference): ?string
    {
        return QuotationPrice::changePercent($this->recommendedQuotation()?->unit_price, $reference);
    }

    /** @return Collection<int, CaseQuotation> */
    private function receivedQuotations()
    {
        return $this->quotations->filter(fn (CaseQuotation $q) => trim((string) $q->supplier_name) !== '');
    }
}
