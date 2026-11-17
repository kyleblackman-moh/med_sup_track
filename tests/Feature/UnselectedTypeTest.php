<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Role;
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

    expect($type->name)->toBe('Not selected')
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
        ->and($case->title)->toBe('Not selected')
        ->and(json_decode($audit->metadata, true))->toBe(['procurement_type' => 'NOT_SELECTED']);
});

it('never lets NOT_SELECTED be passed in as a chosen type', function () {
    expect(fn () => app(CreateDraftCase::class)->handle(vendorUser(vendorOrg()), procurementTypeByCode('NOT_SELECTED')))
        ->toThrow(ValidationException::class);
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

it('shows "Not selected" in the Requests list and finds it by that label', function () {
    [$case, $vendor] = unselectedDraft();
    [$chosen] = newDraft('TENDER_EXCESS', $vendor);

    $rows = collect(pageProps($this->actingAs($vendor)->get(route('requests.index'))->assertOk())['requests']['data'])->keyBy('id');
    expect($rows[$case->id]['procurement_type'])->toBe(['code' => 'NOT_SELECTED', 'name' => 'Not selected', 'category' => null, 'selected' => false])
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
