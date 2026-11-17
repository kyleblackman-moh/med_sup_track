<?php

namespace App\Http\Resources\Requests;

use App\Enums\CaseStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequisitionRules;
use App\Support\Decimal;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Vendor-facing detail of a request: the summary, the vendor-supplied
 * requisition fields and child rows, and values computed on the server.
 *
 * Fields come from RequisitionRules (the vendor-supplied columns), plus row ids.
 * Staff-only columns such as technical_status are never included. Decimals are
 * strings, enums their value, dates Y-m-d. Expects the child relations loaded.
 *
 * @property ProcurementCase $resource
 */
class RequestDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...RequestSummaryResource::fields($this->resource, $request->user()),
            ...self::requisitionContent($this->resource),
            // Slice 9b: the date only. Never who recorded it, corrections or reasons.
            'hardcopy_received_on' => $this->resource->hardcopyReceived() ? $this->resource->hardcopy_received_on->toDateString() : null,
            // Slice 10f: once issued, the letter date and reference only. Never the
            // channel dates, who recorded it, or anything earlier in the process.
            'letter' => self::letter($this->resource),
        ];
    }

    /** @return array{date: string, reference: string|null}|null */
    private static function letter(ProcurementCase $case): ?array
    {
        if (! in_array($case->status, [CaseStatus::LETTER_ISSUED, CaseStatus::CLOSED], true) || $case->letter_date === null) {
            return null;
        }

        return ['date' => $case->letter_date->toDateString(), 'reference' => $case->letter_reference];
    }

    /**
     * The vendor-entered content and its server-computed values: requisition,
     * the four child sections and section totals. Shared with the staff case
     * view (slice 9a) so both show the same figures. Vendor-supplied columns
     * only; never technical_status or other staff-set columns.
     */
    public static function requisitionContent(ProcurementCase $case, bool $forStaff = false): array
    {
        $requisition = $case->requisition;
        $percentages = $case->tenderAwardPercentages();
        $code = $case->procurementType?->code;
        $adHoc = $code === ProcurementTypeProfile::AD_HOC;

        return [
            'requisition' => [
                ...self::values($requisition, array_keys(RequisitionRules::requisitionFields($code))),
                'derived' => $adHoc
                    // Slice F1: stock is the sum of the batches; coverage is staff-side only.
                    ? ['stock_on_hand' => $case->batchStockOnHand(), ...($forStaff ? ['coverage_months' => $case->batchCoverageMonths()] : [])]
                    : [
                        'stock_on_hand' => $requisition?->stockOnHand(),
                        'coverage_months' => $requisition?->coverageMonths(),
                    ],
            ],
            'quotations' => $case->quotations->map(fn (CaseQuotation $q) => [
                ...self::row($q, 'quotations', $code),
                'derived' => [
                    'offered_total' => $q->offeredTotal(),
                    'recommended_total' => $q->recommendedTotal(),
                    'recommended_vat_amount' => $q->recommendedVatAmount(),
                    'recommended_total_including_vat' => $q->recommendedTotalIncludingVat(),
                ],
            ])->values()->all(),
            'tender_awards' => $case->tenderAwards->map(fn (CaseTenderAward $a) => [
                ...self::row($a, 'tender_awards', $code),
                'derived' => [
                    'total' => $a->total(),
                    'percentage' => $percentages[$a->id] ?? null,
                ],
            ])->values()->all(),
            'purchase_orders' => $case->purchaseOrders->map(fn (CasePurchaseOrder $po) => [
                ...self::row($po, 'purchase_orders', $code),
                'derived' => [
                    'quantity_outstanding' => $po->quantityOutstanding(),
                    // AD_HOC: the supplier comes from the linked award row (typed once).
                    ...($adHoc ? ['supplier_name' => $case->tenderAwards->firstWhere('id', $po->award_id)?->supplier_name] : []),
                ],
            ])->values()->all(),
            'stock_expiries' => $case->stockExpiries->map(fn (CaseStockExpiry $e) => self::row($e, 'stock_expiries', $code))
                ->values()->all(),
            'totals' => self::totals($case),
            'figures' => $adHoc ? self::adHocFigures($case) : null,
        ];
    }

    /**
     * AD_HOC figures (slice F1), all computed by ProcurementCase on the server.
     * Price changes are null unless the reference price is entered.
     */
    private static function adHocFigures(ProcurementCase $case): array
    {
        $requisition = $case->requisition;

        return [
            'suppliers_awarded' => $case->suppliersAwarded(),
            'quotations_received' => $case->quotationsReceived(),
            'quotations_considered' => $case->quotationsConsidered(),
            'recommended_unit_price' => $case->recommendedQuotation()?->unit_price,
            'total_cost' => $case->totalCost(),
            'price_change_vs_tender_percent' => $case->priceChangePercent($requisition?->tender_price),
            'price_change_vs_previous_percent' => $case->priceChangePercent($requisition?->previous_price),
        ];
    }

    private static function totals(ProcurementCase $case): array
    {
        $recommended = $case->quotations->filter(fn (CaseQuotation $q) => $q->is_recommended && $q->recommendedTotal() !== null);
        $withVat = $recommended->map(fn (CaseQuotation $q) => $q->recommendedTotalIncludingVat());

        return [
            'quotations' => [
                'recommended_quantity' => self::sum($recommended->pluck('recommended_quantity')),
                'recommended_total' => self::sum($recommended->map(fn (CaseQuotation $q) => $q->recommendedTotal())),
                // Null when any recommended row has no VAT rate: a partial sum would mislead.
                'recommended_total_including_vat' => $withVat->contains(null) ? null : self::sum($withVat),
            ],
            'tender_awards' => [
                'quantity_awarded' => self::sum($case->tenderAwards->pluck('quantity_awarded')),
                'total' => self::sum($case->tenderAwards->map(fn (CaseTenderAward $a) => $a->total())),
            ],
            'purchase_orders' => [
                'quantity_ordered' => self::sum($case->purchaseOrders->pluck('quantity_ordered')),
                'quantity_delivered' => self::sum($case->purchaseOrders->pluck('quantity_delivered')),
                'quantity_outstanding' => self::sum($case->purchaseOrders->map(fn (CasePurchaseOrder $po) => $po->quantityOutstanding())),
            ],
            'stock_expiries' => [
                'quantity' => self::sum($case->stockExpiries->pluck('quantity')),
            ],
        ];
    }

    /** The row id plus its vendor-supplied fields. */
    private static function row(Model $row, string $section, ?string $code = null): array
    {
        return ['id' => $row->getKey(), ...self::values($row, array_keys(RequisitionRules::rowFields($section, $code)))];
    }

    /** @param  list<string>  $fields */
    private static function values(?Model $model, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $value = $model?->{$field};
            $out[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof CarbonInterface => $value->toDateString(),
                default => $value,
            };
        }

        return $out;
    }

    /** Exact sum of decimal strings, 2 decimals; null values are skipped. */
    private static function sum(Collection $values): string
    {
        return Decimal::round2($values->filter(fn ($v) => $v !== null)
            ->reduce(fn (string $carry, string $v) => Decimal::add($carry, $v), '0'));
    }
}
