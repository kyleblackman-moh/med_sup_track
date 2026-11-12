<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TechnicalStatus;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

/** Keys that must never reach a vendor, at any depth. */
const FORBIDDEN_PROP_KEYS = [
    'org_id', 'created_by', 'file_no', 'current_holder_role', 'status',
    'technical_track', 'funding_track', 'hardcopy_received_at', 'hardcopy_received_by', 'hardcopy_receipt_method',
    'technical_status', 'technical_status_set_by', 'technical_status_set_at',
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

/** A vendor with a draft built from $payload through the 8a actions. */
function vendorDraft(string $code = 'AD_HOC_NON_PHARM', ?array $payload = null): array
{
    [$case, $vendor] = newDraft($code);
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
    ['get', '/requests/create'],
    ['post', '/requests'],
    ['get', '/requests/{id}'],
    ['get', '/requests/{id}/edit'],
    ['put', '/requests/{id}'],
]);

// 2. Index ----------------------------------------------------------------------

it('lists only the vendor\'s own organisation\'s requests', function () {
    [$mine, $vendor] = vendorDraft(payload: nonPharmPayload());
    [$theirs] = vendorDraft(payload: nonPharmPayload());

    $response = $this->actingAs($vendor)->get('/requests')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('requests/index')
        ->has('requests.data', 1)
        ->has('requests.data.0', fn (Assert $row) => $row
            ->where('id', $mine->id)
            ->where('reference_no', null)
            ->where('status_label', 'Draft')
            ->where('status_group', 'DRAFT')
            ->where('procurement_type', ['code' => 'AD_HOC_NON_PHARM', 'name' => 'Ad hoc purchase (Non-Pharm)', 'category' => 'NON_PHARM'])
            ->where('item_description', 'Infant feeding tube, size 6Fr')
            ->where('vendor_reference', 'REQ-2026-001')
            ->where('updated_at', fn ($v) => str_ends_with($v, 'Z'))
            ->where('can', ['edit' => true]))
        ->where('can.create', true)
        ->where('can.requests', true));

    expect(json_encode(pageProps($response)))->not->toContain($theirs->id);
    expectVendorSafe(pageProps($response));
});

it('never serves request pages to staff users', function (Role $role) {
    [$case] = vendorDraft(payload: nonPharmPayload());
    $staff = caseUser($role);

    foreach (['/requests', '/requests/create', "/requests/{$case->id}", "/requests/{$case->id}/edit"] as $uri) {
        $response = $this->actingAs($staff)->get($uri);

        expect($response->status())->not->toBe(200)
            ->and($response->getContent())->not->toContain('Infant feeding tube');
    }
})->with([Role::Cmo, Role::Technocrat, Role::SystemAdmin, Role::Auditor]);

// 3. Create / store -------------------------------------------------------------

it('lists only active procurement types on create', function () {
    $vendor = vendorUser(vendorOrg());

    $this->actingAs($vendor)->get('/requests/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('requests/create')
        ->where('types', fn ($types) => collect($types)->pluck('code')->sort()->values()->all()
            === ['AD_HOC_NON_PHARM', 'AD_HOC_PHARM', 'TENDER_EXCESS'])
        ->has('types.0', fn (Assert $type) => $type->hasAll(['id', 'code', 'name', 'category'])));
});

it('creates a draft in the user\'s organisation and ignores extra input', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $type = procurementTypeByCode('AD_HOC_PHARM');

    $response = $this->actingAs($vendor)->post('/requests', [
        'procurement_type_id' => $type->id,
        'org_id' => vendorOrg()->id,
        'status' => 'CLOSED',
        'reference_no' => 'NOR-HACK-1',
    ]);

    $case = ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->sole();
    $response->assertRedirect("/requests/{$case->id}/edit");
    expect($case->org_id)->toBe($org->id)
        ->and($case->status)->toBe(CaseStatus::DRAFT)
        ->and($case->reference_no)->toBeNull()
        ->and((int) $case->procurement_type_id)->toBe($type->id);
});

it('rejects an inactive or unknown procurement type and creates nothing', function (Closure $typeId) {
    $vendor = vendorUser(vendorOrg());

    $this->actingAs($vendor)->post('/requests', ['procurement_type_id' => $typeId()])
        ->assertSessionHasErrors('procurement_type_id');

    expect(ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->count())->toBe(0);
})->with([
    'inactive' => fn () => procurementTypeByCode('TENDER_VARIATION')->id,
    'unknown' => fn () => 999999999,
    'missing' => fn () => null,
]);

// 4. Edit -----------------------------------------------------------------------

it('renders the edit page with case, readiness, config and can', function () {
    [$case, $vendor] = vendorDraft('TENDER_EXCESS', tenderExcessPayload());
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
        ->where('config.required_sections', ['quotations', 'tender_awards', 'purchase_orders'])
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
    [$theirs] = vendorDraft(payload: nonPharmPayload());
    $vendor = vendorUser(vendorOrg());

    $hidden = $this->actingAs($vendor)->get("/requests/{$theirs->id}{$suffix}");
    $missing = $this->actingAs($vendor)->get('/requests/'.Str::lower((string) Str::ulid()).$suffix);

    $hidden->assertNotFound();
    $missing->assertNotFound();
    expect($hidden->getContent())->toBe($missing->getContent())
        ->and($hidden->getContent())->not->toContain('Infant feeding tube');
})->with(['', '/edit']);

// 5. Update ---------------------------------------------------------------------

it('saves a valid payload and redirects back with a success flash', function () {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->from("/requests/{$case->id}/edit")
        ->put("/requests/{$case->id}", [...nonPharmPayload(), '_token' => 'x', '_method' => 'PUT'])
        ->assertRedirect("/requests/{$case->id}/edit")
        ->assertSessionHas('success');

    expect(requisitionOf($case)->item_description)->toBe('Infant feeding tube, size 6Fr')
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(6)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.draft_saved')->count())->toBe(1);

    // Replace-all: a second save with two quotations leaves two.
    $this->actingAs($vendor)->put("/requests/{$case->id}", ['quotations' => array_slice(nonPharmPayload()['quotations'], 0, 2)]);
    expect(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(2);
});

it('shares the success flash on the next page', function () {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->withSession(['success' => 'Draft saved.'])
        ->get("/requests/{$case->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Draft saved.')->where('flash.error', null));
});

it('rejects an invalid payload with dotted error keys and saves nothing', function () {
    [$case, $vendor] = vendorDraft();

    $payload = nonPharmPayload();
    $payload['quotations'][0]['unit_price'] = '1.59751';
    $payload['requisition']['request_date'] = 'yesterday';

    $this->actingAs($vendor)->put("/requests/{$case->id}", $payload)
        ->assertSessionHasErrors(['quotations.0.unit_price', 'requisition.request_date']);

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
    [$theirs] = vendorDraft(payload: nonPharmPayload());

    $this->actingAs(vendorUser(vendorOrg()))
        ->put("/requests/{$theirs->id}", ['requisition' => ['item_description' => 'Hijacked']])
        ->assertNotFound();

    expect(requisitionOf($theirs)->item_description)->toBe('Infant feeding tube, size 6Fr');
});

it('does not let staff write', function (Role $role) {
    [$case] = vendorDraft(payload: nonPharmPayload());

    $response = $this->actingAs(caseUser($role))->put("/requests/{$case->id}", ['requisition' => ['item_description' => 'Staff edit']]);

    expect($response->status())->toBeIn([403, 404])
        ->and(requisitionOf($case)->item_description)->toBe('Infant feeding tube, size 6Fr');
})->with([Role::Cmo, Role::Technocrat]);

it('keeps the latest of two quick saves', function () {
    [$case, $vendor] = vendorDraft();

    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['item_code' => 'FIRST']]);
    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['item_code' => 'SECOND']]);

    expect(requisitionOf($case)->item_code)->toBe('SECOND')
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.draft_saved')->count())->toBe(2);
});

// 6. Privacy --------------------------------------------------------------------

it('never sends staff-set technical fields to the vendor', function () {
    [$case, $vendor] = vendorDraft(payload: nonPharmPayload());
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

it('shows exact server-computed values', function () {
    $payload = pharmPayload();
    $payload['quotations'][2]['vat_rate'] = '12.5';
    $payload['requisition']['rha_stock'] = '1500';
    $payload['stock_expiries'] = [['quantity' => '1500', 'expiry_date' => '2027-06-30']];
    $payload['tender_awards'] = tenderExcessPayload()['tender_awards'];
    $payload['purchase_orders'] = tenderExcessPayload()['purchase_orders'];
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

it('shows readiness errors for an empty draft and none for a complete one', function () {
    [$empty, $vendor] = vendorDraft();

    $this->actingAs($vendor)->get("/requests/{$empty->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('readiness.is_ready', false)
        ->where('readiness.pending', []) // slice 8c: documents are now evaluated
        ->where('readiness.errors', fn ($errors) => collect([
            'requisition.vendor_reference', 'requisition.item_description', 'requisition.quantity_requested',
            'requisition.tender_outcome', 'quotations', 'quotations.recommended', 'documents.original_rfq',
        ])->diff(collect($errors)->pluck('key'))->isEmpty()));

    [$complete, $owner] = vendorDraft(payload: nonPharmPayload());
    attachRequiredDocuments($complete);
    $this->actingAs($owner)->get("/requests/{$complete->id}/edit")->assertInertia(fn (Assert $page) => $page
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
    [CaseStatus::LETTER_ISSUED, 'Letter issued', 'LETTER_ISSUED'],
    [CaseStatus::CLOSED, 'Closed', 'CLOSED'],
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
    ];

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
        $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['item_code' => "C{$i}"]])->assertRedirect();
    }

    $this->actingAs($vendor)->put("/requests/{$case->id}", ['requisition' => ['item_code' => 'C31']])->assertStatus(429);
    expect(requisitionOf($case)->item_code)->toBe('C30');
});

it('shares can.requests only with users who hold the vendor permission', function (Closure $user, bool $expected) {
    $this->actingAs($user())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('can.requests', $expected));
})->with([
    'vendor' => [fn () => vendorUser(vendorOrg()), true],
    'cmo' => [fn () => caseUser(Role::Cmo), false],
]);
