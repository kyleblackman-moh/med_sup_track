<?php

namespace App\Http\Resources\Requests;

use App\Enums\DeliveryState;
use App\Enums\DocumentVisibility;
use App\Enums\ScanStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\CaseVariationRow;
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
 *
 * NEXT part 5: documents are shown once per page. Only the Review step lists
 * them in the summary; the vendor request page and the staff case page have
 * their own document lists with actions, so they pass withDocuments: false.
 */
final class RequestSummary
{
    public const NONE = 'None entered';

    /** V2-5: the summary label of the previous-request question. */
    public const PREVIOUS_REQUEST_LABEL = 'Previous Request in This Tender Cycle';

    private const TIMEZONE = 'America/Port_of_Spain';

    /** v0.4: the stock block when the batch total is 0 (no_stock_on_hand, set by the server). */
    public const NO_STOCK_TITLE = 'No stock on hand';

    public const NO_STOCK_LINE = 'The stock batches total 0, so there is no stock of this item on hand.';

    /** @return array{type_label: string, sections: list<array{key: string, number: int, label: string, blocks: list<array<string, mixed>>}>}|null */
    public static function for(ProcurementCase $case, User $viewer, bool $forStaff = false, bool $withDocuments = true, bool $forPrint = false): ?array
    {
        $case->loadMissing(['procurementType', 'requisition', 'variationRows', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries']);
        $code = (string) $case->procurementType?->code;

        if (! ProcurementTypeProfile::for($code)->isAdHocForm()) {
            return null;
        }

        $sections = [];
        foreach (RequestSections::visible($code, true, $case->previous_request_in_cycle) as $i => $section) {
            // The number is the section's place among ALL visible sections, so leaving
            // Documents out never renumbers the others.
            if (! $withDocuments && $section['key'] === 'documents') {
                continue;
            }
            $sections[] = [
                'key' => $section['key'],
                'number' => $i + 1,
                'label' => $section['label'],
                'blocks' => match ($section['key']) {
                    'request_type' => self::requestType($case),
                    'item_tender' => self::itemTender($case),
                    'variation_details' => self::variation($case),
                    'awarded_supplier' => self::awards($case),
                    'existing_arrangement' => self::orders($case),
                    'stock' => self::stock($case),
                    'quotation_comparison' => self::quotations($case),
                    'documents' => self::documents($case, $viewer, $forStaff, $forPrint),
                },
            ];
        }

        return ['type_label' => (string) $case->procurementType?->name, 'sections' => $sections];
    }

    /** V2-5: the type, the tender cycle and the previous-request answer (Yes, No, or a dash). */
    private static function requestType(ProcurementCase $case): array
    {
        $r = $case->requisition;

        return [self::fields([
            'Request Type' => (string) $case->procurementType?->name,
            'Tender Cycle Start' => self::date($r?->tender_cycle_start),
            'Tender Cycle End' => self::date($r?->tender_cycle_end),
            self::PREVIOUS_REQUEST_LABEL => Display::yesNo($case->previous_request_in_cycle),
        ])];
    }

    private static function itemTender(ProcurementCase $case): array
    {
        $r = $case->requisition;
        $unit = self::unit($case);

        return [self::fields([
            'DIN' => Display::text($r?->din),
            'Item Name' => Display::text($r?->item_description),
            'Strength' => Display::text($r?->strength),
            'Dosage Form' => Display::text($r?->dosage_form),
            'Qty Requested' => self::withUnit(Display::quantity($r?->quantity_requested), $unit),
            'Unit of Measure' => Display::text($r?->unit_of_measure),
            'Contract Reference' => Display::text($r?->tender_reference),
            'Clinical Importance (Purpose of Drugs)' => Display::text($r?->clinical_note),
        ])];
    }

    /**
     * V2-5: Details of Variation Requested as a table ("Variation Row N"; an
     * empty value is a dash; no rows is "None entered"). Only reached when the
     * section is visible (the answer is Yes): hidden rows are never shown.
     */
    private static function variation(ProcurementCase $case): array
    {
        return [self::table('Details of Variation Requested', 'Variation Row', [
            'variation_type' => 'Variation Type',
            'existing_detail' => 'Existing Approved Detail',
            'proposed_detail' => 'Proposed Revised Detail',
            'effective_detail' => 'Effective Date / Application P.O.',
        ], $case->variationRows->map(fn (CaseVariationRow $v) => [
            'variation_type' => Display::text($v->variation_type),
            'existing_detail' => Display::text($v->existing_detail),
            'proposed_detail' => Display::text($v->proposed_detail),
            'effective_detail' => Display::text($v->effective_detail),
        ])->values()->all())];
    }

    private static function awards(ProcurementCase $case): array
    {
        $totals = $case->tenderAwards->map(fn (CaseTenderAward $a) => $a->total())->filter(fn ($t) => $t !== null);

        $table = self::table('Awarded Suppliers', 'Supplier Row', [
            'supplier_name' => 'Supplier',
            'manufacturer' => 'Manufacturer',
            'brand' => 'Brand',
            'country_of_origin' => 'Country of Origin',
            'quantity_awarded' => 'Qty Awarded',
            'unit_cost' => 'Unit Cost',
            'percentage' => '% awarded',
            'line_cost' => 'Total Cost',
        ], $case->tenderAwards->map(fn (CaseTenderAward $a) => [
            'supplier_name' => Display::text($a->supplier_name),
            'manufacturer' => Display::text($a->manufacturer),
            'brand' => Display::text($a->brand),
            'country_of_origin' => Display::text($a->country_of_origin),
            'quantity_awarded' => Display::quantity($a->quantity_awarded),
            'unit_cost' => Display::price($a->unit_cost),
            'percentage' => Display::percent($a->percentage_awarded), // typed by the vendor (2026-11-24)
            'line_cost' => Display::money($a->total()),
        ])->values()->all());

        return [
            self::fields([
                'No. of Suppliers Awarded' => (string) $case->suppliersAwarded(),
                'Total Cost of the Awards' => $totals->isEmpty()
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
            self::table('Orders', 'Order Row', [
                'supplier' => 'Supplier',
                'po_number' => 'P.O #', // Kyle, 2026-11-26
                'quantity_ordered' => 'Qty Ordered',
                'ordered_on' => 'Date Ordered',
                'quantity_delivered' => 'Qty Delivered',
                'delivery_status' => 'Delivery Status',
                'delivery_date' => 'Delivery Date',
                'delivery_note' => 'Delivery Note No.', // 2026-11-25
                'quantity_outstanding' => 'Qty Outstanding',
            ], $case->purchaseOrders->map(fn (CasePurchaseOrder $po) => [
                'supplier' => Display::text($suppliers[$po->award_id] ?? null),
                'po_number' => Display::text($po->po_number),
                'quantity_ordered' => Display::quantity($po->quantity_ordered),
                'delivery_note' => Display::text($po->delivery_note),
                'ordered_on' => self::date($po->ordered_on),
                'quantity_delivered' => Display::quantity($po->quantity_delivered),
                // 2026-11-23: the status dropdown and delivery date; a row saved before them
                // (and not mapped by the migration) shows its old values instead.
                // 2026-11-25: Other shows what the vendor wrote for it.
                'delivery_status' => $po->delivery_state === DeliveryState::OTHER
                    ? Display::text($po->delivery_status)
                    : ($po->delivery_state?->label() ?? Display::text($po->delivery_status)),
                'delivery_date' => self::date($po->delivery_date ?? $po->delivery_eta),
                'quantity_outstanding' => Display::quantity($po->quantityOutstanding()),
            ])->values()->all()),
            self::fields(['Issues Affecting the Tender Qty' => Display::text($case->requisition?->tender_issue_explanation)]),
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
            // Kyle, 2026-11-24: the consumption and the quantity on hand are quantities only (no unit of measure).
            'Average Monthly Consumption' => Display::quantity($r?->average_monthly_consumption),
            'Qty on Order' => self::withUnit(Display::quantity($r?->quantity_on_order), $unit),
            'No Stock on Hand' => Display::yesNo($noStock),
            'Qty on Hand' => Display::quantity($case->quantityOnHand()),
            'Days on Hand' => $risk['days_on_hand'] ?? Display::EMPTY, // 2 dp (Kyle, 2026-11-26)
        ])];

        // v0.4: stated plainly on the review page and staff pages, not only as a Yes/No field.
        if ($noStock) {
            $blocks[] = ['type' => 'warning', 'title' => self::NO_STOCK_TITLE, 'lines' => [self::NO_STOCK_LINE]];
        }

        if (! $noStock) {
            $blocks[] = self::table('Stock Items', 'Item', [
                // Kyle, 2026-11-26: a stock item is its drug name and expiry date (the quantity on hand is typed above).
                'item_name' => 'Drug Name',
                'expiry_date' => 'Expiry Date',
            ], $case->stockExpiries->map(fn (CaseStockExpiry $b) => [
                'item_name' => Display::text($b->item_name),
                'expiry_date' => self::date($b->expiry_date),
            ])->values()->all());
        }

        $lines = [];
        foreach ($risk['expiry_warnings'] as $batchId => $warning) {
            $date = self::date($case->stockExpiries->firstWhere('id', $batchId)?->expiry_date);
            $lines[] = $warning['state'] === ExpiryRisk::EXPIRED
                ? "Item expiring {$date} has expired"
                : "Item expiring {$date}: about ".Display::wholeDays($warning['cover_days'])." days of stock on hand, and it expires in {$warning['days_until_expiry']} days (the earliest to expire)";
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
                'RFQ Sent To' => self::enumLabel($r?->rfq_audience),
                'No. of Quotations' => (string) $case->quotationsReceived(),
                'Quotations Considered' => (string) $case->quotationsConsidered(),
                'Recommended Quotation' => Display::text($recommended?->supplier_name),
                'Recommended Unit Price' => Display::price($recommended?->unit_price),
                // v0.4: the recommended quotations' Total Price, which includes VAT.
                'Total Cost (incl. VAT)' => Display::money($case->totalCost()),
            ]),
            self::table('Quotations', 'Quotation Row', [
                // Kyle, 2026-11-26: no Considered column (the form no longer asks it).
                'recommended' => 'Recommended',
                'supplier_name' => 'Supplier',
                'quantity_offered' => 'Qty',
                'unit_price' => 'Unit Price',
                // 2026-11-23: computed, in the form's column order.
                'subtotal' => 'Subtotal',
                'vat' => 'VAT',
                'total_price' => 'Total Price Incl. VAT',
                'ex_stock' => 'Ex Stock',
                'lead_time_days' => 'Lead Time (Days)',
                'brand' => 'Brand',
                'manufacturer' => 'Manufacturer',
                'country_of_origin' => 'Country of Origin',
                'remarks' => 'Remarks',
                'reason' => 'Reason for Justification',
            ], $case->quotations->map(fn (CaseQuotation $q) => [
                'recommended' => $q->is_recommended ? 'Yes' : Display::EMPTY,
                'supplier_name' => Display::text($q->supplier_name),
                'quantity_offered' => Display::quantity($q->quantity_offered),
                'unit_price' => Display::price($q->unit_price),
                'subtotal' => Display::money($q->subtotal()),
                'vat' => Display::money($q->vatAmount()),
                'total_price' => Display::money($q->totalPrice()),
                // 2026-11-24: a quantity; a row saved before then shows its old yes/no.
                'ex_stock' => $q->quantity_available_now !== null
                    ? self::withUnit(Display::quantity($q->quantity_available_now), $unit)
                    : Display::yesNo($q->ex_stock === null ? null : (bool) $q->ex_stock),
                'lead_time_days' => Display::text($q->lead_time_days),
                'brand' => Display::text($q->brand),
                'manufacturer' => Display::text($q->manufacturer),
                'country_of_origin' => Display::text($q->country_of_origin),
                'remarks' => Display::text($q->remarks),
                // The recommended quotation's justification (a row saved before 2026-11-26 as not considered keeps its reason in the letter).
                'reason' => $q->is_recommended ? Display::text($q->reason_accepted_excluded) : Display::EMPTY,
            ])->values()->all()),
        ];

        // The optional price comparison, only once something is entered in it (as on the form).
        // 2026-11-23: the tender and previous price only where the type shows them (Tender Excess).
        $prices = $r !== null && ! in_array('tender_price', RequestSections::hiddenFields((string) $case->procurementType?->code), true);
        if ($r !== null && (($prices && ($r->tender_price !== null || $r->previous_price !== null)) || $r->quoted_price_acceptable !== null || filled($r->price_note))) {
            $blocks[] = self::fields([
                ...($prices ? [
                    'Tender Price' => Display::price($r->tender_price),
                    'Previous Price' => Display::price($r->previous_price),
                    'Change Against Tender Price' => Display::percent($case->priceChangePercent($r->tender_price)),
                    'Change Against Previous Price' => Display::percent($case->priceChangePercent($r->previous_price)),
                ] : []),
                'Quoted Price Is Acceptable' => Display::yesNo($r->quoted_price_acceptable === null ? null : (bool) $r->quoted_price_acceptable),
                'Note on the Price' => Display::text($r->price_note),
            ]);
        }

        return $blocks;
    }

    /** Current documents the viewer may see (vendor: the document gate; staff: CLEAN only too, as StaffCaseDetail). */
    /** $forPrint (2026-11-24): the request as filled, so external documents only, for staff too, and no Visibility column. */
    private static function documents(ProcurementCase $case, User $viewer, bool $forStaff, bool $forPrint = false): array
    {
        $gate = Gate::forUser($viewer);
        $labels = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->get(['purpose', 'label'])
            ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->label]);
        $forStaff = $forStaff && ! $forPrint; // the Visibility column and the CLEAN filter below

        $documents = Document::withoutGlobalScopes()->current()
            ->where('case_id', $case->getKey())
            ->when($forPrint, fn ($q) => $q->where('visibility', DocumentVisibility::EXTERNAL->value)->where('scan_status', ScanStatus::CLEAN->value))
            ->when($forStaff, fn ($q) => $q->where('scan_status', ScanStatus::CLEAN->value))
            ->orderBy('uploaded_at')->orderBy('id')
            ->get()
            ->filter(fn (Document $d) => $gate->allows('view', $d))
            ->values();

        return [self::table('Documents', 'Document', [
            'name' => 'Document',
            'category' => 'Category',
            'uploaded' => 'Uploaded',
            'scan_status' => 'Scan Status',
            ...($forStaff ? ['visibility' => 'Visibility'] : []),
        ], $documents->map(fn (Document $d) => [
            'name' => (string) $d->original_name,
            'category' => $labels[$d->purpose->value] ?? Str::headline($d->purpose->value),
            'uploaded' => self::date($d->uploaded_at?->copy()->setTimezone(self::TIMEZONE)),
            'scan_status' => $d->scan_status->label(),
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
