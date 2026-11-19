<?php

namespace App\Submissions;

use App\Models\ProcurementType;
use Illuminate\Support\Str;

/**
 * FORM-ERR-1 (C): the validation messages for a request save, in the words the
 * form shows. A field is named by its label ("Quantity Requested"); a row
 * column by the row and the column header exactly as the table shows them
 * ("Quotation Row 1: Quantity must be a number, for example 2800."). No
 * dotted key and no snake_case reaches the user (RequisitionMessagesTest).
 *
 * Messages are keyed by rule, with "*" for the row index; Laravel fills in
 * ":position" (the 1-based row number) and ":max".
 */
final class RequisitionMessages
{
    /** Each row section's row label, as the table shows it ("Quotation Row 1", "Batch 1"). */
    public const ROW_LABELS = [
        'quotations' => 'Quotation Row',
        'tender_awards' => 'Supplier Row',
        'purchase_orders' => 'Order Row',
        'stock_expiries' => 'Batch',
        'variation_rows' => 'Variation Row',
    ];

    /** Each row section as a whole, for list-level messages. */
    public const SECTION_LABELS = [
        'quotations' => 'Quotations',
        'tender_awards' => 'Awarded Suppliers',
        'purchase_orders' => 'Orders',
        'stock_expiries' => 'Stock Batches',
        'variation_rows' => 'Details of Variation Requested',
    ];

    /** Request field labels, as the form shows them. Anything not listed is named from its key (label()). */
    public const FIELD_LABELS = [
        'din' => 'DIN',
        'item_description' => 'Item Description',
        'quantity_requested' => 'Quantity Requested',
        'unit_of_measure' => 'Unit of Measure',
        'clinical_note' => 'Clinical Importance',
        'tender_reference' => 'Contract Reference',
        'tender_issue_explanation' => 'Issues Affecting the Tender Quantity',
        'tender_cycle_start' => 'Tender Cycle Start',
        'tender_cycle_end' => 'Tender Cycle End',
        'quantity_on_order' => 'Quantity on Order',
        'stock_location' => 'Location',
        'average_monthly_consumption' => 'Average Monthly Consumption',
        'no_stock_on_hand' => 'No Stock on Hand',
        'rfq_audience' => 'RFQ Sent To',
        'quantity_to_purchase' => 'Quantity to Purchase',
        'quoted_price_acceptable' => 'Quoted Price Is Acceptable',
        'price_note' => 'Note on the Price',
        'rha_stock' => 'RHA Stock',
        'rfq_date' => 'RFQ Date',
    ];

    /** Row column headers per section, exactly as each table shows them. */
    public const COLUMN_LABELS = [
        'quotations' => [
            'supplier_name' => 'Supplier',
            'quantity_offered' => 'Quantity',
            'unit_price' => 'Unit Price',
            'lead_time_days' => 'Lead Time (Days)',
            'ex_stock' => 'Ex Stock',
            'is_recommended' => 'Recommended',
            'reason_accepted_excluded' => 'Reason Not Considered',
            'vat_rate' => 'VAT Rate',
        ],
        'tender_awards' => [
            'supplier_name' => 'Supplier',
        ],
        'purchase_orders' => [
            'award_id' => 'Supplier',
            'po_number' => 'PO Number',
            'ordered_on' => 'Date Ordered',
            'delivery_eta' => 'Delivery Date',
        ],
        'stock_expiries' => [],
        'variation_rows' => [
            'existing_detail' => 'Existing Approved Detail',
            'proposed_detail' => 'Proposed Revised Detail',
            'effective_detail' => 'Effective Date / Application P.O.',
        ],
    ];

    /** Words kept in capitals when a label is made from a key. */
    private const ACRONYMS = ['din' => 'DIN', 'rha' => 'RHA', 'rfq' => 'RFQ', 'vat' => 'VAT', 'po' => 'PO', 'eta' => 'ETA'];

    private const SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

    /** Shown for a row the page cannot have produced (a stale or forged id): the fix is a reload. */
    public const ROW_RELOAD = 'could not be saved. Reload the page and try again.';

    /** @return array<string, string> rule key => message */
    public static function for(ProcurementType $type): array
    {
        $messages = [
            'requisition.array' => 'The request '.self::ROW_RELOAD,
            'previous_request_in_cycle.boolean' => 'Answer Yes or No: was a request previously made for this drug in this current tender cycle?',
            'previous_request_in_cycle.missing' => 'This question is not part of this request type.',
        ];

        foreach (RequisitionRules::requisitionFields($type->code) as $field => $spec) {
            $messages += self::forField("requisition.{$field}", self::fieldLabel($field), $spec);
        }

        $formSections = ProcurementTypeProfile::for($type)->isAdHocForm() ? RequisitionRules::FORM_SECTIONS : [];

        foreach ([...ProcurementTypeProfile::SECTIONS, ...$formSections] as $section) {
            $title = self::SECTION_LABELS[$section];
            $row = self::ROW_LABELS[$section].' :position';

            $messages += [
                "{$section}.array" => "{$title} ".self::ROW_RELOAD,
                "{$section}.list" => "{$title} ".self::ROW_RELOAD,
                "{$section}.max" => "{$title}: add no more than :max rows.",
                "{$section}.missing" => "{$title} are not part of this request type.",
                "{$section}.*.array" => "{$row} ".self::ROW_RELOAD,
            ];

            foreach (RequisitionRules::rowFields($section, $type->code) as $column => $spec) {
                if ($column === 'id' || $column === 'client_key') {
                    // Never typed: only a stale page or a forged payload gets these wrong.
                    foreach (['integer', 'min', 'string', 'max', 'distinct'] as $rule) {
                        $messages["{$section}.*.{$column}.{$rule}"] = "{$row} ".self::ROW_RELOAD;
                    }

                    continue;
                }

                $messages += self::forField("{$section}.*.{$column}", "{$row}: ".self::columnLabel($section, $column), $spec);
            }
        }

        // The narrower DIN wording kept from V2-5.
        return [...$messages, ...RequisitionRules::MESSAGES];
    }

    /** "Quotation Row 3": a row label by section and 0-based index (SaveRequisition's own errors). */
    public static function rowLabel(string $section, int $index): string
    {
        return (self::ROW_LABELS[$section] ?? 'Row').' '.($index + 1);
    }

    public static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? self::label($field);
    }

    public static function columnLabel(string $section, string $column): string
    {
        return self::COLUMN_LABELS[$section][$column] ?? self::fieldLabel($column);
    }

    /** "average_monthly_consumption" => "Average Monthly Consumption"; acronyms stay capitals, small words lowercase. */
    private static function label(string $key): string
    {
        $words = explode('_', $key);

        return implode(' ', array_map(
            fn (string $word, int $i) => self::ACRONYMS[$word] ?? ($i > 0 && in_array($word, self::SMALL_WORDS, true) ? $word : Str::ucfirst($word)),
            $words,
            array_keys($words),
        ));
    }

    /**
     * Messages for every rule rulesFor() gives a field of this spec.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, string>
     */
    private static function forField(string $path, string $name, array $spec): array
    {
        $common = [
            "{$path}.required" => "{$name} is required.",
            "{$path}.missing" => "{$name} is not part of this request type.",
        ];

        return $common + match ($spec['type']) {
            'string', 'text' => [
                "{$path}.string" => "{$name} must be text.",
                "{$path}.max" => "{$name} must be :max characters or fewer.",
            ],
            'digits' => [
                "{$path}.string" => "{$name} must be text.",
                "{$path}.max" => "{$name} must be :max characters or fewer.",
                "{$path}.regex" => "{$name} must contain digits only.",
            ],
            'alnum' => [
                "{$path}.string" => "{$name} must be text.",
                "{$path}.max" => "{$name} must be :max characters or fewer.",
                "{$path}.regex" => "{$name} must contain letters and numbers only.",
            ],
            'qty' => [
                "{$path}.numeric" => "{$name} must be a number, for example 2800.",
                "{$path}.regex" => "{$name} must be 0 or more, with up to 2 decimal places, for example 2800.",
            ],
            'price' => [
                "{$path}.numeric" => "{$name} must be a number, for example 12.50.",
                "{$path}.regex" => "{$name} must be 0 or more, with up to 4 decimal places, for example 12.50.",
            ],
            'rate' => [
                "{$path}.numeric" => "{$name} must be a number, for example 12.5.",
                "{$path}.regex" => "{$name} must be from 0 to 100, with up to 2 decimal places, for example 12.5.",
                "{$path}.max" => "{$name} must be from 0 to 100, with up to 2 decimal places, for example 12.5.",
            ],
            'smallint' => [
                "{$path}.integer" => "{$name} must be a whole number, for example 14.",
                "{$path}.between" => "{$name} must be a whole number from 0 to 32767.",
            ],
            'date' => [
                "{$path}.date_format" => "{$name} must be a date.",
            ],
            'enum' => [
                "{$path}.enum" => "{$name} must be one of the options.",
            ],
            'bool' => [
                "{$path}.boolean" => "{$name} must be Yes or No.",
            ],
            'currency' => [
                "{$path}.string" => "{$name} must be text.",
                "{$path}.regex" => "{$name} must be a 3-letter currency code, for example TTD.",
            ],
            'rowid' => [
                // award_id: the order's supplier, chosen from the award rows.
                "{$path}.integer" => "{$name} must be one of the awarded suppliers.",
                "{$path}.min" => "{$name} must be one of the awarded suppliers.",
            ],
        };
    }
}
