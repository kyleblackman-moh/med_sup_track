<?php

namespace App\Submissions;

use App\Enums\TenderOutcome;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What an AD_HOC request needs before it can be submitted (adhoc-form-spec,
 * slices F1 and F2). Every rule is here, so the interim list is easy to change
 * once the CMO's office confirms it. Documents (at least one CLEAN Bids file)
 * are checked by SubmissionReadiness from the type's document configuration.
 *
 * Rows: a completely blank row never reaches here (SaveRequisition drops it).
 * Every saved row must be complete; a partly filled row gets one error per
 * missing field, keyed "<section>.<index>.<field>" (index in saved order).
 *
 * Optional: strength, dosage form, DIN, clinical note, stock location, the
 * price comparison, and the regulatory and evidence files.
 *
 * Read-only. Errors are keyed like the payload, so the form can link to the
 * field; SECTION_FIELDS groups them by form section.
 */
final class AdHocReadiness
{
    /** Request fields that must be filled in: field => message. */
    public const REQUIRED_FIELDS = [
        'item_description' => 'Describe the item.',
        'unit_of_measure' => 'Enter the unit of measure.',
        'tender_reference' => 'Enter the tender period or reference.',
        'tender_outcome' => 'Choose the tender outcome.',
        'average_monthly_consumption' => 'Enter the average monthly consumption (0 if none).',
        'rfq_audience' => 'Choose who received the RFQ.',
    ];

    /** Request quantities that must be greater than zero: field => message. */
    public const POSITIVE_FIELDS = [
        'quantity_requested' => 'Enter a quantity requested greater than zero.',
        'quantity_to_purchase' => 'Enter a quantity to purchase greater than zero.',
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

    public const AWARD_ROW_NEEDED = 'Add at least one award row.';

    public const STOCK_NEEDED = 'Add a stock batch, or tick "No stock on hand".';

    public const QUOTATION_NEEDED = 'Add at least one quotation.';

    public const RECOMMEND_ONE = 'Choose the recommended quotation.';

    public const RECOMMEND_ONLY_ONE = 'Recommend only one quotation.';

    /** Section => the request fields shown in it. Child sections map by their own key. */
    public const SECTION_FIELDS = [
        'item' => ['item_description', 'strength', 'dosage_form', 'din', 'quantity_requested', 'unit_of_measure', 'clinical_note'],
        'tender' => ['tender_reference', 'tender_outcome', 'tender_awards', 'purchase_orders', 'tender_issue_explanation'],
        'stock' => ['stock_location', 'average_monthly_consumption', 'no_stock_on_hand', 'stock_expiries'],
        'quotations' => ['rfq_audience', 'quotations', 'quantity_to_purchase'],
        'price' => ['tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note'],
        'documents' => ['documents'],
    ];

    /**
     * @param  array{quotations: Collection<int, CaseQuotation>, tender_awards: Collection<int, Model>, purchase_orders: Collection<int, Model>, stock_expiries: Collection<int, Model>}  $rows
     *                                                                                                                                                                                           rows re-indexed from 0 in saved order
     * @return list<array{key: string, message: string}>
     */
    public function errors(CaseRequisition $r, array $rows): array
    {
        $errors = [];
        $add = function (string $key, string $message) use (&$errors) {
            $errors[] = ['key' => $key, 'message' => $message];
        };

        foreach (self::REQUIRED_FIELDS as $field => $message) {
            if (self::blank($r->{$field})) {
                $add("requisition.{$field}", $message);
            }
        }

        // 0 is allowed (slice F2): an item may not have been used yet.
        if ($r->average_monthly_consumption !== null && Decimal::compare($r->average_monthly_consumption, '0') < 0) {
            $add('requisition.average_monthly_consumption', self::REQUIRED_FIELDS['average_monthly_consumption']);
        }

        foreach (self::POSITIVE_FIELDS as $field => $message) {
            if ($r->{$field} === null || Decimal::compare($r->{$field}, '0') <= 0) {
                $add("requisition.{$field}", $message);
            }
        }

        // 2a and 2b apply only when something was awarded (the form hides them otherwise;
        // SubmitCase discards those rows when nothing was awarded).
        if ($r->tender_outcome !== null && $r->tender_outcome !== TenderOutcome::NOT_AWARDED) {
            if ($rows['tender_awards']->isEmpty()) {
                $add('tender_awards', self::AWARD_ROW_NEEDED);
            }
            self::checkRows('tender_awards', $rows['tender_awards'], $add);
            self::checkRows('purchase_orders', $rows['purchase_orders'], $add); // orders are optional
        }

        // 3 Stock: the batches are hidden (and ignored) when "No stock on hand" is ticked.
        if (! $r->no_stock_on_hand) {
            if ($rows['stock_expiries']->isEmpty()) {
                $add('stock_expiries', self::STOCK_NEEDED);
            }
            self::checkRows('stock_expiries', $rows['stock_expiries'], $add);
        }

        // 4 Quotations
        if ($rows['quotations']->isEmpty()) {
            $add('quotations', self::QUOTATION_NEEDED);
        }
        self::checkRows('quotations', $rows['quotations'], $add);

        $recommended = $rows['quotations']->filter(fn (CaseQuotation $q) => $q->is_recommended)->count();
        if ($recommended !== 1) {
            $add('quotations.recommended', $recommended === 0 ? self::RECOMMEND_ONE : self::RECOMMEND_ONLY_ONE);
        }

        foreach ($rows['quotations'] as $i => $q) {
            if (! $q->considered && self::blank($q->reason_accepted_excluded)) {
                $add("quotations.{$i}.reason_accepted_excluded", 'Quotation '.($i + 1).': give the reason it was not considered.');
            }
        }

        return $errors;
    }

    /** The form section a readiness key belongs to (item, tender, stock, quotations, price, documents). */
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
