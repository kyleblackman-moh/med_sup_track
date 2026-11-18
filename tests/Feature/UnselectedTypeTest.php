<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Resources\Requests\RequestDetailResource;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\User;
use App\Submissions\ChangeRequestType;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice V2-1b: a new draft's type is NOT_SELECTED until the vendor chooses one of
// the five in the form (ChangeRequestType). DatabaseTransactions only.

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

/** A vendor and a new, unselected draft (as New request makes it). */
function unselectedDraft(?User $vendor = null): array
{
    $vendor ??= vendorUser(vendorOrg());

    return [app(CreateDraftCase::class)->handle($vendor), $vendor];
}

function unselectedReport(ProcurementCase $case): array
{
    return app(SubmissionReadiness::class)->evaluate(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors;
}

// 1. The type -----------------------------------------------------------------------

it('seeds NOT_SELECTED as an inactive type with no category and no required file', function () {
    $type = ProcurementType::where('code', 'NOT_SELECTED')->sole();

    expect($type->name)->toBe('Not Selected')
        ->and($type->is_active)->toBeFalse()
        ->and($type->category)->toBeNull()
        ->and($type->documents->pluck('purpose')->map(fn ($p) => $p->value)->all())->toBe(['bids', 'regulatory', 'evidence'])
        ->and($type->documents->where('is_required', true)->count())->toBe(0)
        ->and(ProcurementTypeProfile::REQUEST_TYPES)->not->toContain('NOT_SELECTED');

    // Idempotent: a second run changes nothing.
    $before = [DB::table('procurement_types')->count(), DB::table('procurement_type_documents')->count()];
    $this->seed(ProcurementTypeSeeder::class);
    expect([DB::table('procurement_types')->count(), DB::table('procurement_type_documents')->count()])->toBe($before)
        ->and(ProcurementType::where('code', 'NOT_SELECTED')->pluck('id')->all())->toBe([$type->id]);
});

it('creates a draft with no type given as NOT_SELECTED, audited with codes only', function () {
    [$case, $vendor] = unselectedDraft();

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseCreated->value)->sole();
    expect($case->procurementType->code)->toBe('NOT_SELECTED')
        ->and($case->status)->toBe(CaseStatus::DRAFT)
        ->and($case->org_id)->toBe($vendor->org_id)
        ->and($case->title)->toBe('Not Selected')
        ->and(json_decode($audit->metadata, true))->toBe(['procurement_type' => 'NOT_SELECTED']);
});

it('never lets NOT_SELECTED be passed in as a chosen type', function () {
    expect(fn () => app(CreateDraftCase::class)->handle(vendorUser(vendorOrg()), procurementTypeByCode('NOT_SELECTED')))
        ->toThrow(ValidationException::class);
});

it('starts an unselected draft from New request, which another organisation cannot open', function () {
    $vendor = vendorUser(vendorOrg());

    $response = $this->actingAs($vendor)->post(route('requests.store'));

    $case = ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->sole();
    $response->assertRedirect(route('requests.edit', $case->id));
    expect($case->procurementType->code)->toBe('NOT_SELECTED')
        ->and($case->org_id)->toBe($vendor->org_id);

    $this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk();
    $other = vendorUser(vendorOrg());
    foreach ([route('requests.edit', $case->id), route('requests.show', $case->id)] as $uri) {
        $this->actingAs($other)->get($uri)->assertNotFound();
    }
    $this->actingAs($other)->put(route('requests.type', $case->id), ['procurement_type' => 'TENDER_EXCESS'])->assertNotFound();
});

it('has no GET /requests/create any more', function () {
    $this->actingAs(vendorUser(vendorOrg()))->get('/requests/create')->assertNotFound();
});

// 2. Choosing the type ----------------------------------------------------------------

it('changes an unselected draft to each of the five types, audited with codes only', function (string $code) {
    [$case, $vendor] = unselectedDraft();

    $changed = app(ChangeRequestType::class)->handle($case, $vendor, $code);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::DraftTypeChanged->value)->sole();
    expect($changed->procurementType->code)->toBe($code)
        ->and($changed->title)->toBe(procurementTypeByCode($code)->name)
        ->and(json_decode($audit->metadata, true))->toBe(['from' => 'NOT_SELECTED', 'to' => $code]);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('never changes a draft to NOT_SELECTED', function (?string $from) {
    [$case, $vendor] = $from === null ? unselectedDraft() : newDraft($from);

    expect(fn () => app(ChangeRequestType::class)->handle($case, $vendor, 'NOT_SELECTED'))->toThrow(ValidationException::class);

    $this->actingAs($vendor)->from(route('requests.edit', $case->id))
        ->put(route('requests.type', $case->id), ['procurement_type' => 'NOT_SELECTED'])
        ->assertSessionHasErrors('procurement_type');

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->procurementType->code)->toBe($from ?? 'NOT_SELECTED')
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::DraftTypeChanged->value)->exists())->toBeFalse();
})->with([null, 'AD_HOC_EMERGENCY', 'TENDER_VARIATION']);

// 3. Readiness ---------------------------------------------------------------------------

it('blocks the submit of an unselected draft with "Choose a request type" in Item and tender', function () {
    [$case, $vendor] = unselectedDraft();
    saveCompleteAdHoc($case, $vendor); // every common item filled in

    $errors = unselectedReport($case);
    expect($errors)->toBe([['key' => 'request_type', 'message' => 'Choose a request type']])
        ->and(RequestSections::sectionOf('request_type'))->toBe('item_tender');

    expect(fn () => app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor))
        ->toThrow(SubmissionNotReady::class);
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('DRAFT');
});

it('lists only the common items, never per-type ones, while unselected', function () {
    [$case] = unselectedDraft();

    expect(array_column(unselectedReport($case), 'key'))->toBe([
        'request_type',
        'requisition.item_description', 'requisition.din', 'requisition.quantity_requested', 'requisition.unit_of_measure',
        'requisition.tender_cycle_start', 'requisition.tender_cycle_end',
    ]);
});

it('applies the per-type rules once a type is chosen', function (string $code, array $keys) {
    [$case, $vendor] = unselectedDraft();
    saveCompleteAdHoc($case, $vendor);
    saveDraft($case, $vendor, ['requisition' => ['tender_reference' => null]]);

    app(ChangeRequestType::class)->handle($case, $vendor, $code);

    expect(array_column(unselectedReport($case), 'key'))->toBe($keys);
})->with([
    // Contract reference and the Bids file are required here...
    ['AD_HOC_TENDER_UNABLE', ['requisition.tender_reference', 'documents.bids']],
    // ...not here (Bids only).
    ['AD_HOC_EMERGENCY', ['documents.bids']],
    // ...and no file for Excess, but the contract reference.
    ['TENDER_EXCESS', ['requisition.tender_reference']],
]);

// 4. The list, search and staff -----------------------------------------------------------

it('shows "Not Selected" in the Requests list and finds it by that label', function () {
    [$case, $vendor] = unselectedDraft();
    [$chosen] = newDraft('TENDER_EXCESS', $vendor);

    $rows = collect(pageProps($this->actingAs($vendor)->get(route('requests.index'))->assertOk())['requests']['data'])->keyBy('id');
    expect($rows[$case->id]['procurement_type'])->toBe(['code' => 'NOT_SELECTED', 'name' => 'Not Selected', 'category' => null, 'selected' => false])
        ->and($rows[$chosen->id]['procurement_type']['selected'])->toBeTrue();

    $found = collect(pageProps($this->actingAs($vendor)->get(route('requests.index', ['q' => 'not sel']))->assertOk())['requests']['data'])->pluck('id')->all();
    expect($found)->toBe([$case->id]);
});

it('never shows an unselected draft to staff', function (Role $role) {
    [$case] = unselectedDraft();
    $staff = caseUser($role);

    $this->actingAs($staff)->get(route('cases.show', $case->id))->assertNotFound();
    expect(ProcurementCase::query()->whereKey($case->id)->exists())->toBeFalse(); // the visibility scope, as that user
})->with([Role::CmoRegistry, Role::Cmo, Role::PsOffice, Role::Auditor]);

// 5. The draft DTO and the temporary type control --------------------------------------

it('gives a draft its type code and the five types it may change to, in order', function (?string $code) {
    [$case, $vendor] = $code === null ? unselectedDraft() : newDraft($code);

    $detail = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['case'];

    expect($detail['request_type'])->toBe($code ?? 'NOT_SELECTED')
        ->and($detail['type_options'])->toBe([
            ['code' => 'AD_HOC_EMERGENCY', 'label' => 'Ad Hoc - Emergency', 'group' => 'Ad Hoc', 'icon' => 'siren'],
            ['code' => 'AD_HOC_TENDER_UNABLE', 'label' => 'Ad Hoc - Tender Unable to Supply', 'group' => 'Ad Hoc', 'icon' => 'package-x'],
            ['code' => 'AD_HOC_NOT_AWARDED', 'label' => 'Ad Hoc - Not Awarded', 'group' => 'Ad Hoc', 'icon' => 'file-x'],
            ['code' => 'TENDER_EXCESS', 'label' => 'Tender - Excess', 'group' => null, 'icon' => 'trending-up'],
            ['code' => 'TENDER_VARIATION', 'label' => 'Tender - Variation', 'group' => null, 'icon' => 'arrow-left-right'],
        ]);
})->with([null, 'TENDER_VARIATION']);

it('never offers NOT_SELECTED, even if it were made active', function () {
    ProcurementType::where('code', 'NOT_SELECTED')->update(['is_active' => true]);

    expect(array_column(RequestDetailResource::typeOptions(), 'code'))->toBe(ProcurementTypeProfile::REQUEST_TYPES);
});

it('sends no type choice once the request is submitted', function () {
    [$case, $vendor] = readyDraft();
    app(SubmitCase::class)->handle($case, $vendor);

    $detail = pageProps($this->actingAs($vendor)->get(route('requests.show', $case->id))->assertOk())['case'];

    expect($detail)->not->toHaveKey('request_type')->not->toHaveKey('type_options');
});

it('reloads the form sections for the new type after a change', function () {
    [$case, $vendor] = unselectedDraft();
    $keys = fn () => array_column(pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id)))['request_sections'], 'key');

    expect($keys())->not->toContain('variation_details');
    $this->actingAs($vendor)->put(route('requests.type', $case->id), ['procurement_type' => 'TENDER_VARIATION'])->assertRedirect(route('requests.edit', $case->id));
    expect($keys())->toContain('variation_details');
    $this->actingAs($vendor)->put(route('requests.type', $case->id), ['procurement_type' => 'TENDER_EXCESS']);
    expect($keys())->not->toContain('variation_details'); // the page shows the "may hide sections" note
});

it('never names NOT_SELECTED in the vendor-facing code', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        expect(str_contains(file_get_contents($file->getPathname()), 'NOT_SELECTED'))->toBeFalse($file->getPathname());
    }
});

// 6. Section states before and after the type is chosen (slice V2-1c) -------------------

/** The edit page's two section lists for $case, keyed by section key. */
function sectionStates($test, ProcurementCase $case, User $vendor): array
{
    $props = pageProps($test->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk());

    return [collect($props['request_sections'])->keyBy('key'), collect($props['sections'])->keyBy('key')];
}

it('never reports a type-dependent section as Complete or Optional on an empty unselected draft', function () {
    [$case, $vendor] = unselectedDraft();
    [$request, $screen] = sectionStates($this, $case, $vendor);

    $dependent = $request->only(RequestSections::TYPE_DEPENDENT);
    expect($dependent->keys()->all())->toBe(['awarded_supplier', 'existing_arrangement', 'stock', 'quotation_comparison', 'documents']);
    foreach ($dependent as $key => $section) {
        expect($section['complete'])->toBeFalse($key)
            ->and($section['optional'])->toBeFalse($key)
            ->and($section['note'])->toBe('Choose a request type');
    }
    // Item and tender does not depend on the type: its normal state (not complete: it is empty).
    expect($request['item_tender']['note'])->toBeNull()
        ->and($request['item_tender']['complete'])->toBeFalse();

    // The current screen's rail: the same, for every section holding a type-dependent one.
    foreach (['tender', 'stock', 'quotations', 'price', 'documents'] as $key) {
        expect($screen[$key]['complete'])->toBeFalse($key)
            ->and($screen[$key]['optional'])->toBeFalse($key)
            ->and($screen[$key]['summary'])->toBe('Choose a request type');
    }
    expect($screen['item']['summary'])->toBe('Not started');
});

it('applies the Emergency rules once chosen: stock, quotations and documents are not complete on an empty draft', function () {
    [$case, $vendor] = unselectedDraft();
    app(ChangeRequestType::class)->handle($case, $vendor, 'AD_HOC_EMERGENCY');
    [$request, $screen] = sectionStates($this, $case, $vendor);

    foreach (['stock', 'quotation_comparison', 'documents'] as $key) {
        expect($request[$key]['complete'])->toBeFalse($key)
            ->and($request[$key]['optional'])->toBeFalse($key)
            ->and($request[$key]['note'])->toBeNull();
    }
    // Emergency: awarded supplier optional, orders always optional (nothing entered, so complete).
    expect($request['awarded_supplier']['optional'])->toBeTrue()
        ->and($request['existing_arrangement']['optional'])->toBeTrue()
        ->and($request)->not->toHaveKey('variation_details');
    foreach (['stock', 'quotations', 'documents'] as $key) {
        expect($screen[$key]['complete'])->toBeFalse($key);
    }
    expect($screen['price']['optional'])->toBeTrue();
});

it('applies the Tender Variation rules once chosen', function () {
    [$case, $vendor] = unselectedDraft();
    app(ChangeRequestType::class)->handle($case, $vendor, 'TENDER_VARIATION');
    [$request] = sectionStates($this, $case, $vendor);

    expect($request['variation_details']['complete'])->toBeFalse()
        ->and($request['variation_details']['optional'])->toBeFalse()
        ->and($request['awarded_supplier']['complete'])->toBeFalse()
        ->and($request['awarded_supplier']['optional'])->toBeFalse()
        // The table: stock and quotations optional for a Tender Variation; nothing entered,
        // so they report "optional", never complete.
        ->and($request['stock']['optional'])->toBeTrue()
        ->and($request['stock']['state'])->toBe('optional')
        ->and($request['stock']['complete'])->toBeFalse()
        ->and($request['quotation_comparison']['optional'])->toBeTrue()
        ->and($request['quotation_comparison']['state'])->toBe('optional')
        ->and($request['quotation_comparison']['complete'])->toBeFalse()
        // No file is required for a Tender Variation, and none is attached.
        ->and($request['documents']['optional'])->toBeTrue()
        ->and($request['documents']['state'])->toBe('optional')
        ->and($request['documents']['complete'])->toBeFalse()
        ->and($request->pluck('note')->filter()->all())->toBe([]);
});
