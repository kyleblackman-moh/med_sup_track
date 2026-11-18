<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TechnicalStatus;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// Slice 8b: vendor request pages (server side). DatabaseTransactions; the
// complete payload fixtures come from tests/Support/RequisitionPayloads.php.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    // Slice 8c: required documents count towards readiness; makeDocument() writes to this disk.
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A vendor with a draft built from $payload through the 8a actions. Slice F1:
 * AD_HOC by default; inactive old types come from legacyDraft (draftOf).
 */
function vendorDraft(string $code = 'AD_HOC_TENDER_UNABLE', ?array $payload = null): array
{
    [$case, $vendor] = draftOf($code);
    if ($payload !== null) {
        saveDraft($case, $vendor, $payload);
    }

    return [$case, $vendor];
}

function requisitionOf(ProcurementCase $case): CaseRequisition
{
    return CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
}

// 1. Guests ---------------------------------------------------------------------

it('redirects guests to login on every route', function (string $method, string $uri) {
    $this->{$method}(str_replace('{id}', (string) Str::ulid(), $uri))->assertRedirect('/login');
})->with([
    ['get', '/requests'],
    ['post', '/requests'],
    ['get', '/requests/{id}'],
    ['get', '/requests/{id}/edit'],
    ['put', '/requests/{id}'],
]);

// 2. Index ----------------------------------------------------------------------

it('lists only the vendor\'s own organisation\'s requests', function () {
    [$mine, $vendor] = vendorDraft(payload: adHocPayload());
    [$theirs] = vendorDraft(payload: adHocPayload());

    $response = $this->actingAs($vendor)->get('/requests')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('requests/index')
        ->has('requests.data', 1)
        ->has('requests.data.0', fn (Assert $row) => $row
            ->where('id', $mine->id)
            ->where('reference_no', null)
            ->where('status_label', 'Draft')
            ->where('status_group', 'DRAFT')
            ->where('status_tone', 'neutral') // UI-4: decided by the server
            // Slice V2-1: the Ad Hoc types have category AD_HOC and no vendor reference field.
            ->where('procurement_type', ['code' => 'AD_HOC_TENDER_UNABLE', 'name' => 'Ad Hoc - Tender unable to supply', 'category' => 'AD_HOC', 'selected' => true])
            ->where('item_description', 'Infant feeding tube, size 6Fr')
            ->where('vendor_reference', null)
            ->where('updated_at', fn ($v) => str_ends_with($v, 'Z'))
            ->where('can', ['edit' => true, 'delete' => true]))
        ->where('can.create', true)
        ->where('can.requests', true));

    expect(json_encode(pageProps($response)))->not->toContain($theirs->id);
    expectVendorSafe(pageProps($response));
});

it('never serves request pages to staff users', function (Role $role) {
    [$case] = vendorDraft(payload: adHocPayload());
    $staff = caseUser($role);

    foreach (['/requests', "/requests/{$case->id}", "/requests/{$case->id}/edit"] as $uri) {
        $response = $this->actingAs($staff)->get($uri);

        expect($response->status())->not->toBe(200)
            ->and($response->getContent())->not->toContain('Infant feeding tube');
    }
})->with([Role::Cmo, Role::Technocrat, Role::SystemAdmin, Role::Auditor]);

// 3. Create / store -------------------------------------------------------------

// Slice V2-1b: there is no create page; New request posts here and lands on the
// new, unselected draft's form (the type is chosen there). Changed on purpose.
it('creates an unselected draft in the user\'s organisation and ignores every input', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);

    $response = $this->actingAs($vendor)->post(route('requests.store'), [
        'procurement_type_id' => procurementTypeByCode('AD_HOC_TENDER_UNABLE')->id,
        'org_id' => vendorOrg()->id,
        'status' => 'CLOSED',
        'reference_no' => 'NOR-HACK-1',
    ]);

    $case = ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->sole();
    $response->assertRedirect(route('requests.edit', $case->id));
    expect($case->org_id)->toBe($org->id)
        ->and($case->status)->toBe(CaseStatus::DRAFT)
        ->and($case->reference_no)->toBeNull()
        ->and($case->procurementType->code)->toBe(ProcurementTypeProfile::NOT_SELECTED);
});

it('has no create page: GET /requests/create is 404', function () {
    $this->actingAs(vendorUser(vendorOrg()))->get('/requests/create')->assertNotFound();

    expect(Route::has('requests.create'))->toBeFalse();
});

it('needs case.submit to start a request, and creates nothing without it', function (Role $role) {
    $staff = caseUser($role);

    $this->actingAs($staff)->post(route('requests.store'))->assertForbidden();

    expect(ProcurementCase::withoutGlobalScopes()->where('created_by', $staff->id)->count())->toBe(0);
})->with([Role::Cmo, Role::CmoRegistry, Role::SystemAdmin, Role::Auditor]);

// 4. Edit -----------------------------------------------------------------------

it('renders the edit page with case, readiness, config and can', function () {
    // An old-form type (a legacy draft): TENDER_EXCESS uses the request form since slice V2-1.
    [$case, $vendor] = vendorDraft('AD_HOC_PHARM', pharmAllSectionsPayload());
    attachRequiredDocuments($case);

    $response = $this->actingAs($vendor)->get("/requests/{$case->id}/edit")->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('requests/edit')
        ->where('case.id', $case->id)
        ->where('case.requisition.din', '02345678')
        ->has('case.quotations', 1)
        ->has('case.tender_awards', 2)
        ->has('case.purchase_orders', 1)
        ->has('case.stock_expiries', 0)
        ->where('readiness.is_ready', true)
        ->where('readiness.pending', []) // slice 8c: documents are now evaluated
        ->where('config.allowed_sections', ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'])
        ->where('config.required_sections', ['quotations'])
        ->where('config.is_pharm', true)
        ->where('config.options.need_classification', [
            ['value' => 'VITAL', 'label' => 'Vital'],
            ['value' => 'ESSENTIAL', 'label' => 'Essential'],
            ['value' => 'OTHER', 'label' => 'Other'],
        ])
        ->has('config.options.tender_outcome', 3)
        ->has('config.options.rfq_audience', 2)
        ->has('config.options.previous_price_maintained', 3)
        ->where('can.edit', true)
        ->where('can.requests', true));

    expectVendorSafe(pageProps($response));
});

it('gives each type its own section config', function (string $code, array $allowed, bool $pharm) {
    [$case, $vendor] = vendorDraft($code);

    $this->actingAs($vendor)->get("/requests/{$case->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('config.allowed_sections', $allowed)
        ->where('config.is_pharm', $pharm));
})->with([
    // Old types: drafts made before slice F1 keep their section config and the old page.
    // AD_HOC has its own page since slice F2 (AdHocFormTest).
    ['AD_HOC_NON_PHARM', ['quotations'], false],
    ['AD_HOC_PHARM', ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'], true],
]);

it('redirects edit to show once the case is no longer a draft', function (CaseStatus $status) {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $case = makeCase(['org_id' => $org->id, 'status' => $status]);

    $this->actingAs($vendor)->get("/requests/{$case->id}/edit")->assertRedirect("/requests/{$case->id}");
})->with([CaseStatus::SUBMITTED, CaseStatus::WITHDRAWN]);

it('returns the same 404 for another org\'s case and a missing case', function (string $suffix) {
    [$theirs] = vendorDraft(payload: adHocPayload());
    $vendor = vendorUser(vendorOrg());

    $hidden = $this->actingAs($vendor)->get("/requests/{$theirs->id}{$suffix}");
    $missing = $this->actingAs($vendor)->get('/requests/'.Str::lower((string) Str::ulid()).$suffix);

    $hidden->assertNotFound();
    $missing->assertNotFound();
    expect($hidden->getContent())->toBe($missing->getContent())
        ->and($hidden->getContent())->not->toContain('Infant feeding tube');
})->with(['', '/edit']);

// 5. Update ---------------------------------------------------------------------

// Slice F2: an AD_HOC save is an autosave: no banner, the saved row ids instead.
it('saves a valid payload and redirects back with the saved row ids', function () {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->put("/requests/{$case->id}", [...adHocPayload(), '_token' => 'x', '_method' => 'PUT'])
        ->assertRedirect("/requests/{$case->id}/edit")
        ->assertSessionMissing('success')
        ->assertSessionHas('saved', fn (array $saved) => count((array) $saved['row_ids']->quotations) === 2);

    expect(requisitionOf($case)->item_description)->toBe('Infant feeding tube, size 6Fr')
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.draft_saved')->count())->toBe(1);

    // Rows missing from the payload are deleted: a second save with one new quotation leaves one.
    $this->actingAs($vendor)->put("/requests/{$case->id}", ['quotations' => array_slice(adHocPayload()['quotations'], 0, 1)]);
    expect(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(1);
});

it('still flashes "Draft saved." for an old-type save', function () {
    [$case, $vendor] = vendorDraft('AD_HOC_PHARM');

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->put("/requests/{$case->id}", pharmAllSectionsPayload())
        ->assertSessionHas('success', 'Draft saved.')
        ->assertSessionMissing('saved');
});

it('shares the success flash on the next page', function () {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->withSession(['success' => 'Draft saved.'])
        ->get("/requests/{$case->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Draft saved.')->where('flash.error', null));
});

it('rejects an invalid payload with dotted error keys and saves nothing', function () {
    [$case, $vendor] = vendorDraft();

    $payload = adHocPayload();
    $payload['quotations'][0]['unit_price'] = '1.59751';
    $payload['requisition']['din'] = '0234-5678';

    $this->actingAs($vendor)->put("/requests/{$case->id}", $payload)
        ->assertSessionHasErrors(['quotations.0.unit_price', 'requisition.din' => 'Enter the DIN as digits only.']);

    expect(requisitionOf($case)->item_description)->toBeNull()
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(0);
});

it('rejects each prohibited key', function (array $payload, string $key) {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->put("/requests/{$case->id}", $payload)->assertSessionHasErrors($key);

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('DRAFT');
})->with([
    [['status' => 'CLOSED'], 'status'],
    [['org_id' => 'x'], 'org_id'],
    [['case_id' => 'x'], 'case_id'],
    [['reference_no' => 'NOR-1'], 'reference_no'],
    [['requisition' => ['status' => 'CLOSED']], 'requisition.status'],
    [['quotations' => [['supplier_name' => 'S', 'unit_price' => '1', 'quantity_offered' => '1', 'technical_status' => 'SAMPLE_ACCEPTED']]], 'quotations.0.technical_status'],
]);

it('refuses to update a case that is no longer a draft', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $case = makeCase(['org_id' => $org->id, 'status' => CaseStatus::SUBMITTED]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Original'])->save();

    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['item_description' => 'Changed']])
        ->assertRedirect("/requests/{$case->id}")
        ->assertSessionHas('error');

    expect(requisitionOf($case)->item_description)->toBe('Original');
});

it('returns 404 when updating another org\'s case', function () {
    [$theirs] = vendorDraft(payload: adHocPayload());

    $this->actingAs(vendorUser(vendorOrg()))
        ->put("/requests/{$theirs->id}", ['requisition' => ['item_description' => 'Hijacked']])
        ->assertNotFound();

    expect(requisitionOf($theirs)->item_description)->toBe('Infant feeding tube, size 6Fr');
});

it('does not let staff write', function (Role $role) {
    [$case] = vendorDraft(payload: adHocPayload());

    $response = $this->actingAs(caseUser($role))->put("/requests/{$case->id}", ['requisition' => ['item_description' => 'Staff edit']]);

    expect($response->status())->toBeIn([403, 404])
        ->and(requisitionOf($case)->item_description)->toBe('Infant feeding tube, size 6Fr');
})->with([Role::Cmo, Role::Technocrat]);

it('keeps the latest of two quick saves', function () {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['strength' => 'FIRST']]);
    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['strength' => 'SECOND']]);

    expect(requisitionOf($case)->strength)->toBe('SECOND')
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.draft_saved')->count())->toBe(2);
});

// 6. Privacy --------------------------------------------------------------------

it('never sends staff-set technical fields to the vendor', function () {
    [$case, $vendor] = vendorDraft(payload: adHocPayload());
    CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->firstOrFail()->forceFill([
        'technical_status' => TechnicalStatus::TECHNICALLY_REJECTED,
        'technical_status_set_by' => caseUser(Role::Technocrat)->id,
        'technical_status_set_at' => now(),
    ])->save();

    foreach (["/requests/{$case->id}/edit", "/requests/{$case->id}"] as $uri) {
        expectVendorSafe(pageProps($this->actingAs($vendor)->get($uri)->assertOk()));
    }
});

// 7. Derived values -------------------------------------------------------------

// Old type (AD_HOC_PHARM, a legacy draft): its figures are unchanged. AD_HOC figures: AdHocFiguresTest.
it('shows exact server-computed values', function () {
    $payload = pharmPayload();
    $payload['quotations'][2]['vat_rate'] = '12.5';
    $payload['requisition']['rha_stock'] = '1500';
    $payload['stock_expiries'] = [['quantity' => '1500', 'expiry_date' => '2027-06-30']];
    $payload['tender_awards'] = pharmAllSectionsPayload()['tender_awards'];
    $payload['purchase_orders'] = pharmAllSectionsPayload()['purchase_orders'];
    [$case, $vendor] = vendorDraft('AD_HOC_PHARM', $payload);

    $this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('requests/show')
        ->where('case.quotations.2.unit_price', '1.5975')
        ->where('case.quotations.2.derived.offered_total', '4792.50')
        ->where('case.quotations.2.derived.recommended_total', '4473.00')
        ->where('case.quotations.2.derived.recommended_vat_amount', '559.13')
        ->where('case.quotations.2.derived.recommended_total_including_vat', '5032.13')
        ->where('case.quotations.0.derived.recommended_total', null)
        ->where('case.tender_awards.0.derived.percentage', '70.00')
        ->where('case.tender_awards.1.derived.percentage', '30.00')
        ->where('case.tender_awards.0.derived.total', '3500.00')
        ->where('case.purchase_orders.0.derived.quantity_outstanding', '1800.00')
        ->where('case.requisition.derived.stock_on_hand', '1500.00')
        ->where('case.requisition.derived.coverage_months', '3.00')
        ->where('case.totals.quotations.recommended_total', '4473.00')
        ->where('case.totals.quotations.recommended_total_including_vat', '5032.13')
        ->where('case.totals.tender_awards.quantity_awarded', '4000.00')
        ->where('case.totals.purchase_orders.quantity_outstanding', '1800.00'));
});

// 8. Readiness ------------------------------------------------------------------

// Slice F2: the AD_HOC edit page carries "sections"; the show page keeps "readiness".
it('shows readiness errors for an empty draft and none for a complete one', function () {
    [$empty, $vendor] = vendorDraft();

    $this->actingAs($vendor)->get("/requests/{$empty->id}")->assertInertia(fn (Assert $page) => $page
        ->where('readiness.is_ready', false)
        ->where('readiness.pending', []) // slice 8c: documents are now evaluated
        ->where('readiness.errors', fn ($errors) => collect([
            'requisition.item_description', 'requisition.quantity_requested',
            'requisition.din', 'requisition.tender_cycle_start', 'quotations', 'quotations.recommended', 'documents.bids',
        ])->diff(collect($errors)->pluck('key'))->isEmpty()));

    [$complete, $owner] = vendorDraft();
    saveCompleteAdHoc($complete, $owner);
    attachRequiredDocuments($complete);
    $this->actingAs($owner)->get("/requests/{$complete->id}")->assertInertia(fn (Assert $page) => $page
        ->where('readiness.is_ready', true)
        ->where('readiness.errors', []));
});

// 9. Status labels --------------------------------------------------------------

it('maps every status to a vendor label and group', function (CaseStatus $status, string $label, string $group) {
    expect($status->vendorLabel())->toBe($label)
        ->and($status->vendorGroup())->toBe($group);
})->with([
    [CaseStatus::DRAFT, 'Draft', 'DRAFT'],
    [CaseStatus::SUBMITTED, 'Submitted', 'SUBMITTED'],
    [CaseStatus::CMO_INTAKE, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::IN_REVIEW, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::READY_FOR_FILE, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::FILE_PREPARED, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::WITH_LEGAL, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::WITH_PS, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::RETURNED_TO_CMO, 'Under review', 'UNDER_REVIEW'],
    [CaseStatus::NON_OBJECTION_GRANTED, 'Under review', 'UNDER_REVIEW'],
    // Slice 10f: both show the outcome; the groups stay separate.
    [CaseStatus::LETTER_ISSUED, 'Non-objection granted', 'LETTER_ISSUED'],
    [CaseStatus::CLOSED, 'Non-objection granted', 'CLOSED'],
    [CaseStatus::WITHDRAWN, 'Withdrawn', 'WITHDRAWN'],
    [CaseStatus::REJECTED_FINAL, 'Not approved', 'NOT_APPROVED'],
]);

it('never sends internal status values to the vendor', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $cases = collect(CaseStatus::cases())->map(fn ($s) => makeCase(['org_id' => $org->id, 'status' => $s]));

    $index = $this->actingAs($vendor)->get('/requests')->assertOk();
    expect(pageProps($index)['requests']['data'])->toHaveCount(14);
    expectVendorSafe(pageProps($index));

    foreach ($cases as $case) {
        expectVendorSafe(pageProps($this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk()));
    }
});

// 10. Static guard --------------------------------------------------------------

it('keeps the request pages free of ad hoc network and storage calls', function () {
    $files = [
        ...glob(resource_path('js/pages/requests/*.tsx')),
        ...glob(resource_path('js/components/requests/*.tsx')),
        // Slice F2: the AD_HOC page and its components.
        ...glob(resource_path('js/pages/requests/ad-hoc/*.tsx')),
        ...glob(resource_path('js/components/requests/ad-hoc/*.{ts,tsx}'), GLOB_BRACE),
    ];

    expect($files)->toContain(resource_path('js/pages/requests/ad-hoc/edit.tsx'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach (['fetch(', 'axios', 'XMLHttpRequest', 'localStorage', 'sessionStorage', 'IndexedDB', 'indexedDB', 'dangerouslySetInnerHTML'] as $banned) {
            expect(str_contains($source, $banned))->toBeFalse(basename($file)." contains {$banned}");
        }
    }
});

// 11. Throttle ------------------------------------------------------------------

it('throttles write requests at 30 a minute', function () {
    [$case, $vendor] = vendorDraft();

    foreach (range(1, 30) as $i) {
        $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['strength' => "C{$i}"]])->assertRedirect();
    }

    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['strength' => 'C31']])->assertStatus(429);
    expect(requisitionOf($case)->strength)->toBe('C30');
});

it('shares can.requests only with users who hold the vendor permission', function (Closure $user, bool $expected) {
    $this->actingAs($user())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('can.requests', $expected));
})->with([
    'vendor' => [fn () => vendorUser(vendorOrg()), true],
    'cmo' => [fn () => caseUser(Role::Cmo), false],
]);

// UI-3 -------------------------------------------------------------------------

// V2-3: the one RequestSummary replaces the summary labels (summary_config).
it('gives a request-form request page the request summary, and no other type', function (string $code, bool $summary) {
    [$case, $vendor] = vendorDraft($code);

    $props = pageProps($this->actingAs($vendor)->get(route('requests.show', $case->id))->assertOk());

    expect($props)->not->toHaveKey('summary_config');
    if ($summary) {
        expect(array_keys($props['summary']))->toBe(['type_label', 'sections'])
            // NEXT part 5: every visible section but Documents (the page has its own document list).
            ->and(array_column($props['summary']['sections'], 'key'))
            ->toBe(array_values(array_diff(array_column(RequestSections::visible($code), 'key'), ['documents'])))
            ->and($props['case']['figures'])->not->toBeNull();
    } else {
        expect($props['summary'])->toBeNull();
    }
})->with([
    ['AD_HOC_EMERGENCY', true],
    ['AD_HOC_TENDER_UNABLE', true],
    ['AD_HOC_NOT_AWARDED', true],
    ['TENDER_EXCESS', true],
    ['TENDER_VARIATION', true],
    ['AD_HOC', true],
    ['AD_HOC_PHARM', false],
]);

it('sends the edit page\'s summary only when Review asks for it', function () {
    [$case, $vendor] = vendorDraft('TENDER_EXCESS');
    $full = $this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk();
    expect($full->viewData('page')['props'])->not->toHaveKey('summary');

    $props = $this->actingAs($vendor)->get(route('requests.edit', $case->id), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $full->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'requests/ad-hoc/edit',
        'X-Inertia-Partial-Data' => 'summary',
    ])->assertOk()->json('props');

    expect(array_column($props['summary']['sections'], 'key'))->toBe(array_column(RequestSections::visible('TENDER_EXCESS'), 'key'));
});

it('keeps form actions out of the request form headers', function () {
    // CLAUDE.md, UI: form actions go in a footer row, never in the header.
    foreach (['js/pages/requests/ad-hoc/edit.tsx', 'js/pages/requests/edit.tsx'] as $path) {
        $source = file_get_contents(resource_path($path));
        // V2-2c: the vendor form's header is a PageHeader element (its closing "/>" on its own line).
        preg_match('/<header\b.*?<\/header>|<PageHeader\b.*?\n\s*\/>/s', $source, $header);

        expect($header)->not->toBeEmpty("{$path} has no header")
            ->and($header[0])->not->toMatch('/Review and submit|Delete draft|Save draft|Submit request|<Button\b/', "{$path} has an action in its header");
    }
});
