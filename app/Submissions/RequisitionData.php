<?php

namespace App\Submissions;

use App\Models\ProcurementType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validated requisition input. Built only through validate().
 *
 * Values are typed: decimals as strings, enums as enum cases, dates as Y-m-d
 * strings, integers and booleans as such. Only keys present in the payload are
 * kept: an absent key means "unchanged", null means "clear".
 *
 * Slice V2-1: the variation entry (request form only). hasVariation is false
 * when the payload has no "variation" key; $variation is null when it was sent
 * as null (clear it), else the fields sent.
 */
final readonly class RequisitionData
{
    /**
     * @param  array<string, mixed>|null  $requisition  null = requisition not in the payload
     * @param  array<string, list<array<string, mixed>>>  $sections  only the sections present
     * @param  array<string, mixed>|null  $variation
     */
    private function __construct(
        public ?array $requisition,
        public array $sections,
        public bool $hasVariation = false,
        public ?array $variation = null,
    ) {}

    /** @throws ValidationException */
    public static function validate(array $payload, ProcurementType $type): self
    {
        $payload = self::trimText($payload, $type->code);

        $validator = Validator::make($payload, RequisitionRules::for($type), RequisitionRules::MESSAGES);
        $validator->after(function ($validator) use ($payload, $type) {
            foreach (RequisitionRules::unknownKeyErrors($payload, $type->code) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
        $validator->validate();

        // Valid from here on: every key is known and every value well formed.
        $requisition = array_key_exists('requisition', $payload)
            ? self::normalise($payload['requisition'], RequisitionRules::requisitionFields($type->code))
            : null;

        $sections = [];
        foreach (ProcurementTypeProfile::SECTIONS as $section) {
            if (array_key_exists($section, $payload)) {
                $fields = RequisitionRules::rowFields($section, $type->code);
                $sections[$section] = array_map(fn (array $row) => self::normalise($row, $fields), $payload[$section]);
            }
        }

        $hasVariation = array_key_exists('variation', $payload);
        $variation = $hasVariation && $payload['variation'] !== null
            ? self::normalise($payload['variation'], RequisitionRules::variationFields())
            : null;

        return new self($requisition, $sections, $hasVariation, $variation);
    }

    /**
     * V2-5: text fields of type "alnum" (the DIN) are trimmed before they are
     * validated, and an empty one becomes null (cleared). Other values are untouched.
     */
    private static function trimText(array $payload, string $code): array
    {
        if (! is_array($payload['requisition'] ?? null)) {
            return $payload;
        }

        foreach (RequisitionRules::requisitionFields($code) as $field => $spec) {
            $value = $payload['requisition'][$field] ?? null;
            if ($spec['type'] === 'alnum' && is_string($value)) {
                $value = trim($value);
                $payload['requisition'][$field] = $value === '' ? null : $value;
            }
        }

        return $payload;
    }

    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->sections);
    }

    /** @param  array<string, array<string, mixed>>  $fields */
    private static function normalise(array $values, array $fields): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[$key] = $value === null ? null : match ($fields[$key]['type']) {
                'qty', 'price', 'rate' => (string) $value,
                'enum' => $fields[$key]['enum']::from($value),
                'smallint', 'rowid' => (int) $value,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => (string) $value,
            };
        }

        return $out;
    }
}
