<?php

namespace App\Http\Resources\Requests;

use App\Enums\DocumentVisibility;
use App\Enums\ScanStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use App\Submissions\ExpiryRisk;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Support\Decimal;
use App\Support\Display;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Slice V2-3: the ONE read-only summary of a request-form case, used by the
 * Review step, the vendor's request page and the staff case page.
 *
 * The visible sections of the case's type, in the form's order, numbering and
 * labels (RequestSections::visible). Each section is a list of blocks:
 *
 * - fields:  [{label, value}]  label/value pairs
 * - table:   {caption, columns: [{key, label}], rows: [{label, cells: {key: value}}], empty}
 *            rows carry a row label ("Supplier row 1"); empty is "None entered" when there are no rows
 * - warning: {title, lines}  the expiry warning (ExpiryRisk), advisory
 *
 * Every value is display text, computed and formatted here (Display,
 * ExpiryRisk, the ProcurementCase figures). Awards and orders always show
 * when they have rows; the tender outcome is not read. Documents keep the
 * existing visibility: the viewer's document "view" gate, and for staff only
 * CLEAN files (as StaffCaseDetail). Null for a case not on the request form
 * (the old Pharm and Non-Pharm types).
 */
final class RequestSummary
{
    public const NONE = 'None entered';

    private const TIMEZONE = 'America/Port_of_Spain';

    /** @return array{type_label: string, sections: list<array{key: string, number: int, label: string, blocks: list<array<string, mixed>>}>}|null */
    public static function for(ProcurementCase $case, User $viewer, bool $forStaff = false): ?array
    {
        $case->loadMissing(['procurementType', 'requisition', 'variation', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries']);
        $code = (string) $case->procurementType?->code;

        if (! ProcurementTypeProfile::for($code)->isAdHocForm()) {
            return null;
        }

        $sections = [];
        foreach (RequestSections::visible($code) as $i => $section) {
            $sections[] = [
                'key' => $section['key'],
                'number' => $i + 1,
                'label' => $section['label'],
                'blocks' => match ($section['key']) {
                    'item_tender' => self::itemTender($case),
                    'variation_details' => self::variation($case),
                    'awarded_supplier' => self::awards($case),
                    'existing_arrangement' => self::orders($case),
                    'stock' => self::stock($case),
                    'quotation_comparison' => self::quotations($case),
                    'documents' => self::documents($case, $viewer, $forStaff),
                },
            ];
        }

        return ['type_label' => (string) $case->procurementType?->name, 'sections' => $sections];
    }

    private static function itemTender(ProcurementCase $case): array
    {
        $r = $case->requisition;
        $unit = self::unit($case);

        return [self::fields([
            'Request type' => (string) $case->procurementType?->name,
            'DIN' => Display::text($r?->din),
            'Item description' => Display::text($r?->item_description),
            'Strength' => Display::text($r?->strength),
            'Dosage form' => Display::text($r?->dosage_form),
            'Quantity requested' => self::withUnit(Display::quantity($r?->quantity_requested), $unit),
            'Unit of measure' => Display::text($r?->unit_of_measure),
            'Contract reference' => Display::text($r?->tender_reference),
            'Tender cycle start' => self::date($r?->tender_cycle_start),
            'Tender cycle end' => self::date($r?->tender_cycle_end),
            'Clinical importance' => Display::text($r?->clinical_note),
        ])];
    }

    private static function variation(ProcurementCase $case): array
    {
        $v = $case->variation;

        return [self::fields([
            'Variation type' => Display::text($v?->variation_type),
            'Existing approved detail' => Display::text($v?->existing_detail),
            'Proposed revised detail' => Display::text($v?->proposed_detail),
            'Effective date' => self::date($v?->effective_date),
        ])];
    }

    private static function awards(ProcurementCase $case): array
    {
        $percentages = $case->tenderAwardPercentages();
        $totals = $case->tenderAwards->map(fn (CaseTenderAward $a) => $a->total())->filter(fn ($t) => $t !== null);

        $table = self::table('Awarded suppliers', 'Supplier row', [
            'supplier_name' => 'Supplier',
            'manufacturer' => 'Manufacturer',
            'brand' => 'Brand',
            'country_of_origin' => 'Country of origin',
            'quantity_awarded' => 'Quantity awarded',
            'unit_cost' => 'Unit cost',
            'percentage' => '% awarded',
            'line_cost' => 'Line cost',
        ], $case->tenderAwards->map(fn (CaseTenderAward $a) => [
            'supplier_name' => Display::text($a->supplier_name),
            'manufacturer' => Display::text($a->manufacturer),
            'brand' => Display::text($a->brand),
            'country_of_origin' => Display::text($a->country_of_origin),
            'quantity_awarded' => Display::quantity($a->quantity_awarded),
            'unit_cost' => Display::price($a->unit_cost),
            'percentage' => Display::percent($percentages[$a->getKey()] ?? null),
            'line_cost' => Display::money($a->total()),
        ])->values()->all());

        return [
            self::fields([
                'Number of suppliers awarded' => (string) $case->suppliersAwarded(),
                'Total cost of the awards' => $totals->isEmpty()
                    ? Display::EMPTY
                    : Display::money($totals->reduce(fn (string $sum, string $t) => Decimal::add($sum, $t), '0')),
            ]),
            $table,
        ];
    }

    private static function orders(ProcurementCase $case): array
    {
        $suppliers = $case->tenderAwards->pluck('supplier_name', 'id');

        return [
            self::table('Orders', 'Order row', [
                'supplier' => 'Supplier',
                'po_number' => 'PO number',
                'quantity_ordered' => 'Quantity ordered',
                'ordered_on' => 'Date ordered',
                'quantity_delivered' => 'Quantity delivered',
                'delivery_eta' => 'Delivery date',
                'delivery_status' => 'Delivery status',
                'quantity_outstanding' => 'Quantity outstanding',
            ], $case->purchaseOrders->map(fn (CasePurchaseOrder $po) => [
                'supplier' => Display::text($suppliers[$po->award_id] ?? null),
                'po_number' => Display::text($po->po_number),
                'quantity_ordered' => Display::quantity($po->quantity_ordered),
                'ordered_on' => self::date($po->ordered_on),
                'quantity_delivered' => Display::quantity($po->quantity_delivered),
                'delivery_eta' => self::date($po->delivery_eta),
                'delivery_status' => Display::text($po->delivery_status),
                'quantity_outstanding' => Display::quantity($po->quantityOutstanding()),
            ])->values()->all()),
            self::fields(['Issues affecting the tender quantity' => Display::text($case->requisition?->tender_issue_explanation)]),
        ];
    }

    private static function stock(ProcurementCase $case): array
    {
        $r = $case->requisition;
        $unit = self::unit($case);
        $noStock = (bool) $r?->no_stock_on_hand;
        $risk = ExpiryRisk::for($case); // today for a DRAFT, the submission date after submit

        $blocks = [self::fields([
            'Location' => Display::text($r?->stock_location),
            'Average monthly consumption' => self::withUnit(Display::quantity($r?->average_monthly_consumption), $unit),
            'Quantity on order' => self::withUnit(Display::quantity($r?->quantity_on_order), $unit),
            'No stock on hand' => Display::yesNo($noStock),
            'Stock total' => self::withUnit(Display::quantity($case->batchStockOnHand()), $unit),
            'Days on hand' => $risk['days_on_hand'] === null ? Display::EMPTY : (string) $risk['days_on_hand'],
        ])];

        if (! $noStock) {
            $blocks[] = self::table('Stock batches', 'Batch', [
                'quantity' => 'Quantity',
                'expiry_date' => 'Expiry date',
            ], $case->stockExpiries->map(fn (CaseStockExpiry $b) => [
                'quantity' => Display::quantity($b->quantity),
                'expiry_date' => self::date($b->expiry_date),
            ])->values()->all());
        }

        $lines = [];
        foreach ($risk['expiry_warnings'] as $batchId => $warning) {
            $date = self::date($case->stockExpiries->firstWhere('id', $batchId)?->expiry_date);
            $lines[] = $warning['state'] === ExpiryRisk::EXPIRED
                ? "Batch expiring {$date} has expired"
                : "Batch expiring {$date}: about ".Display::wholeDays($warning['cover_days'])." days of cover, expires in {$warning['days_until_expiry']} days";
        }
        if ($lines !== []) {
            $blocks[] = ['type' => 'warning', 'title' => 'Some stock may expire before it is used', 'lines' => $lines];
        }

        return $blocks;
    }

    private static function quotations(ProcurementCase $case): array
    {
        $r = $case->requisition;
        $unit = self::unit($case);
        $recommended = $case->recommendedQuotation();

        $blocks = [
            self::fields([
                'RFQ sent to' => self::enumLabel($r?->rfq_audience),
                'Number of quotations' => (string) $case->quotationsReceived(),
                'Quotations considered' => (string) $case->quotationsConsidered(),
                'Recommended quotation' => Display::text($recommended?->supplier_name),
                'Recommended unit price' => Display::price($recommended?->unit_price),
                'Quantity to purchase' => self::withUnit(Display::quantity($r?->quantity_to_purchase), $unit),
                'Total cost' => Display::money($case->totalCost()),
            ]),
            self::table('Quotations', 'Quotation row', [
                'considered' => 'Considered',
                'recommended' => 'Recommended',
                'supplier_name' => 'Supplier',
                'quantity_offered' => 'Quantity',
                'unit_price' => 'Unit price',
                'ex_stock' => 'Ex stock',
                'lead_time_days' => 'Lead time (days)',
                'brand' => 'Brand',
                'manufacturer' => 'Manufacturer',
                'country_of_origin' => 'Country of origin',
                'remarks' => 'Remarks',
                'reason' => 'Reason not considered',
            ], $case->quotations->map(fn (CaseQuotation $q) => [
                'considered' => Display::yesNo((bool) $q->considered),
                'recommended' => $q->is_recommended ? 'Yes' : Display::EMPTY,
                'supplier_name' => Display::text($q->supplier_name),
                'quantity_offered' => Display::quantity($q->quantity_offered),
                'unit_price' => Display::price($q->unit_price),
                'ex_stock' => Display::yesNo($q->ex_stock === null ? null : (bool) $q->ex_stock),
                'lead_time_days' => Display::text($q->lead_time_days),
                'brand' => Display::text($q->brand),
                'manufacturer' => Display::text($q->manufacturer),
                'country_of_origin' => Display::text($q->country_of_origin),
                'remarks' => Display::text($q->remarks),
                'reason' => $q->considered ? Display::EMPTY : Display::text($q->reason_accepted_excluded),
            ])->values()->all()),
        ];

        // The optional price comparison, only once something is entered in it (as on the form).
        if ($r !== null && ($r->tender_price !== null || $r->previous_price !== null || $r->quoted_price_acceptable !== null || filled($r->price_note))) {
            $blocks[] = self::fields([
                'Tender price' => Display::price($r->tender_price),
                'Previous price' => Display::price($r->previous_price),
                'Change against tender price' => Display::percent($case->priceChangePercent($r->tender_price)),
                'Change against previous price' => Display::percent($case->priceChangePercent($r->previous_price)),
                'Quoted price is acceptable' => Display::yesNo($r->quoted_price_acceptable === null ? null : (bool) $r->quoted_price_acceptable),
                'Note on the price' => Display::text($r->price_note),
            ]);
        }

        return $blocks;
    }

    /** Current documents the viewer may see (vendor: the document gate; staff: CLEAN only too, as StaffCaseDetail). */
    private static function documents(ProcurementCase $case, User $viewer, bool $forStaff): array
    {
        $gate = Gate::forUser($viewer);
        $labels = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->get(['purpose', 'label'])
            ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->label]);

        $documents = Document::withoutGlobalScopes()->current()
            ->where('case_id', $case->getKey())
            ->when($forStaff, fn ($q) => $q->where('scan_status', ScanStatus::CLEAN->value))
            ->orderBy('uploaded_at')->orderBy('id')
            ->get()
            ->filter(fn (Document $d) => $gate->allows('view', $d))
            ->values();

        return [self::table('Documents', 'Document', [
            'name' => 'Document',
            'category' => 'Category',
            'uploaded' => 'Uploaded',
            'status' => 'Scan status',
            ...($forStaff ? ['visibility' => 'Visibility'] : []),
        ], $documents->map(fn (Document $d) => [
            'name' => (string) $d->original_name,
            'category' => $labels[$d->purpose->value] ?? Str::headline($d->purpose->value),
            'uploaded' => self::date($d->uploaded_at?->copy()->setTimezone(self::TIMEZONE)),
            'status' => $d->scan_status->label(),
            ...($forStaff ? ['visibility' => $d->visibility === DocumentVisibility::INTERNAL ? 'Internal' : 'External'] : []),
        ])->all())];
    }

    /** @param  array<string, string>  $pairs */
    private static function fields(array $pairs): array
    {
        $items = [];
        foreach ($pairs as $label => $value) {
            $items[] = ['label' => $label, 'value' => $value];
        }

        return ['type' => 'fields', 'items' => $items];
    }

    /**
     * @param  array<string, string>  $columns
     * @param  list<array<string, string>>  $rows
     */
    private static function table(string $caption, string $rowLabel, array $columns, array $rows): array
    {
        return [
            'type' => 'table',
            'caption' => $caption,
            'columns' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($columns), $columns),
            'rows' => array_map(fn (array $cells, int $i) => ['label' => "{$rowLabel} ".($i + 1), 'cells' => $cells], $rows, array_keys($rows)),
            'empty' => $rows === [] ? self::NONE : null,
        ];
    }

    private static function unit(ProcurementCase $case): string
    {
        return trim((string) $case->requisition?->unit_of_measure);
    }

    private static function withUnit(string $value, string $unit): string
    {
        return $value === Display::EMPTY || $unit === '' ? $value : "{$value} {$unit}";
    }

    private static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return Display::EMPTY;
        }

        return Display::date($value instanceof CarbonInterface ? $value : CarbonImmutable::parse((string) $value));
    }

    private static function enumLabel(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        return Display::text($value);
    }
}
