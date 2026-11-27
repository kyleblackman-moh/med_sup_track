<?php

namespace App\Submissions;

use App\Enums\DeliveryState;
use App\Enums\NeedClassification;
use App\Enums\PreviousPriceMaintained;
use App\Enums\RfqAudience;
use App\Enums\TenderOutcome;
use App\Models\ProcurementType;
use App\Support\Decimal;
use Illuminate\Validation\Rule;

/**
 * Validation rules for a requisition payload:
 *
 *   ['requisition' => [...], 'quotations' => [rows], 'tender_awards' => [rows],
 *    'purchase_orders' => [rows], 'stock_expiries' => [rows],
 *    'previous_request_in_cycle' => bool|null, 'variation_rows' => [rows]]   (request form only, V2-5)
 *
 * Field types, lengths and ranges follow the slice 6b columns. Every requisition
 * field is nullable (a draft saves incomplete). Child rows must carry the
 * columns the database requires. Unknown keys fail at every level
 * (unknownKeyErrors), so system columns can never be supplied.
 *
 * The request form (the five types of slice V2-1, and the old AD_HOC of slice
 * F1) has its own field sets: every field is optional at save (a draft saves
 * partial data and partial rows); completeness is checked at submit by
 * RequestSections. Child rows may carry their row id, so a save updates rows
 * in place (SaveRequisition). The old Pharm / Non-Pharm types keep their rules.
 */
final class RequisitionRules
{
    /** Named explicitly in error messages; they also fail as unknown keys. */
    public const PROHIBITED_KEYS = [
        'id', 'case_id', 'org_id', 'status', 'reference_no', 'file_no', 'current_holder_role',
        'technical_status', 'technical_status_set_by', 'technical_status_set_at',
        'technical_track', 'funding_track', 'hardcopy_received_at', 'hardcopy_received_by',
        'hardcopy_receipt_method', 'sort_order',
    ];

    /** Plain messages for rules whose default message would be unclear. */
    public const MESSAGES = [
    ];

    /**
     * V2-5: row sections of the request form only (not in ProcurementTypeProfile::SECTIONS,
     * so the old types never accept them). Saved exactly like the award rows.
     */
    public const FORM_SECTIONS = ['variation_rows', 'previous_orders']; // Kyle, 2026-11-27: Approved Tender Excesses

    public const ROW_CAPS = [
        'quotations' => 50,
        'tender_awards' => 20,
        'variation_rows' => 20, // V2-5: the award rows' cap
        'purchase_orders' => 20,
        'previous_orders' => 20, // Kyle, 2026-11-27: as the original tender orders
        'stock_expiries' => 20,
    ];

    // decimal(14,2), decimal(14,4) and decimal(5,2): non-negative, with the column's
    // integer digits and decimal places. Checked as text, so no float rounding.
    private const QTY = '/^\d{1,12}(\.\d{1,2})?$/';

    private const PRICE = '/^\d{1,10}(\.\d{1,4})?$/';

    private const RATE = '/^\d{1,3}(\.\d{1,2})?$/';

    /** @return array<string, list<mixed>> */
    public static function for(ProcurementType $type): array
    {
        $profile = ProcurementTypeProfile::for($type);
        $rules = ['requisition' => ['sometimes', 'array']];

        foreach (self::requisitionFields($type->code) as $field => $spec) {
            $rules["requisition.{$field}"] = self::rulesFor($spec);
        }

        foreach (ProcurementTypeProfile::SECTIONS as $section) {
            if (! $profile->allows($section)) {
                $rules[$section] = ['missing'];

                continue;
            }

            $rules[$section] = ['sometimes', 'array', 'list', 'max:'.self::ROW_CAPS[$section]];
            $rules["{$section}.*"] = ['array'];

            foreach (self::rowFields($section, $type->code) as $field => $spec) {
                $rules["{$section}.*.{$field}"] = self::rulesFor($spec);
            }

            if (array_key_exists('id', self::rowFields($section, $type->code))) {
                $rules["{$section}.*.id"][] = 'distinct';
                $rules["{$section}.*.client_key"][] = 'distinct';
            }
        }

        // V2-5: the previous-request answer (a case column); request form only. Null clears it.
        $rules['previous_request_in_cycle'] = $profile->isAdHocForm() ? ['sometimes', 'nullable', 'boolean'] : ['missing'];

        // V2-5: the variation rows, request form only; the same list rules as the award rows.
        foreach (self::FORM_SECTIONS as $section) {
            if (! $profile->isAdHocForm()) {
                $rules[$section] = ['missing'];

                continue;
            }

            $rules[$section] = ['sometimes', 'array', 'list', 'max:'.self::ROW_CAPS[$section]];
            $rules["{$section}.*"] = ['array'];
            foreach (self::rowFields($section, $type->code) as $field => $spec) {
                $rules["{$section}.*.{$field}"] = self::rulesFor($spec);
            }
            $rules["{$section}.*.id"][] = 'distinct';
            $rules["{$section}.*.client_key"][] = 'distinct';
        }

        return $rules;
    }

    /**
     * Errors for keys that are not in the rules, at every level.
     *
     * @return array<string, string> dotted path => message
     */
    public static function unknownKeyErrors(array $payload, ?string $code = null): array
    {
        $errors = [];
        $check = function (array $data, array $allowed, string $prefix) use (&$errors) {
            foreach (array_keys($data) as $key) {
                if (! in_array($key, $allowed, true)) {
                    // FORM-ERR-1: no key in the message (a forged or stale payload; the form never sends these).
                    $errors[$prefix.$key] = in_array($key, self::PROHIBITED_KEYS, true)
                        ? 'This field cannot be set from the form.'
                        : 'This field is not recognised.';
                }
            }
        };

        $formType = ProcurementTypeProfile::for((string) $code)->isAdHocForm();
        $check($payload, ['requisition', ...ProcurementTypeProfile::SECTIONS, ...($formType ? ['previous_request_in_cycle', ...self::FORM_SECTIONS] : [])], '');

        if (is_array($payload['requisition'] ?? null)) {
            $check($payload['requisition'], array_keys(self::requisitionFields($code)), 'requisition.');
        }

        foreach ([...ProcurementTypeProfile::SECTIONS, ...($formType ? self::FORM_SECTIONS : [])] as $section) {
            foreach (is_array($payload[$section] ?? null) ? $payload[$section] : [] as $i => $row) {
                if (is_array($row)) {
                    $check($row, array_keys(self::rowFields($section, $code)), "{$section}.{$i}.");
                }
            }
        }

        return $errors;
    }

    /**
     * FORM-SAVE-1: rules across fields, checked at every save. Each error names
     * only the field that carries it (a draft save then drops just that field).
     * Only fields present in the payload and already valid on their own are compared.
     *
     * @param  list<string>  $failed  keys that already failed their own rules
     * @return array<string, string>
     */
    public static function crossFieldErrors(array $payload, array $failed = []): array
    {
        $r = is_array($payload['requisition'] ?? null) ? $payload['requisition'] : [];
        $errors = [];

        $start = $r['tender_cycle_start'] ?? null;
        $end = $r['tender_cycle_end'] ?? null;
        if (is_string($start) && is_string($end)
            && ! in_array('requisition.tender_cycle_start', $failed, true) && ! in_array('requisition.tender_cycle_end', $failed, true)
            && $end < $start) { // Y-m-d strings compare as dates
            $errors['requisition.tender_cycle_end'] = RequisitionMessages::CYCLE_END_BEFORE_START;
        }

        return $errors;
    }

    /**
     * Field specs: type, optional max length / enum class, and the column's
     * nullability ('required' = NOT NULL without default, 'default' = NOT NULL
     * with a database default, so it may be omitted but not set to null).
     *
     * @return array<string, array{type: string, max?: int, enum?: class-string, required?: bool, default?: bool}>
     */
    public static function requisitionFields(?string $code = null): array
    {
        if (ProcurementTypeProfile::for((string) $code)->isAdHocForm()) {
            return self::adHocRequisitionFields();
        }

        return [
            'vendor_reference' => self::str(64),
            'request_date' => ['type' => 'date'],
            'need_classification' => ['type' => 'enum', 'enum' => NeedClassification::class],
            'item_description' => self::str(500),
            'din' => ['type' => 'din'], // Kyle, 2026-11-26: free text up to 32 characters (no 8-digit rule), as on the Ad Hoc form
            'item_code' => self::str(64),
            'unit_of_measure' => self::str(32),
            'purpose' => ['type' => 'text'],
            'quantity_requested' => ['type' => 'qty'],
            'average_monthly_consumption' => ['type' => 'qty'],
            'central_stores_stock' => ['type' => 'qty'],
            'rha_stock' => ['type' => 'qty'],
            'stock_verified_on' => ['type' => 'date'],
            'stockout_date' => ['type' => 'date'],
            'urgency_explanation' => ['type' => 'text'],
            'tender_reference' => self::str(120),
            'tender_outcome' => ['type' => 'enum', 'enum' => TenderOutcome::class],
            'tender_issue_explanation' => ['type' => 'text'],
            'rfq_date' => ['type' => 'date'],
            'rfq_audience' => ['type' => 'enum', 'enum' => RfqAudience::class],
            'sample_evaluation_summary' => ['type' => 'text'],
            'applicant_comments' => ['type' => 'text'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function rowFields(string $section, ?string $code = null): array
    {
        if (ProcurementTypeProfile::for((string) $code)->isAdHocForm()) {
            return self::adHocRowFields($section);
        }

        return match ($section) {
            'quotations' => [
                'supplier_name' => self::str(200, required: true),
                'manufacturer' => self::str(200),
                'brand' => self::str(200),
                'unit_price' => ['type' => 'price', 'required' => true],
                'currency' => ['type' => 'currency', 'default' => true],
                'vat_rate' => ['type' => 'rate'],
                'quantity_offered' => ['type' => 'qty', 'required' => true],
                'lead_time_days' => ['type' => 'smallint'],
                'minimum_order_quantity' => ['type' => 'qty'],
                'price_valid_until' => ['type' => 'date'],
                'quantity_available_now' => ['type' => 'qty'],
                'previous_price_maintained' => ['type' => 'enum', 'enum' => PreviousPriceMaintained::class, 'default' => true],
                'reason_accepted_excluded' => self::str(500),
                'is_recommended' => ['type' => 'bool', 'default' => true],
                'recommended_quantity' => ['type' => 'qty'],
            ],
            'tender_awards' => [
                'supplier_name' => self::str(200, required: true),
                'manufacturer' => self::str(200),
                'brand' => self::str(200),
                'quantity_awarded' => ['type' => 'qty', 'required' => true],
                'unit_cost' => ['type' => 'price', 'required' => true],
            ],
            'purchase_orders' => [
                'supplier_name' => self::str(200, required: true),
                'po_number' => self::str(64, required: true),
                'quantity_ordered' => ['type' => 'qty', 'required' => true],
                'ordered_on' => ['type' => 'date'],
                'quantity_delivered' => ['type' => 'qty', 'default' => true],
                'delivery_eta' => ['type' => 'date'],
                'delivery_status' => self::str(120),
            ],
            'stock_expiries' => [
                'quantity' => ['type' => 'qty', 'required' => true],
                'expiry_date' => ['type' => 'date', 'required' => true],
            ],
        };
    }

    /**
     * AD_HOC request fields (adhoc-form-spec). All optional at save. There is no
     * separate consumption unit: unit_of_measure is the unit for quantity, stock
     * and average monthly consumption.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function adHocRequisitionFields(): array
    {
        return [
            // 1 Item
            'item_description' => self::str(500),
            'strength' => self::str(100),
            'dosage_form' => self::str(100),
            // V2-5: optional. Kyle, 2026-11-26: free text up to the column's 32 characters (the 8-digit rule is gone).
            'din' => ['type' => 'din'],
            'quantity_requested' => ['type' => 'qty'],
            'unit_of_measure' => self::str(32),
            'clinical_note' => ['type' => 'text', 'max' => 2000],
            // Kyle, 2026-11-27: the letter's section 1 paragraph and section 6 "Purpose" row. Optional.
            'purpose_of_request' => self::str(1000),
            'purpose_summary' => self::str(200),
            // 2 Tender. tender_reference is the contract reference (V2-1, label only).
            'tender_reference' => self::str(120),
            // Read by nothing since V2-1; still accepted so the old screen can save until V2-2.
            'tender_outcome' => ['type' => 'enum', 'enum' => TenderOutcome::class],
            'tender_issue_explanation' => ['type' => 'text'],
            'tender_cycle_start' => ['type' => 'date'], // V2-1
            'tender_cycle_end' => ['type' => 'date'],   // V2-1; not before the start (checked at submit)
            'quantity_on_order' => ['type' => 'qty'],   // V2-1: existing arrangement
            'outstanding_order_note' => self::str(300), // Kyle, 2026-11-27: the letter's section 6 lead-in. Optional.
            // 3 Stock
            'stock_location' => self::str(200),
            'average_monthly_consumption' => ['type' => 'qty'],
            // Kyle, 2026-11-24: one quantity the vendor types (was the batch total).
            'quantity_on_hand' => ['type' => 'qty'],
            // no_stock_on_hand is set by the server from the quantity on hand (StockOnHand); never sent.
            // 4 Quotations
            'rfq_audience' => ['type' => 'enum', 'enum' => RfqAudience::class],
            // Kyle, 2026-11-27: the letter's section 5 and the annex header. Optional.
            'basis_of_recommendation' => self::str(200),
            'rfq_number' => self::str(50),
            // Kyle, 2026-11-24: no longer on the form or checked; still accepted so an older
            // payload (or the dev sample) saves. The column keeps its value.
            'quantity_to_purchase' => ['type' => 'qty'],
            // 5 Price comparison. Tender and previous price show for Tender Excess only
            // (RequestSections::HIDDEN_FIELDS) but are accepted for every type, so a draft
            // keeps them if its type changes back; SubmitCase clears them when hidden.
            'tender_price' => ['type' => 'price'],
            'previous_price' => ['type' => 'price'],
            'quoted_price_acceptable' => ['type' => 'bool'],
            'price_note' => ['type' => 'text', 'max' => 1000],
        ];
    }

    /**
     * AD_HOC child rows: every column optional at save. "id" is the row's own id
     * (absent on a new row); orders point at an award row by award_id, which
     * SaveRequisition checks belongs to the case. "client_key" (slice F2) is a
     * random key the page gives a new row so it can attach the id the save
     * returns (SavedDraft::$idMap); it is never stored.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function adHocRowFields(string $section): array
    {
        $id = ['type' => 'rowid'];

        return ['client_key' => self::str(64), ...self::adHocColumns($section, $id)];
    }

    /** Values a blank row may still carry: the defaults a new row starts with. */
    public const BLANK_ROW_DEFAULTS = ['considered' => true, 'is_recommended' => false, 'quantity_delivered' => '0'];

    /**
     * A row with nothing entered (slice F2): every value is null or empty text,
     * booleans are at their defaults; id and client_key do not count. Such a
     * row is dropped on save (and, if it had an id, deleted).
     */
    public static function isBlankRow(array $values): bool
    {
        foreach ($values as $key => $value) {
            if ($key === 'id' || $key === 'client_key') {
                continue;
            }
            if (is_bool($value)) {
                if ($value !== (self::BLANK_ROW_DEFAULTS[$key] ?? false)) {
                    return false;
                }

                continue;
            }
            // A numeric default (quantity_delivered 0) counts as nothing entered.
            if (is_string($value) && isset(self::BLANK_ROW_DEFAULTS[$key]) && is_numeric($value)
                && Decimal::compare($value, (string) self::BLANK_ROW_DEFAULTS[$key]) === 0) {
                continue;
            }
            if ($value !== null && ! (is_string($value) && trim($value) === '')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    private static function adHocColumns(string $section, array $id): array
    {
        return match ($section) {
            'quotations' => [
                'id' => $id,
                'supplier_name' => self::str(200),
                'manufacturer' => self::str(200),
                'brand' => self::str(200),
                'unit_price' => ['type' => 'price'],
                'quantity_offered' => ['type' => 'qty'],
                'lead_time_days' => ['type' => 'smallint'],
                'considered' => ['type' => 'bool', 'default' => true],
                'reason_accepted_excluded' => self::str(500),
                'is_recommended' => ['type' => 'bool', 'default' => true],
                // Kyle, 2026-11-24: Ex Stock is the quantity available from stock, a number, kept in
                // the existing quantity_available_now column. The old ex_stock yes/no column is kept
                // (dropped fields keep their columns) and no longer written by the request form.
                'quantity_available_now' => ['type' => 'qty'],
                'country_of_origin' => self::str(100),
                'remarks' => self::str(255),
            ],
            'tender_awards' => [
                'id' => $id,
                'supplier_name' => self::str(200),
                'manufacturer' => self::str(200),
                'brand' => self::str(200),
                'quantity_awarded' => ['type' => 'qty'],
                'unit_cost' => ['type' => 'price'],
                'country_of_origin' => self::str(100), // V2-1
                // Kyle, 2026-11-24: typed, optional; 0 to 100 with up to 2 decimals.
                'percentage_awarded' => ['type' => 'rate'],
                // Kyle, 2026-11-26: the form no longer asks for a pack size; still accepted, never required.
                'pack_size' => self::str(100), // Kyle, 2026-11-25: optional
            ],
            // Kyle, 2026-11-27: Approved Tender Excesses (previous_orders) has the same columns.
            'purchase_orders', 'previous_orders' => [
                'id' => $id,
                'award_id' => ['type' => 'rowid'],
                'po_number' => self::str(64),
                'quantity_ordered' => ['type' => 'qty'],
                'ordered_on' => ['type' => 'date'],
                'quantity_delivered' => ['type' => 'qty'],
                // Kyle, 2026-11-23: the status dropdown and the delivery date replace the
                // old delivery_eta / free-text delivery_status (columns kept, no longer accepted).
                'delivery_state' => ['type' => 'enum', 'enum' => DeliveryState::class],
                // Kyle, 2026-11-25: what "Other" means, in the older free-text column (required for Other: RequestSections::orders).
                'delivery_status' => self::str(120),
                'delivery_date' => ['type' => 'date'],
                // Kyle, 2026-11-25: optional free text.
                'pack_size' => self::str(100),
                'delivery_note' => self::str(100),
            ],
            'stock_expiries' => [
                'id' => $id,
                // Kyle, 2026-11-24: the quantity on hand is typed (requisition.quantity_on_hand); a
                // batch is its expiry date. Still accepted so the current screen saves; never read.
                'quantity' => ['type' => 'qty'],
                'expiry_date' => ['type' => 'date'],
                'item_name' => self::str(200), // Kyle, 2026-11-26: the drug the row is for
            ],
            // V2-5: Details of Variation Requested. The effective date or application
            // P.O. is one free-text field (assumption, see the V2-5 report).
            'variation_rows' => [
                'id' => $id,
                'variation_type' => self::str(100),
                'existing_detail' => self::str(500),
                'proposed_detail' => self::str(500),
                'effective_detail' => self::str(100),
            ],
        };
    }

    private static function str(int $max, bool $required = false): array
    {
        return ['type' => 'string', 'max' => $max, 'required' => $required];
    }

    /** @return list<mixed> */
    private static function rulesFor(array $spec): array
    {
        $presence = match (true) {
            $spec['required'] ?? false => ['required'],
            $spec['default'] ?? false => ['sometimes'],
            default => ['nullable'],
        };

        // FORM-ERR-1: bail, so a field fails with one message (its first broken rule), never two.
        return ['bail', ...$presence, ...match ($spec['type']) {
            'string' => ['string', 'max:'.$spec['max']],
            'text' => isset($spec['max']) ? ['string', 'max:'.$spec['max']] : ['string'],
            // A string, so leading zeros are kept.
            'digits' => ['string', 'max:'.$spec['max'], 'regex:/^\d+$/'],
            // DIN: Kyle, 2026-11-26: any text up to the column's 32 characters (was exactly 8 digits);
            // trimmed, and empty stored as null (RequisitionData).
            'din' => ['string', 'max:32'],
            'rowid' => ['integer', 'min:1'],
            'date' => ['date_format:Y-m-d'],
            'enum' => [Rule::enum($spec['enum'])],
            'qty' => ['numeric', 'regex:'.self::QTY],
            'price' => ['numeric', 'regex:'.self::PRICE],
            'rate' => ['numeric', 'regex:'.self::RATE, 'max:100'],
            'smallint' => ['integer', 'between:0,32767'],
            'bool' => ['boolean'],
            'currency' => ['string', 'regex:/^[A-Z]{3}$/'],
        }];
    }
}
