<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\CaseRequisition;
use App\Models\CaseVariationRow;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Submissions\ChangeRequestType;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\ReadinessReport;
use App\Submissions\RequestSections;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
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
 * contract reference, awarded supplier, stock, quotations, Bids file. V2-5: the
 * variation details are required whenever the previous-request answer is Yes.
 */
const V2_REQUIRES = [
    'AD_HOC_EMERGENCY' => ['contract' => false, 'awards' => false, 'stock' => true, 'quotes' => true, 'bids' => true],
    'AD_HOC_TENDER_UNABLE' => ['contract' => true, 'awards' => true, 'stock' => true, 'quotes' => true, 'bids' => true],
    'AD_HOC_NOT_AWARDED' => ['contract' => false, 'awards' => false, 'stock' => true, 'quotes' => true, 'bids' => true],
    'TENDER_EXCESS' => ['contract' => true, 'awards' => true, 'stock' => true, 'quotes' => true, 'bids' => false],
    'TENDER_VARIATION' => ['contract' => true, 'awards' => true, 'stock' => false, 'quotes' => false, 'bids' => false],
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
        // V2-5: the variation details show (and are required) when the answer is Yes.
        $payload['previous_request_in_cycle'] = true;
        $payload['variation_rows'] = [variationEntry()];
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

it('shows Details of Variation Requested only when the previous-request answer is Yes, for every type', function (string $code, ?bool $answer) {
    // 2026-11-24: Existing Approved Arrangement shows for Tender Variation only.
    $arrangement = $code === 'TENDER_VARIATION';
    $expected = array_values(array_filter([
        'request_type', 'item_tender', $answer === true ? 'variation_details' : null, 'awarded_supplier',
        $arrangement ? 'existing_arrangement' : null, 'stock', 'quotation_comparison', 'documents',
    ]));
    $discarded = array_values(array_filter([$answer === true ? null : 'variation_details', $arrangement ? null : 'existing_arrangement']));

    expect(array_column(RequestSections::visible($code, true, $answer), 'key'))->toBe($expected)
        ->and(RequestSections::discardedAtSubmit($code, $answer))->toBe($discarded)
        ->and(RequestSections::LABELS['variation_details'])->toBe('Details of Variation Requested');
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with(['unanswered' => null, 'no' => false, 'yes' => true]);

it('marks a section optional exactly when the type requires nothing in it', function (string $code) {
    $optional = collect(RequestSections::visible($code))->pluck('optional', 'key')->all();
    $r = V2_REQUIRES[$code];

    expect($optional['request_type'])->toBeFalse()
        ->and($optional['item_tender'])->toBeFalse()
        ->and($optional['awarded_supplier'])->toBe(! $r['awards'])
        ->and($optional['existing_arrangement'] ?? null)->toBe($code === 'TENDER_VARIATION' ? true : null) // 2026-11-24: Tender Variation only
        ->and($optional['stock'])->toBe(! $r['stock'])
        ->and($optional['quotation_comparison'])->toBe(! $r['quotes'])
        ->and($optional['variation_details'] ?? null)->toBeNull() // hidden until the answer is Yes
        ->and(collect(RequestSections::visible($code, true, true))->pluck('optional', 'key')['variation_details'])->toBeFalse();
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('sends the visible sections, in order, with anchors and issues to the form', function (string $code) {
    [$case, $vendor] = newDraft($code);

    $sections = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['request_sections'];

    expect(array_column($sections, 'key'))->toBe(array_column(RequestSections::visible($code), 'key'))
        ->and(array_column($sections, 'anchor'))->toBe(array_map(fn ($k) => "section-{$k}", array_column($sections, 'key')));

    $byKey = collect($sections)->keyBy('key');
    expect(collect($byKey['item_tender']['issues'])->pluck('key')->all())->toContain('requisition.item_description')
        ->and(collect($byKey['request_type']['issues'])->pluck('key')->all())
        ->toContain('requisition.tender_cycle_start', 'requisition.tender_cycle_end', 'previous_request_in_cycle')
        ->and($byKey['item_tender']['complete'])->toBeFalse()
        ->and($byKey->has('existing_arrangement'))->toBe($code === 'TENDER_VARIATION'); // 2026-11-24
    if ($code === 'TENDER_VARIATION') {
        // Optional and empty: optional, never complete.
        expect($byKey['existing_arrangement']['complete'])->toBeFalse()
            ->and($byKey['existing_arrangement']['state'])->toBe('optional');
    }

    // V2-5: unanswered, so Details of Variation Requested is hidden for every type.
    expect($byKey->has('variation_details'))->toBeFalse();
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('puts every readiness key in a section', function (string $key, string $section) {
    expect(RequestSections::sectionOf($key))->toBe($section);
})->with([
    ['request_type', 'request_type'],
    ['previous_request_in_cycle', 'request_type'],
    ['requisition.tender_cycle_start', 'request_type'],
    ['requisition.tender_cycle_end', 'request_type'],
    ['requisition.din', 'item_tender'],
    ['requisition.tender_reference', 'item_tender'],
    ['variation_rows', 'variation_details'],
    ['variation_rows.0.effective_detail', 'variation_details'],
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
    'quantity requested' => [null, fn ($p) => withRequisition($p, ['quantity_requested' => '0']), true, true, ['requisition.quantity_requested'], 'item_tender'],
    'unit' => [null, fn ($p) => withRequisition($p, ['unit_of_measure' => null]), true, true, ['requisition.unit_of_measure'], 'item_tender'],
    'tender cycle start' => [null, fn ($p) => withRequisition($p, ['tender_cycle_start' => null]), true, true, ['requisition.tender_cycle_start'], 'request_type'],
    'tender cycle end' => [null, fn ($p) => withRequisition($p, ['tender_cycle_end' => null]), true, true, ['requisition.tender_cycle_end'], 'request_type'],
    'previous-request answer' => [null, fn ($p) => [...$p, 'previous_request_in_cycle' => null], true, true, ['previous_request_in_cycle'], 'request_type'],
    'contract reference' => ['contract', fn ($p) => withRequisition($p, ['tender_reference' => null]), true, true, ['requisition.tender_reference'], 'item_tender'],
    'awarded supplier' => ['awards', fn ($p) => [...$p, 'tender_awards' => []], true, true, ['tender_awards'], 'awarded_supplier'],
    'average monthly consumption' => ['stock', fn ($p) => withRequisition($p, ['average_monthly_consumption' => null]), true, true, ['requisition.average_monthly_consumption'], 'stock'],
    // 2026-11-23: no "a batch or no stock" item: a batch total of 0 is no stock on hand (FormRulesTest).
    'a quotation' => ['quotes', fn ($p) => [...$p, 'quotations' => []], true, true, ['quotations', 'quotations.recommended'], 'quotation_comparison'],
    'a recommended quotation' => ['quotes', fn ($p) => [...$p, 'quotations' => [adHocQuoteRow('Supplier D', '1.5975')]], true, true, ['quotations.recommended'], 'quotation_comparison'],
    // V2-5: every type, once the answer is Yes.
    'variation details' => [null, fn ($p) => [...$p, 'previous_request_in_cycle' => true], false, true, ['variation_rows'], 'variation_details'],
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

    // 2026-11-24: orders are checked on a Tender Variation only (hidden for the other types).
    $checked = array_values(array_filter($keys, fn (string $k) => $code === 'TENDER_VARIATION' || ! str_starts_with($k, 'purchase_orders')));

    expect(v2Keys(v2Report($case)))->toBe($checked);
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with([
    'a partly filled award row' => [fn ($p) => [...$p, 'tender_awards' => [...$p['tender_awards'], ['brand' => 'Generic Brand']]],
        ['tender_awards.2.supplier_name', 'tender_awards.2.quantity_awarded', 'tender_awards.2.unit_cost']],
    'an order without supplier, PO number or quantity' => [fn ($p) => $p, ['purchase_orders.0.award_id', 'purchase_orders.0.po_number', 'purchase_orders.0.quantity_ordered', 'purchase_orders.0.delivery_state'],
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
    // 2026-11-24: no batch is needed, and the typed quantity on hand is optional for now.
    'no batches' => [fn ($p) => [...$p, 'stock_expiries' => []]],
    'no quantity on hand' => [fn ($p) => withRequisition($p, ['quantity_on_hand' => null])],
    'quantity on hand 0' => [fn ($p) => withRequisition($p, ['quantity_on_hand' => '0'])],
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

it('keeps the DIN as 8-digit text with its leading zeros', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => '00012345']]);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din'))->toBe('00012345');

    // Kyle, 2026-11-23: exactly 8 digits (this was an open length question). Optional,
    // and never a readiness error; anything else is refused at save.
    foreach (['12.3', '-1', '1 2', 'AB-12', '7', '000123', str_repeat('9', 9), str_repeat('9', 32), 'ABCD1234'] as $din) {
        expect(fn () => saveDraft($case, $vendor, ['requisition' => ['din' => $din]]))
            ->toThrow(ValidationException::class);
    }

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din'))->toBe('00012345');
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

// 3. Hidden data and the variation rows: VariationRowsTest (V2-5) ---------------------

// 4. The new fields on save ------------------------------------------------------------

it('saves and returns the new V2-1 fields', function () {
    [$case, $vendor] = v2Draft('TENDER_VARIATION');

    $detail = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['case'];

    expect($detail['requisition'])->toMatchArray([
        'tender_cycle_start' => '2026-01-01', 'tender_cycle_end' => '2026-10-31', 'quantity_on_order' => '1800.00',
        'tender_reference' => 'TENDER-2026-14 (2026-2027)',
    ])
        ->and($detail['tender_awards'][0]['country_of_origin'])->toBe('Country A')
        ->and($detail['quotations'][1])->toMatchArray(['quantity_available_now' => '500.00', 'country_of_origin' => 'Country B', 'remarks' => 'Two-week delivery.'])
        ->and($detail['quotations'][0]['quantity_available_now'])->toBeNull()
        // 2026-11-24: Ex Stock is a quantity; the old yes/no is no longer sent.
        ->and($detail['quotations'][0])->not->toHaveKey('ex_stock')
        ->and(collect($detail['variation_rows'])->map(fn ($r) => Arr::except($r, 'id'))->all())->toBe([variationEntry()]);

    // Staff see the same content once submitted.
    app(SubmitCase::class)->handle($case, $vendor);
    $staff = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertOk())['case'];
    expect(collect($staff['variation_rows'])->map(fn ($r) => Arr::except($r, 'id'))->all())->toBe([variationEntry()])
        ->and($staff['requisition']['tender_cycle_end'])->toBe('2026-10-31');
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
        // V2-5: the variation rows depend on the answer, not the type; kept (and still shown).
        ->and(CaseVariationRow::withoutGlobalScopes()->where('case_id', $case->id)->exists())->toBeTrue();
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
        'request_type' => 'incomplete', 'item_tender' => 'incomplete', 'awarded_supplier' => 'optional',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'incomplete',
    ],
    'AD_HOC_TENDER_UNABLE' => [
        'request_type' => 'incomplete', 'item_tender' => 'incomplete', 'awarded_supplier' => 'incomplete',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'incomplete',
    ],
    'AD_HOC_NOT_AWARDED' => [
        'request_type' => 'incomplete', 'item_tender' => 'incomplete', 'awarded_supplier' => 'optional',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'incomplete',
    ],
    'TENDER_EXCESS' => [
        'request_type' => 'incomplete', 'item_tender' => 'incomplete', 'awarded_supplier' => 'incomplete',
        'stock' => 'incomplete', 'quotation_comparison' => 'incomplete', 'documents' => 'optional',
    ],
    'TENDER_VARIATION' => [
        'request_type' => 'incomplete', 'item_tender' => 'incomplete', 'awarded_supplier' => 'incomplete',
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

    // V2-5: a complete Tender Variation answers Yes, so it also shows Details of Variation Requested.
    $keys = array_keys(SECTION_STATES_WHEN_EMPTY[$code]);
    if ($filled && $code === 'TENDER_VARIATION') {
        array_splice($keys, 2, 0, ['variation_details']);
    }

    expect($states)->toBe($filled ? array_fill_keys($keys, 'complete') : SECTION_STATES_WHEN_EMPTY[$code]);
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
