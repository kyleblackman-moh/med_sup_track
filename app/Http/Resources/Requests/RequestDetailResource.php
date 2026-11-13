<?php

namespace App\Http\Resources\Requests;

use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
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
            'hardcopy_received_on' => $this->resource->hardcopy_received_on?->toDateString(),
        ];
    }

    /**
     * The vendor-entered content and its server-computed values: requisition,
     * the four child sections and section totals. Shared with the staff case
     * view (slice 9a) so both show the same figures. Vendor-supplied columns
     * only; never technical_status or other staff-set columns.
     */
    public static function requisitionContent(ProcurementCase $case): array
    {
        $requisition = $case->requisition;
        $percentages = $case->tenderAwardPercentages();

        return [
            'requisition' => [
                ...self::values($requisition, array_keys(RequisitionRules::requisitionFields())),
                'derived' => [
                    'stock_on_hand' => $requisition?->stockOnHand(),
                    'coverage_months' => $requisition?->coverageMonths(),
                ],
            ],
            'quotations' => $case->quotations->map(fn (CaseQuotation $q) => [
                ...self::row($q, 'quotations'),
                'derived' => [
                    'offered_total' => $q->offeredTotal(),
                    'recommended_total' => $q->recommendedTotal(),
                    'recommended_vat_amount' => $q->recommendedVatAmount(),
                    'recommended_total_including_vat' => $q->recommendedTotalIncludingVat(),
                ],
            ])->values()->all(),
            'tender_awards' => $case->tenderAwards->map(fn (CaseTenderAward $a) => [
                ...self::row($a, 'tender_awards'),
                'derived' => [
                    'total' => $a->total(),
                    'percentage' => $percentages[$a->id] ?? null,
                ],
            ])->values()->all(),
            'purchase_orders' => $case->purchaseOrders->map(fn (CasePurchaseOrder $po) => [
                ...self::row($po, 'purchase_orders'),
                'derived' => [
                    'quantity_outstanding' => $po->quantityOutstanding(),
                ],
            ])->values()->all(),
            'stock_expiries' => $case->stockExpiries->map(fn (CaseStockExpiry $e) => self::row($e, 'stock_expiries'))
                ->values()->all(),
            'totals' => self::totals($case),
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
    private static function row(Model $row, string $section): array
    {
        return ['id' => $row->getKey(), ...self::values($row, array_keys(RequisitionRules::rowFields($section)))];
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
