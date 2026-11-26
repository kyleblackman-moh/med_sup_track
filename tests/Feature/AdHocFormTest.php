<?php

use App\Documents\DocumentUploadService;
use App\Enums\DocumentPurpose;
use App\Enums\Role;
use App\Http\Resources\Requests\RequestDetailResource;
use App\Http\Resources\Requests\RequestReceipt;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\ReadinessReport;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
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
    return procurementTypeByCode('AD_HOC_TENDER_UNABLE');
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

// 1. Readiness ----------------------------------------------------------------------
// Slice V2-1: the rules per request type (and the tender outcome rules they
// replaced) are tested in RequestSectionsTest.

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
        'quotations' => [[], ['supplier_name' => 'Supplier C'], ['considered' => true, 'is_recommended' => false]],
        'tender_awards' => [['unit_cost' => '1.25']],
        'purchase_orders' => [['po_number' => 'PO-9', 'quantity_delivered' => null]],
        'stock_expiries' => [['quantity' => '5'], ['expiry_date' => '2027-01-31']],
    ]);

    $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    $order = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($requisition->item_description)->toBe('Just started')
        ->and($requisition->quantity_requested)->toBeNull()
        ->and($requisition->no_stock_on_hand)->toBeFalse()
        // Slice F2: the two blank quotation rows are dropped; the partly filled one is kept.
        ->and(CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->pluck('supplier_name')->all())->toBe(['Supplier C'])
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

// Kyle, 2026-11-23: the DIN is always exactly 8 digits (optional; leading zeros kept).
it('accepts a DIN of exactly 8 digits, keeping leading zeros', function (?string $din) {
    expect(adHocValidationErrors(['requisition' => ['din' => $din]]))->toBe([]);

    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => $din]]);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din'))->toBe($din);
})->with([
    'leading zero' => ['02345678'],
    'all zeros but one' => ['00000007'],
    'eight digits' => ['12345678'],
    'cleared' => [null],
]);

it('refuses a DIN that is not exactly 8 digits', function (mixed $din, string $message) {
    expect(adHocValidationErrors(['requisition' => ['din' => $din]]))->toBe(['requisition.din' => $message]);
})->with([
    'space' => ['0234 5678', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'dash' => ['0234-5678', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'decimal' => ['123.4', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'negative' => ['-123', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'one digit' => ['7', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'seven digits' => ['0234567', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'nine digits' => ['023456789', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    'letters' => ['ABC12345', 'Enter the DIN as exactly 8 digits, for example 02345678.'],
    // A number would lose leading zeros, so only text is accepted.
    'number, not text' => [2345678, 'DIN needs to be text.'],
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
    [$case, $vendor] = readyDraft('TENDER_VARIATION'); // 2026-11-24: the only type that shows orders
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
        expect($e->errors())->toBe(['purchase_orders.0.award_id' => ['Order Row 1: Supplier needs to be one of the awarded suppliers.']]);
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
        'days_on_hand' => 12,                               // V2-1: 200.50 / (500 / 30) = 12.03, whole days
        'expiry_warnings' => [],                            // V2-1: the fixture's batches are well covered
        'suppliers_awarded' => 2,
        'quotations_received' => 2,
        'quotations_considered' => 1,
        'recommended_unit_price' => '1.5975',
        'total_cost' => '5032.13',                          // 2800 x 1.5975 = 4473.00, plus 12.5% VAT 559.13
        'price_change_vs_tender_percent' => '27.80',        // (1.5975 - 1.25) / 1.25
        'price_change_vs_previous_percent' => '6.50',       // (1.5975 - 1.50) / 1.50
    ])
        ->and(collect($detail['tender_awards'])->pluck('derived.percentage')->all())->toBe(['75.00', '25.00'])
        ->and(collect($detail['tender_awards'])->pluck('derived.total')->all())->toBe(['2625.00', '910.00'])
        ->and($detail['totals']['tender_awards'])->toBe(['quantity_awarded' => '2800.00', 'total' => '3535.00'])
        ->and($detail['purchase_orders'][0]['derived']['quantity_outstanding'])->toBe('1800.00')
        ->and(collect($detail['quotations'])->pluck('derived.offered_total')->all())->toBe(['3920.00', '4473.00']) // 2800 offered (not above the quantity requested)
        ->and($detail['requisition']['derived'])->toBe(['stock_on_hand' => '200.50']); // 120 + 80.5; no coverage for the vendor
});

it('shows coverage (stock / consumption) to staff only', function () {
    [$case, $vendor] = readyDraft();
    app(SubmitCase::class)->handle($case, $vendor);

    $staff = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk())['case'];
    $vendorView = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk())['case'];

    expect($staff['requisition']['derived'])->toBe(['stock_on_hand' => '200.50', 'coverage_months' => '0.40']) // 200.50 / 500
        ->and($staff['category'])->toBe('AD_HOC')
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
    // 2026-11-24: the quantity on hand is typed; batch quantities are never read.
    'typed 0' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_on_hand' => '0']], 'stock_on_hand', '0.00'],
    'not typed' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_on_hand' => null]], 'stock_on_hand', null],
    'batch quantities not read' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'quantity_on_hand' => '10'], 'stock_expiries' => [['quantity' => '999', 'expiry_date' => '2027-01-31']]], 'stock_on_hand', '10.00'],
    'coverage needs consumption' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'average_monthly_consumption' => null]], 'coverage_months', null],
    'same supplier twice counts once' => [fn ($p) => [...$p, 'tender_awards' => [['supplier_name' => 'Supplier A'], ['supplier_name' => ' supplier a '], ['supplier_name' => null]]], 'suppliers_awarded', 1],
    'blank quotation rows are not received' => [fn ($p) => [...$p, 'quotations' => [...$p['quotations'], [], ['supplier_name' => '  ']]], 'quotations_received', 2],
    'no tender price' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'tender_price' => null]], 'price_change_vs_tender_percent', null],
    'zero previous price' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'previous_price' => '0']], 'price_change_vs_previous_percent', null],
    'price decrease' => [fn ($p) => [...$p, 'requisition' => [...$p['requisition'], 'previous_price' => '2.1300']], 'price_change_vs_previous_percent', '-25.00'],
    // 2026-11-23: the total cost is the recommended rows' Total Price (2800 x price, plus 12.5% VAT), summed.
    'two recommended: their totals summed' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('C', '1.00', ['is_recommended' => true]), adHocQuoteRow('D', '2.00', ['is_recommended' => true])]], 'total_cost', '9450.00'],
    'no recommended row: no total cost' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('C', '1.00')]], 'total_cost', null],
    'no unit price on the recommended row: no total cost' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('C', '1.00', ['is_recommended' => true, 'unit_price' => null])]], 'total_cost', null],
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

it('accepts AD_HOC uploads in the bids, regulatory, evidence and additional categories', function (DocumentPurpose $purpose) {
    [$case, $vendor] = newDraft();

    $document = app(DocumentUploadService::class)->store($case, $vendor, $purpose, upload('file.pdf', pdfBytes()));

    expect($document->purpose)->toBe($purpose);
})->with([DocumentPurpose::bids, DocumentPurpose::regulatory, DocumentPurpose::evidence, DocumentPurpose::other_supporting]);

it('accepts several files in one category, one request each', function () {
    [$case, $vendor] = newDraft();

    foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
        $this->actingAs($vendor)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload($name, pdfBytes())])
            ->assertRedirect();
    }

    expect(DB::table('documents')->where('case_id', $case->id)->where('purpose', 'bids')->whereNull('supersedes_id')->count())->toBe(3);
});

// UI-3: the drop zone sends a batch one file per request, in order.
it('keeps a batch going past a rejected file, with the error on that request only', function () {
    [$case, $vendor] = newDraft();
    $edit = route('requests.edit', $case->id);

    $this->actingAs($vendor)->from($edit)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('a.pdf', pdfBytes())])
        ->assertRedirect($edit)->assertSessionHasNoErrors();
    $this->actingAs($vendor)->from($edit)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('b.png', pngBytes())])
        ->assertRedirect($edit)->assertSessionHasErrors(['file' => 'Only PDF files are accepted.']);
    $this->actingAs($vendor)->from($edit)->post("/requests/{$case->id}/documents", ['purpose' => 'bids', 'file' => upload('c.pdf', pdfBytes())])
        ->assertRedirect($edit)->assertSessionHasNoErrors();

    expect(DB::table('documents')->where('case_id', $case->id)->orderBy('original_name')->pluck('original_name')->all())->toBe(['a.pdf', 'c.pdf']);
});

it('refuses the old categories for AD_HOC', function (DocumentPurpose $purpose) {
    [$case, $vendor] = newDraft();

    expect(fn () => app(DocumentUploadService::class)->store($case, $vendor, $purpose, upload('file.pdf', pdfBytes())))
        ->toThrow(ValidationException::class, 'This document is not part of this type of request.');
})->with([DocumentPurpose::original_rfq, DocumentPurpose::signed_request]);

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
        ->and($receipt['procurement_type'])->toBe('Ad Hoc Purchase (Non-Pharm)')
        ->and($receipt['documents'])->toHaveCount(6);
});

// 8. Slice F2: blank rows, client keys, discard at submit, sections, the page ----------

it('drops a blank row on save; a blank row with an id is deleted; an id-only row is kept', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());
    [$firstId, $secondId] = array_keys(rowsOf(CaseQuotation::class, $case));

    saveDraft($case, $vendor, ['quotations' => [
        ['id' => $firstId],                                                                    // keep, unchanged
        ['id' => $secondId, 'supplier_name' => '', 'manufacturer' => null, 'brand' => null,
            'unit_price' => null, 'quantity_offered' => null, 'lead_time_days' => null,
            'reason_accepted_excluded' => null, 'considered' => true, 'is_recommended' => false,
            'quantity_available_now' => null, 'country_of_origin' => null, 'remarks' => ''],      // emptied: deleted
        ['client_key' => 'k-blank', 'considered' => true, 'is_recommended' => false],           // new and blank: dropped
    ], 'purchase_orders' => [['client_key' => 'k-order', 'quantity_delivered' => '0']]]);    // only the default: dropped

    $rows = rowsOf(CaseQuotation::class, $case);
    expect(array_keys($rows))->toBe([$firstId])
        ->and($rows[$firstId]->supplier_name)->toBe('Supplier C')
        ->and(CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(0);
});

it('returns an id map for the new rows that carried a client key, and the saved row ids', function () {
    [$case, $vendor] = newDraft();
    $payload = adHocPayload();
    $payload['tender_awards'][0]['client_key'] = 'award-a';
    $payload['tender_awards'][1]['client_key'] = 'award-b';
    $payload['quotations'][0]['client_key'] = 'quote-c';
    $payload['stock_expiries'][] = ['client_key' => 'blank-batch'];

    $saved = app(SaveRequisition::class)->handle($case, $vendor, RequisitionData::validate($payload, adHocType()));
    $awards = array_keys(rowsOf(CaseTenderAward::class, $case));
    $quotes = array_keys(rowsOf(CaseQuotation::class, $case));

    expect($saved->idMap)->toBe(['award-a' => $awards[0], 'award-b' => $awards[1], 'quote-c' => $quotes[0]])
        ->and($saved->rowIds['tender_awards'])->toBe($awards)
        ->and($saved->rowIds['quotations'])->toBe($quotes)
        ->and($saved->rowIds['stock_expiries'])->toHaveCount(2) // the blank batch was dropped
        ->and($saved->rowIds)->not->toHaveKey('purchase_orders');

    // Saved again with the ids: the same rows, and nothing new to map.
    $again = app(SaveRequisition::class)->handle($case, $vendor, RequisitionData::validate(['tender_awards' => [
        ['id' => $awards[0], 'client_key' => 'award-a'], ['id' => $awards[1], 'client_key' => 'award-b'],
    ]], adHocType()));
    expect($again->idMap)->toBe([])
        ->and($again->rowIds['tender_awards'])->toBe($awards);
});

it('sends the id map back through the save redirect and never stores or shows the client key', function () {
    [$case, $vendor] = newDraft();

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->put("/requests/{$case->id}", ['quotations' => [adHocQuoteRow('Supplier C', '1.40', ['client_key' => 'q-1'])]])
        ->assertRedirect("/requests/{$case->id}/edit");

    $id = (int) CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->value('id');
    $saved = session('saved');
    expect((array) $saved['id_map'])->toBe(['q-1' => $id])
        ->and((array) $saved['row_ids'])->toBe(['quotations' => [$id]])
        ->and($saved['saved_at'])->toEndWith('Z');

    $props = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit"));
    expect($props['case']['quotations'][0])->not->toHaveKey('client_key');
});

it('refuses two rows with the same client key', function () {
    expect(adHocValidationErrors(['quotations' => [['client_key' => 'a'], ['client_key' => 'a']]]))->toHaveKey('quotations.1.client_key');
});

it('accepts an average monthly consumption of 0, with no coverage', function () {
    [$case, $vendor] = newDraft();
    $payload = adHocPayload();
    $payload['requisition']['average_monthly_consumption'] = '0';
    saveDraft($case, $vendor, $payload);
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    actAs($vendor);

    $content = RequestDetailResource::requisitionContent($case->load(['procurementType', 'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries']), forStaff: true);

    expect($content['requisition']['average_monthly_consumption'])->toBe('0.00')
        ->and($content['requisition']['derived']['coverage_months'])->toBeNull()
        ->and(adHocErrorKeys(app(SubmissionReadiness::class)->evaluate($case)))->not->toContain('requisition.average_monthly_consumption');
});

// Slice V2-1: the tender outcome is no longer read. Award and order rows are
// kept at submit whatever it says (the "not awarded" discard was removed; the
// only discard now is of hidden sections, RequestSectionsTest).
it('keeps the award and order rows at submit, whatever the old tender outcome says', function () {
    [$case, $vendor] = readyDraft('TENDER_VARIATION'); // 2026-11-24: orders show for Tender Variation only
    saveDraft($case, $vendor, ['requisition' => ['tender_outcome' => 'NOT_AWARDED']]);

    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED')
        ->and(DB::table('case_tender_awards')->where('case_id', $case->id)->count())->toBe(2)
        ->and(DB::table('case_purchase_orders')->where('case_id', $case->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.draft_rows_discarded')->count())->toBe(0);
});

it('renders a draft of each request type on the new page, never the old one', function (string $code) {
    [$case, $vendor] = newDraft($code);

    $this->actingAs($vendor)->get("/requests/{$case->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('requests/ad-hoc/edit')
            ->missing('readiness')
            ->has('sections', 6)
            // V2-3: the config no longer carries the tender outcome (awards never depend on it).
            ->where('config.options.rfq_audience.0.label', 'All registered suppliers')
            ->missing('config.options.tender_outcome')
            ->missing('config.not_awarded')
            ->where('hardcopy_notice', RequestReceipt::HARDCOPY_NOTICE)
            ->where('can.submit', true)
            ->where('can.requests', true));
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('keeps the old page for the old Pharm / Non-Pharm types', function (string $code) {
    [$case, $vendor] = draftOf($code);

    $this->actingAs($vendor)->get("/requests/{$case->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('requests/edit')->has('readiness'));
})->with(['AD_HOC_PHARM', 'AD_HOC_NON_PHARM']);

it('groups readiness by section, in form order, with plain-English issues and field keys', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['item_description' => 'Feeding tube', 'tender_outcome' => 'PARTIALLY_AWARDED'],
        'tender_awards' => [['supplier_name' => 'Supplier A']]]);
    $awardId = (int) CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->value('id');

    $sections = collect(pageProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit"))['sections'])->keyBy('key');

    expect($sections->keys()->all())->toBe(['item', 'tender', 'stock', 'quotations', 'price', 'documents'])
        ->and($sections->pluck('complete')->unique()->all())->toBe([false])
        ->and($sections['price']['optional'])->toBeTrue()
        ->and($sections['price']['issues'])->toBe([])
        ->and($sections['item']['summary'])->toBe('Feeding tube')
        ->and($sections['tender']['summary'])->toBe('1 supplier awarded, 0 orders')
        ->and($sections['stock']['summary'])->toBe('Not started')
        // V2-1 and V2-5 issues are mapped onto the current screen (the DIN is optional since V2-5).
        ->and(collect($sections['item']['issues'])->pluck('field')->all())->toBe(['previous_request_in_cycle', 'requisition.quantity_requested', 'requisition.unit_of_measure'])
        ->and(collect($sections['tender']['issues'])->pluck('field')->all())->toContain('requisition.tender_cycle_start', 'requisition.tender_cycle_end', 'requisition.tender_reference')
        ->and(collect($sections['documents']['issues'])->pluck('message')->all())->toBe(['Required document missing: Bids'])
        ->and(collect($sections['tender']['issues'])->firstWhere('key', 'tender_awards.0.quantity_awarded'))->toBe([
            'key' => 'tender_awards.0.quantity_awarded',
            'field' => 'tender_awards.quantity_awarded',
            'row_id' => $awardId,
            'message' => 'Supplier Row 1: Qty Awarded is required.',
        ]);
});

it('marks every section complete for a ready draft (price only once entered)', function () {
    [$case, $vendor] = readyDraft();

    $complete = fn () => collect(pageProps($this->actingAs($vendor)->get("/requests/{$case->id}/edit"))['sections'])->pluck('complete', 'key')->all();
    expect($complete())->toBe(['item' => true, 'tender' => true, 'stock' => true, 'quotations' => true, 'price' => true, 'documents' => true]);

    saveDraft($case, $vendor, ['requisition' => ['tender_price' => null, 'previous_price' => null, 'quoted_price_acceptable' => null, 'price_note' => null]]);
    expect($complete()['price'])->toBeFalse();
});

it('serves the sections as a partial reload', function () {
    [$case, $vendor] = newDraft();

    $version = $this->actingAs($vendor)->get("/requests/{$case->id}/edit")->viewData('page')['version'];

    $props = $this->actingAs($vendor)->get("/requests/{$case->id}/edit", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'requests/ad-hoc/edit',
        'X-Inertia-Partial-Data' => 'sections',
    ])->assertOk()->json('props');

    expect($props)->toHaveKey('sections')
        ->and($props['sections'])->toHaveCount(6)
        ->and($props)->not->toHaveKeys(['case', 'documents', 'config']);
});
