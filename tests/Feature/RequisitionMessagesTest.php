<?php

use App\Models\ProcurementType;
use App\Submissions\RequisitionData;
use App\Submissions\RequisitionMessages;
use App\Submissions\RequisitionRules;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// FORM-ERR-1 (C, G): validation messages for a request save use the form's
// words: the row label and the column header exactly as the table shows them.
// No dotted key and no snake_case ever reaches the user.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A dotted payload key ("quotations.0.quantity_offered", "requisition.din"): lowercase snake words joined by dots. */
const DOTTED_KEY = '/[a-z_][a-z0-9_]*\.[a-z0-9_*]/';

/** Every message (all of them, not only the first) for $payload under $code; [] when valid. */
function messagesFor(string $code, array $payload): array
{
    try {
        RequisitionData::validate($payload, procurementTypeByCode($code));

        return [];
    } catch (ValidationException $e) {
        return $e->errors();
    }
}

it('names the row and the column in the three quotation messages, exactly', function () {
    $errors = messagesFor('AD_HOC_TENDER_UNABLE', ['quotations' => [[
        'quantity_offered' => 'lots',
        'unit_price' => 'cheap',
        'lead_time_days' => '1.5',
    ]]]);

    expect($errors)->toBe([
        'quotations.0.unit_price' => ['Quotation Row 1: Unit Price needs to be a number, for example 12.50.'],
        'quotations.0.quantity_offered' => ['Quotation Row 1: Quantity needs to be a number, for example 2800.'],
        'quotations.0.lead_time_days' => ['Quotation Row 1: Lead Time (Days) needs to be a whole number, for example 14.'],
    ]);
});

it('returns three separate messages for three bad fields in one row, each with the row and its column', function () {
    $errors = messagesFor('AD_HOC_TENDER_UNABLE', ['quotations' => [
        ['supplier_name' => 'Fine'],
        ['quantity_offered' => 'x', 'unit_price' => 'y', 'lead_time_days' => 'z'],
    ]]);

    $row = collect($errors)->filter(fn ($m, $key) => str_starts_with($key, 'quotations.1.'))->flatten();

    expect($row)->toHaveCount(3)
        ->and($row->every(fn (string $m) => str_starts_with($m, 'Quotation Row 2: ')))->toBeTrue()
        ->and($row->contains(fn ($m) => str_contains($m, ': Quantity needs')))->toBeTrue()
        ->and($row->contains(fn ($m) => str_contains($m, ': Unit Price needs')))->toBeTrue()
        ->and($row->contains(fn ($m) => str_contains($m, ': Lead Time (Days) needs')))->toBeTrue()
        ->and(collect($errors)->keys()->filter(fn ($k) => str_starts_with($k, 'quotations.0.')))->toBeEmpty();
});

it('gives one message per field, never two', function () {
    // "abc" breaks both numeric and the decimal pattern; only the first is reported (bail).
    expect(messagesFor('AD_HOC_TENDER_UNABLE', ['requisition' => ['quantity_requested' => 'abc']]))
        ->toBe(['requisition.quantity_requested' => ['Quantity Requested needs to be a number, for example 2800.']]);
});

it('uses each table\'s own row label and column header', function (string $section, string $column, mixed $bad, string $message) {
    expect(messagesFor('AD_HOC_TENDER_UNABLE', [$section => [[$column => $bad]]])["{$section}.0.{$column}"])->toBe([$message]);
})->with([
    'award' => ['tender_awards', 'unit_cost', 'x', 'Supplier Row 1: Unit Cost needs to be a number, for example 12.50.'],
    'order' => ['purchase_orders', 'ordered_on', '19/11/2026', 'Order Row 1: Date Ordered needs to be a date.'],
    'batch' => ['stock_expiries', 'quantity', '-5', 'Batch 1: Quantity needs to be 0 or more, with up to 2 decimal places, for example 2800.'],
    'variation' => ['variation_rows', 'effective_detail', str_repeat('a', 101), 'Variation Row 1: Effective Date / Application P.O. needs to be 100 characters or fewer.'],
]);

/**
 * Bad values for a field spec: each breaks one rule of rulesFor() (type, size,
 * range or pattern), so every rule a field has is exercised.
 *
 * @return list<mixed>
 */
function badValuesFor(array $spec): array
{
    return match ($spec['type']) {
        'string', 'text' => [['an', 'array'], isset($spec['max']) ? str_repeat('a', $spec['max'] + 1) : ['x']],
        'digits' => [['x'], str_repeat('1', $spec['max'] + 1), '12a'],
        'din' => [['x'], '1234567', 'ABCD1234'],
        'qty' => ['abc', '-1', '1.234'],
        'price' => ['abc', '-1', '1.23456'],
        'rate' => ['abc', '101', '1.234'],
        'smallint' => ['1.5', '40000'],
        'date' => ['19/11/2026'],
        'enum' => ['NOT_AN_OPTION'],
        'bool' => ['maybe'],
        'currency' => [['x'], 'tt'],
        'rowid' => ['x', '0'],
    };
}

it('never shows a dotted key or an underscore in any message of the vendor form', function (string $code) {
    $type = procurementTypeByCode($code);
    $seen = [];

    $collect = function (array $payload) use ($code, &$seen) {
        foreach (messagesFor($code, $payload) as $messages) {
            array_push($seen, ...$messages);
        }
    };

    foreach (RequisitionRules::requisitionFields($code) as $field => $spec) {
        foreach (badValuesFor($spec) as $bad) {
            $collect(['requisition' => [$field => $bad]]);
        }
    }

    $sections = array_keys(RequisitionMessages::ROW_LABELS);
    foreach ($sections as $section) {
        // Section-level: not a list, too many rows, a row that is not an object.
        $collect([$section => 'nope']);
        $collect([$section => array_fill(0, RequisitionRules::ROW_CAPS[$section] + 1, [])]);
        $collect([$section => ['nope']]);
        try {
            $fields = RequisitionRules::rowFields($section, $code);
        } catch (UnhandledMatchError) {
            continue; // the old types have no variation rows (refused as "missing" above)
        }
        foreach ($fields as $column => $spec) {
            foreach (badValuesFor($spec) as $bad) {
                $collect([$section => [[$column => $bad]]]);
            }
        }
    }

    // Unknown and system keys, at every level; the previous-request answer.
    $collect(['status' => 'X', 'surprise' => 1, 'requisition' => ['org_id' => 'X'], 'previous_request_in_cycle' => 'maybe']);
    $collect(['requisition' => 'nope']);

    expect($seen)->not->toBeEmpty();
    foreach (array_unique($seen) as $message) {
        expect($message)->not->toMatch(DOTTED_KEY, "Dotted key in: {$message}")
            ->and(str_contains($message, '_'))->toBeFalse("Underscore in: {$message}");
    }
})->with(['request form' => ['AD_HOC_TENDER_UNABLE'], 'older form' => ['AD_HOC_PHARM']]);

it('has no dotted key or underscore in any message it defines', function () {
    $types = ProcurementType::query()->pluck('code')->all();

    foreach ($types as $code) {
        foreach (RequisitionMessages::for(procurementTypeByCode($code)) as $message) {
            expect($message)->not->toMatch(DOTTED_KEY)->and(str_contains($message, '_'))->toBeFalse("{$code}: {$message}");
        }
    }
});
