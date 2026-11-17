<?php

namespace App\Submissions;

use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseVariation;
use App\Support\Decimal;
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
 * "quotations.0.unit_price", "variation.effective_date"), so the form can link
 * to the field; sectionOf() maps a key to its section. Rows are re-indexed
 * from 0 in saved order. A completely blank row never reaches here
 * (SaveRequisition drops it); every saved row must be complete.
 */
final class RequestSections
{
    /** Every section, in form order. */
    public const ORDER = [
        'item_tender',
        'variation_details',
        'awarded_supplier',
        'existing_arrangement',
        'stock',
        'quotation_comparison',
        'documents',
    ];

    public const LABELS = [
        'item_tender' => 'Item and tender',
        'variation_details' => 'Variation details',
        'awarded_supplier' => 'Awarded supplier',
        'existing_arrangement' => 'Existing arrangement',
        'stock' => 'Stock',
        'quotation_comparison' => 'Quotation comparison',
        'documents' => 'Documents',
    ];

    /** Shown only for these types. Every other section is shown for every type. */
    private const ONLY_FOR = [
        'variation_details' => [ProcurementTypeProfile::TENDER_VARIATION],
    ];

    /**
     * What each type requires beyond the rules every type shares.
     * Documents are configured per type in procurement_type_documents.
     *
     * @var array<string, array{contract_reference: bool, awarded_supplier: bool, stock: bool, quotations: bool, variation: bool}>
     */
    private const REQUIRES = [
        ProcurementTypeProfile::AD_HOC_EMERGENCY => ['contract_reference' => false, 'awarded_supplier' => false, 'stock' => true, 'quotations' => true, 'variation' => false],
        ProcurementTypeProfile::AD_HOC_TENDER_UNABLE => ['contract_reference' => true, 'awarded_supplier' => true, 'stock' => true, 'quotations' => true, 'variation' => false],
        ProcurementTypeProfile::AD_HOC_NOT_AWARDED => ['contract_reference' => false, 'awarded_supplier' => false, 'stock' => true, 'quotations' => true, 'variation' => false],
        ProcurementTypeProfile::TENDER_EXCESS => ['contract_reference' => true, 'awarded_supplier' => true, 'stock' => true, 'quotations' => true, 'variation' => false],
        ProcurementTypeProfile::TENDER_VARIATION => ['contract_reference' => true, 'awarded_supplier' => true, 'stock' => false, 'quotations' => false, 'variation' => true],
    ];

    /** Section => the request fields and child sections shown in it. */
    public const SECTION_FIELDS = [
        'item_tender' => [
            'request_type', 'item_description', 'strength', 'dosage_form', 'din', 'quantity_requested', 'unit_of_measure',
            'clinical_note', 'tender_reference', 'tender_cycle_start', 'tender_cycle_end', 'tender_issue_explanation',
            'tender_outcome', // unused since V2-1; the old screen still shows it until V2-2
        ],
        'variation_details' => ['variation'],
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
        'purchase_orders' => ['Order row', [
            'award_id' => 'choose the supplier from the award rows',
            'po_number' => 'enter the PO number',
            'quantity_ordered' => 'enter the quantity ordered',
        ]],
        'stock_expiries' => ['Stock batch', [
            'quantity' => 'enter the quantity',
            'expiry_date' => 'enter the expiry date',
        ]],
        'quotations' => ['Quotation', [
            'supplier_name' => 'enter the supplier',
            'unit_price' => 'enter the unit price',
            'quantity_offered' => 'enter the quantity offered',
        ]],
    ];

    /** Variation fields that must be filled in (TENDER_VARIATION): field => message. */
    public const VARIATION_FIELDS = [
        'variation_type' => 'Enter the type of variation.',
        'existing_detail' => 'Describe the existing arrangement.',
        'proposed_detail' => 'Describe the proposed change.',
        'effective_date' => 'Enter the date the variation takes effect.',
    ];

    public const REQUEST_TYPE_NEEDED = 'Choose a request type';

    /**
     * Slice V2-1c: the sections whose requirement depends on the type. Until a
     * type is chosen (NOT_SELECTED, or the old AD_HOC) they are never Complete
     * or Optional: not complete, with the note REQUEST_TYPE_NEEDED.
     * Existing arrangement is optional for every type, but is held too so an
     * empty unselected draft shows no section as done.
     */
    public const TYPE_DEPENDENT = [
        'variation_details', 'awarded_supplier', 'existing_arrangement', 'stock', 'quotation_comparison', 'documents',
    ];

    public const AWARD_ROW_NEEDED = 'Add at least one awarded supplier.';

    public const STOCK_NEEDED = 'Add a stock batch, or tick "No stock on hand".';

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
     * @return list<array{key: string, label: string, optional: bool, note: string|null}>
     */
    public static function visible(string $code): array
    {
        $sections = [];
        foreach (self::ORDER as $key) {
            if (self::isVisible($code, $key)) {
                $waiting = self::awaitsType($code, $key);
                $sections[] = [
                    'key' => $key,
                    'label' => self::LABELS[$key],
                    'optional' => ! $waiting && self::isOptional($code, $key),
                    'note' => $waiting ? self::REQUEST_TYPE_NEEDED : null,
                ];
            }
        }

        return $sections;
    }

    /** True for a type-dependent section of a draft whose type is not one of the five (V2-1c). */
    public static function awaitsType(string $code, string $section): bool
    {
        return ! isset(self::REQUIRES[$code]) && in_array($section, self::TYPE_DEPENDENT, true);
    }

    public static function isVisible(string $code, string $section): bool
    {
        return ! isset(self::ONLY_FOR[$section]) || in_array($code, self::ONLY_FOR[$section], true);
    }

    /**
     * Hidden sections whose saved data SubmitCase discards at submit. Data in a
     * hidden section stays in the draft until then (the type may change back).
     *
     * @return list<string>
     */
    public static function discardedAtSubmit(string $code): array
    {
        return array_values(array_filter(array_keys(self::ONLY_FOR), fn (string $s) => ! self::isVisible($code, $s)));
    }

    /** A section with nothing in it that blocks submission for this type. */
    private static function isOptional(string $code, string $section): bool
    {
        $requires = self::REQUIRES[$code] ?? null;

        return match ($section) {
            'item_tender' => false,
            'variation_details' => ! ($requires['variation'] ?? false),
            'awarded_supplier' => ! ($requires['awarded_supplier'] ?? false),
            'existing_arrangement' => true, // orders are always optional
            'stock' => ! ($requires['stock'] ?? false),
            'quotation_comparison' => ! ($requires['quotations'] ?? false),
            'documents' => false, // per type (procurement_type_documents); shown either way
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
     * @param  array{quotations: Collection<int, CaseQuotation>, tender_awards: Collection<int, Model>, purchase_orders: Collection<int, Model>, stock_expiries: Collection<int, Model>}  $rows
     * @return list<array{key: string, message: string}>
     */
    public function errors(string $code, CaseRequisition $r, array $rows, ?CaseVariation $variation): array
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
            $requires = ['contract_reference' => false, 'awarded_supplier' => false, 'stock' => false, 'quotations' => false, 'variation' => false];
        }

        $this->itemAndTender($r, $requires['contract_reference'], $add);

        if ($requires['variation'] && self::isVisible($code, 'variation_details')) {
            foreach (self::VARIATION_FIELDS as $field => $message) {
                if (self::blank($variation?->{$field})) {
                    $add("variation.{$field}", $message);
                }
            }
        }

        // Awarded supplier: at least one complete row when required; every saved row complete.
        if ($requires['awarded_supplier'] && $rows['tender_awards']->isEmpty()) {
            $add('tender_awards', self::AWARD_ROW_NEEDED);
        }
        self::checkRows('tender_awards', $rows['tender_awards'], $add);

        // Existing arrangement: orders are optional; an order that exists must be complete.
        self::checkRows('purchase_orders', $rows['purchase_orders'], $add);

        $this->stock($r, $rows['stock_expiries'], $requires['stock'], $add);
        $this->quotations($r, $rows['quotations'], $requires['quotations'], $add);

        return $errors;
    }

    /** Required for every type, plus the contract reference where the type needs it. */
    private function itemAndTender(CaseRequisition $r, bool $contractReference, callable $add): void
    {
        if (self::blank($r->item_description)) {
            $add('requisition.item_description', 'Describe the item.');
        }

        // Digits only, kept as text (leading zeros); no length rule (open question).
        if (self::blank($r->din) || preg_match('/^\d+$/', (string) $r->din) !== 1) {
            $add('requisition.din', 'Enter the DIN (digits only).');
        }

        if ($r->quantity_requested === null || Decimal::compare($r->quantity_requested, '0') <= 0) {
            $add('requisition.quantity_requested', 'Enter a quantity requested greater than zero.');
        }

        if (self::blank($r->unit_of_measure)) {
            $add('requisition.unit_of_measure', 'Enter the unit of measure.');
        }

        if ($r->tender_cycle_start === null) {
            $add('requisition.tender_cycle_start', 'Enter the start of the tender cycle.');
        }

        if ($r->tender_cycle_end === null) {
            $add('requisition.tender_cycle_end', 'Enter the end of the tender cycle.');
        } elseif ($r->tender_cycle_start !== null && $r->tender_cycle_end->lt($r->tender_cycle_start)) {
            $add('requisition.tender_cycle_end', self::CYCLE_END_BEFORE_START);
        }

        if ($contractReference && self::blank($r->tender_reference)) {
            $add('requisition.tender_reference', 'Enter the contract reference.');
        }
    }

    /**
     * Required: average monthly consumption (0 allowed) and either "No stock on
     * hand" or one complete batch. Batches are ignored when "No stock on hand"
     * is ticked; a batch that is entered must be complete either way.
     */
    private function stock(CaseRequisition $r, Collection $batches, bool $required, callable $add): void
    {
        if ($required && $r->average_monthly_consumption === null) {
            $add('requisition.average_monthly_consumption', 'Enter the average monthly consumption (0 if none).');
        }

        if ($r->no_stock_on_hand) {
            return;
        }

        if ($required && $batches->isEmpty()) {
            $add('stock_expiries', self::STOCK_NEEDED);
        }
        self::checkRows('stock_expiries', $batches, $add);
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

        foreach ($quotations as $i => $q) {
            if (! $q->considered && self::blank($q->reason_accepted_excluded)) {
                $add("quotations.{$i}.reason_accepted_excluded", 'Quotation '.($i + 1).': give the reason it was not considered.');
            }
        }
    }

    /** One error per missing field of each saved row: "Award row 2: enter the unit cost." */
    private static function checkRows(string $section, Collection $rows, callable $add): void
    {
        [$label, $fields] = self::ROW_FIELDS[$section];

        foreach ($rows as $i => $row) {
            foreach ($fields as $field => $what) {
                if (self::blank($row->{$field})) {
                    $add("{$section}.{$i}.{$field}", "{$label} ".($i + 1).": {$what}.");
                }
            }
        }
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
