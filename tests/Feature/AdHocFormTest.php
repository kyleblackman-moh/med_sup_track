<?php

use App\Documents\DocumentUploadService;
use App\Enums\DocumentPurpose;
use App\Enums\Role;
use App\Http\Resources\Requests\RequestDetailResource;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\AdHocReadiness;
use App\Submissions\ReadinessReport;
use App\Submissions\RequisitionData;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice F1: the single Ad Hoc form (AD_HOC). Rules, upsert save, readiness,
// computed figures and document categories. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function adHocType()
{
    return procurementTypeByCode('AD_HOC');
}

/** Validation errors (dotted key => first message) for an AD_HOC payload; [] when valid. */
function adHocValidationErrors(array $payload): array
{
    try {
        RequisitionData::validate($payload, adHocType());

        return [];
    } catch (ValidationException $e) {
        return collect($e->errors())->map(fn (array $messages) => $messages[0])->all();
    }
}

/**
 * A saved AD_HOC draft: the complete payload after $mutate, then $orders (by
 * default one order against the first award, when there is an award), then
 * the Bids file unless $bids is false. Returns the readiness report.
 */
function adHocReport(?Closure $mutate = null, ?Closure $orders = null, bool $bids = true): ReadinessReport
{
    [$case, $vendor] = newDraft();
    $payload = adHocPayload();
    if ($mutate) {
        $payload = $mutate($payload);
    }
    saveDraft($case, $vendor, $payload);

    $orderPayload = $orders
        ? $orders($case)
        : (CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->exists() ? adHocOrdersFor($case) : null);
    if ($orderPayload !== null) {
        saveDraft($case, $vendor, $orderPayload);
    }

    if ($bids) {
        attachRequiredDocuments($case);
    }

    return app(SubmissionReadiness::class)->evaluate($case);
}

function adHocErrorKeys(ReadinessReport $report): array
{
    return array_column($report->errors, 'key');
}

/** The case's rows of $model, by sort_order (or id), as id => row. */
function rowsOf(string $model, ProcurementCase $case): array
{
    $query = $model::withoutGlobalScopes()->where('case_id', $case->id);
    $query = $model === CaseStockExpiry::class ? $query->orderBy('id') : $query->orderBy('sort_order');

    return $query->get()->keyBy('id')->all();
}

// 1. Readiness: the complete payload passes; each missing item has its own error ----

it('reports the complete AD_HOC request as ready, with no errors or warnings', function () {
    $report = adHocReport();

    expect($report->errors)->toBe([])
        ->and($report->warnings)->toBe([])
        ->and($report->isReady())->toBeTrue();
});

it('reports each missing required item with its own key and message', function (Closure $mutate, array $expected, ?Closure $orders = null, bool $bids = true) {
    $report = adHocReport($mutate, $orders, $bids);

    expect($report->errors)->toBe($expected)
        ->and($report->isReady())->toBeFalse();
})->with([
    'description' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'item_description' => null]], [['key' => 'requisition.item_description', 'message' => 'Describe the item.']]],
    'description blank' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'item_description' => '   ']], [['key' => 'requisition.item_description', 'message' => 'Describe the item.']]],
    'unit' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'unit_of_measure' => null]], [['key' => 'requisition.unit_of_measure', 'message' => 'Enter the unit.']]],
    'tender reference' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_reference' => null]], [['key' => 'requisition.tender_reference', 'message' => 'Enter the tender period or reference.']]],
    'tender outcome' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_outcome' => null]], [['key' => 'requisition.tender_outcome', 'message' => 'Choose the tender outcome.']]],
    'rfq audience' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'rfq_audience' => null]], [['key' => 'requisition.rfq_audience', 'message' => 'Choose who received the RFQ.']]],
    'quantity requested' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_requested' => null]], [['key' => 'requisition.quantity_requested', 'message' => 'Enter a quantity requested greater than zero.']]],
    'quantity requested zero' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_requested' => '0']], [['key' => 'requisition.quantity_requested', 'message' => 'Enter a quantity requested greater than zero.']]],
    'consumption' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'average_monthly_consumption' => null]], [['key' => 'requisition.average_monthly_consumption', 'message' => 'Enter an average monthly consumption greater than zero.']]],
    'quantity to purchase' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_to_purchase' => null]], [['key' => 'requisition.quantity_to_purchase', 'message' => 'Enter a quantity to purchase greater than zero.']]],
    'quantity to purchase zero' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_to_purchase' => '0.00']], [['key' => 'requisition.quantity_to_purchase', 'message' => 'Enter a quantity to purchase greater than zero.']]],
    'no award row (partially awarded)' => [fn ($p) => [...$p, 'tender_awards' => []], [['key' => 'tender_awards', 'message' => AdHocReadiness::AWARD_ROW_NEEDED]]],
    'only an incomplete award row' => [fn ($p) => [...$p, 'tender_awards' => [['supplier_name' => 'Supplier A', 'quantity_awarded' => '10']]], [['key' => 'tender_awards', 'message' => AdHocReadiness::AWARD_ROW_NEEDED]], fn () => null],
    'no award row (unable to supply)' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_outcome' => 'SUPPLIER_UNABLE_TO_SUPPLY'], 'tender_awards' => []], [['key' => 'tender_awards', 'message' => AdHocReadiness::AWARD_ROW_NEEDED]]],
    'order without award' => [fn ($p) => $p, [['key' => 'purchase_orders.0.award_id', 'message' => AdHocReadiness::ORDER_AWARD]], fn () => ['purchase_orders' => [['po_number' => 'PO-1', 'quantity_ordered' => '10']]]],
    'order without PO number or quantity' => [fn ($p) => $p, [
        ['key' => 'purchase_orders.0.po_number', 'message' => AdHocReadiness::ORDER_PO_NUMBER],
        ['key' => 'purchase_orders.0.quantity_ordered', 'message' => AdHocReadiness::ORDER_QUANTITY],
    ], fn (ProcurementCase $case) => ['purchase_orders' => [['award_id' => (int) CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->value('id')]]]],
    'no stock and no batch' => [fn ($p) => [...$p, 'stock_expiries' => []], [['key' => 'stock_expiries', 'message' => AdHocReadiness::STOCK_NEEDED]]],
    'only an incomplete batch' => [fn ($p) => [...$p, 'stock_expiries' => [['quantity' => '5']]], [['key' => 'stock_expiries', 'message' => AdHocReadiness::STOCK_NEEDED]]],
    'no quotations' => [fn ($p) => [...$p, 'quotations' => []], [
        ['key' => 'quotations', 'message' => AdHocReadiness::QUOTATION_NEEDED],
        ['key' => 'quotations.recommended', 'message' => AdHocReadiness::RECOMMEND_ONE],
    ]],
    'only an incomplete quotation' => [fn ($p) => [...$p, 'quotations' => [['supplier_name' => 'Supplier D', 'unit_price' => '1.00', 'is_recommended' => true]]], [['key' => 'quotations', 'message' => AdHocReadiness::QUOTATION_NEEDED]]],
    'none recommended' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier C', '1.40'), adHocQuoteRow('Supplier D', '1.5975')]], [['key' => 'quotations.recommended', 'message' => AdHocReadiness::RECOMMEND_ONE]]],
    'two recommended' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier C', '1.40', ['is_recommended' => true]), adHocQuoteRow('Supplier D', '1.5975', ['is_recommended' => true])]], [['key' => 'quotations.recommended', 'message' => AdHocReadiness::RECOMMEND_ONLY_ONE]]],
    'not considered without a reason' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier C', '1.40', ['considered' => false, 'reason_accepted_excluded' => '  ']), adHocQuoteRow('Supplier D', '1.5975', ['is_recommended' => true])]], [['key' => 'quotations.0.reason_accepted_excluded', 'message' => AdHocReadiness::REASON_NEEDED]]],
    'no Bids file' => [fn ($p) => $p, [['key' => 'documents.bids', 'message' => 'Required document missing: Bids']], null, false],
]);

it('accepts the optional parts left empty', function (Closure $mutate, ?Closure $orders = null) {
    $report = adHocReport($mutate, $orders);

    expect($report->errors)->toBe([])->and($report->isReady())->toBeTrue();
})->with([
    'strength, dosage form, DIN, clinical note, location, price comparison' => [fn ($p) => [...$p, 'requisition' => [
        ...$p['requisition'],
        'strength' => null, 'dosage_form' => null, 'din' => null, 'clinical_note' => null, 'stock_location' => null,
        'tender_price' => null, 'previous_price' => null, 'quoted_price_acceptable' => null, 'price_note' => null,
    ]]],
    'no orders' => [fn ($p) => $p, fn () => null],
    'nothing awarded: no award or order rows' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_outcome' => 'NOT_AWARDED'], 'tender_awards' => []]],
    // The form hides the order table when nothing was awarded, so leftover rows do not block.
    'nothing awarded: a leftover incomplete order' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_outcome' => 'NOT_AWARDED'], 'tender_awards' => []], fn () => ['purchase_orders' => [['po_number' => 'PO-1']]]],
    'no stock on hand, no batches' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'no_stock_on_hand' => true], 'stock_expiries' => []]],
    'every quotation considered' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier D', '1.5975', ['is_recommended' => true])]]],
]);

it('lets an AD_HOC draft with every rule met be submitted', function () {
    [$case, $vendor] = readyDraft();

    app(SubmitCase::class)->handle($case, $vendor);

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED');
});

// 2. Draft save: lenient, partial data ---------------------------------------------

it('saves a partial draft: any field and any row may be incomplete', function () {
    [$case, $vendor] = newDraft();

    saveDraft($case, $vendor, [
        'requisition' => ['item_description' => 'Just started'],
        'quotations' => [[], ['supplier_name' => 'Supplier C']],
        'tender_awards' => [['unit_cost' => '1.25']],
        'purchase_orders' => [['po_number' => 'PO-9', 'quantity_delivered' => null]],
        'stock_expiries' => [['quantity' => '5'], ['expiry_date' => '2027-01-31']],
    ]);

    $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    $order = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($requisition->item_description)->toBe('Just started')
        ->and($requisition->quantity_requested)->toBeNull()
        ->and($requisition->no_stock_on_hand)->toBeFalse()
        ->and(CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(2)
        ->and(CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->value('supplier_name'))->toBeNull()
        ->and($order->award_id)->toBeNull()
        ->and($order->quantity_delivered)->toBeNull()
        ->and(CaseStockExpiry::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(2);
});

it('accepts an empty payload and saves nothing new', function () {
    [$case, $vendor] = newDraft();

    expect(adHocValidationErrors([]))->toBe([]);
    saveDraft($case, $vendor, []);

    expect(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(0);
});

it('still checks types, lengths and dates on a draft save', function (array $payload, string $key) {
    expect(adHocValidationErrors($payload))->toHaveKey($key);
})->with([
    [['requisition' => ['quantity_requested' => 'lots']], 'requisition.quantity_requested'],
    [['requisition' => ['tender_price' => '1.23456']], 'requisition.tender_price'],
    [['requisition' => ['tender_outcome' => 'AWARDED']], 'requisition.tender_outcome'],
    [['requisition' => ['item_description' => str_repeat('x', 501)]], 'requisition.item_description'],
    [['requisition' => ['clinical_note' => str_repeat('x', 2001)]], 'requisition.clinical_note'],
    [['requisition' => ['price_note' => str_repeat('x', 1001)]], 'requisition.price_note'],
    [['requisition' => ['no_stock_on_hand' => null]], 'requisition.no_stock_on_hand'],
    [['stock_expiries' => [['expiry_date' => '31/01/2027']]], 'stock_expiries.0.expiry_date'],
    [['quotations' => [['lead_time_days' => -1]]], 'quotations.0.lead_time_days'],
    [['quotations' => [['id' => 0]]], 'quotations.0.id'],
    [['quotations' => [['id' => 5], ['id' => 5]]], 'quotations.1.id'],
    [['purchase_orders' => [['award_id' => 'first']]], 'purchase_orders.0.award_id'],
    // Fields that left the Ad Hoc form are unknown keys for AD_HOC.
    [['requisition' => ['vendor_reference' => 'X']], 'requisition.vendor_reference'],
    [['requisition' => ['central_stores_stock' => '1']], 'requisition.central_stores_stock'],
    [['quotations' => [['vat_rate' => '12.5']]], 'quotations.0.vat_rate'],
    [['quotations' => [['recommended_quantity' => '1']]], 'quotations.0.recommended_quantity'],
    [['purchase_orders' => [['supplier_name' => 'X']]], 'purchase_orders.0.supplier_name'],
    [['quotations' => [['technical_status' => 'SAMPLE_ACCEPTED']]], 'quotations.0.technical_status'],
]);

it('caps the clinical note at 2000 and the price note at 1000 characters', function () {
    expect(adHocValidationErrors(['requisition' => ['clinical_note' => str_repeat('x', 2000), 'price_note' => str_repeat('x', 1000)]]))->toBe([]);
});

it('accepts a DIN of digits only, keeping leading zeros, with no length rule', function (?string $din) {
    expect(adHocValidationErrors(['requisition' => ['din' => $din]]))->toBe([]);

    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => $din]]);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din'))->toBe($din);
})->with([
    'leading zero' => ['02345678'],
    'one digit' => ['7'],
    'twelve digits' => ['000123456789'],
    '32 digits' => [str_repeat('1', 32)],
    'cleared' => [null],
]);

it('refuses a DIN that is not a string of digits', function (mixed $din, string $message) {
    expect(adHocValidationErrors(['requisition' => ['din' => $din]]))->toBe(['requisition.din' => $message]);
})->with([
    'letter' => ['0234567A', 'Enter the DIN as digits only.'],
    'space' => ['0234 5678', 'Enter the DIN as digits only.'],
    'dash' => ['0234-5678', 'Enter the DIN as digits only.'],
    'decimal' => ['123.4', 'Enter the DIN as digits only.'],
    'negative' => ['-123', 'Enter the DIN as digits only.'],
    // A number would lose leading zeros, so only text is accepted.
    'number, not text' => [2345678, 'The requisition.din field must be a string.'],
    // max 32 is the column size, not a DIN length rule.
    '33 digits' => [str_repeat('1', 33), 'The requisition.din field must not be greater than 32 characters.'],
]);

// 3. Upsert by row id ------------------------------------------------------------------

it('keeps row ids stable across saves, updating rows in place and in the new order', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());
    [$firstId, $secondId] = array_keys(rowsOf(CaseQuotation::class, $case));

    saveDraft($case, $vendor, ['quotations' => [
        ['id' => $secondId, 'unit_price' => '1.6000'],               // moved to the top, price changed
        ['id' => $firstId, 'brand' => 'Other Brand'],                // other keys unchanged
        adHocQuoteRow('Supplier E', '1.10'),                        // new row
    ]]);

    $rows = rowsOf(CaseQuotation::class, $case);
    $new = array_values(array_diff(array_keys($rows), [$firstId, $secondId]));
    expect(array_keys($rows))->toBe([$secondId, $firstId, ...$new])
        ->and($new)->toHaveCount(1)
        ->and($rows[$secondId]->unit_price)->toBe('1.6000')
        ->and($rows[$secondId]->supplier_name)->toBe('Supplier D')
        ->and($rows[$firstId]->brand)->toBe('Other Brand')
        ->and($rows[$firstId]->supplier_name)->toBe('Supplier C')
        ->and($rows[$firstId]->reason_accepted_excluded)->toBe('No registration on file.')
        ->and($rows[$new[0]]->supplier_name)->toBe('Supplier E');
});

it('deletes the case\'s rows that are missing from the payload, section by section', function (string $section, string $model) {
    [$case, $vendor] = newDraft();
    saveCompleteAdHoc($case, $vendor);
    $ids = array_keys(rowsOf($model, $case));
    $keep = $ids[0];

    saveDraft($case, $vendor, [$section => [['id' => $keep]]]);

    expect(array_keys(rowsOf($model, $case)))->toBe([$keep]);
})->with([
    ['quotations', CaseQuotation::class],
    ['tender_awards', CaseTenderAward::class],
    ['stock_expiries', CaseStockExpiry::class],
]);

it('refuses a row id that is not a row of this case and section, and saves nothing', function (Closure $foreignId) {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());
    $before = array_keys(rowsOf(CaseQuotation::class, $case));
    $id = $foreignId($case);

    try {
        saveDraft($case, $vendor, ['requisition' => ['strength' => 'CHANGED'], 'quotations' => [['id' => $id, 'brand' => 'X']]]);
        $this->fail('saved');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('quotations.0.id');
    }

    expect(array_keys(rowsOf(CaseQuotation::class, $case)))->toBe($before)
        ->and(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('strength'))->toBe('6Fr');
})->with([
    'another case\'s quotation' => [function () {
        [$other, $otherVendor] = newDraft();
        saveDraft($other, $otherVendor, adHocPayload());

        return (int) CaseQuotation::withoutGlobalScopes()->where('case_id', $other->id)->value('id');
    }],
    'an award row of this case' => [fn (ProcurementCase $case) => (int) CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->value('id')],
    'a row id that does not exist' => [fn () => 999999999],
]);

// 4. Orders and awards -------------------------------------------------------------

it('sets award_id to null on the orders of a removed award, and readiness then flags them', function () {
    [$case, $vendor] = newDraft();
    saveCompleteAdHoc($case, $vendor);
    attachRequiredDocuments($case);
    [$awardA, $awardB] = array_keys(rowsOf(CaseTenderAward::class, $case));
    $order = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($order->award_id)->toBe($awardA);

    saveDraft($case, $vendor, ['tender_awards' => [['id' => $awardB]]]);

    expect(array_keys(rowsOf(CaseTenderAward::class, $case)))->toBe([$awardB])
        ->and($order->fresh()->award_id)->toBeNull()
        ->and(adHocErrorKeys(app(SubmissionReadiness::class)->evaluate($case)))->toBe(['purchase_orders.0.award_id']);
});

it('refuses an order that points at an award being removed in the same save, and saves nothing', function () {
    [$case, $vendor] = newDraft();
    saveCompleteAdHoc($case, $vendor);
    [$awardA, $awardB] = array_keys(rowsOf(CaseTenderAward::class, $case));
    $order = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->sole();

    expect(fn () => saveDraft($case, $vendor, [
        'tender_awards' => [['id' => $awardB]],
        'purchase_orders' => [['id' => $order->id, 'award_id' => $awardA]],
    ]))->toThrow(ValidationException::class);

    expect(array_keys(rowsOf(CaseTenderAward::class, $case)))->toBe([$awardA, $awardB])
        ->and($order->fresh()->award_id)->toBe($awardA);
});

it('refuses an order that points at another case\'s award', function () {
    [$other, $otherVendor] = newDraft();
    saveCompleteAdHoc($other, $otherVendor);
    $foreignAward = (int) CaseTenderAward::withoutGlobalScopes()->where('case_id', $other->id)->value('id');
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());

    try {
        saveDraft($case, $vendor, ['purchase_orders' => [['award_id' => $foreignAward, 'po_number' => 'PO-1']]]);
        $this->fail('saved');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['purchase_orders.0.award_id' => ['Choose a supplier from the award rows.']]);
    }
    expect(CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(0);
});

it('lets one supplier have several orders and shows the order supplier from its award', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());
    [$awardA] = array_keys(rowsOf(CaseTenderAward::class, $case));
    saveDraft($case, $vendor, ['purchase_orders' => [
        ['award_id' => $awardA, 'po_number' => 'PO-1', 'quantity_ordered' => '1000'],
        ['award_id' => $awardA, 'po_number' => 'PO-2', 'quantity_ordered' => '500'],
    ]]);

    $orders = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}"))['case']['purchase_orders'];

    expect(collect($orders)->pluck('award_id')->all())->toBe([$awardA, $awardA])
        ->and(collect($orders)->pluck('derived.supplier_name')->all())->toBe(['Supplier A', 'Supplier A']);
});

// 5. Computed figures --------------------------------------------------------------

it('computes the AD_HOC figures on the server', function () {
    [$case, $vendor] = newDraft();
    saveCompleteAdHoc($case, $vendor);

    $detail = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk())['case'];

    expect($detail['figures'])->toBe([
        'suppliers_awarded' => 2,
        'quotations_received' => 2,
        'quotations_considered' => 1,
        'recommended_unit_price' => '1.5975',
        'total_cost' => '4473.00',                          // 1.5975 x 2800
        'price_change_vs_tender_percent' => '27.80',        // (1.5975 - 1.25) / 1.25
        'price_change_vs_previous_percent' => '6.50',       // (1.5975 - 1.50) / 1.50
    ])
        ->and(collect($detail['tender_awards'])->pluck('derived.percentage')->all())->toBe(['75.00', '25.00'])
        ->and(collect($detail['tender_awards'])->pluck('derived.total')->all())->toBe(['3750.00', '1300.00'])
        ->and($detail['totals']['tender_awards'])->toBe(['quantity_awarded' => '4000.00', 'total' => '5050.00'])
        ->and($detail['purchase_orders'][0]['derived']['quantity_outstanding'])->toBe('1800.00')
        ->and(collect($detail['quotations'])->pluck('derived.offered_total')->all())->toBe(['4200.00', '4792.50'])
        ->and($detail['requisition']['derived'])->toBe(['stock_on_hand' => '200.50']); // 120 + 80.5; no coverage for the vendor
});

it('shows coverage (stock / consumption) to staff only', function () {
    [$case, $vendor] = readyDraft();
    app(SubmitCase::class)->handle($case, $vendor);

    $staff = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk())['case'];
    $vendorView = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk())['case'];

    expect($staff['requisition']['derived'])->toBe(['stock_on_hand' => '200.50', 'coverage_months' => '0.40']) // 200.50 / 500
        ->and($staff['category'])->toBeNull()
        ->and($staff['figures'])->toBe($vendorView['figures'])
        ->and($vendorView['requisition']['derived'])->not->toHaveKey('coverage_months');
});

it('computes each figure from the saved rows only, null when not computable', function (Closure $mutate, string $figure, mixed $expected) {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, $mutate(adHocPayload()));
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    actAs($vendor);

    $content = RequestDetailResource::requisitionContent($case->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries']), forStaff: true);
    $values = [...$content['figures'], ...$content['requisition']['derived']];

    expect($values[$figure])->toBe($expected);
})->with([
    'no stock on hand ignores the batches' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'no_stock_on_hand' => true]], 'stock_on_hand', '0.00'],
    'no batches, no flag' => [fn ($p) => [...$p, 'stock_expiries' => []], 'stock_on_hand', null],
    'batch without quantity skipped' => [fn ($p) => [...$p, 'stock_expiries' => [['quantity' => '10'], ['expiry_date' => '2027-01-31']]], 'stock_on_hand', '10.00'],
    'coverage needs consumption' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'average_monthly_consumption' => null]], 'coverage_months', null],
    'same supplier twice counts once' => [fn ($p) => [...$p, 'tender_awards' => [['supplier_name' => 'Supplier A'], ['supplier_name' => ' supplier a '], ['supplier_name' => null]]], 'suppliers_awarded', 1],
    'blank quotation rows are not received' => [fn ($p) => [...$p, 'quotations' => [...$p['quotations'], [], ['supplier_name' => '  ']]], 'quotations_received', 2],
    'no tender price' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_price' => null]], 'price_change_vs_tender_percent', null],
    'zero previous price' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'previous_price' => '0']], 'price_change_vs_previous_percent', null],
    'price decrease' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'previous_price' => '2.1300']], 'price_change_vs_previous_percent', '-25.00'],
    'two recommended: no total cost' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('C', '1.00', ['is_recommended' => true]), adHocQuoteRow('D', '2.00', ['is_recommended' => true])]], 'total_cost', null],
    'no quantity to purchase: no total cost' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_to_purchase' => null]], 'total_cost', null],
]);

it('treats a blank quantity delivered as 0 for the quantity outstanding', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());
    $award = (int) CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->value('id');
    saveDraft($case, $vendor, ['purchase_orders' => [['award_id' => $award, 'po_number' => 'PO-1', 'quantity_ordered' => '300', 'quantity_delivered' => null]]]);

    $detail = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}"))['case'];

    expect($detail['purchase_orders'][0]['quantity_delivered'])->toBeNull()
        ->and($detail['purchase_orders'][0]['derived']['quantity_outstanding'])->toBe('300.00');
});

// 6. Document categories -----------------------------------------------------------

it('accepts AD_HOC uploads in the bids, regulatory and evidence categories', function (DocumentPurpose $purpose) {
    [$case, $vendor] = newDraft();

    $document = app(DocumentUploadService::class)->store($case, $vendor, $purpose, upload('file.pdf', pdfBytes()));

    expect($document->purpose)->toBe($purpose);
})->with([DocumentPurpose::bids, DocumentPurpose::regulatory, DocumentPurpose::evidence]);

it('accepts several files in one category, one request each', function () {
    [$case, $vendor] = newDraft();

    foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
        $this->actingAs($vendor)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload($name, pdfBytes())])
            ->assertRedirect();
    }

    expect(DB::table('documents')->where('case_id', $case->id)->where('purpose', 'bids')->whereNull('supersedes_id')->count())->toBe(3);
});

it('refuses the old categories for AD_HOC', function (DocumentPurpose $purpose) {
    [$case, $vendor] = newDraft();

    expect(fn () => app(DocumentUploadService::class)->store($case, $vendor, $purpose, upload('file.pdf', pdfBytes())))
        ->toThrow(ValidationException::class, 'This document is not part of this type of request.');
})->with([DocumentPurpose::original_rfq, DocumentPurpose::signed_request, DocumentPurpose::other_supporting]);

it('refuses the new categories for an old type', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_PHARM');

    expect(fn () => app(DocumentUploadService::class)->store($case, $vendor, DocumentPurpose::bids, upload('file.pdf', pdfBytes())))
        ->toThrow(ValidationException::class);
    expect(app(DocumentUploadService::class)->store($case, $vendor, DocumentPurpose::original_rfq, upload('file.pdf', pdfBytes()))->purpose)
        ->toBe(DocumentPurpose::original_rfq);
});

// 7. Old types and old cases still load ----------------------------------------------

it('still loads an old-type draft with its old fields and form config', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_PHARM');
    saveDraft($case, $vendor, pharmPayload());

    $props = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit")->assertOk());

    expect($props['config']['is_pharm'])->toBeTrue()
        ->and($props['case']['procurement_type']['category'])->toBe('PHARM')
        ->and($props['case']['requisition']['vendor_reference'])->toBe('REQ-2026-001')
        ->and($props['case']['requisition']['derived'])->toHaveKeys(['stock_on_hand', 'coverage_months'])
        ->and($props['case']['figures'])->toBeNull()
        ->and(collect($props['documents'])->pluck('purpose')->first())->toBe('original_rfq');
});

it('still shows a submitted old-type case to the vendor and to staff', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_NON_PHARM');
    saveDraft($case, $vendor, nonPharmPayload());
    attachRequiredDocuments($case);
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    $vendorView = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk())['case'];
    $staff = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk())['case'];
    $receipt = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}/receipt")->assertOk())['receipt'];

    expect($vendorView['procurement_type']['code'])->toBe('AD_HOC_NON_PHARM')
        ->and($vendorView['requisition']['vendor_reference'])->toBe('REQ-2026-001')
        ->and($vendorView['quotations'])->toHaveCount(6)
        ->and($staff['category'])->toBe('NON_PHARM')
        ->and($staff['totals']['quotations']['recommended_total'])->toBe('4473.00')
        ->and($receipt['procurement_type'])->toBe('Ad hoc purchase (Non-Pharm)')
        ->and($receipt['documents'])->toHaveCount(6);
});
