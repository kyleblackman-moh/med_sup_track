<?php

namespace App\Models;

use App\Enums\PreviousPriceMaintained;
use App\Enums\TechnicalStatus;
use App\Models\Concerns\BelongsToCase;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supplier quotation. Excluded quotations are kept. Totals are computed,
 * never stored; the VAT rate is per-row data, never a hard-coded rate.
 */
class CaseQuotation extends Model
{
    use BelongsToCase;

    /** Mirrors the database defaults, so unsaved models read the same values. */
    protected $attributes = [
        'currency' => 'TTD',
        'previous_price_maintained' => 'UNKNOWN',
        'is_recommended' => false,
        'technical_status' => 'PENDING',
        'considered' => true,
    ];

    /**
     * Applicant-supplied columns only. NOT fillable:
     * - case_id: set through the case relation;
     * - technical_status, technical_status_set_by, technical_status_set_at:
     *   set by MOH staff, never by the vendor.
     */
    protected $fillable = [
        'sort_order',
        'supplier_name',
        'manufacturer',
        'brand',
        'unit_price',
        'currency',
        'vat_rate',
        'quantity_offered',
        'lead_time_days',
        'minimum_order_quantity',
        'price_valid_until',
        'quantity_available_now',
        'previous_price_maintained',
        'reason_accepted_excluded',
        'is_recommended',
        'recommended_quantity',
        'considered',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'unit_price' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'quantity_offered' => 'decimal:2',
            'lead_time_days' => 'integer',
            'minimum_order_quantity' => 'decimal:2',
            'price_valid_until' => 'date',
            'quantity_available_now' => 'decimal:2',
            'previous_price_maintained' => PreviousPriceMaintained::class,
            'is_recommended' => 'boolean',
            'recommended_quantity' => 'decimal:2',
            'considered' => 'boolean',
            'technical_status' => TechnicalStatus::class,
            'technical_status_set_at' => 'datetime',
        ];
    }

    public function technicalStatusSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_status_set_by');
    }

    /** unit_price x quantity_offered, 2 decimals. */
    public function offeredTotal(): ?string
    {
        return $this->lineTotal($this->quantity_offered);
    }

    /** unit_price x recommended_quantity, 2 decimals; null without a recommended quantity. */
    public function recommendedTotal(): ?string
    {
        return $this->lineTotal($this->recommended_quantity);
    }

    /** VAT on the (rounded) recommended total at this row's rate; null without a rate. */
    public function recommendedVatAmount(): ?string
    {
        $total = $this->recommendedTotal();

        if ($total === null || $this->vat_rate === null) {
            return null;
        }

        return Decimal::round2(Decimal::div(Decimal::mul($total, $this->vat_rate), '100'));
    }

    public function recommendedTotalIncludingVat(): ?string
    {
        $vat = $this->recommendedVatAmount();

        return $vat === null ? null : Decimal::round2(Decimal::add($this->recommendedTotal(), $vat));
    }

    private function lineTotal(?string $quantity): ?string
    {
        if ($quantity === null || $this->unit_price === null) {
            return null;
        }

        return Decimal::round2(Decimal::mul($this->unit_price, $quantity));
    }
}
