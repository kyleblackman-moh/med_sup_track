<?php

use App\Enums\NeedClassification;
use App\Submissions\RequisitionData;
use Database\Seeders\ProcurementTypeSeeder;
use Illuminate\Validation\ValidationException;

// Slice 8a: RequisitionRules via RequisitionData::validate. No database writes
// beyond seeding the procurement types (rolled back).

beforeEach(function () {
    $this->seed(ProcurementTypeSeeder::class);
});

/** Validation error keys for $payload against $code, or [] when valid. */
function validationErrors(array $payload, string $code = 'AD_HOC_NON_PHARM'): array
{
    try {
        RequisitionData::validate($payload, procurementTypeByCode($code));

        return [];
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }
}

it('accepts an empty payload', function (string $code) {
    expect(validationErrors([], $code))->toBe([]);
})->with(['AD_HOC_PHARM', 'AD_HOC_NON_PHARM', 'TENDER_EXCESS']);

it('accepts the complete fixtures', function () {
    expect(validationErrors(nonPharmPayload(), 'AD_HOC_NON_PHARM'))->toBe([])
        ->and(validationErrors(pharmPayload(), 'AD_HOC_PHARM'))->toBe([])
        ->and(validationErrors(pharmAllSectionsPayload(), 'AD_HOC_PHARM'))->toBe([]);
});

it('allows or prohibits sections per procurement type', function (string $code, string $section, bool $allowed) {
    $rows = [
        'quotations' => [quoteRow('Supplier A', '1.00')],
        'tender_awards' => [['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1']],
        'purchase_orders' => [['supplier_name' => 'S', 'po_number' => 'PO-1', 'quantity_ordered' => '1']],
        'stock_expiries' => [['quantity' => '1', 'expiry_date' => '2027-01-31']],
    ];

    $errors = validationErrors([$section => $rows[$section]], $code);

    expect(in_array($section, $errors, true))->toBe(! $allowed);
})->with([
    ['AD_HOC_NON_PHARM', 'quotations', true],
    ['AD_HOC_NON_PHARM', 'tender_awards', false],
    ['AD_HOC_NON_PHARM', 'purchase_orders', false],
    ['AD_HOC_NON_PHARM', 'stock_expiries', false],
    ['AD_HOC_PHARM', 'quotations', true],
    ['AD_HOC_PHARM', 'tender_awards', true],
    ['AD_HOC_PHARM', 'purchase_orders', true],
    ['AD_HOC_PHARM', 'stock_expiries', true],
    ['TENDER_EXCESS', 'quotations', true],
    ['TENDER_EXCESS', 'tender_awards', true],
    ['TENDER_EXCESS', 'purchase_orders', true],
    ['TENDER_EXCESS', 'stock_expiries', true],
]);

it('prohibits even an empty array for a section the type does not allow', function () {
    expect(validationErrors(['stock_expiries' => []], 'AD_HOC_NON_PHARM'))->toContain('stock_expiries');
});

it('rejects unknown keys at every level', function (array $payload, string $key) {
    expect(validationErrors($payload))->toContain($key);
})->with([
    'root' => [['surprise' => 1], 'surprise'],
    'requisition' => [['requisition' => ['colour' => 'blue']], 'requisition.colour'],
    'quotation row' => [['quotations' => [quoteRow('S', '1', ['discount' => '5'])]], 'quotations.0.discount'],
]);

it('rejects each prohibited key wherever it appears', function (string $key) {
    $value = 'x';

    expect(validationErrors([$key => $value]))->toContain($key)
        ->and(validationErrors(['requisition' => [$key => $value]]))->toContain("requisition.{$key}")
        ->and(validationErrors(['quotations' => [quoteRow('S', '1', [$key => $value])]]))->toContain("quotations.0.{$key}");
})->with([
    'id', 'case_id', 'org_id', 'status', 'reference_no', 'file_no', 'current_holder_role',
    'technical_status', 'technical_status_set_by', 'technical_status_set_at',
    'technical_track', 'funding_track', 'hardcopy_received_at', 'hardcopy_received_by', 'sort_order',
]);

it('enforces decimal places and ranges', function (array $payload, bool $valid, string $key, string $code = 'AD_HOC_NON_PHARM') {
    expect(in_array($key, validationErrors($payload, $code), true))->toBe(! $valid);
})->with([
    'price 4dp' => [['quotations' => [quoteRow('S', '1.5975')]], true, 'quotations.0.unit_price'],
    'price 5dp' => [['quotations' => [quoteRow('S', '1.59751')]], false, 'quotations.0.unit_price'],
    'price numeric' => [['quotations' => [quoteRow('S', 1.5975)]], true, 'quotations.0.unit_price'],
    'price negative' => [['quotations' => [quoteRow('S', '-1')]], false, 'quotations.0.unit_price'],
    'price too big' => [['quotations' => [quoteRow('S', '12345678901.0000')]], false, 'quotations.0.unit_price'],
    'price max' => [['quotations' => [quoteRow('S', '9999999999.9999')]], true, 'quotations.0.unit_price'],
    'qty 1dp' => [['requisition' => ['quantity_requested' => '10.5']], true, 'requisition.quantity_requested'],
    'qty 3dp' => [['requisition' => ['quantity_requested' => '10.555']], false, 'requisition.quantity_requested'],
    'qty too big' => [['requisition' => ['quantity_requested' => '1234567890123']], false, 'requisition.quantity_requested'],
    'qty max' => [['requisition' => ['quantity_requested' => '999999999999.99']], true, 'requisition.quantity_requested'],
    'qty text' => [['requisition' => ['quantity_requested' => 'lots']], false, 'requisition.quantity_requested'],
    'vat 12.5' => [['quotations' => [quoteRow('S', '1', ['vat_rate' => '12.5'])]], true, 'quotations.0.vat_rate'],
    'vat 100.01' => [['quotations' => [quoteRow('S', '1', ['vat_rate' => '100.01'])]], false, 'quotations.0.vat_rate'],
    'vat 3dp' => [['quotations' => [quoteRow('S', '1', ['vat_rate' => '12.505'])]], false, 'quotations.0.vat_rate'],
    'lead time max' => [['quotations' => [quoteRow('S', '1', ['lead_time_days' => 32767])]], true, 'quotations.0.lead_time_days'],
    'lead time over' => [['quotations' => [quoteRow('S', '1', ['lead_time_days' => 32768])]], false, 'quotations.0.lead_time_days'],
    'lead time fraction' => [['quotations' => [quoteRow('S', '1', ['lead_time_days' => '1.5'])]], false, 'quotations.0.lead_time_days'],
    'unit cost 5dp' => [['tender_awards' => [['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1.00001']]], false, 'tender_awards.0.unit_cost', 'TENDER_EXCESS'],
    'unit cost 4dp' => [['tender_awards' => [['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1.0001']]], true, 'tender_awards.0.unit_cost', 'TENDER_EXCESS'],
]);

it('validates types, lengths, dates and enums', function (array $payload, string $key) {
    expect(validationErrors($payload))->toContain($key);
})->with([
    'bad enum' => [['requisition' => ['need_classification' => 'URGENT']], 'requisition.need_classification'],
    'bad tender outcome' => [['requisition' => ['tender_outcome' => 'LOST']], 'requisition.tender_outcome'],
    'bad rfq audience' => [['requisition' => ['rfq_audience' => 'SOME']], 'requisition.rfq_audience'],
    'bad previous price' => [['quotations' => [quoteRow('S', '1', ['previous_price_maintained' => 'MAYBE'])]], 'quotations.0.previous_price_maintained'],
    'date format' => [['requisition' => ['request_date' => '02/11/2026']], 'requisition.request_date'],
    'too long' => [['requisition' => ['vendor_reference' => str_repeat('x', 65)]], 'requisition.vendor_reference'],
    'din too long' => [['requisition' => ['din' => str_repeat('1', 33)]], 'requisition.din'],
    'currency' => [['quotations' => [quoteRow('S', '1', ['currency' => 'dollars'])]], 'quotations.0.currency'],
    'boolean' => [['quotations' => [quoteRow('S', '1', ['is_recommended' => 'perhaps'])]], 'quotations.0.is_recommended'],
    'requisition not array' => [['requisition' => 'text'], 'requisition'],
    'row not array' => [['quotations' => ['text']], 'quotations.0'],
]);

it('requires the NOT NULL columns on each child row', function (string $section, array $row, string $missing) {
    unset($row[$missing]);

    expect(validationErrors([$section => [$row]], 'AD_HOC_PHARM'))->toContain("{$section}.0.{$missing}");
})->with([
    ['quotations', ['supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1'], 'supplier_name'],
    ['quotations', ['supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1'], 'unit_price'],
    ['quotations', ['supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1'], 'quantity_offered'],
    ['tender_awards', ['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1'], 'supplier_name'],
    ['tender_awards', ['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1'], 'quantity_awarded'],
    ['tender_awards', ['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1'], 'unit_cost'],
    ['purchase_orders', ['supplier_name' => 'S', 'po_number' => 'P', 'quantity_ordered' => '1'], 'supplier_name'],
    ['purchase_orders', ['supplier_name' => 'S', 'po_number' => 'P', 'quantity_ordered' => '1'], 'po_number'],
    ['purchase_orders', ['supplier_name' => 'S', 'po_number' => 'P', 'quantity_ordered' => '1'], 'quantity_ordered'],
    ['stock_expiries', ['quantity' => '1', 'expiry_date' => '2027-01-31'], 'quantity'],
    ['stock_expiries', ['quantity' => '1', 'expiry_date' => '2027-01-31'], 'expiry_date'],
]);

it('caps the number of rows per section', function (string $section, int $cap, array $row) {
    expect(validationErrors([$section => array_fill(0, $cap, $row)], 'TENDER_EXCESS'))->not->toContain($section)
        ->and(validationErrors([$section => array_fill(0, $cap + 1, $row)], 'TENDER_EXCESS'))->toContain($section);
})->with([
    ['quotations', 50, ['supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1']],
    ['tender_awards', 20, ['supplier_name' => 'S', 'quantity_awarded' => '1', 'unit_cost' => '1']],
    ['purchase_orders', 20, ['supplier_name' => 'S', 'po_number' => 'P', 'quantity_ordered' => '1']],
    ['stock_expiries', 20, ['quantity' => '1', 'expiry_date' => '2027-01-31']],
]);

it('returns typed data: decimal strings, enum cases and Y-m-d dates', function () {
    $data = RequisitionData::validate([
        'requisition' => ['need_classification' => 'VITAL', 'quantity_requested' => 2800, 'request_date' => '2026-11-02', 'din' => null],
        'quotations' => [quoteRow('S', 1.5975, ['is_recommended' => '1', 'lead_time_days' => '14'])],
    ], procurementTypeByCode('AD_HOC_NON_PHARM'));

    expect($data->requisition)->toBe([
        'need_classification' => NeedClassification::VITAL,
        'quantity_requested' => '2800',
        'request_date' => '2026-11-02',
        'din' => null,
    ])->and($data->sections['quotations'][0]['unit_price'])->toBe('1.5975')
        ->and($data->sections['quotations'][0]['is_recommended'])->toBeTrue()
        ->and($data->sections['quotations'][0]['lead_time_days'])->toBe(14)
        ->and($data->hasSection('tender_awards'))->toBeFalse();
});
