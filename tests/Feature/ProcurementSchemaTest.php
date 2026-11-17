<?php

use App\Enums\OrganisationType;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Slice 6b schema. Runs against the dev database inside DatabaseTransactions
// (tests/Pest.php), so every row written here is rolled back.
//
// Statements expected to fail run inside DB::transaction() (a savepoint), so the
// error rolls back only that statement and the test transaction stays usable.

/**
 * Expected SQL Server types per column (as reported by Schema::getColumns).
 * Slice F1 added the columns marked "F1" and slice V2-1 those marked "V2" (additive).
 */
const SLICE_6B_COLUMNS = [
    'procurement_types' => [
        'id' => 'bigint', 'code' => 'nvarchar(40)', 'name' => 'nvarchar(120)', 'category' => 'nvarchar(16)',
        'is_active' => 'bit', 'sort_order' => 'smallint', 'created_at' => 'datetime', 'updated_at' => 'datetime',
    ],
    'procurement_type_documents' => [
        'id' => 'bigint', 'procurement_type_id' => 'bigint', 'purpose' => 'nvarchar(64)', 'label' => 'nvarchar(160)',
        'is_required' => 'bit', 'sort_order' => 'smallint', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        'hint' => 'nvarchar(255)', // F1
    ],
    'case_requisitions' => [
        'id' => 'bigint', 'case_id' => 'nchar(26)', 'vendor_reference' => 'nvarchar(64)', 'request_date' => 'date',
        'need_classification' => 'nvarchar(16)', 'item_description' => 'nvarchar(500)', 'din' => 'nvarchar(32)',
        'item_code' => 'nvarchar(64)', 'unit_of_measure' => 'nvarchar(32)', 'purpose' => 'nvarchar(max)',
        'quantity_requested' => 'decimal(14,2)', 'average_monthly_consumption' => 'decimal(14,2)',
        'central_stores_stock' => 'decimal(14,2)', 'rha_stock' => 'decimal(14,2)',
        'stock_verified_on' => 'date', 'stockout_date' => 'date', 'urgency_explanation' => 'nvarchar(max)',
        'tender_reference' => 'nvarchar(120)', 'tender_outcome' => 'nvarchar(32)',
        'tender_issue_explanation' => 'nvarchar(max)', 'rfq_date' => 'date', 'rfq_audience' => 'nvarchar(24)',
        'sample_evaluation_summary' => 'nvarchar(max)', 'applicant_comments' => 'nvarchar(max)',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
        // F1
        'strength' => 'nvarchar(100)', 'dosage_form' => 'nvarchar(100)', 'clinical_note' => 'nvarchar(max)',
        'stock_location' => 'nvarchar(200)', 'no_stock_on_hand' => 'bit', 'quantity_to_purchase' => 'decimal(14,2)',
        'tender_price' => 'decimal(14,4)', 'previous_price' => 'decimal(14,4)', 'quoted_price_acceptable' => 'bit',
        'price_note' => 'nvarchar(max)',
        // V2
        'tender_cycle_start' => 'date', 'tender_cycle_end' => 'date', 'quantity_on_order' => 'decimal(14,2)',
    ],
    'case_quotations' => [
        'id' => 'bigint', 'case_id' => 'nchar(26)', 'sort_order' => 'smallint', 'supplier_name' => 'nvarchar(200)',
        'manufacturer' => 'nvarchar(200)', 'brand' => 'nvarchar(200)', 'unit_price' => 'decimal(14,4)',
        'currency' => 'nchar(3)', 'vat_rate' => 'decimal(5,2)', 'quantity_offered' => 'decimal(14,2)',
        'lead_time_days' => 'smallint', 'minimum_order_quantity' => 'decimal(14,2)', 'price_valid_until' => 'date',
        'quantity_available_now' => 'decimal(14,2)', 'previous_price_maintained' => 'nvarchar(8)',
        'reason_accepted_excluded' => 'nvarchar(500)', 'is_recommended' => 'bit',
        'recommended_quantity' => 'decimal(14,2)', 'technical_status' => 'nvarchar(40)',
        'technical_status_set_by' => 'bigint', 'technical_status_set_at' => 'datetime',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
        'considered' => 'bit', // F1
        'ex_stock' => 'bit', 'country_of_origin' => 'nvarchar(100)', 'remarks' => 'nvarchar(255)', // V2
    ],
    'case_tender_awards' => [
        'id' => 'bigint', 'case_id' => 'nchar(26)', 'sort_order' => 'smallint', 'supplier_name' => 'nvarchar(200)',
        'manufacturer' => 'nvarchar(200)', 'brand' => 'nvarchar(200)', 'quantity_awarded' => 'decimal(14,2)',
        'unit_cost' => 'decimal(14,4)', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        'country_of_origin' => 'nvarchar(100)', // V2
    ],
    'case_purchase_orders' => [
        'id' => 'bigint', 'case_id' => 'nchar(26)', 'sort_order' => 'smallint', 'supplier_name' => 'nvarchar(200)',
        'po_number' => 'nvarchar(64)', 'quantity_ordered' => 'decimal(14,2)', 'ordered_on' => 'date',
        'quantity_delivered' => 'decimal(14,2)', 'delivery_eta' => 'date', 'delivery_status' => 'nvarchar(120)',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
        'award_id' => 'bigint', // F1
    ],
    'case_stock_expiries' => [
        'id' => 'bigint', 'case_id' => 'nchar(26)', 'quantity' => 'decimal(14,2)', 'expiry_date' => 'date',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ],
    'case_variations' => [ // V2
        'id' => 'bigint', 'case_id' => 'nchar(26)', 'variation_type' => 'nvarchar(100)',
        'existing_detail' => 'nvarchar(500)', 'proposed_detail' => 'nvarchar(500)', 'effective_date' => 'date',
        'created_at' => 'datetime', 'updated_at' => 'datetime',
    ],
];

function procurementType(array $attributes = []): int
{
    return DB::table('procurement_types')->insertGetId([
        'code' => 'TEST-'.Str::upper(Str::random(10)),
        'name' => 'Test type',
        'category' => 'PHARM',
        ...$attributes,
    ]);
}

function insertCase(array $attributes = []): string
{
    $moh = Organisation::where('type', OrganisationType::Moh)->firstOrFail();
    $user = User::factory()->create(['email' => 'schema-'.Str::lower(Str::ulid()).'@example.test']);
    $id = Str::lower((string) Str::ulid());

    DB::table('cases')->insert([
        'id' => $id,
        'org_id' => $moh->id,
        'created_by' => $user->id,
        'title' => 'Schema test case',
        'procurement_type_id' => procurementType(),
        ...$attributes,
    ]);

    return $id;
}

function quotationRow(string $caseId, array $attributes = []): array
{
    return [
        'case_id' => $caseId,
        'supplier_name' => 'Supplier A',
        'unit_price' => '1.5975',
        'quantity_offered' => '100.00',
        ...$attributes,
    ];
}

/** Runs $insert in a savepoint and expects the database to reject it. */
function expectRejected(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('creates every slice 6b table and column with the expected type', function () {
    foreach (SLICE_6B_COLUMNS as $table => $expected) {
        // INFORMATION_SCHEMA rather than Schema::getColumns(): on sqlsrv the latter
        // reports nvarchar lengths in bytes (nvarchar(40) shows as nvarchar(80)).
        $actual = collect(DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
             FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        ))->mapWithKeys(fn ($c) => [$c->COLUMN_NAME => match (true) {
            $c->DATA_TYPE === 'decimal' => "decimal({$c->NUMERIC_PRECISION},{$c->NUMERIC_SCALE})",
            (int) $c->CHARACTER_MAXIMUM_LENGTH === -1 => "{$c->DATA_TYPE}(max)",
            $c->CHARACTER_MAXIMUM_LENGTH !== null => "{$c->DATA_TYPE}({$c->CHARACTER_MAXIMUM_LENGTH})",
            default => $c->DATA_TYPE,
        }])->all();

        expect($actual)->toEqual($expected, "column types differ on {$table}");
    }

    // Every case_id above is nchar(26); it must match the cases primary key exactly.
    $casesId = DB::selectOne(
        "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'cases' AND COLUMN_NAME = 'id'",
    );
    expect("{$casesId->DATA_TYPE}({$casesId->CHARACTER_MAXIMUM_LENGTH})")->toBe('nchar(26)');
});

it('sets the documented column defaults', function () {
    $defaults = fn (string $table) => collect(Schema::getColumns($table))
        ->mapWithKeys(fn ($c) => [$c['name'] => $c['default']]);

    $quotation = $defaults('case_quotations');
    expect($quotation['currency'])->toContain('TTD')
        ->and($quotation['previous_price_maintained'])->toContain('UNKNOWN')
        ->and($quotation['technical_status'])->toContain('PENDING')
        ->and($quotation['is_recommended'])->toContain('0')
        ->and($defaults('procurement_types')['is_active'])->toContain('1')
        ->and($defaults('case_purchase_orders')['quantity_delivered'])->toContain('0');
});

// Slice F1 ---------------------------------------------------------------------------

it('sets the F1 defaults: considered 1, no_stock_on_hand 0', function () {
    $defaults = fn (string $table) => collect(Schema::getColumns($table))->mapWithKeys(fn ($c) => [$c['name'] => $c['default']]);

    expect($defaults('case_quotations')['considered'])->toContain('1')
        ->and($defaults('case_requisitions')['no_stock_on_hand'])->toContain('0');

    $caseId = insertCase();
    $quotationId = DB::table('case_quotations')->insertGetId(quotationRow($caseId));
    $requisitionId = DB::table('case_requisitions')->insertGetId(['case_id' => $caseId]);

    expect((bool) DB::table('case_quotations')->where('id', $quotationId)->value('considered'))->toBeTrue()
        ->and((bool) DB::table('case_requisitions')->where('id', $requisitionId)->value('no_stock_on_hand'))->toBeFalse();
});

it('accepts partial child rows: the vendor-typed columns are nullable', function () {
    $caseId = insertCase();

    DB::table('case_quotations')->insert(['case_id' => $caseId]);
    DB::table('case_tender_awards')->insert(['case_id' => $caseId]);
    DB::table('case_purchase_orders')->insert(['case_id' => $caseId, 'quantity_delivered' => null]);
    DB::table('case_stock_expiries')->insert(['case_id' => $caseId]);

    $nullable = fn (string $table, array $columns) => collect(Schema::getColumns($table))
        ->whereIn('name', $columns)->pluck('nullable', 'name')->all();

    expect($nullable('case_quotations', ['supplier_name', 'unit_price', 'quantity_offered']))->toBe(['supplier_name' => true, 'unit_price' => true, 'quantity_offered' => true])
        ->and($nullable('case_tender_awards', ['supplier_name', 'quantity_awarded', 'unit_cost']))->toBe(['supplier_name' => true, 'quantity_awarded' => true, 'unit_cost' => true])
        ->and($nullable('case_purchase_orders', ['supplier_name', 'po_number', 'quantity_ordered', 'quantity_delivered', 'award_id']))->toEqual(['supplier_name' => true, 'po_number' => true, 'quantity_ordered' => true, 'quantity_delivered' => true, 'award_id' => true])
        ->and($nullable('case_stock_expiries', ['quantity', 'expiry_date']))->toBe(['quantity' => true, 'expiry_date' => true])
        ->and($nullable('procurement_types', ['category']))->toBe(['category' => true])
        // System and defaulted columns stay NOT NULL.
        ->and($nullable('case_quotations', ['technical_status', 'is_recommended', 'considered']))->toEqual(['technical_status' => false, 'is_recommended' => false, 'considered' => false]);
});

it('links an order to an award row with NO ACTION on delete', function () {
    $caseId = insertCase();
    $awardId = DB::table('case_tender_awards')->insertGetId(['case_id' => $caseId, 'supplier_name' => 'Supplier A']);
    DB::table('case_purchase_orders')->insert(['case_id' => $caseId, 'award_id' => $awardId]);

    $rule = DB::selectOne("
        SELECT fk.delete_referential_action_desc AS on_delete
        FROM sys.foreign_keys fk
        WHERE fk.parent_object_id = OBJECT_ID('case_purchase_orders') AND fk.name = 'case_purchase_orders_award_id_foreign'
    ");

    expect($rule?->on_delete)->toBe('NO_ACTION');
    expectRejected(fn () => DB::table('case_tender_awards')->where('id', $awardId)->delete());
    expectRejected(fn () => DB::table('case_purchase_orders')->insert(['case_id' => $caseId, 'award_id' => 999999999]));
});

it('replaces cases.category with a NOT NULL procurement_type_id', function () {
    $column = collect(Schema::getColumns('cases'))->firstWhere('name', 'procurement_type_id');

    expect(Schema::hasColumn('cases', 'category'))->toBeFalse()
        ->and($column)->not->toBeNull()
        ->and($column['type'])->toBe('bigint')
        ->and($column['nullable'])->toBeFalse()
        ->and(Schema::hasIndex('cases', ['procurement_type_id']))->toBeTrue();
});

it('keeps 4 decimal places on unit prices and unit costs', function () {
    $caseId = insertCase();

    $quotationId = DB::table('case_quotations')->insertGetId(quotationRow($caseId));
    $awardId = DB::table('case_tender_awards')->insertGetId([
        'case_id' => $caseId, 'supplier_name' => 'Supplier A',
        'quantity_awarded' => '10.00', 'unit_cost' => '1.5975',
    ]);

    expect((string) DB::table('case_quotations')->where('id', $quotationId)->value('unit_price'))->toBe('1.5975')
        ->and((string) DB::table('case_tender_awards')->where('id', $awardId)->value('unit_cost'))->toBe('1.5975');
});

it('enforces a unique procurement type code', function () {
    procurementType(['code' => 'DUP-CODE-6B']);

    expectRejected(fn () => procurementType(['code' => 'DUP-CODE-6B']));
});

it('enforces a unique purpose per procurement type', function () {
    $typeId = procurementType();
    $row = ['procurement_type_id' => $typeId, 'purpose' => 'QUOTATION', 'label' => 'Supplier quotation'];

    DB::table('procurement_type_documents')->insert($row);
    expectRejected(fn () => DB::table('procurement_type_documents')->insert($row));

    // The same purpose on another type is fine.
    expect(DB::table('procurement_type_documents')
        ->insert([...$row, 'procurement_type_id' => procurementType()]))->toBeTrue();
});

it('allows only one requisition per case', function () {
    $caseId = insertCase();

    DB::table('case_requisitions')->insert(['case_id' => $caseId]);
    expectRejected(fn () => DB::table('case_requisitions')->insert(['case_id' => $caseId]));
});

it('allows only one variation row per case, and blocks deleting its case (V2-1)', function () {
    $caseId = insertCase();

    DB::table('case_variations')->insert(['case_id' => $caseId]);
    expectRejected(fn () => DB::table('case_variations')->insert(['case_id' => $caseId]));
    expectRejected(fn () => DB::table('case_variations')->insert(['case_id' => Str::lower((string) Str::ulid())]));
    expectRejected(fn () => DB::table('cases')->where('id', $caseId)->delete());
});

it('rejects child rows for a case that does not exist', function () {
    $missing = Str::lower((string) Str::ulid());

    expectRejected(fn () => DB::table('case_requisitions')->insert(['case_id' => $missing]));
    expectRejected(fn () => DB::table('case_quotations')->insert(quotationRow($missing)));
    expectRejected(fn () => DB::table('case_stock_expiries')->insert([
        'case_id' => $missing, 'quantity' => '5.00', 'expiry_date' => '2027-01-31',
    ]));
});

it('rejects a case without a procurement type', function () {
    $moh = Organisation::where('type', OrganisationType::Moh)->firstOrFail();
    $user = User::factory()->create(['email' => 'schema-'.Str::lower(Str::ulid()).'@example.test']);

    expectRejected(fn () => DB::table('cases')->insert([
        'id' => Str::lower((string) Str::ulid()),
        'org_id' => $moh->id,
        'created_by' => $user->id,
        'title' => 'No type',
    ]));
});

it('blocks deleting a case that has child rows', function () {
    $caseId = insertCase();
    DB::table('case_purchase_orders')->insert([
        'case_id' => $caseId, 'supplier_name' => 'Supplier A', 'po_number' => 'PO-1', 'quantity_ordered' => '10.00',
    ]);

    expectRejected(fn () => DB::table('cases')->where('id', $caseId)->delete());
    expect(DB::table('cases')->where('id', $caseId)->exists())->toBeTrue();
});

it('blocks deleting a procurement type that is in use', function () {
    $caseId = insertCase();
    $typeId = DB::table('cases')->where('id', $caseId)->value('procurement_type_id');

    expectRejected(fn () => DB::table('procurement_types')->where('id', $typeId)->delete());
});
