<?php

// Vendor prop leak checks (from slice 8b), shared by the request, document and
// submission tests. Loaded from tests/Pest.php.

use Illuminate\Testing\TestResponse;

/** Keys that must never reach a vendor, at any depth. */
const FORBIDDEN_PROP_KEYS = [
    'org_id', 'created_by', 'file_no', 'current_holder_role', 'status',
    'technical_track', 'funding_track', 'hardcopy_received_at', 'hardcopy_received_by', 'hardcopy_receipt_method',
    'technical_status', 'technical_status_set_by', 'technical_status_set_at',
    // Slice 10f: the vendor sees the letter date and reference only.
    'letter_emailed_on', 'letter_hardcopy_sent_on', 'letter_recorded_by', 'letter_recorded_at',
    'emailed_on', 'hardcopy_sent_on', 'recorded_by', 'recorded_at', 'closed_at',
];

/** Internal status values with no vendor-facing equivalent. */
const INTERNAL_STATUS_VALUES = [
    'CMO_INTAKE', 'IN_REVIEW', 'READY_FOR_FILE', 'FILE_PREPARED', 'WITH_LEGAL', 'WITH_PS',
    'RETURNED_TO_CMO', 'NON_OBJECTION_GRANTED', 'REJECTED_FINAL',
];

/** @return array{keys: list<string>, strings: list<string>} every key and string value, recursively */
function scanProps(array $data): array
{
    $keys = [];
    $strings = [];
    array_walk_recursive($data, function ($value, $key) use (&$strings) {
        if (is_string($value)) {
            $strings[] = $value;
        }
    });
    $walk = function (array $node) use (&$walk, &$keys) {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($data);

    return ['keys' => $keys, 'strings' => $strings];
}

function pageProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

function expectVendorSafe(array $props): void
{
    $scan = scanProps($props);

    foreach (FORBIDDEN_PROP_KEYS as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['keys'] as $key) {
        expect($key)->not->toStartWith('technical_');
    }
    foreach ($scan['strings'] as $string) {
        expect(INTERNAL_STATUS_VALUES)->not->toContain($string)
            ->and($string)->not->toContain('technical_status');
    }
}
