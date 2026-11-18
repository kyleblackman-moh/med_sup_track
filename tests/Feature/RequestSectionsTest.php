<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\CaseRequisition;
use App\Models\CaseVariation;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Submissions\ChangeRequestType;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\ReadinessReport;
use App\Submissions\RequestSections;
use App\Submissions\RequisitionData;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice V2-1: the five request types on the one request form. Visible sections,
// required-to-submit rules, the discard of hidden data at submit, the variation
// entry and ChangeRequestType. DatabaseTransactions only.

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

/**
 * The rules per type, as agreed for V2-1 (interim, CMO's office to confirm):
 * contract reference, awarded supplier, stock, quotations, variation details, Bids file.
 */
const V2_REQUIRES = [
    'AD_HOC_EMERGENCY' => ['contract' => false, 'awards' => false, 'stock' => true, 'quotes' => true, 'variation' => false, 'bids' => true],
    'AD_HOC_TENDER_UNABLE' => ['contract' => true, 'awards' => true, 'stock' => true, 'quotes' => true, 'variation' => false, 'bids' => true],
    'AD_HOC_NOT_AWARDED' => ['contract' => false, 'awards' => false, 'stock' => true, 'quotes' => true, 'variation' => false, 'bids' => true],
    'TENDER_EXCESS' => ['contract' => true, 'awards' => true, 'stock' => true, 'quotes' => true, 'variation' => false, 'bids' => false],
    'TENDER_VARIATION' => ['contract' => true, 'awards' => true, 'stock' => false, 'quotes' => false, 'variation' => true, 'bids' => false],
];

/**
 * A saved draft of $code: the complete payload after $mutate, one order against
 * the first award (when there is one), the variation entry (Tender Variation,
 * unless $variation is false) and the required files unless $files is false.
 */
function v2Draft(string $code, ?Closure $mutate = null, bool $variation = true, bool $files = true): array
{
    [$case, $vendor] = newDraft($code);
    $payload = adHocPayload();
    if ($code === 'TENDER_VARIATION' && $variation) {
        $payload['variation'] = variationEntry();
    }
    saveDraft($case, $vendor, $mutate ? $mutate($payload) : $payload);

    if (DB::table('case_tender_awards')->where('case_id', $case->id)->exists()) {
        saveDraft($case, $vendor, adHocOrdersFor($case));
    }
    if ($files) {
        attachRequiredDocuments($case);
    }

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

function v2Report(ProcurementCase $case): ReadinessReport
{
    return app(SubmissionReadiness::class)->evaluate($case);
}

function v2Keys(ReadinessReport $report): array
{
    return array_column($report->errors, 'key');
}

/** $payload with requisition fields replaced. */
function withRequisition(array $payload, array $fields): array
{
    return [...$payload, 'requisition' => [...$payload['requisition'], ...$fields]];
}

// 1. Visible sections ------------------------------------------------------------

it('shows every section except variation details, which only a Tender Variation shows', function (string $code) {
    $expected = $code === 'TENDER_VARIATION'
        ? ['item_tender', 'variation_details', 'awarded_supplier', 'existing_arrangement', 'stock', 'quotation_comparison', 'documents']
        : ['item_tender', 'awarded_supplier', 'existing_arrangement', 'stock', 'quotation_comparison', 'documents'];

    expect(array_column(RequestSections::visible($code), 'key'))->toBe($expected)
        ->and(RequestSections::discardedAtSubmit($code))->toBe($code === 'TENDER_VARIATION' ? [] : ['variation_details']);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('marks a section optional exactly when the type requires nothing in it', function (string $code) {
    $optional = collect(RequestSections::visible($code))->pluck('optional', 'key')->all();
    $r = V2_REQUIRES[$code];

    expect($optional['item_tender'])->toBeFalse()
        ->and($optional['awarded_supplier'])->toBe(! $r['awards'])
        ->and($optional['existing_arrangement'])->toBeTrue()
        ->and($optional['stock'])->toBe(! $r['stock'])
        ->and($optional['quotation_comparison'])->toBe(! $r['quotes'])
        ->and($optional['variation_details'] ?? null)->toBe($r['variation'] ? false : null);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('sends the visible sections, in order, with anchors and issues to the form', function (string $code) {
    [$case, $vendor] = newDraft($code);

    $sections = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['request_sections'];

    expect(array_column($sections, 'key'))->toBe(array_column(RequestSections::visible($code), 'key'))
        ->and(array_column($sections, 'anchor'))->toBe(array_map(fn ($k) => "section-{$k}", array_column($sections, 'key')));

    $byKey = collect($sections)->keyBy('key');
    expect(collect($byKey['item_tender']['issues'])->pluck('key')->all())->toContain('requisition.item_description', 'requisition.din', 'requisition.tender_cycle_start')
        ->and($byKey['item_tender']['complete'])->toBeFalse()
        // Optional and empty: optional, never complete.
        ->and($byKey['existing_arrangement']['complete'])->toBeFalse()
        ->and($byKey['existing_arrangement']['state'])->toBe('optional');

    if ($code === 'TENDER_VARIATION') {
        expect(collect($byKey['variation_details']['issues'])->pluck('key')->all())
            ->toBe(['variation.variation_type', 'variation.existing_detail', 'variation.proposed_detail', 'variation.effective_date']);
    }
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('puts every readiness key in a section', function (string $key, string $section) {
    expect(RequestSections::sectionOf($key))->toBe($section);
})->with([
    ['request_type', 'item_tender'],
    ['requisition.din', 'item_tender'],
    ['requisition.tender_reference', 'item_tender'],
    ['requisition.tender_cycle_end', 'item_tender'],
    ['variation.effective_date', 'variation_details'],
    ['tender_awards', 'awarded_supplier'],
    ['tender_awards.0.unit_cost', 'awarded_supplier'],
    ['purchase_orders.1.po_number', 'existing_arrangement'],
    ['requisition.quantity_on_order', 'existing_arrangement'],
    ['requisition.average_monthly_consumption', 'stock'],
    ['stock_expiries', 'stock'],
    ['quotations.recommended', 'quotation_comparison'],
    ['requisition.quantity_to_purchase', 'quotation_comparison'],
    ['documents.bids', 'documents'],
]);

// 2. Required to submit ------------------------------------------------------------

it('reports the complete request of each type as ready', function (string $code) {
    [$case] = v2Draft($code);
    $report = v2Report($case);

    expect($report->errors)->toBe([])
        ->and($report->isReady())->toBeTrue();
})->with(ProcurementTypeProfile::REQUEST_TYPES);

/*
 * Each requirement: [rule in V2_REQUIRES (null = every type), how the draft is
 * built (mutate, variation, files), the error keys it must give, their section].
 */
dataset('requirements', [
    'item description' => [null, fn ($p) => withRequisition($p, ['item_description' => '  ']), true, true, ['requisition.item_description'], 'item_tender'],
    'DIN' => [null, fn ($p) => withRequisition($p, ['din' => null]), true, true, ['requisition.din'], 'item_tender'],
    'quantity requested' => [null, fn ($p) => withRequisition($p, ['quantity_requested' => '0']), true, true, ['requisition.quantity_requested'], 'item_tender'],
    'unit' => [null, fn ($p) => withRequisition($p, ['unit_of_measure' => null]), true, true, ['requisition.unit_of_measure'], 'item_tender'],
    'tender cycle start' => [null, fn ($p) => withRequisition($p, ['tender_cycle_start' => null]), true, true, ['requisition.tender_cycle_start'], 'item_tender'],
    'tender cycle end' => [null, fn ($p) => withRequisition($p, ['tender_cycle_end' => null]), true, true, ['requisition.tender_cycle_end'], 'item_tender'],
    'contract reference' => ['contract', fn ($p) => withRequisition($p, ['tender_reference' => null]), true, true, ['requisition.tender_reference'], 'item_tender'],
    'awarded supplier' => ['awards', fn ($p) => [...$p, 'tender_awards' => []], true, true, ['tender_awards'], 'awarded_supplier'],
    'average monthly consumption' => ['stock', fn ($p) => withRequisition($p, ['average_monthly_consumption' => null]), true, true, ['requisition.average_monthly_consumption'], 'stock'],
    'a batch or no stock' => ['stock', fn ($p) => [...$p, 'stock_expiries' => []], true, true, ['stock_expiries'], 'stock'],
    'a quotation' => ['quotes', fn ($p) => [...$p, 'quotations' => []], true, true, ['quotations', 'quotations.recommended'], 'quotation_comparison'],
    'a recommended quotation' => ['quotes', fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier D', '1.5975')]], true, true, ['quotations.recommended'], 'quotation_comparison'],
    'quantity to purchase' => ['quotes', fn ($p) => withRequisition($p, ['quantity_to_purchase' => null]), true, true, ['requisition.quantity_to_purchase'], 'quotation_comparison'],
    'variation details' => ['variation', null, false, true, ['variation.variation_type', 'variation.existing_detail', 'variation.proposed_detail', 'variation.effective_date'], 'variation_details'],
    'Bids file' => ['bids', null, true, false, ['documents.bids'], 'documents'],
]);

it('fails each required item in its own section, and passes it where the type does not require it', function (string $code, ?string $rule, ?Closure $mutate, bool $variation, bool $files, array $keys, string $section) {
    [$case] = v2Draft($code, $mutate, $variation, $files);
    $report = v2Report($case);
    $required = $rule === null || V2_REQUIRES[$code][$rule];

    if ($required) {
        expect(v2Keys($report))->toBe($keys)
            ->and(array_unique(array_map(fn ($k) => RequestSections::sectionOf($k), $keys)))->toBe([$section]);
    } else {
        expect($report->errors)->toBe([]);
    }
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with('requirements');

it('needs every saved row complete, whatever the type', function (string $code, Closure $mutate, array $keys, ?Closure $orders = null) {
    [$case, $vendor] = v2Draft($code, $mutate);
    if ($orders) {
        saveDraft($case, $vendor, $orders($case));
    }

    expect(v2Keys(v2Report($case)))->toBe($keys);
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with([
    'a partly filled award row' => [fn ($p) => [...$p, 'tender_awards' => [...$p['tender_awards'], ['brand' => 'Generic Brand']]],
        ['tender_awards.2.supplier_name', 'tender_awards.2.quantity_awarded', 'tender_awards.2.unit_cost']],
    'an order without supplier, PO number or quantity' => [fn ($p) => $p, ['purchase_orders.0.award_id', 'purchase_orders.0.po_number', 'purchase_orders.0.quantity_ordered'],
        fn () => ['purchase_orders' => [['ordered_on' => '2026-06-01']]]],
    'a partly filled batch' => [fn ($p) => [...$p, 'stock_expiries' => [['quantity' => '5']]], ['stock_expiries.0.expiry_date']],
    'a partly filled quotation' => [fn ($p) => [...$p, 'quotations' => [...$p['quotations'], ['manufacturer' => 'Generic Manufacturer']]],
        ['quotations.2.supplier_name', 'quotations.2.unit_price', 'quotations.2.quantity_offered']],
    'not considered without a reason' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier C', '1.40', ['considered' => false]), adHocQuoteRow('Supplier D', '1.5975', ['is_recommended' => true])]],
        ['quotations.0.reason_accepted_excluded']],
    'two recommended' => [fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier C', '1.40', ['is_recommended' => true]), adHocQuoteRow('Supplier D', '1.5975', ['is_recommended' => true])]],
        ['quotations.recommended']],
]);

it('accepts the optional parts left out', function (string $code, Closure $mutate) {
    [$case] = v2Draft($code, $mutate);

    expect(v2Report($case)->errors)->toBe([]);
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with([
    'strength, dosage form, clinical note, location, quantity on order, price comparison' => [fn ($p) => withRequisition($p, [
        'strength' => null, 'dosage_form' => null, 'clinical_note' => null, 'stock_location' => null, 'quantity_on_order' => null,
        'tender_price' => null, 'previous_price' => null, 'quoted_price_acceptable' => null, 'price_note' => null, 'rfq_audience' => null,
    ])],
    'no stock on hand, no batches' => [fn ($p) => [...withRequisition($p, ['no_stock_on_hand' => true]), 'stock_expiries' => []]],
    'no stock on hand, a leftover partly filled batch' => [fn ($p) => [...withRequisition($p, ['no_stock_on_hand' => true]), 'stock_expiries' => [['quantity' => '5']]]],
    'average monthly consumption 0' => [fn ($p) => withRequisition($p, ['average_monthly_consumption' => '0'])],
    'tender cycle of one day' => [fn ($p) => withRequisition($p, ['tender_cycle_start' => '2026-05-01', 'tender_cycle_end' => '2026-05-01'])],
    'the tender outcome is not read' => [fn ($p) => withRequisition($p, ['tender_outcome' => 'NOT_AWARDED'])],
]);

it('accepts a draft with no orders', function (string $code) {
    [$case, $vendor] = v2Draft($code);
    saveDraft($case, $vendor, ['purchase_orders' => []]);

    expect(v2Report($case)->errors)->toBe([]);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('fails a tender cycle that ends before it starts', function (string $code) {
    [$case] = v2Draft($code, fn ($p) => withRequisition($p, ['tender_cycle_start' => '2027-01-01', 'tender_cycle_end' => '2026-12-31']));

    expect(v2Report($case)->errors)->toBe([['key' => 'requisition.tender_cycle_end', 'message' => RequestSections::CYCLE_END_BEFORE_START]]);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('keeps the DIN as digits-only text with its leading zeros', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => '000123']]);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din'))->toBe('000123');

    // (An empty DIN is a blank draft field, not an error at save; readiness requires it.)
    foreach (['12A4', ' 123', '12.3', '-1', '1 2'] as $din) {
        expect(fn () => saveDraft($case, $vendor, ['requisition' => ['din' => $din]]))
            ->toThrow(ValidationException::class);
    }

    // No length rule (open question): one digit and 32 digits both pass.
    saveDraft($case, $vendor, ['requisition' => ['din' => '7']]);
    saveDraft($case, $vendor, ['requisition' => ['din' => str_repeat('9', 32)]]);
    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din'))->toBe(str_repeat('9', 32));
});

it('asks an old AD_HOC draft to choose one of the five types first', function () {
    [$case, $vendor] = legacyDraft('AD_HOC');
    saveCompleteAdHoc($case, $vendor);
    attachRequiredDocuments($case);

    expect(v2Report($case)->errors)->toBe([['key' => 'request_type', 'message' => RequestSections::REQUEST_TYPE_NEEDED]]);

    $this->actingAs($vendor)->put(route('requests.type', $case->id), ['procurement_type' => 'AD_HOC_TENDER_UNABLE'])
        ->assertRedirect(route('requests.edit', $case->id));

    expect(v2Report(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors)->toBe([]);
});

// 3. Hidden data is discarded at submit -------------------------------------------

it('discards the variation entry at submit for every type but Tender Variation', function (string $code) {
    [$case, $vendor] = readyDraft($code);
    saveDraft($case, $vendor, ['variation' => variationEntry()]);
    expect(CaseVariation::withoutGlobalScopes()->where('case_id', $case->id)->exists())->toBeTrue(); // kept in the draft

    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftRowsDiscarded->value)->sole();
    expect(CaseVariation::withoutGlobalScopes()->where('case_id', $case->id)->exists())->toBeFalse()
        ->and(json_decode($audit->metadata, true))->toBe(['reason' => 'section_hidden', 'counts' => ['variation_details' => 1]])
        // Nothing else is discarded: awards and orders stay whatever the type.
        ->and(DB::table('case_tender_awards')->where('case_id', $case->id)->count())->toBe(2)
        ->and(DB::table('case_purchase_orders')->where('case_id', $case->id)->count())->toBe(1);
})->with(['AD_HOC_EMERGENCY', 'AD_HOC_TENDER_UNABLE', 'AD_HOC_NOT_AWARDED', 'TENDER_EXCESS']);

it('keeps the variation entry of a Tender Variation at submit, and discards nothing', function () {
    [$case, $vendor] = readyDraft('TENDER_VARIATION');

    app(SubmitCase::class)->handle($case, $vendor);

    expect(CaseVariation::withoutGlobalScopes()->where('case_id', $case->id)->value('variation_type'))->toBe('Price')
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftRowsDiscarded->value)->exists())->toBeFalse();
});

it('keeps the variation entry if the submit is refused', function () {
    [$case, $vendor] = v2Draft('AD_HOC_EMERGENCY', fn ($p) => [...withRequisition($p, ['item_description' => null]), 'variation' => variationEntry()]);

    expect(fn () => app(SubmitCase::class)->handle($case, $vendor))->toThrow(SubmissionNotReady::class);

    expect(CaseVariation::withoutGlobalScopes()->where('case_id', $case->id)->exists())->toBeTrue();
});

// 4. The variation entry and the new fields on save --------------------------------

it('upserts one variation row: partial saves merge, a blank entry is not kept, null clears it', function () {
    [$case, $vendor] = newDraft('TENDER_VARIATION');
    $row = fn () => CaseVariation::withoutGlobalScopes()->where('case_id', $case->id)->get();

    saveDraft($case, $vendor, ['variation' => ['variation_type' => '', 'existing_detail' => null]]);
    expect($row())->toHaveCount(0); // blank: ignored

    saveDraft($case, $vendor, ['variation' => ['variation_type' => 'Price']]);
    $id = $row()->sole()->id;
    saveDraft($case, $vendor, ['variation' => ['effective_date' => '2027-01-01']]);
    expect($row()->sole()->id)->toBe($id)
        ->and($row()->sole()->variation_type)->toBe('Price')
        ->and($row()->sole()->effective_date->toDateString())->toBe('2027-01-01');

    // A partial entry saves; it is an error at submit only.
    expect(v2Keys(v2Report($case)))->toContain('variation.existing_detail', 'variation.proposed_detail')
        ->not->toContain('variation.variation_type', 'variation.effective_date');

    saveDraft($case, $vendor, ['variation' => ['variation_type' => ' ', 'effective_date' => null]]);
    expect($row())->toHaveCount(0); // cleared to blank: deleted

    saveDraft($case, $vendor, ['variation' => variationEntry()]);
    saveDraft($case, $vendor, ['variation' => null]);
    expect($row())->toHaveCount(0);
});

it('validates the variation entry and refuses it for the old types', function (string $code, array $payload, string $key) {
    [$case] = draftOf($code);

    try {
        RequisitionData::validate($payload, $case->procurementType()->firstOrFail());
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
})->with([
    ['TENDER_VARIATION', ['variation' => ['variation_type' => str_repeat('x', 101)]], 'variation.variation_type'],
    ['TENDER_VARIATION', ['variation' => ['existing_detail' => str_repeat('x', 501)]], 'variation.existing_detail'],
    ['TENDER_VARIATION', ['variation' => ['effective_date' => '01/01/2027']], 'variation.effective_date'],
    ['TENDER_VARIATION', ['variation' => ['case_id' => 'x']], 'variation.case_id'],
    ['TENDER_VARIATION', ['variation' => 'text'], 'variation'],
    ['AD_HOC_PHARM', ['variation' => variationEntry()], 'variation'],
]);

it('audits a save with the variation by count only, never its text', function () {
    [$case, $vendor] = newDraft('TENDER_VARIATION');
    saveDraft($case, $vendor, ['variation' => variationEntry()]);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftSaved->value)->latest('id')->first();
    expect(json_decode($audit->metadata, true))->toBe(['sections' => ['variation'], 'counts' => ['variation' => 1]])
        ->and($audit->metadata)->not->toContain('Unit Cost')->not->toContain('Price');
});

it('saves and returns the new V2-1 fields', function () {
    [$case, $vendor] = v2Draft('TENDER_VARIATION');

    $detail = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['case'];

    expect($detail['requisition'])->toMatchArray([
        'tender_cycle_start' => '2026-01-01', 'tender_cycle_end' => '2027-12-31', 'quantity_on_order' => '1800.00',
        'tender_reference' => 'TENDER-2026-14 (2026-2027)',
    ])
        ->and($detail['tender_awards'][0]['country_of_origin'])->toBe('Country A')
        ->and($detail['quotations'][1])->toMatchArray(['ex_stock' => true, 'country_of_origin' => 'Country B', 'remarks' => 'Two-week delivery.'])
        ->and($detail['quotations'][0]['ex_stock'])->toBeNull()
        ->and($detail['variation'])->toBe(variationEntry());

    // Staff see the same content once submitted.
    app(SubmitCase::class)->handle($case, $vendor);
    $staff = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertOk())['case'];
    expect($staff['variation'])->toBe(variationEntry())
        ->and($staff['requisition']['tender_cycle_end'])->toBe('2027-12-31');
});

// 5. ChangeRequestType ----------------------------------------------------------------

it('changes the type of a draft and audits the two codes only', function () {
    [$case, $vendor] = v2Draft('TENDER_VARIATION');
    $typed = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('item_description');

    $this->actingAs($vendor)->put(route('requests.type', $case->id), ['procurement_type' => 'AD_HOC_EMERGENCY'])
        ->assertRedirect(route('requests.edit', $case->id));

    $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'draft.type_changed')->sole();

    expect($fresh->procurementType->code)->toBe('AD_HOC_EMERGENCY')
        ->and($fresh->status)->toBe(CaseStatus::DRAFT)
        ->and(json_decode($audit->metadata, true))->toBe(['from' => 'TENDER_VARIATION', 'to' => 'AD_HOC_EMERGENCY'])
        ->and((int) $audit->actor_user_id)->toBe($vendor->id)
        ->and($audit->metadata)->not->toContain($typed)
        // Hidden now, but kept until submit: the type may change back.
        ->and(CaseVariation::withoutGlobalScopes()->where('case_id', $case->id)->exists())->toBeTrue();
});

it('writes nothing when the type does not change', function () {
    [$case, $vendor] = newDraft('TENDER_EXCESS');

    app(ChangeRequestType::class)->handle($case, $vendor, 'TENDER_EXCESS');

    expect(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'draft.type_changed')->exists())->toBeFalse();
});

it('refuses an inactive or unknown type and changes nothing', function (string $code) {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');

    $this->actingAs($vendor)->from(route('requests.edit', $case->id))
        ->put(route('requests.type', $case->id), ['procurement_type' => $code])
        ->assertSessionHasErrors('procurement_type');

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->procurementType->code)->toBe('AD_HOC_EMERGENCY')
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'draft.type_changed')->exists())->toBeFalse();
})->with(['AD_HOC', 'AD_HOC_PHARM', 'AD_HOC_NON_PHARM', 'NOT_A_TYPE', '']);

it('refuses an active type that is not one of the five', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    ProcurementType::create(['code' => 'OTHER_ACTIVE', 'name' => 'Other', 'is_active' => true, 'sort_order' => 999]);

    expect(fn () => app(ChangeRequestType::class)->handle($case, $vendor, 'OTHER_ACTIVE'))->toThrow(ValidationException::class);
});

it('returns 404 to another organisation and to staff (staff never see drafts), and changes nothing', function () {
    [$case] = newDraft('AD_HOC_EMERGENCY');

    $this->actingAs(vendorUser(vendorOrg()))->put(route('requests.type', $case->id), ['procurement_type' => 'TENDER_EXCESS'])->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->put(route('requests.type', $case->id), ['procurement_type' => 'TENDER_EXCESS'])->assertNotFound();

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->procurementType->code)->toBe('AD_HOC_EMERGENCY');
});

it('returns 403 to the owner once the case is not a draft', function (CaseStatus $status) {
    $vendor = vendorUser(vendorOrg());
    $case = makeCase([
        'org_id' => $vendor->org_id, 'created_by' => $vendor->id, 'status' => $status,
        'procurement_type_id' => procurementTypeByCode('AD_HOC_EMERGENCY')->id, 'status_changed_at' => CarbonImmutable::now('UTC'),
    ]);

    $this->actingAs($vendor)->put(route('requests.type', $case->id), ['procurement_type' => 'TENDER_EXCESS'])->assertForbidden();

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->procurementType->code)->toBe('AD_HOC_EMERGENCY');
})->with([CaseStatus::SUBMITTED, CaseStatus::IN_REVIEW, CaseStatus::CLOSED]);

it('refuses a draft that was submitted after it was loaded', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $stale = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::SUBMITTED->value]);

    expect(fn () => app(ChangeRequestType::class)->handle($stale, $vendor, 'TENDER_EXCESS'))
        ->toThrow(DraftNotEditable::class);
});

// Section states: the one rule (RequestSections::state) -------------------------------

/**
 * The state of every visible section of an EMPTY draft, per type. A section
 * with required content is incomplete; one with nothing required for the type
 * (and nothing entered) is "optional", never complete.
 */
const SECTION_STATES_WHEN_EMPTY = [
    'AD_HOC_EMERGENCY' => [
        'item_tender' => 'incomplete', 'awarded_supplier' => 'optional', 'existing_arrangement' => 'optional',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'incomplete',
    ],
    'AD_HOC_TENDER_UNABLE' => [
        'item_tender' => 'incomplete', 'awarded_supplier' => 'incomplete', 'existing_arrangement' => 'optional',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'incomplete',
    ],
    'AD_HOC_NOT_AWARDED' => [
        'item_tender' => 'incomplete', 'awarded_supplier' => 'optional', 'existing_arrangement' => 'optional',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'incomplete',
    ],
    'TENDER_EXCESS' => [
        'item_tender' => 'incomplete', 'awarded_supplier' => 'incomplete', 'existing_arrangement' => 'optional',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'optional',
    ],
    'TENDER_VARIATION' => [
        'item_tender' => 'incomplete', 'variation_details' => 'incomplete', 'awarded_supplier' => 'incomplete',
        'existing_arrangement' => 'optional', 'stock' => 'optional', 'quotation_comparison' => 'optional',
        'documents' => 'optional',
    ],
];

it('reports each section state per type, empty and filled', function (string $code, bool $filled) {
    [$case, $vendor] = newDraft($code);
    if ($filled) {
        // Every section holds valid data: the required content, an order, and a file
        // (an optional Evidence file too, so a type that requires none still has one).
        saveCompleteAdHoc($case, $vendor);
        attachRequiredDocuments($case);
        makeDocument($case, 'evidence');
    }

    $form = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['form'];
    $states = collect($form['sections'])->where('visible', true)->pluck('state', 'key')->all();

    expect($states)->toBe($filled
        ? array_fill_keys(array_keys(SECTION_STATES_WHEN_EMPTY[$code]), 'complete')
        : SECTION_STATES_WHEN_EMPTY[$code]);
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with(['empty' => false, 'filled' => true]);

it('keeps an optional section optional until it holds data, then complete', function () {
    // Emergency: the awarded supplier is optional. Empty: optional; one complete row: complete.
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $state = fn () => collect(pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['form']['sections'])
        ->pluck('state', 'key');

    expect($state()['awarded_supplier'])->toBe('optional');

    saveDraft($case, $vendor, ['tender_awards' => adHocPayload()['tender_awards']]);
    expect($state()['awarded_supplier'])->toBe('complete');
});

it('applies the state rule in order: blocked, incomplete, optional, complete', function (?string $note, bool $issues, bool $optional, bool $data, string $expected) {
    expect(RequestSections::state($note, $issues, $optional, $data))->toBe($expected);
})->with([
    'waiting for the type' => [RequestSections::REQUEST_TYPE_NEEDED, false, false, false, 'blocked'],
    'required, with issues' => [null, true, false, true, 'incomplete'],
    'required, satisfied' => [null, false, false, true, 'complete'],
    'optional, empty' => [null, false, true, false, 'optional'],
    'optional, valid data' => [null, false, true, true, 'complete'],
    'optional, invalid data' => [null, true, true, true, 'incomplete'],
]);
