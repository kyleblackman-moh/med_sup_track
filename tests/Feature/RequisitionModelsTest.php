<?php

use App\Enums\Role;
use App\Enums\TechnicalStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Requisition child models: relations, mass assignment, exact money maths and
// the VisibleViaCaseScope. Runs inside DatabaseTransactions.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const CHILD_MODELS = [
    CaseRequisition::class,
    CaseQuotation::class,
    CaseTenderAward::class,
    CasePurchaseOrder::class,
    CaseStockExpiry::class,
];

/** One row of every child type for $case, written through the relations. */
function addChildren(ProcurementCase $case): void
{
    $case->requisition()->create(['item_description' => 'Item', 'quantity_requested' => '100']);
    $case->quotations()->create(['supplier_name' => 'Supplier A', 'unit_price' => '1.5975', 'quantity_offered' => '2800']);
    $case->tenderAwards()->create(['supplier_name' => 'Supplier A', 'quantity_awarded' => '2800', 'unit_cost' => '1.2500']);
    $case->purchaseOrders()->create(['supplier_name' => 'Supplier A', 'po_number' => 'PO-1', 'quantity_ordered' => '3000']);
    $case->stockExpiries()->create(['quantity' => '50', 'expiry_date' => '2027-06-30']);
}

// 3. Relations ------------------------------------------------------------------

it('loads every child back through the case, in the specified order', function () {
    $this->actingAs(caseUser(Role::Cmo));
    $case = makeCase();

    $case->requisition()->create(['item_description' => 'Amoxicillin 500mg', 'vendor_reference' => 'REF-1']);
    $case->quotations()->create(['sort_order' => 3, 'supplier_name' => 'Q3', 'unit_price' => '1', 'quantity_offered' => '1']);
    $case->quotations()->create(['sort_order' => 1, 'supplier_name' => 'Q1', 'unit_price' => '1', 'quantity_offered' => '1']);
    $case->quotations()->create(['sort_order' => 1, 'supplier_name' => 'Q1b', 'unit_price' => '1', 'quantity_offered' => '1']);
    $case->tenderAwards()->create(['sort_order' => 2, 'supplier_name' => 'A2', 'quantity_awarded' => '1', 'unit_cost' => '1']);
    $case->tenderAwards()->create(['sort_order' => 1, 'supplier_name' => 'A1', 'quantity_awarded' => '1', 'unit_cost' => '1']);
    $case->purchaseOrders()->create(['sort_order' => 2, 'supplier_name' => 'P2', 'po_number' => 'PO-2', 'quantity_ordered' => '1']);
    $case->purchaseOrders()->create(['sort_order' => 1, 'supplier_name' => 'P1', 'po_number' => 'PO-1', 'quantity_ordered' => '1']);
    $case->stockExpiries()->create(['quantity' => '5', 'expiry_date' => '2028-01-31']);
    $case->stockExpiries()->create(['quantity' => '7', 'expiry_date' => '2027-03-31']);

    $loaded = ProcurementCase::with(['requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries'])
        ->findOrFail($case->id);

    expect($loaded->requisition->item_description)->toBe('Amoxicillin 500mg')
        ->and($loaded->quotations->pluck('supplier_name')->all())->toBe(['Q1', 'Q1b', 'Q3'])
        ->and($loaded->tenderAwards->pluck('supplier_name')->all())->toBe(['A1', 'A2'])
        ->and($loaded->purchaseOrders->pluck('po_number')->all())->toBe(['PO-1', 'PO-2'])
        ->and($loaded->stockExpiries->map(fn ($e) => $e->expiry_date->toDateString())->all())->toBe(['2027-03-31', '2028-01-31']);

    foreach ([$loaded->requisition, ...$loaded->quotations, ...$loaded->tenderAwards, ...$loaded->purchaseOrders, ...$loaded->stockExpiries] as $child) {
        expect($child->case->id)->toBe($case->id);
    }
});

it('keeps decimals as strings and casts enums and dates', function () {
    $this->actingAs(caseUser(Role::Cmo));
    $case = makeCase();
    $case->quotations()->create(['supplier_name' => 'S', 'unit_price' => '0.754', 'quantity_offered' => '2000', 'vat_rate' => '12.5']);

    $quotation = $case->quotations()->firstOrFail();

    expect($quotation->unit_price)->toBe('0.7540')
        ->and($quotation->quantity_offered)->toBe('2000.00')
        ->and($quotation->vat_rate)->toBe('12.50')
        ->and($quotation->technical_status)->toBe(TechnicalStatus::PENDING)
        ->and($quotation->currency)->toBe('TTD');
});

// 4. Mass assignment ------------------------------------------------------------

it('does not mass-assign MOH-only quotation columns or case_id', function () {
    $quotation = CaseQuotation::make([
        'supplier_name' => 'S',
        'technical_status' => 'SAMPLE_ACCEPTED',
        'technical_status_set_by' => 1,
        'technical_status_set_at' => now(),
        'case_id' => '01aaaaaaaaaaaaaaaaaaaaaaaa',
    ]);

    expect($quotation->technical_status)->toBe(TechnicalStatus::PENDING)
        ->and($quotation->getAttributes())->not->toHaveKeys(['technical_status_set_by', 'technical_status_set_at', 'case_id']);

    foreach (CHILD_MODELS as $model) {
        expect((new $model)->isFillable('case_id'))->toBeFalse("{$model} case_id");
    }
});

// 5. Money maths ----------------------------------------------------------------

it('multiplies quotation prices exactly', function (string $price, string $quantity, string $expected) {
    expect((new CaseQuotation(['unit_price' => $price, 'quantity_offered' => $quantity]))->offeredTotal())->toBe($expected);
})->with([
    ['1.5975', '2800', '4473.00'],
    ['0.754', '2000', '1508.00'],
    ['4.15', '2800', '11620.00'],
]);

it('rounds the recommended total half-up to 2 decimals', function (string $price, ?string $quantity, ?string $expected) {
    $quotation = new CaseQuotation(['unit_price' => $price, 'quantity_offered' => '1', 'recommended_quantity' => $quantity]);

    expect($quotation->recommendedTotal())->toBe($expected);
})->with([
    ['0.6675', '3', '2.00'],    // 2.0025
    ['0.6675', '30', '20.03'],  // 20.025
    ['1.5975', '2800', '4473.00'],
    ['1.5975', null, null],
]);

it('computes VAT from the per-row rate', function () {
    $quotation = new CaseQuotation(['unit_price' => '1.5975', 'quantity_offered' => '2800', 'recommended_quantity' => '2800', 'vat_rate' => '12.5']);

    expect($quotation->recommendedTotal())->toBe('4473.00')
        ->and($quotation->recommendedVatAmount())->toBe('559.13')       // 559.125
        ->and($quotation->recommendedTotalIncludingVat())->toBe('5032.13');
});

it('returns null VAT when the rate or recommended quantity is missing', function () {
    $noRate = new CaseQuotation(['unit_price' => '1.5975', 'quantity_offered' => '1', 'recommended_quantity' => '2800']);
    $noQuantity = new CaseQuotation(['unit_price' => '1.5975', 'quantity_offered' => '1', 'vat_rate' => '12.5']);

    expect($noRate->recommendedVatAmount())->toBeNull()
        ->and($noRate->recommendedTotalIncludingVat())->toBeNull()
        ->and($noQuantity->recommendedVatAmount())->toBeNull()
        ->and($noQuantity->recommendedTotalIncludingVat())->toBeNull();
});

it('multiplies tender award costs exactly', function () {
    expect((new CaseTenderAward(['unit_cost' => '1.5975', 'quantity_awarded' => '2800']))->total())->toBe('4473.00');
});

// 6. Award percentages ----------------------------------------------------------

it('computes each award as a share of the awarded quantity', function () {
    $this->actingAs(caseUser(Role::Cmo));
    $case = makeCase();
    $a = $case->tenderAwards()->create(['supplier_name' => 'A', 'quantity_awarded' => '2800', 'unit_cost' => '1']);
    $b = $case->tenderAwards()->create(['supplier_name' => 'B', 'quantity_awarded' => '1200', 'unit_cost' => '1']);
    $c = $case->tenderAwards()->create(['supplier_name' => 'C', 'quantity_awarded' => '0', 'unit_cost' => '1']);

    expect($case->fresh()->tenderAwardPercentages())->toBe([$a->id => '70.00', $b->id => '30.00', $c->id => '0.00']);
});

it('returns no percentages when there are no awards or they sum to zero', function () {
    $this->actingAs(caseUser(Role::Cmo));
    $none = makeCase();
    $zero = makeCase();
    $zero->tenderAwards()->create(['supplier_name' => 'A', 'quantity_awarded' => '0', 'unit_cost' => '1']);

    expect($none->tenderAwardPercentages())->toBe([])
        ->and($zero->fresh()->tenderAwardPercentages())->toBe([]);
});

// 7. Purchase orders ------------------------------------------------------------

it('computes outstanding quantity without clamping', function (string $ordered, string $delivered, string $expected) {
    expect((new CasePurchaseOrder(['quantity_ordered' => $ordered, 'quantity_delivered' => $delivered]))->quantityOutstanding())
        ->toBe($expected);
})->with([
    ['3000', '1200', '1800.00'],
    ['1000', '1250.5', '-250.50'],
]);

// 8. Stock coverage -------------------------------------------------------------

it('computes stock on hand and coverage', function (?string $central, ?string $rha, ?string $consumption, ?string $stock, ?string $months) {
    $requisition = new CaseRequisition([
        'central_stores_stock' => $central,
        'rha_stock' => $rha,
        'average_monthly_consumption' => $consumption,
    ]);

    expect($requisition->stockOnHand())->toBe($stock)
        ->and($requisition->coverageMonths())->toBe($months);
})->with([
    'normal' => ['0', '1500', '500', '1500.00', '3.00'],
    'rounding' => ['100', '0', '30', '100.00', '3.33'],
    'zero consumption' => ['0', '1500', '0', '1500.00', null],
    'null consumption' => ['0', '1500', null, '1500.00', null],
    'central null' => [null, '1500', '500', null, null],
    'rha null' => ['0', null, '500', null, null],
]);

// 9. Child visibility -----------------------------------------------------------

/** Letters of fixture cases whose $model rows the current user can see. */
function visibleChildLetters(string $model, array $fixture): array
{
    $letters = collect($fixture['cases'])->mapWithKeys(fn ($c, $l) => [$c->id => $l]);

    return $model::whereIn('case_id', fixtureIds($fixture))->pluck('case_id')
        ->map(fn ($id) => $letters[$id])->unique()->sort()->values()->all();
}

it('shows child rows only for cases the user can see', function (string $actor) {
    $fixture = visibilityFixture();
    foreach ($fixture['cases'] as $case) {
        addChildren($case);
    }

    // A technocrat whose only assignment is an active one on B.
    $techActiveOnly = caseUser(Role::Technocrat);
    assign($fixture['cases']['B'], Role::Technocrat, $techActiveOnly);
    $actors = $fixture['actors'] + ['tech_active_only' => $techActiveOnly];
    $expected = EXPECTED_VISIBLE[$actor] ?? ['B'];

    actAs($actors[$actor]);

    foreach (CHILD_MODELS as $model) {
        expect(visibleChildLetters($model, $fixture))->toBe($expected, "{$model} for {$actor}")
            ->and($model::whereIn('case_id', fixtureIds($fixture))->count())->toBe(count($expected));

        foreach ($fixture['cases'] as $letter => $case) {
            $id = $model::withoutGlobalScopes()->where('case_id', $case->id)->value('id');
            expect($model::find($id) !== null)->toBe(in_array($letter, $expected, true), "{$model} find {$letter}");
        }

        // withoutGlobalScopes() is the only bypass.
        expect($model::withoutGlobalScopes()->whereIn('case_id', fixtureIds($fixture))->count())->toBe(6);
    }
})->with(['vendor_x', 'vendor_y', 't1', 'tech_active_only', 'cmo', 'ps_office', 'system_admin', 'auditor', 'guest']);

it('returns child rows through the relation of a visible case', function () {
    $fixture = visibilityFixture();
    addChildren($fixture['cases']['C']);
    actAs($fixture['actors']['vendor_y']);

    $case = ProcurementCase::findOrFail($fixture['cases']['C']->id);

    expect($case->quotations)->toHaveCount(1)
        ->and($case->requisition)->not->toBeNull()
        ->and($case->tenderAwards)->toHaveCount(1)
        ->and($case->purchaseOrders)->toHaveCount(1)
        ->and($case->stockExpiries)->toHaveCount(1);
});

it('hides another org\'s child rows from a vendor even by id', function () {
    $fixture = visibilityFixture();
    addChildren($fixture['cases']['A']); // org X
    actAs($fixture['actors']['vendor_y']);

    foreach (CHILD_MODELS as $model) {
        $id = DB::table((new $model)->getTable())->where('case_id', $fixture['cases']['A']->id)->value('id');

        expect($model::find($id))->toBeNull()
            ->and($model::whereKey($id)->get())->toHaveCount(0)
            ->and($model::whereKey($id)->count())->toBe(0);
    }
});
