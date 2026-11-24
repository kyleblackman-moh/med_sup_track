<?php

namespace App\Submissions;

use App\Enums\DeliveryState;
use App\Enums\ScanStatus;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Support\Decimal;
use App\Support\Display;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The one request form (slice V2-1): per request type, the sections it shows
 * (in order) and what must be filled in before it can be submitted. The single
 * source for SubmissionReadiness, SubmitCase's discard of hidden data, the
 * review summary and the visible-sections list sent to the form.
 *
 * Interim rules until the CMO's office confirms them. Read-only.
 *
 * Errors are keyed like the payload ("requisition.din", "tender_awards",
 * "quotations.0.unit_price", "variation_rows.0.effective_detail"), so the form can link
 * to the field; sectionOf() maps a key to its section. Rows are re-indexed
 * from 0 in saved order. A completely blank row never reaches here
 * (SaveRequisition drops it); every saved row must be complete.
 */
final class RequestSections
{
    /** Every section, in form order. */
    public const ORDER = [
        'request_type', // V2-5: the type cards, the tender cycle and the previous-request question
        'item_tender',
        'variation_details',
        'awarded_supplier',
        'existing_arrangement',
        'stock',
        'quotation_comparison',
        'documents',
    ];

    public const LABELS = [
        'request_type' => 'Request Type',
        'item_tender' => 'Item and Tender Details',
        'variation_details' => 'Details of Variation Requested',
        'awarded_supplier' => 'Awarded Supplier',
        'existing_arrangement' => 'Existing Approved Arrangement',
        'stock' => 'Stock',
        'quotation_comparison' => 'Quotation Comparison',
        'documents' => 'Documents',
    ];

    /**
     * V2-5: shown only when the previous-request question is answered Yes, for
     * any type (no longer tied to Tender Variation). Every other section is
     * shown for every type.
     */
    private const ONLY_WHEN_PREVIOUS_REQUEST = ['variation_details'];

    /**
     * What each type requires beyond the rules every type shares.
     * Documents are configured per type in procurement_type_documents.
     *
     * V2-5: the variation details are no longer per type; they are required
     * whenever the previous-request answer is Yes.
     *
     * @var array<string, array{contract_reference: bool, awarded_supplier: bool, stock: bool, quotations: bool}>
     */
    private const REQUIRES = [
        ProcurementTypeProfile::AD_HOC_EMERGENCY => ['contract_reference' => false, 'awarded_supplier' => false, 'stock' => true, 'quotations' => true],
        ProcurementTypeProfile::AD_HOC_TENDER_UNABLE => ['contract_reference' => true, 'awarded_supplier' => true, 'stock' => true, 'quotations' => true],
        ProcurementTypeProfile::AD_HOC_NOT_AWARDED => ['contract_reference' => false, 'awarded_supplier' => false, 'stock' => true, 'quotations' => true],
        ProcurementTypeProfile::TENDER_EXCESS => ['contract_reference' => true, 'awarded_supplier' => true, 'stock' => true, 'quotations' => true],
        ProcurementTypeProfile::TENDER_VARIATION => ['contract_reference' => true, 'awarded_supplier' => true, 'stock' => false, 'quotations' => false],
    ];

    /** Section => the request fields and child sections shown in it. */
    public const SECTION_FIELDS = [
        // V2-5: the type, the tender cycle and the previous-request question.
        'request_type' => ['request_type', 'tender_cycle_start', 'tender_cycle_end', 'previous_request_in_cycle'],
        'item_tender' => [
            'item_description', 'strength', 'dosage_form', 'din', 'quantity_requested', 'unit_of_measure',
            'clinical_note', 'tender_reference', 'tender_issue_explanation',
            'tender_outcome', // unused since V2-1; the old screen still shows it until V2-2
        ],
        'variation_details' => ['variation_rows'],
        'awarded_supplier' => ['tender_awards'],
        'existing_arrangement' => ['purchase_orders', 'quantity_on_order'],
        'stock' => ['stock_location', 'average_monthly_consumption', 'no_stock_on_hand', 'stock_expiries'],
        'quotation_comparison' => [
            'rfq_audience', 'quotations', 'quantity_to_purchase',
            'tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note',
        ],
        'documents' => ['documents'],
    ];

    /**
     * The columns each saved row must have: section => [row label, [field => what to enter]].
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    public const ROW_FIELDS = [
        'tender_awards' => ['Award row', [
            'supplier_name' => 'enter the supplier',
            'quantity_awarded' => 'enter the quantity awarded',
            'unit_cost' => 'enter the unit cost',
        ]],
        // Kyle, 2026-11-23: the delivery status too (the delivery date is required unless
        // the status is Not delivered: orders()).
        'purchase_orders' => ['Order row', [
            'award_id' => 'choose the supplier from the award rows',
            'po_number' => 'enter the PO number',
            'quantity_ordered' => 'enter the quantity ordered',
            'delivery_state' => 'choose the delivery status',
        ]],
        // The expiry date is required only on a batch with a quantity above 0 (stock()).
        'stock_expiries' => ['Stock batch', [
            'quantity' => 'enter the quantity',
            'expiry_date' => 'enter the expiry date',
        ]],
        'quotations' => ['Quotation', [
            'supplier_name' => 'enter the supplier',
            'unit_price' => 'enter the unit price',
            'quantity_offered' => 'enter the quantity offered',
        ]],
        // V2-5: every column of a variation row is required once the row is entered.
        'variation_rows' => ['Variation row', [
            'variation_type' => 'enter the variation type',
            'existing_detail' => 'enter the existing approved detail',
            'proposed_detail' => 'enter the proposed revised detail',
            'effective_detail' => 'enter the effective date or application P.O', // checkRows adds the final "."
        ]],
    ];

    /** V2-5: when the answer is Yes, at least one complete variation row. */
    public const VARIATION_ROW_NEEDED = 'Add at least one variation.';

    public const REQUEST_TYPE_NEEDED = 'Choose a request type';

    /** V2-5: the previous-request question (every type) must be answered to submit. */
    public const PREVIOUS_REQUEST_NEEDED = 'Answer whether a request was previously made for this drug in this tender cycle.';

    /** Section states (the form's progress strip and section headers): see state(). */
    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    public const OPTIONAL = 'optional';

    public const BLOCKED = 'blocked';

    /**
     * Slice V2-1c: the sections whose requirement depends on the type. Until a
     * type is chosen (NOT_SELECTED, or the old AD_HOC) they are never Complete
     * or Optional: not complete, with the note REQUEST_TYPE_NEEDED.
     * Existing arrangement is optional for every type, but is held too so an
     * empty unselected draft shows no section as done.
     */
    public const TYPE_DEPENDENT = [
        'awarded_supplier', 'existing_arrangement', 'stock', 'quotation_comparison', 'documents',
    ];

    public const AWARD_ROW_NEEDED = 'Add at least one awarded supplier.';

    /**
     * Kyle, 2026-11-23: request fields shown only for some types. Hidden ones are
     * not required, stay in the draft (the type may change back) and are cleared
     * at submit (SubmitCase), like the variation details.
     *
     * @var array<string, list<string>> field => the types that show it
     */
    public const FIELDS_FOR_TYPES = [
        'tender_price' => [ProcurementTypeProfile::TENDER_EXCESS],
        'previous_price' => [ProcurementTypeProfile::TENDER_EXCESS],
    ];

    /* Kyle, 2026-11-23: the quantity and date rules. ":n" and ":x" are filled with display quantities. */
    public const QUANTITY_OVER_REQUESTED = 'Quantity cannot be more than the quantity requested (:n).';

    public const TOTAL_OVER_REQUESTED = 'Total quantity (:x) is more than the quantity requested (:n).';

    public const ORDERED_IN_FUTURE = 'Date ordered must be today or earlier.';

    public const DELIVERED_OVER_ORDERED = 'Quantity delivered cannot be more than quantity ordered.';

    public const DELIVERY_IN_FUTURE = 'Delivery date must be today or earlier.';

    public const DELIVERY_BEFORE_ORDERED = 'Delivery date cannot be before the date ordered.';

    public const FULLY_DELIVERED_MISMATCH = 'Fully delivered needs the quantity delivered to equal the quantity ordered.';

    public const PARTIALLY_DELIVERED_MISMATCH = 'Partially delivered needs a quantity delivered above 0 and below the quantity ordered.';

    public const NOT_DELIVERED_MISMATCH = 'Not delivered needs a quantity delivered of 0.';

    /** The form's time zone: "today" for the date rules. */
    public const LOCAL_TIMEZONE = 'America/Port_of_Spain';

    public const QUOTATION_NEEDED = 'Add at least one quotation.';

    public const RECOMMEND_ONE = 'Choose the recommended quotation.';

    public const RECOMMEND_ONLY_ONE = 'Recommend only one quotation.';

    public const CYCLE_END_BEFORE_START = 'The tender cycle end cannot be before its start.';

    /**
     * The sections a type shows, in form order, with whether each is optional
     * for that type. Unknown types (the old AD_HOC) show every shared section.
     *
     * Slice V2-1c: while no type is chosen, a type-dependent section is never
     * optional and carries the note "Choose a request type" (else note null).
     *
     * Documents are optional when the type requires no file ($documentsRequired,
     * from procurement_type_documents: see documentsRequired()).
     *
     * V2-5: $previousRequest is the case's previous-request answer (null =
     * not answered); Details of Variation Requested shows only when it is true.
     *
     * @return list<array{key: string, label: string, optional: bool, note: string|null}>
     */
    public static function visible(string $code, bool $documentsRequired = true, ?bool $previousRequest = null): array
    {
        $sections = [];
        foreach (self::ORDER as $key) {
            if (self::isVisible($code, $key, $previousRequest)) {
                $waiting = self::awaitsType($code, $key);
                $sections[] = [
                    'key' => $key,
                    'label' => self::LABELS[$key],
                    'optional' => ! $waiting && self::isOptional($code, $key, $documentsRequired),
                    'note' => $waiting ? self::REQUEST_TYPE_NEEDED : null,
                ];
            }
        }

        return $sections;
    }

    /**
     * Slice V2-2: per section, whether each field the form shows must be filled
     * in before a draft of $code can be submitted (true) or may stay empty
     * (false; the form marks true fields aria-required). Read from the same REQUIRES
     * and ROW_FIELDS that errors() checks. Row columns are keyed
     * "<section>.<column>" and are required whenever a row is entered (V2-5:
     * the variation rows too, "variation_rows.<column>"). Until a type is
     * chosen (V2-1c) nothing that depends on the type is optional.
     *
     * Two fields sit in a different section on the V2-2 screen than in
     * SECTION_FIELDS: the tender issues text (under Existing arrangement) and
     * the quantity on order (under Stock). Neither has a rule.
     *
     * @return array<string, array<string, bool>>
     */
    public static function requiredFields(string $code): array
    {
        $requires = self::REQUIRES[$code] ?? null;
        $byType = fn (string $flag): bool => $requires === null || $requires[$flag];
        $rows = function (string $section, array $columns): array {
            $out = [];
            foreach ($columns as $column) {
                $out["{$section}.{$column}"] = array_key_exists($column, self::ROW_FIELDS[$section][1]);
            }

            return $out;
        };

        return [
            'request_type' => [
                'tender_cycle_start' => true,
                'tender_cycle_end' => true,
                'previous_request_in_cycle' => true,
            ],
            'item_tender' => [
                'item_description' => true,
                'strength' => false,
                'dosage_form' => false,
                'din' => false, // V2-5: optional for every type
                'quantity_requested' => true,
                'unit_of_measure' => true,
                'tender_reference' => $byType('contract_reference'),
                'clinical_note' => false,
            ],
            // V2-5: shown only when the answer is Yes; every column of an entered row is required.
            'variation_details' => $rows('variation_rows', ['variation_type', 'existing_detail', 'proposed_detail', 'effective_detail']),
            'awarded_supplier' => $rows('tender_awards', ['supplier_name', 'manufacturer', 'brand', 'country_of_origin', 'quantity_awarded', 'unit_cost']),
            'existing_arrangement' => [
                // The delivery date is required unless the status is Not delivered (false here: conditional).
                ...$rows('purchase_orders', ['award_id', 'po_number', 'quantity_ordered', 'ordered_on', 'quantity_delivered', 'delivery_state', 'delivery_date']),
                'tender_issue_explanation' => false,
            ],
            'stock' => [
                'stock_location' => false,
                'average_monthly_consumption' => $byType('stock'),
                'quantity_on_order' => false,
                ...$rows('stock_expiries', ['quantity', 'expiry_date']),
            ],
            'quotation_comparison' => [
                'rfq_audience' => false,
                ...$rows('quotations', ['supplier_name', 'quantity_offered', 'unit_price', 'lead_time_days', 'brand', 'manufacturer', 'country_of_origin', 'remarks']),
                // Asked only on a row that was not considered, and then required.
                'quotations.reason_accepted_excluded' => true,
                'quantity_to_purchase' => $byType('quotations'),
                // Kyle, 2026-11-23: Tender Excess only; left out of the map where hidden.
                ...array_fill_keys(array_values(array_diff(['tender_price', 'previous_price'], self::hiddenFields($code))), false),
                'price_note' => false,
            ],
            'documents' => [],
        ];
    }

    /** True for a type-dependent section of a draft whose type is not one of the five (V2-1c). */
    public static function awaitsType(string $code, string $section): bool
    {
        return ! isset(self::REQUIRES[$code]) && in_array($section, self::TYPE_DEPENDENT, true);
    }

    /** V2-5: the variation section depends on the previous-request answer only, never on the type. */
    public static function isVisible(string $code, string $section, ?bool $previousRequest = null): bool
    {
        return ! in_array($section, self::ONLY_WHEN_PREVIOUS_REQUEST, true) || $previousRequest === true;
    }

    /**
     * Hidden sections whose saved data SubmitCase discards at submit. Data in a
     * hidden section stays in the draft until then (the answer may change back).
     *
     * @return list<string>
     */
    public static function discardedAtSubmit(string $code, ?bool $previousRequest = null): array
    {
        return array_values(array_filter(self::ONLY_WHEN_PREVIOUS_REQUEST, fn (string $s) => ! self::isVisible($code, $s, $previousRequest)));
    }

    /**
     * Kyle, 2026-11-23: the request fields $code does not show (FIELDS_FOR_TYPES).
     * Never required; SubmitCase clears them at submit.
     *
     * @return list<string>
     */
    public static function hiddenFields(string $code): array
    {
        return array_keys(array_filter(self::FIELDS_FOR_TYPES, fn (array $types) => ! in_array($code, $types, true)));
    }

    /** A section with nothing in it that blocks submission for this type. */
    private static function isOptional(string $code, string $section, bool $documentsRequired): bool
    {
        $requires = self::REQUIRES[$code] ?? null;

        return match ($section) {
            'request_type', 'item_tender' => false,
            'variation_details' => false, // V2-5: shown only when the answer is Yes, and then required
            'awarded_supplier' => ! ($requires['awarded_supplier'] ?? false),
            'existing_arrangement' => true, // orders are always optional
            'stock' => ! ($requires['stock'] ?? false),
            'quotation_comparison' => ! ($requires['quotations'] ?? false),
            'documents' => ! $documentsRequired, // per type (procurement_type_documents); shown either way
        };
    }

    /** Whether the type requires at least one file (procurement_type_documents.is_required). */
    public static function documentsRequired(?int $procurementTypeId): bool
    {
        return $procurementTypeId !== null && ProcurementTypeDocument::where('procurement_type_id', $procurementTypeId)
            ->where('is_required', true)->exists();
    }

    /**
     * The one section-state rule. A section waiting for the type is blocked; one
     * with issues is incomplete. An optional section (nothing in it is required
     * for this type) reports "optional" while it holds no data, and complete
     * only once it holds valid data. A section with required content is
     * complete when that content is satisfied (no issues).
     */
    public static function state(?string $note, bool $hasIssues, bool $optional, bool $hasData): string
    {
        return match (true) {
            $note !== null => self::BLOCKED,
            $hasIssues => self::INCOMPLETE,
            $optional && ! $hasData => self::OPTIONAL,
            default => self::COMPLETE,
        };
    }

    /**
     * Whether the section holds anything the vendor entered, grouped as the
     * form shows it (the tender issues text under Existing arrangement, the
     * quantity on order under Stock). Documents count current, clean files of
     * the type's purposes. Reads the case's relations (loaded or lazily).
     */
    public static function hasData(ProcurementCase $case, string $section): bool
    {
        $r = $case->requisition;
        $filled = fn (array $fields): bool => $r !== null
            && collect($fields)->contains(fn (string $field) => ! self::blank($r->{$field}));

        return match ($section) {
            'request_type' => isset(self::REQUIRES[(string) $case->procurementType?->code])
                || $case->previous_request_in_cycle !== null || $filled(['tender_cycle_start', 'tender_cycle_end']),
            'item_tender' => $filled([
                'item_description', 'strength', 'dosage_form', 'din', 'quantity_requested', 'unit_of_measure',
                'clinical_note', 'tender_reference',
            ]),
            'variation_details' => $case->variationRows->isNotEmpty(),
            'awarded_supplier' => $case->tenderAwards->isNotEmpty(),
            'existing_arrangement' => $case->purchaseOrders->isNotEmpty() || $filled(['tender_issue_explanation']),
            // Not no_stock_on_hand: the server sets it from the batch total (an empty draft has it).
            'stock' => $case->stockExpiries->isNotEmpty()
                || $filled(['stock_location', 'average_monthly_consumption', 'quantity_on_order']),
            'quotation_comparison' => $case->quotations->isNotEmpty() || $filled([
                'rfq_audience', 'quantity_to_purchase', 'tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note',
            ]),
            'documents' => Document::withoutGlobalScopes()->current()
                ->where('case_id', $case->getKey())
                ->where('scan_status', ScanStatus::CLEAN)
                ->whereIn('purpose', ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)->select('purpose'))
                ->exists(),
            default => false,
        };
    }

    /** The section a readiness key belongs to; null when it maps to none. */
    public static function sectionOf(string $key): ?string
    {
        $parts = explode('.', $key);
        $field = $parts[0] === 'requisition' ? ($parts[1] ?? '') : $parts[0];

        foreach (self::SECTION_FIELDS as $section => $fields) {
            if (in_array($field, $fields, true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Content errors for a draft of $code (documents are checked by
     * SubmissionReadiness from the type's document configuration).
     *
     * @param  array{quotations: Collection<int, CaseQuotation>, tender_awards: Collection<int, Model>, purchase_orders: Collection<int, Model>, stock_expiries: Collection<int, Model>, variation_rows: Collection<int, Model>}  $rows
     * @return list<array{key: string, message: string}>
     */
    public function errors(string $code, CaseRequisition $r, array $rows, ?bool $previousRequest = null): array
    {
        $errors = [];
        $add = function (string $key, string $message) use (&$errors) {
            $errors[] = ['key' => $key, 'message' => $message];
        };

        // A draft whose type is not one of the five (NOT_SELECTED, or the old AD_HOC) must choose one
        // first: the items every type needs, plus this one error. Per-type rules apply once chosen.
        $requires = self::REQUIRES[$code] ?? null;
        if ($requires === null) {
            $add('request_type', self::REQUEST_TYPE_NEEDED);
            $requires = ['contract_reference' => false, 'awarded_supplier' => false, 'stock' => false, 'quotations' => false];
        }

        $this->requestType($r, $previousRequest, $add);
        $this->itemAndTender($r, $requires['contract_reference'], $add);

        // V2-5: when the answer is Yes (any type), at least one row, and every saved row
        // complete; hidden and ignored otherwise (and discarded at submit).
        if (self::isVisible($code, 'variation_details', $previousRequest)) {
            if ($rows['variation_rows']->isEmpty()) {
                $add('variation_rows', self::VARIATION_ROW_NEEDED);
            }
            self::checkRows('variation_rows', $rows['variation_rows'], $add);
        }

        // Awarded supplier: at least one complete row when required; every saved row complete.
        if ($requires['awarded_supplier'] && $rows['tender_awards']->isEmpty()) {
            $add('tender_awards', self::AWARD_ROW_NEEDED);
        }
        self::checkRows('tender_awards', $rows['tender_awards'], $add);

        // Existing arrangement: orders are optional; an order that exists must be complete and consistent.
        $today = now(self::LOCAL_TIMEZONE)->toDateString();
        self::checkRows('purchase_orders', $rows['purchase_orders'], $add);
        $this->orders($rows['purchase_orders'], $today, $add);

        $this->stock($r, $rows['stock_expiries'], $requires['stock'], $add);
        $this->quotations($r, $rows['quotations'], $requires['quotations'], $add);

        return $errors;
    }

    /** V2-5: the tender cycle and the previous-request answer, required for every type. */
    private function requestType(CaseRequisition $r, ?bool $previousRequest, callable $add): void
    {
        if ($r->tender_cycle_start === null) {
            $add('requisition.tender_cycle_start', 'Enter the start of the tender cycle.');
        }

        if ($r->tender_cycle_end === null) {
            $add('requisition.tender_cycle_end', 'Enter the end of the tender cycle.');
        } elseif ($r->tender_cycle_start !== null && $r->tender_cycle_end->lt($r->tender_cycle_start)) {
            $add('requisition.tender_cycle_end', self::CYCLE_END_BEFORE_START);
        }

        if ($previousRequest === null) {
            $add('previous_request_in_cycle', self::PREVIOUS_REQUEST_NEEDED);
        }
    }

    /** Required for every type, plus the contract reference where the type needs it. */
    private function itemAndTender(CaseRequisition $r, bool $contractReference, callable $add): void
    {
        if (self::blank($r->item_description)) {
            $add('requisition.item_description', 'Describe the item.');
        }

        // V2-5: the DIN is optional (its format is checked at save, RequisitionRules).

        if ($r->quantity_requested === null || Decimal::compare($r->quantity_requested, '0') <= 0) {
            $add('requisition.quantity_requested', 'Enter a quantity requested greater than zero.');
        }

        if (self::blank($r->unit_of_measure)) {
            $add('requisition.unit_of_measure', 'Enter the unit of measure.');
        }

        if ($contractReference && self::blank($r->tender_reference)) {
            $add('requisition.tender_reference', 'Enter the contract reference.');
        }
    }

    /**
     * Kyle, 2026-11-23: each saved order row. The date ordered and the delivery
     * date are not after today (Port of Spain); the delivery date is required
     * unless the status is Not delivered, and not before the date ordered; the
     * quantity delivered (blank = 0) is not above the quantity ordered and agrees
     * with the status. Missing values are left to checkRows.
     */
    private function orders(Collection $orders, string $today, callable $add): void
    {
        foreach ($orders as $i => $po) {
            $row = 'purchase_orders.'.$i;
            $label = RequisitionMessages::rowLabel('purchase_orders', (int) $i).': ';
            $ordered = $po->ordered_on?->toDateString();
            $delivered = $po->quantity_delivered ?? '0';
            $state = $po->delivery_state;

            if ($ordered !== null && $ordered > $today) {
                $add("{$row}.ordered_on", $label.self::ORDERED_IN_FUTURE);
            }

            $over = $po->quantity_ordered !== null && Decimal::compare($delivered, $po->quantity_ordered) > 0;
            if ($over) {
                $add("{$row}.quantity_delivered", $label.self::DELIVERED_OVER_ORDERED);
            }

            if ($state !== null && $state !== DeliveryState::NOT_DELIVERED && $po->delivery_date === null) {
                $add("{$row}.delivery_date", $label.RequisitionMessages::columnLabel('purchase_orders', 'delivery_date').' is required.');
            }

            $date = $po->delivery_date?->toDateString();
            if ($date !== null && $date > $today) {
                $add("{$row}.delivery_date", $label.self::DELIVERY_IN_FUTURE);
            } elseif ($date !== null && $ordered !== null && $date < $ordered) {
                $add("{$row}.delivery_date", $label.self::DELIVERY_BEFORE_ORDERED);
            }

            // The status against the quantities (once the quantity ordered is in and not exceeded).
            if ($state === null || $po->quantity_ordered === null || $over) {
                continue;
            }
            $mismatch = match ($state) {
                DeliveryState::FULLY_DELIVERED => Decimal::compare($delivered, $po->quantity_ordered) !== 0 ? self::FULLY_DELIVERED_MISMATCH : null,
                DeliveryState::PARTIALLY_DELIVERED => Decimal::compare($delivered, '0') <= 0 || Decimal::compare($delivered, $po->quantity_ordered) >= 0
                    ? self::PARTIALLY_DELIVERED_MISMATCH : null,
                DeliveryState::NOT_DELIVERED => Decimal::compare($delivered, '0') !== 0 ? self::NOT_DELIVERED_MISMATCH : null,
            };
            if ($mismatch !== null) {
                $add("{$row}.delivery_state", $label.$mismatch);
            }
        }
    }

    /**
     * Kyle, 2026-11-23: required, the average monthly consumption (0 allowed).
     * Quantity on hand is the batch total: with a total of 0 (no batches, or
     * every quantity 0) the case has no stock on hand (set by the server, see
     * StockOnHand) and no batch is needed. Every batch that is entered needs its
     * quantity, and an expiry date when its quantity is above 0.
     */
    private function stock(CaseRequisition $r, Collection $batches, bool $required, callable $add): void
    {
        if ($required && $r->average_monthly_consumption === null) {
            $add('requisition.average_monthly_consumption', 'Enter the average monthly consumption (0 if none).');
        }

        foreach ($batches as $i => $batch) {
            $label = RequisitionMessages::rowLabel('stock_expiries', (int) $i).': ';
            if ($batch->quantity === null) {
                $add("stock_expiries.{$i}.quantity", $label.RequisitionMessages::columnLabel('stock_expiries', 'quantity').' is required.');
            } elseif (Decimal::compare($batch->quantity, '0') > 0 && $batch->expiry_date === null) {
                $add("stock_expiries.{$i}.expiry_date", $label.RequisitionMessages::columnLabel('stock_expiries', 'expiry_date').' is required.');
            }
        }
    }

    /**
     * Required: at least one complete row, exactly one recommended, the quantity
     * to purchase, and a reason on every row not considered. When optional, the
     * rows that are entered must still be complete, with reasons, and at most
     * one recommended.
     */
    private function quotations(CaseRequisition $r, Collection $quotations, bool $required, callable $add): void
    {
        if ($required && $quotations->isEmpty()) {
            $add('quotations', self::QUOTATION_NEEDED);
        }
        self::checkRows('quotations', $quotations, $add);

        $recommended = $quotations->filter(fn (CaseQuotation $q) => $q->is_recommended)->count();
        if ($recommended > 1) {
            $add('quotations.recommended', self::RECOMMEND_ONLY_ONE);
        } elseif ($required && $recommended === 0) {
            $add('quotations.recommended', self::RECOMMEND_ONE);
        }

        if ($required && ($r->quantity_to_purchase === null || Decimal::compare($r->quantity_to_purchase, '0') <= 0)) {
            $add('requisition.quantity_to_purchase', 'Enter a quantity to purchase greater than zero.');
        }

        // Kyle, 2026-11-23: no quotation row, and not the total being purchased (the
        // quantity to purchase), may be above the quantity requested. Quotation
        // comparison only: award quantities are tender quantities and may exceed it.
        $requested = $r->quantity_requested;
        if ($requested !== null && Decimal::compare($requested, '0') > 0) {
            $n = Display::quantity($requested);
            if ($r->quantity_to_purchase !== null && Decimal::compare($r->quantity_to_purchase, $requested) > 0) {
                $add('requisition.quantity_to_purchase', strtr(self::TOTAL_OVER_REQUESTED, [':x' => Display::quantity($r->quantity_to_purchase), ':n' => $n]));
            }
            foreach ($quotations as $i => $q) {
                if ($q->quantity_offered !== null && Decimal::compare($q->quantity_offered, $requested) > 0) {
                    $add("quotations.{$i}.quantity_offered", RequisitionMessages::rowLabel('quotations', (int) $i).': '.strtr(self::QUANTITY_OVER_REQUESTED, [':n' => $n]));
                }
            }
        }

        foreach ($quotations as $i => $q) {
            if (! $q->considered && self::blank($q->reason_accepted_excluded)) {
                $add("quotations.{$i}.reason_accepted_excluded", RequisitionMessages::rowLabel('quotations', (int) $i).': Reason Not Considered is required.');
            }
        }
    }

    /** One error per missing field of each saved row: "Supplier Row 2: Unit Cost is required." */
    private static function checkRows(string $section, Collection $rows, callable $add): void
    {
        $fields = self::ROW_FIELDS[$section][1];

        foreach ($rows as $i => $row) {
            foreach (array_keys($fields) as $field) {
                if (self::blank($row->{$field})) {
                    // FORM-SAVE-1 (A5c): the row and column as the table shows them ("Supplier Row 1: Supplier is required.").
                    $add("{$section}.{$i}.{$field}", RequisitionMessages::rowLabel($section, (int) $i).': '.RequisitionMessages::columnLabel($section, $field).' is required.');
                }
            }
        }
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
