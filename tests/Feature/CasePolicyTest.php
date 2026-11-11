<?php

use App\Enums\CaseStatus;
use App\Enums\Permission as PermissionName;
use App\Enums\ProcurementCategory;
use App\Enums\Role;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// CasePolicy::viewAny / act, the seeded view_all holders, mass assignment and CaseStatus.

const TEST_ACT = 'test.act';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Permission::findOrCreate(TEST_ACT, 'web');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('allows viewAny only for users who could ever see a case', function (Role $role, bool $expected) {
    $user = $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);

    expect(Gate::forUser($user)->allows('viewAny', ProcurementCase::class))->toBe($expected);
})->with([
    [Role::VendorApplicant, true],
    [Role::CmoRegistry, true],
    [Role::Cmo, true],
    [Role::Technocrat, true],
    [Role::CabOfficer, true],
    [Role::LegalOfficer, true],
    [Role::PsOffice, true],
    [Role::SystemAdmin, false],
    [Role::Auditor, false],
]);

/**
 * act() table. $assignment: active | completed | cancelled | pool_active | pool_completed | none.
 * $vendor: null (staff), 'own' (vendor of the case's org) or 'other'.
 */
it('decides act() from visibility, permission, status and active assignment', function (
    Role $role, string $assignment, CaseStatus $status, bool $hasPermission, ?string $vendor, bool $expected,
) {
    $own = vendorOrg();
    $case = makeCase(['org_id' => $own->id, 'status' => $status]);

    $user = match ($vendor) {
        'own' => vendorUser($own),
        'other' => vendorUser(vendorOrg()),
        default => caseUser($role),
    };

    if ($hasPermission) {
        $user->givePermissionTo(TEST_ACT);
    }

    match ($assignment) {
        'active' => assign($case, $role, $user),
        'completed' => assign($case, $role, $user, completed: true),
        'cancelled' => assign($case, $role, $user)->forceFill(['cancelled_at' => now()])->save(),
        'pool_active' => assign($case, $role),
        'pool_completed' => assign($case, $role, completed: true),
        'none' => null,
    };

    expect(Gate::forUser($user)->allows('act', [$case, TEST_ACT]))->toBe($expected);
})->with([
    'active assignment + permission' => [Role::Technocrat, 'active', CaseStatus::IN_REVIEW, true, null, true],
    'active pool row + permission' => [Role::CmoRegistry, 'pool_active', CaseStatus::SUBMITTED, true, null, true],
    'completed assignment' => [Role::Technocrat, 'completed', CaseStatus::IN_REVIEW, true, null, false],
    'completed pool row' => [Role::CmoRegistry, 'pool_completed', CaseStatus::SUBMITTED, true, null, false],
    'cancelled assignment' => [Role::Technocrat, 'cancelled', CaseStatus::IN_REVIEW, true, null, false],
    'no assignment' => [Role::Technocrat, 'none', CaseStatus::IN_REVIEW, true, null, false],
    'active assignment, no permission' => [Role::Technocrat, 'active', CaseStatus::IN_REVIEW, false, null, false],
    'cmo + permission' => [Role::Cmo, 'none', CaseStatus::IN_REVIEW, true, null, true],
    'ps_office + permission' => [Role::PsOffice, 'none', CaseStatus::WITH_PS, true, null, true],
    'cmo without permission' => [Role::Cmo, 'none', CaseStatus::IN_REVIEW, false, null, false],
    'vendor own org + permission' => [Role::VendorApplicant, 'none', CaseStatus::DRAFT, true, 'own', true],
    'vendor other org + permission' => [Role::VendorApplicant, 'none', CaseStatus::DRAFT, true, 'other', false],
    'vendor own org, no permission' => [Role::VendorApplicant, 'none', CaseStatus::DRAFT, false, 'own', false],
    // Terminal statuses: false for everyone.
    'CLOSED, cmo' => [Role::Cmo, 'none', CaseStatus::CLOSED, true, null, false],
    'WITHDRAWN, ps_office' => [Role::PsOffice, 'none', CaseStatus::WITHDRAWN, true, null, false],
    'REJECTED_FINAL, cmo' => [Role::Cmo, 'none', CaseStatus::REJECTED_FINAL, true, null, false],
    'CLOSED, active assignment' => [Role::Technocrat, 'active', CaseStatus::CLOSED, true, null, false],
    'WITHDRAWN, active assignment' => [Role::Technocrat, 'active', CaseStatus::WITHDRAWN, true, null, false],
    'REJECTED_FINAL, vendor own org' => [Role::VendorApplicant, 'none', CaseStatus::REJECTED_FINAL, true, 'own', false],
]);

it('denies act() to a vendor holding case.view_all on another org', function () {
    $case = makeCase(['status' => CaseStatus::SUBMITTED]);
    $vendor = vendorUser(vendorOrg());
    $vendor->givePermissionTo([TEST_ACT, PermissionName::CaseViewAll->value]);

    expect(Gate::forUser($vendor)->allows('act', [$case, TEST_ACT]))->toBeFalse();
});

it('gives case.view_all to cmo and ps_office only, across all roles', function () {
    $holders = SpatieRole::with('permissions')->get()
        ->filter(fn ($r) => $r->permissions->contains('name', PermissionName::CaseViewAll->value))
        ->pluck('name')->sort()->values()->all();

    expect(SpatieRole::count())->toBeGreaterThanOrEqual(count(Role::cases()))
        ->and($holders)->toBe([Role::Cmo->value, Role::PsOffice->value]);
});

it('does not mass-assign workflow columns on ProcurementCase', function () {
    $case = ProcurementCase::make([
        'title' => 'Allowed',
        'status' => 'CLOSED',
        'current_holder_role' => 'cmo',
        'technical_track' => 'favourable',
        'funding_track' => 'confirmed',
        'hardcopy_received_at' => now(),
        'hardcopy_received_by' => 1,
        'file_no' => 'HE:8/2/50/1',
        'reference_no' => 'NOR-2026-0001',
        'org_id' => 'x',
    ]);

    expect($case->title)->toBe('Allowed')
        ->and($case->getAttributes())->toHaveKeys(['title'])
        ->and($case->getAttributes())->not->toHaveKeys([
            'status', 'current_holder_role', 'technical_track', 'funding_track',
            'hardcopy_received_at', 'hardcopy_received_by', 'file_no', 'reference_no', 'org_id',
        ]);
});

it('keeps CaseTransition insert-only in the model', function () {
    $case = makeCase();
    $transition = new CaseTransition;
    $transition->forceFill([
        'case_id' => $case->id,
        'from_status' => CaseStatus::DRAFT,
        'to_status' => CaseStatus::SUBMITTED,
    ])->save();

    expect($transition->fresh()->to_status)->toBe(CaseStatus::SUBMITTED)
        ->and(function () use ($transition) {
            $transition->reason = 'changed';
            $transition->save();
        })->toThrow(LogicException::class, 'insert-only')
        ->and(fn () => $transition->delete())->toThrow(LogicException::class, 'insert-only');
});

it('casts ProcurementType category and is_active', function () {
    $type = testProcurementType()->fresh();

    expect($type)->toBeInstanceOf(ProcurementType::class)
        ->and($type->category)->toBe(ProcurementCategory::PHARM)
        ->and($type->is_active)->toBeTrue();
});

it('has exactly 14 statuses and three terminal ones', function () {
    $terminal = collect(CaseStatus::cases())->filter->isTerminal()->map->value->sort()->values()->all();

    expect(CaseStatus::cases())->toHaveCount(14)
        ->and(collect(CaseStatus::cases())->every(fn ($s) => $s->name === $s->value))->toBeTrue()
        ->and($terminal)->toBe(['CLOSED', 'REJECTED_FINAL', 'WITHDRAWN']);
});
