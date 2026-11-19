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
 * V2-5: the previous-request answer (request form only): hasPreviousRequest is
 * false when the payload has no such key. The variation rows are a section like
 * the award rows (RequisitionRules::FORM_SECTIONS).
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
        public bool $hasPreviousRequest = false,
        public ?bool $previousRequest = null,
    ) {}

    /** Strict: any error rejects the whole payload. @throws ValidationException */
    public static function validate(array $payload, ProcurementType $type): self
    {
        $payload = self::trimText($payload, $type->code);

        self::validator($payload, $type)->validate();

        return self::build($payload, $type);
    }

    /**
     * FORM-SAVE-1 (A1): a DRAFT save never loses valid work. The same rules as
     * validate(), but only what failed is left out: a request field, a row cell,
     * or (when a row cannot be matched safely: a bad row id, a section that is
     * not a list) the whole section, which then stays as it is. Absent means
     * "unchanged" for a field or an existing row's cell, and empty for a new
     * row's cell. What is left is validated again, so a value that failed a
     * rule is never returned (and so never saved).
     *
     * @return array{0: self, 1: array<string, string>} the data, and dropped key => message
     *
     * @throws ValidationException only if what is left still fails (never expected)
     */
    public static function validateDraft(array $payload, ProcurementType $type): array
    {
        $payload = self::trimText($payload, $type->code);
        $dropped = [];

        for ($pass = 0; $pass < 3; $pass++) {
            $validator = self::validator($payload, $type, crossField: true);
            if (! $validator->fails()) {
                return [self::build($payload, $type), $dropped];
            }

            foreach ($validator->errors()->messages() as $key => $messages) {
                $dropped[$key] ??= $messages[0];
                $payload = self::without($payload, $key);
            }
        }

        // What is left still fails: refuse the save as a whole (strict).
        self::validator($payload, $type, crossField: true)->validate();

        return [self::build($payload, $type), $dropped];
    }

    /** The payload without what an error key points at (see validateDraft). */
    private static function without(array $payload, string $key): array
    {
        $parts = explode('.', $key);
        $sections = [...ProcurementTypeProfile::SECTIONS, ...RequisitionRules::FORM_SECTIONS];

        if ($parts[0] === 'requisition' && isset($parts[1]) && is_array($payload['requisition'] ?? null)) {
            unset($payload['requisition'][$parts[1]]);
        } elseif (in_array($parts[0], $sections, true) && count($parts) === 3 && ! in_array($parts[2], ['id', 'client_key'], true)
            && is_array($payload[$parts[0]][(int) $parts[1]] ?? null)) {
            unset($payload[$parts[0]][(int) $parts[1]][$parts[2]]);
        } else {
            // The whole top-level key: the request block, a section whose rows cannot be matched safely, or an unknown key.
            unset($payload[$parts[0]]);
        }

        return $payload;
    }

    /**
     * The one validator for both modes: the rules and unknown keys; with
     * $crossField (draft saves) also the cross-field rules, which a draft save
     * resolves by leaving out the one field that carries the error. The strict
     * path leaves them to readiness at submit, as before.
     */
    private static function validator(array $payload, ProcurementType $type, bool $crossField = false): \Illuminate\Validation\Validator
    {
        // FORM-ERR-1: messages in the form's words (RequisitionMessages), never a dotted key.
        $validator = Validator::make($payload, RequisitionRules::for($type), RequisitionMessages::for($type));
        $validator->after(function ($validator) use ($payload, $type, $crossField) {
            foreach (RequisitionRules::unknownKeyErrors($payload, $type->code) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
            // FORM-SAVE-1: a cross-field rule names only the field that carries the error.
            foreach ($crossField ? RequisitionRules::crossFieldErrors($payload, $validator->errors()->keys()) : [] as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });

        return $validator;
    }

    /** Typed data from a payload that has passed validation. */
    private static function build(array $payload, ProcurementType $type): self
    {
        // Valid from here on: every key is known and every value well formed.
        $requisition = array_key_exists('requisition', $payload)
            ? self::normalise($payload['requisition'], RequisitionRules::requisitionFields($type->code))
            : null;

        $sections = [];
        // Validation has refused FORM_SECTIONS for the old types ("missing").
        foreach ([...ProcurementTypeProfile::SECTIONS, ...RequisitionRules::FORM_SECTIONS] as $section) {
            if (array_key_exists($section, $payload)) {
                $fields = RequisitionRules::rowFields($section, $type->code);
                $sections[$section] = array_map(fn (array $row) => self::normalise($row, $fields), $payload[$section]);
            }
        }

        // V2-5: the previous-request answer; absent = unchanged, null = not answered.
        $hasPreviousRequest = array_key_exists('previous_request_in_cycle', $payload);
        $previousRequest = $hasPreviousRequest && $payload['previous_request_in_cycle'] !== null
            ? filter_var($payload['previous_request_in_cycle'], FILTER_VALIDATE_BOOLEAN)
            : null;

        return new self($requisition, $sections, $hasPreviousRequest, $previousRequest);
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
