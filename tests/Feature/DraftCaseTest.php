<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TechnicalStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Submissions\ProcurementTypeProfile;
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
    $type = procurementTypeByCode('AD_HOC_TENDER_UNABLE');

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
        ->and(json_decode($audit->metadata, true))->toBe(['procurement_type' => 'AD_HOC_TENDER_UNABLE']);
});

it('refuses staff users and writes nothing', function (Role $role) {
    $user = caseUser($role);

    expect(fn () => app(CreateDraftCase::class)->handle($user, procurementTypeByCode('AD_HOC_TENDER_UNABLE')))
        ->toThrow(AuthorizationException::class);
    expect(casesCreatedBy($user->id))->toBe(0);
})->with([Role::Cmo, Role::Technocrat, Role::SystemAdmin, Role::Auditor]);

it('refuses a vendor user without an organisation', function () {
    $vendor = vendorUser(null);

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode('AD_HOC_TENDER_UNABLE')))
        ->toThrow(AuthorizationException::class);
    expect(casesCreatedBy($vendor->id))->toBe(0);
});

// Slice V2-1: exactly the five request types.
it('creates a draft of each of the five request types', function (string $code) {
    [$case] = newDraft($code);

    expect($case->procurementType->code)->toBe($code)
        ->and($case->status)->toBe(CaseStatus::DRAFT);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('refuses an inactive procurement type with a validation error', function (string $code) {
    $vendor = vendorUser(vendorOrg());

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode($code)))
        ->toThrow(ValidationException::class);
    expect(casesCreatedBy($vendor->id))->toBe(0);
})->with(['AD_HOC', 'AD_HOC_PHARM', 'AD_HOC_NON_PHARM']);

it('refuses an active type that is not one of the five request types', function () {
    $vendor = vendorUser(vendorOrg());
    $other = ProcurementType::create(['code' => 'OTHER_ACTIVE', 'name' => 'Other', 'is_active' => true, 'sort_order' => 999]);

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, $other))->toThrow(ValidationException::class);
    expect(casesCreatedBy($vendor->id))->toBe(0);

    // Slice V2-1b: New request reads no type at all; the draft starts unselected.
    $this->actingAs($vendor)->post(route('requests.store'), ['procurement_type_id' => $other->id])->assertRedirect();
    expect(ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->sole()->procurementType->code)->toBe('NOT_SELECTED');
});

it('rolls back the draft and requisition when the audit write fails', function () {
    $vendor = vendorUser(vendorOrg());
    $requisitionsBefore = DB::table('case_requisitions')->count();
    failAuditWrites();

    expect(fn () => app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode('AD_HOC_TENDER_UNABLE')))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(casesCreatedBy($vendor->id))->toBe(0)
        ->and(DB::table('case_requisitions')->count())->toBe($requisitionsBefore);
});

// 3. SaveRequisition -------------------------------------------------------------

it('saves requisition fields and child rows', function () {
    [$case, $vendor] = newDraft();

    saveCompleteAdHoc($case, $vendor);

    $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    $quotations = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->get();
    $awards = CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->get();
    $order = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->sole();

    expect($requisition->item_description)->toBe('Infant feeding tube, size 6Fr')
        ->and($requisition->din)->toBe('02345678') // leading zero kept
        ->and($requisition->quantity_requested)->toBe('2800.00')
        ->and($requisition->quantity_to_purchase)->toBe('2800.00')
        ->and($requisition->tender_price)->toBe('1.2500')
        ->and($requisition->no_stock_on_hand)->toBeFalse()
        ->and($requisition->quoted_price_acceptable)->toBeTrue()
        ->and($quotations->pluck('supplier_name')->all())->toBe(['Supplier C', 'Supplier D'])
        ->and($quotations->pluck('sort_order')->all())->toBe([0, 1])
        ->and($quotations->pluck('considered')->all())->toBe([true, true]) // 2026-11-26: every quotation considered
        ->and($quotations[1]->unit_price)->toBe('1.5975')
        ->and($quotations[1]->is_recommended)->toBeTrue()
        ->and($awards->pluck('supplier_name')->all())->toBe(['Supplier A', 'Supplier B'])
        ->and($order->award_id)->toBe($awards[0]->id)
        ->and(DB::table('case_stock_expiries')->where('case_id', $case->id)->count())->toBe(2);
});

it('replaces a child section on each save, in array order', function () {
    [$case, $vendor] = newDraft('TENDER_EXCESS');
    saveCompleteAdHoc($case, $vendor);
    saveDraft($case, $vendor, ['stock_expiries' => [
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
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(2);

    $awards = DB::table('case_tender_awards')->where('case_id', $case->id)->orderBy('sort_order')->pluck('supplier_name')->all();
    expect($awards)->toBe(['Supplier A', 'Supplier B']);
});

it('clears a field on null and leaves an absent key unchanged', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());

    saveDraft($case, $vendor, ['requisition' => ['clinical_note' => null, 'strength' => '10Fr']]);

    $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($requisition->clinical_note)->toBeNull()
        ->and($requisition->strength)->toBe('10Fr')
        ->and($requisition->item_description)->toBe('Infant feeding tube, size 6Fr')
        ->and($requisition->tender_reference)->toBe('TENDER-2026-14 (2026-2027)');
});

it('writes every quotation as PENDING, even after staff had set a status', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, adHocPayload());

    $quotation = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->firstOrFail();
    $quotation->forceFill([
        'technical_status' => TechnicalStatus::SAMPLE_ACCEPTED,
        'technical_status_set_by' => caseUser(Role::Technocrat)->id,
        'technical_status_set_at' => now(),
    ])->save();

    // Saved in place (with its id): the update also resets the staff-set columns.
    $rows = adHocPayload()['quotations'];
    $rows[0]['id'] = $quotation->id;
    saveDraft($case, $vendor, ['quotations' => $rows]);

    $statuses = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->get();
    expect($statuses->pluck('id'))->toContain($quotation->id)
        ->and($statuses->pluck('technical_status')->unique()->all())->toBe([TechnicalStatus::PENDING])
        ->and($statuses->pluck('technical_status_set_by')->filter()->all())->toBe([]);
});

it('never changes status, reference_no or tracks', function () {
    [$case, $vendor] = newDraft();
    $before = rawCase($case);

    saveCompleteAdHoc($case, $vendor);

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
    saveDraft($case, $vendor, adHocPayload());
    $other = vendorUser(vendorOrg());

    expect(fn () => saveDraft($case, $other, ['requisition' => ['item_description' => 'Hijacked']]))
        ->toThrow(AuthorizationException::class);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('item_description'))
        ->toBe('Infant feeding tube, size 6Fr');
});

it('refuses staff users', function (Role $role) {
    [$case] = newDraft();

    expect(fn () => saveDraft($case, caseUser($role), ['requisition' => ['strength' => 'X']]))
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
        $case, $vendor, RequisitionData::validate(['requisition' => ['strength' => 'X']], $case->procurementType),
    ))->toThrow(DraftNotEditable::class);
});

it('audits a save with section names and counts but no field values', function () {
    [$case, $vendor] = newDraft();

    saveDraft($case, $vendor, adHocPayload());

    $audit = auditFor($case->id, 'case.draft_saved')->sole();
    expect(json_decode($audit->metadata, true))->toBe([
        'sections' => ['requisition', 'previous_request_in_cycle', 'quotations', 'tender_awards', 'stock_expiries'],
        'counts' => ['quotations' => 2, 'tender_awards' => 2, 'stock_expiries' => 2],
    ])->and((int) $audit->actor_user_id)->toBe($vendor->id)
        ->and($audit->metadata)->not->toContain('Infant feeding tube')
        ->and($audit->metadata)->not->toContain('Supplier')
        // Typed text (reasons, notes) never reaches the audit payload.
        ->and($audit->metadata)->not->toContain('No registration')
        ->and($audit->metadata)->not->toContain('Neonatal')
        ->and($audit->metadata)->not->toContain('expected range');
});

it('rolls back the save when the audit write fails', function () {
    [$case, $vendor] = newDraft();
    failAuditWrites();

    expect(fn () => saveDraft($case, $vendor, adHocPayload()))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('item_description'))->toBeNull()
        ->and(DB::table('case_quotations')->where('case_id', $case->id)->count())->toBe(0);
});
