<?php

namespace App\Submissions;

use App\Enums\NeedClassification;
use App\Enums\PreviousPriceMaintained;
use App\Enums\RfqAudience;
use App\Enums\TenderOutcome;
use App\Models\ProcurementType;
use Illuminate\Validation\Rule;

/**
 * Validation rules for a requisition payload:
 *
 *   ['requisition' => [...], 'quotations' => [rows], 'tender_awards' => [rows],
 *    'purchase_orders' => [rows], 'stock_expiries' => [rows]]
 *
 * Field types, lengths and ranges follow the slice 6b columns. Every requisition
 * field is nullable (a draft saves incomplete). Child rows must carry the
 * columns the database requires. Unknown keys fail at every level
 * (unknownKeyErrors), so system columns can never be supplied.
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

    public const ROW_CAPS = [
        'quotations' => 50,
        'tender_awards' => 20,
        'purchase_orders' => 20,
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

        foreach (self::requisitionFields() as $field => $spec) {
            $rules["requisition.{$field}"] = self::rulesFor($spec);
        }

        foreach (ProcurementTypeProfile::SECTIONS as $section) {
            if (! $profile->allows($section)) {
                $rules[$section] = ['missing'];

                continue;
            }

            $rules[$section] = ['sometimes', 'array', 'list', 'max:'.self::ROW_CAPS[$section]];
            $rules["{$section}.*"] = ['array'];

            foreach (self::rowFields($section) as $field => $spec) {
                $rules["{$section}.*.{$field}"] = self::rulesFor($spec);
            }
        }

        return $rules;
    }

    /**
     * Errors for keys that are not in the rules, at every level.
     *
     * @return array<string, string> dotted path => message
     */
    public static function unknownKeyErrors(array $payload): array
    {
        $errors = [];
        $check = function (array $data, array $allowed, string $prefix) use (&$errors) {
            foreach (array_keys($data) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $errors[$prefix.$key] = in_array($key, self::PROHIBITED_KEYS, true)
                        ? "The {$key} field cannot be set."
                        : 'This field is not recognised.';
                }
            }
        };

        $check($payload, ['requisition', ...ProcurementTypeProfile::SECTIONS], '');

        if (is_array($payload['requisition'] ?? null)) {
            $check($payload['requisition'], array_keys(self::requisitionFields()), 'requisition.');
        }

        foreach (ProcurementTypeProfile::SECTIONS as $section) {
            foreach (is_array($payload[$section] ?? null) ? $payload[$section] : [] as $i => $row) {
                if (is_array($row)) {
                    $check($row, array_keys(self::rowFields($section)), "{$section}.{$i}.");
                }
            }
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
    public static function requisitionFields(): array
    {
        return [
            'vendor_reference' => self::str(64),
            'request_date' => ['type' => 'date'],
            'need_classification' => ['type' => 'enum', 'enum' => NeedClassification::class],
            'item_description' => self::str(500),
            'din' => self::str(32),
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
    public static function rowFields(string $section): array
    {
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

        return [...$presence, ...match ($spec['type']) {
            'string' => ['string', 'max:'.$spec['max']],
            'text' => ['string'],
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
