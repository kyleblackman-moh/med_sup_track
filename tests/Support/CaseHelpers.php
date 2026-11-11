<?php

// Shared helpers for case tests. Loaded from tests/Pest.php.
//
// Everything here writes inside the test's DatabaseTransactions, so it is rolled
// back. Nothing is cached in statics, because a cached id would point at a row
// that the previous test rolled back.

use App\Enums\CaseStatus;
use App\Enums\OrganisationType;
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
