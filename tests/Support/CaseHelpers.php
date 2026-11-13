<?php

// Shared helpers for case tests. Loaded from tests/Pest.php.
//
// Everything here writes inside the test's DatabaseTransactions, so it is rolled
// back. Nothing is cached in statics, because a cached id would point at a row
// that the previous test rolled back.

use App\Enums\CaseStatus;
use App\Enums\OrganisationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\CaseAssignment;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\User;
use Illuminate\Support\Str;

function vendorOrg(): Organisation
{
    return Organisation::create([
        'name' => 'Test Vendor '.Str::ulid(),
        'type' => OrganisationType::Vendor,
        'active' => true,
    ]);
}

function mohOrg(): Organisation
{
    return Organisation::where('type', OrganisationType::Moh)->firstOrFail();
}

function testProcurementType(): ProcurementType
{
    return ProcurementType::firstOrCreate(
        ['code' => 'TEST-HELPER-TYPE'],
        ['name' => 'Test helper type', 'category' => 'PHARM'],
    );
}

/**
 * A staff user (MOH) with an optional role, or a vendor user when $attributes
 * sets user_type to vendor. Same factory pattern as the slice 3-4 tests.
 */
function caseUser(Role|string|null $role = null, array $attributes = []): User
{
    $user = User::factory()->create([
        'email' => 'case-'.Str::lower(Str::ulid()).'@example.test',
        'org_id' => mohOrg()->id,
        ...$attributes,
    ]);

    if ($role !== null) {
        $user->assignRole($role instanceof Role ? $role->value : $role);
    }

    return $user;
}

function vendorUser(?Organisation $org, array $attributes = []): User
{
    return caseUser(Role::VendorApplicant, [
        'user_type' => UserType::Vendor->value,
        'org_id' => $org?->id,
        ...$attributes,
    ]);
}

/**
 * Inserts a case with forceFill, so it works despite the non-fillable columns.
 */
function makeCase(array $attributes = []): ProcurementCase
{
    $case = new ProcurementCase;
    $case->forceFill([
        'org_id' => $attributes['org_id'] ?? vendorOrg()->id,
        'created_by' => $attributes['created_by'] ?? caseUser()->id,
        'procurement_type_id' => testProcurementType()->id,
        'title' => 'Test case',
        'status' => CaseStatus::DRAFT,
        ...$attributes,
    ])->save();

    return $case;
}

/**
 * Assigns $case to $role, for one user or (when $user is null) as a role-pool row.
 */
function assign(ProcurementCase $case, Role|string $role, ?User $user = null, bool $completed = false): CaseAssignment
{
    $assignment = new CaseAssignment;
    $assignment->forceFill([
        'case_id' => $case->id,
        'role' => $role instanceof Role ? $role->value : $role,
        'user_id' => $user?->id,
        'assigned_at' => now(),
        'completed_at' => $completed ? now() : null,
    ])->save();

    return $assignment;
}

// ---------------------------------------------------------------------------
// Slice 6 visibility fixture, shared by the case and child-table visibility tests.
// ---------------------------------------------------------------------------

/**
 * Fixture from the slice brief:
 *   A = org X SUBMITTED, active pool assignment for cmo_registry
 *   B = org X IN_REVIEW, active assignment to technocrat T1
 *   C = org Y IN_REVIEW, completed assignment to T1, active assignment to cab_officer C1
 *   D = org Y WITH_LEGAL, active assignment to legal_officer L1
 *   E = org X CLOSED, completed assignment to T1
 *   F = org Y DRAFT, no assignments
 */
function visibilityFixture(): array
{
    $x = vendorOrg();
    $y = vendorOrg();

    $vendorWithViewAll = vendorUser($x);
    $vendorWithViewAll->givePermissionTo(Permission::CaseViewAll->value);

    $actors = [
        'cmo' => caseUser(Role::Cmo),
        'ps_office' => caseUser(Role::PsOffice),
        'vendor_x' => vendorUser($x),
        'vendor_y' => vendorUser($y),
        't1' => caseUser(Role::Technocrat),
        't2' => caseUser(Role::Technocrat),
        'c1' => caseUser(Role::CabOfficer),
        'l1' => caseUser(Role::LegalOfficer),
        'registry' => caseUser(Role::CmoRegistry),
        'system_admin' => caseUser(Role::SystemAdmin),
        'auditor' => caseUser(Role::Auditor),
        'vendor_no_org' => vendorUser(null),
        'vendor_with_view_all' => $vendorWithViewAll,
        'guest' => null,
    ];

    $cases = [
        'A' => makeCase(['org_id' => $x->id, 'status' => CaseStatus::SUBMITTED]),
        'B' => makeCase(['org_id' => $x->id, 'status' => CaseStatus::IN_REVIEW]),
        'C' => makeCase(['org_id' => $y->id, 'status' => CaseStatus::IN_REVIEW]),
        'D' => makeCase(['org_id' => $y->id, 'status' => CaseStatus::WITH_LEGAL]),
        'E' => makeCase(['org_id' => $x->id, 'status' => CaseStatus::CLOSED]),
        'F' => makeCase(['org_id' => $y->id, 'status' => CaseStatus::DRAFT]),
    ];

    assign($cases['A'], Role::CmoRegistry);
    assign($cases['B'], Role::Technocrat, $actors['t1']);
    assign($cases['C'], Role::Technocrat, $actors['t1'], completed: true);
    assign($cases['C'], Role::CabOfficer, $actors['c1']);
    assign($cases['D'], Role::LegalOfficer, $actors['l1']);
    assign($cases['E'], Role::Technocrat, $actors['t1'], completed: true);

    return ['x' => $x, 'y' => $y, 'actors' => $actors, 'cases' => $cases];
}

// F is a DRAFT: no staff user sees it, even with case.view_all (slice 9a).
const EXPECTED_VISIBLE = [
    'cmo' => ['A', 'B', 'C', 'D', 'E'],
    'ps_office' => ['A', 'B', 'C', 'D', 'E'],
    'vendor_x' => ['A', 'B', 'E'],
    'vendor_y' => ['C', 'D', 'F'],
    't1' => ['B', 'C', 'E'],
    't2' => [],
    'c1' => ['C'],
    'l1' => ['D'],
    'registry' => ['A'],
    'system_admin' => [],
    'auditor' => [],
    'vendor_no_org' => [],
    'vendor_with_view_all' => ['A', 'B', 'E'],
    'guest' => [],
];

function actAs(?User $user): void
{
    if ($user) {
        test()->actingAs($user);
    } else {
        auth()->guard('web')->logout();
    }
}

/** Fixture ids, so rows outside this test never affect the result. */
function fixtureIds(array $fixture): array
{
    return collect($fixture['cases'])->map->id->values()->all();
}
