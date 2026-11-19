<?php

use App\Enums\Role;
use App\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// FIX-2 (3): guards on the seeded role -> permission matrix, read back from the
// database after seeding (not from the enum). A staff role never holds
// case.submit; a vendor role holds only the vendor permissions. A failure
// names each offending role and permission. DatabaseTransactions only.

/** The only permissions a vendor role may hold. Anything else is a staff permission. */
const VENDOR_PERMISSIONS = [
    'case.submit',
    'case.view_assigned',
    'case.withdraw',
    'document.upload',
    'document.download',
];

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Seeded role name => its permission names, from the database. @return array<string, list<string>> */
function seededMatrix(): array
{
    return DB::table('roles')
        ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
        ->leftJoin('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.guard_name', 'web')
        ->orderBy('roles.name')->orderBy('permissions.name')
        ->get(['roles.name as role', 'permissions.name as permission'])
        ->groupBy('role')
        ->map(fn ($rows) => $rows->pluck('permission')->filter()->values()->all())
        ->all();
}

/** The kind of account a seeded role is for; an unknown role is reported, never guessed. */
function seededRoleType(string $role): ?UserType
{
    return Role::tryFrom($role)?->userType();
}

it('knows the kind of every seeded role', function () {
    $unknown = array_values(array_filter(array_keys(seededMatrix()), fn (string $role) => seededRoleType($role) === null));

    expect($unknown)->toBe([], 'Roles in the database that App\Enums\Role does not define: '.implode(', ', $unknown));
});

/** "role holds case.submit" for each staff role that does. @return list<string> */
function staffSubmitOffences(array $matrix): array
{
    $offending = [];
    foreach ($matrix as $role => $permissions) {
        if (seededRoleType($role) === UserType::Staff && in_array('case.submit', $permissions, true)) {
            $offending[] = "{$role} holds case.submit";
        }
    }

    return $offending;
}

/** "role holds permission" for each staff permission a vendor role holds. @return list<string> */
function vendorStaffOffences(array $matrix): array
{
    $offending = [];
    foreach ($matrix as $role => $permissions) {
        if (seededRoleType($role) !== UserType::Vendor) {
            continue;
        }
        foreach (array_diff($permissions, VENDOR_PERMISSIONS) as $permission) {
            $offending[] = "{$role} holds {$permission}";
        }
    }

    return $offending;
}

it('gives no staff role case.submit', function () {
    $offending = staffSubmitOffences(seededMatrix());

    expect($offending)->toBe([], 'Staff roles holding case.submit: '.implode('; ', $offending));
});

it('gives no vendor role a staff permission', function () {
    $offending = vendorStaffOffences(seededMatrix());

    expect($offending)->toBe([], 'Vendor roles holding staff permissions: '.implode('; ', $offending));
});

it('names the offending role and permission when the matrix is wrong', function () {
    // Inside this test's transaction only: plant one bad grant each way.
    Spatie\Permission\Models\Role::findByName('vendor_applicant', 'web')->givePermissionTo('query.forward');
    Spatie\Permission\Models\Role::findByName('technocrat', 'web')->givePermissionTo('case.submit');

    expect(vendorStaffOffences(seededMatrix()))->toBe(['vendor_applicant holds query.forward'])
        ->and(staffSubmitOffences(seededMatrix()))->toBe(['technocrat holds case.submit']);
});

it('names the staff permissions the vendor guard checks', function (string $permission) {
    expect(VENDOR_PERMISSIONS)->not->toContain($permission)
        ->and(DB::table('permissions')->where('name', $permission)->exists())->toBeTrue();
})->with(['case.view_all', 'query.forward', 'query.raise_to_vendor', 'case.route_to_review', 'users.manage', 'case.intake']);

it('has at least one vendor role and one staff role to guard', function () {
    $types = array_map(fn (string $role) => seededRoleType($role), array_keys(seededMatrix()));

    expect($types)->toContain(UserType::Vendor)->toContain(UserType::Staff);
});
