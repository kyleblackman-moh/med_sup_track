<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TechnicalStatus;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Slice 8a: CreateDraftCase and SaveRequisition. DatabaseTransactions; no HTTP.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function casesCreatedBy(int $userId): int
{
    return DB::table('cases')->where('created_by', $userId)->count();
}

function auditFor(string $caseId, string $action)
{
    return DB::table('audit_logs')->where('case_id', $caseId)->where('action', $action)->get();
}

function failAuditWrites(): void
{
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs')) {
            throw new RuntimeException('audit store unavailable');
        }
    });
}

function rawCase(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

// 1. CreateDraftCase ------------------------------------------------------------

it('creates a draft for a vendor user, owned by the user\'s organisation', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $type = procurementTypeByCode('AD_HOC_NON_PHARM');

    $case = app(CreateDraftCase::class)->handle($vendor, $type);

    $row = rawCase($case);
    expect($row->status)->toBe('DRAFT')
        ->and($row->org_id)->toBe($org->id)
        ->and((int) $row->procurement_type_id)->toBe($type->id)
        ->and($row->current_holder_role)->toBe('vendor_applicant')
        ->and($row->reference_no)->toBeNull()
        ->and((int) $row->created_by)->toBe($vendor->id)
        ->and(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(1);

    $audit = auditFor($case->id, 'case.created')->sole();
    expect((int) $audit->actor_user_id)->toBe($vendor->id)
        ->and(json_decode($audit->metadata, true))->toBe(['procurement_type' => 'AD_HOC_NON_PHARM']);
});

it('refuses staff users and writes nothing', function (Role $role) {
    $user = caseUser($role);

    expect(fn () => app(CreateDraftCase::class)->handle($user, procurementTypeByCode('AD_HOC_PHARM')))
        ->toThrow(AuthorizationException::class);
    expect(casesCreatedBy($user->id))->toBe(0);
})->with([Role::Cmo, Role::Technocrat, Role::SystemAdmin, Role::Auditor]);

it('refuses a vendor user without an organisation', function () {
    $vendor = vendorUser(null);

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode('AD_HOC_PHARM')))
        ->toThrow(AuthorizationException::class);
    expect(casesCreatedBy($vendor->id))->toBe(0);
});

it('refuses an inactive procurement type with a validation error', function () {
    $vendor = vendorUser(vendorOrg());

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode('TENDER_VARIATION')))
        ->toThrow(ValidationException::class);
    expect(casesCreatedBy($vendor->id))->toBe(0);
});

it('rolls back the draft and requisition when the audit write fails', function () {
    $vendor = vendorUser(vendorOrg());
    $requisitionsBefore = DB::table('case_requisitions')->count();
    failAuditWrites();

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode('AD_HOC_PHARM')))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(casesCreatedBy($vendor->id))->toBe(0)
        ->and(DB::table('case_requisitions')->count())->toBe($requisitionsBefore);
});

// 3. SaveRequisition -------------------------------------------------------------

it('saves requisition fields and child rows', function () {
    [$case, $vendor] = newDraft();

    saveDraft($case, $vendor, nonPharmPayload());

    $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    $quotations = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->get();

    expect($requisition->item_description)->toBe('Infant feeding tube, size 6Fr')
        ->and($requisition->quantity_requested)->toBe('2800.00')
        ->and($quotations->pluck('supplier_name')->all())->toBe(['Supplier A', 'Supplier B', 'Supplier C', 'Supplier D', 'Supplier E', 'Supplier F'])
        ->and($quotations->pluck('sort_order')->all())->toBe([0, 1, 2, 3, 4, 5])
        ->and($quotations[2]->unit_price)->toBe('1.5975')
        ->and($quotations[2]->recommended_quantity)->toBe('2800.00');
});

it('replaces a child section on each save, in array order', function () {
    [$case, $vendor] = newDraft('TENDER_EXCESS');
    saveDraft($case, $vendor, tenderExcessPayload() + ['stock_expiries' => [
        ['quantity' => '10', 'expiry_date' => '2027-01-31'],
        ['quantity' => '20', 'expiry_date' => '2027-02-28'],
        ['quantity' => '30', 'expiry_date' => '2027-03-31'],
    ]]);

    saveDraft($case, $vendor, ['stock_expiries' => [
        ['quantity' => '40', 'expiry_date' => '2027-05-31'],
        ['quantity' => '50', 'expiry_date' => '2027-04-30'],
    ]]);

    $expiries = DB::table('case_stock_expiries')->where('case_id', $case->id)->orderBy('id')->get();
    expect($expiries)->toHaveCount(2)
        ->and($expiries->pluck('quantity')->map(fn ($q) => (string) (int) $q)->all())->toBe(['40', '50'])
        // Sections absent from the second payload are untouched.
        ->and(DB::table('case_tender_awards')->where('case_id', $case->id)->count())->toBe(2)
        ->and(DB::table('case_purchase_orders')->where('case_id', $case->id)->count())->toBe(1)
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(1);

    $awards = DB::table('case_tender_awards')->where('case_id', $case->id)->orderBy('sort_order')->pluck('supplier_name')->all();
    expect($awards)->toBe(['Supplier A', 'Supplier B']);
});

it('clears a field on null and leaves an absent key unchanged', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, nonPharmPayload());

    saveDraft($case, $vendor, ['requisition' => ['purpose' => null, 'item_code' => 'NEW-CODE']]);

    $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($requisition->purpose)->toBeNull()
        ->and($requisition->item_code)->toBe('NEW-CODE')
        ->and($requisition->item_description)->toBe('Infant feeding tube, size 6Fr')
        ->and($requisition->vendor_reference)->toBe('REQ-2026-001');
});

it('writes every quotation as PENDING, even after staff had set a status', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, nonPharmPayload());

    $quotation = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->firstOrFail();
    $quotation->forceFill([
        'technical_status' => TechnicalStatus::SAMPLE_ACCEPTED,
        'technical_status_set_by' => caseUser(Role::Technocrat)->id,
        'technical_status_set_at' => now(),
    ])->save();

    saveDraft($case, $vendor, ['quotations' => nonPharmPayload()['quotations']]);

    $statuses = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->get();
    expect($statuses->pluck('technical_status')->unique()->all())->toBe([TechnicalStatus::PENDING])
        ->and($statuses->pluck('technical_status_set_by')->filter()->all())->toBe([]);
});

it('never changes status, reference_no or tracks', function () {
    [$case, $vendor] = newDraft();
    $before = rawCase($case);

    saveDraft($case, $vendor, nonPharmPayload());

    $after = rawCase($case);
    expect($after->status)->toBe($before->status)
        ->and($after->reference_no)->toBe($before->reference_no)
        ->and($after->current_holder_role)->toBe($before->current_holder_role)
        ->and($after->technical_track)->toBe($before->technical_track)
        ->and($after->funding_track)->toBe($before->funding_track)
        ->and($after->hardcopy_received_at)->toBe($before->hardcopy_received_at);
});

it('refuses a vendor of another organisation and changes nothing', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, nonPharmPayload());
    $other = vendorUser(vendorOrg());

    expect(fn () => saveDraft($case, $other, ['requisition' => ['item_description' => 'Hijacked']]))
        ->toThrow(AuthorizationException::class);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('item_description'))
        ->toBe('Infant feeding tube, size 6Fr');
});

it('refuses staff users', function (Role $role) {
    [$case] = newDraft();

    expect(fn () => saveDraft($case, caseUser($role), ['requisition' => ['item_code' => 'X']]))
        ->toThrow(AuthorizationException::class);
})->with([Role::Cmo, Role::Technocrat]);

it('refuses to edit a case that is no longer a draft', function (CaseStatus $status) {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $case = makeCase(['org_id' => $org->id, 'status' => $status]);

    expect(fn () => saveDraft($case, $vendor, ['requisition' => ['item_code' => 'X']]))
        ->toThrow(DraftNotEditable::class);
})->with([CaseStatus::SUBMITTED, CaseStatus::WITHDRAWN]);

it('refuses when the case left DRAFT after it was loaded', function () {
    [$case, $vendor] = newDraft();
    DB::table('cases')->where('id', $case->id)->update(['status' => 'SUBMITTED']);

    expect(fn () => app(SaveRequisition::class)->handle(
        $case, $vendor, RequisitionData::validate(['requisition' => ['item_code' => 'X']], $case->procurementType),
    ))->toThrow(DraftNotEditable::class);
});

it('audits a save with section names and counts but no field values', function () {
    [$case, $vendor] = newDraft();

    saveDraft($case, $vendor, nonPharmPayload());

    $audit = auditFor($case->id, 'case.draft_saved')->sole();
    expect(json_decode($audit->metadata, true))->toBe([
        'sections' => ['requisition', 'quotations'],
        'counts' => ['quotations' => 6],
    ])->and((int) $audit->actor_user_id)->toBe($vendor->id)
        ->and($audit->metadata)->not->toContain('Infant feeding tube')
        ->and($audit->metadata)->not->toContain('Supplier');
});

it('rolls back the save when the audit write fails', function () {
    [$case, $vendor] = newDraft();
    failAuditWrites();

    expect(fn () => saveDraft($case, $vendor, nonPharmPayload()))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('item_description'))->toBeNull()
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(0);
});
