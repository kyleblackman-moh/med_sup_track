<?php

use App\Enums\OrganisationType;
use App\Enums\Permission as PermissionName;
use App\Enums\Role as RoleName;
use App\Models\Organisation;
use Database\Seeders\OrganisationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** The expected matrix, written out independently of App\Enums\Role::permissions(). */
const EXPECTED_ROLE_PERMISSIONS = [
    'vendor_applicant' => ['case.submit', 'case.view_assigned', 'document.upload', 'document.download', 'case.withdraw'],
    'cmo_registry' => ['case.view_assigned', 'document.upload', 'document.download', 'hardcopy.confirm', 'case.intake'],
    'cmo' => [
        'case.view_all', 'case.view_assigned', 'document.upload', 'document.download', 'hardcopy.confirm',
        'case.intake', 'case.route_to_review', 'query.forward', 'file.prepare', 'letter.issue',
        'case.history.view', 'vendor_user.invite', 'assignment.reassign', 'delegation.manage',
        'case.withdraw', 'case.reject_final',
    ],
    'technocrat' => ['case.view_assigned', 'document.upload', 'document.download', 'technical.consider', 'query.raise_to_vendor'],
    'cab_officer' => ['case.view_assigned', 'document.upload', 'document.download', 'funding.confirm'],
    'legal_officer' => ['case.view_assigned', 'document.upload', 'document.download', 'legal.confirm'],
    'ps_office' => ['case.view_all', 'document.upload', 'document.download', 'nonobjection.grant', 'case.history.view'],
    'system_admin' => [
        'audit.view', 'user.create', 'vendor_user.invite', 'user.reset_credentials',
        'config.manage', 'assignment.reassign', 'delegation.manage',
    ],
    'auditor' => ['audit.view'],
];

function rolePermissionNames(string $role): array
{
    return Role::findByName($role, 'web')->permissions->pluck('name')->sort()->values()->all();
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates every role in the enum, and only those', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleName::cases())->map->value->sort()->values()->all());
});

it('creates every permission in the enum, and only those', function () {
    expect(Permission::pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionName::values())->sort()->values()->all());
});

it('gives each role exactly its listed permissions', function (string $role, array $expected) {
    expect(rolePermissionNames($role))->toBe(collect($expected)->sort()->values()->all());
})->with(fn () => collect(EXPECTED_ROLE_PERMISSIONS)->map(fn ($perms, $role) => [$role, $perms])->all());

it('grants case.view_all to cmo and ps_office only', function () {
    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'case.view_all'))
        ->pluck('name')->sort()->values()->all();

    expect($holders)->toBe(['cmo', 'ps_office']);
});

it('gives system_admin and auditor no case-content permissions', function (string $role) {
    $forbidden = collect(rolePermissionNames($role))
        ->filter(fn (string $p) => preg_match('/^(case|document|query|nonobjection)\./', $p))
        ->values()
        ->all();

    expect($forbidden)->toBe([]);
})->with(['system_admin', 'auditor']);

it('grants assignment.reassign to cmo and system_admin only', function () {
    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'assignment.reassign'))
        ->pluck('name')->sort()->values()->all();

    expect($holders)->toBe(['cmo', 'system_admin']);
});

it('splits intake (cmo_registry, cmo) from routing to review (cmo only)', function () {
    $holders = fn (string $perm) => Role::whereHas('permissions', fn ($q) => $q->where('name', $perm))
        ->pluck('name')->sort()->values()->all();

    expect($holders('case.intake'))->toBe(['cmo', 'cmo_registry'])
        ->and($holders('case.route_to_review'))->toBe(['cmo']);
});

it('lets cmo_registry log intake but not route to review', function () {
    $registry = Role::findByName('cmo_registry', 'web');

    expect($registry->hasPermissionTo('case.intake'))->toBeTrue()
        ->and($registry->hasPermissionTo('case.route_to_review'))->toBeFalse();
});

it('no longer defines the retired permission names', function () {
    expect(Permission::whereIn('name', ['case.reassign', 'case.intake_route'])->count())->toBe(0);
});

it('is idempotent when the seeders run twice', function () {
    $this->seed(OrganisationSeeder::class);

    $counts = fn () => [
        'roles' => Role::count(),
        'permissions' => Permission::count(),
        'role_has_permissions' => DB::table(config('permission.table_names.role_has_permissions'))->count(),
        'organisations' => Organisation::count(),
    ];

    $before = $counts();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(OrganisationSeeder::class);

    expect($counts())->toBe($before)
        ->and($before['roles'])->toBe(count(RoleName::cases()))
        ->and($before['permissions'])->toBe(count(PermissionName::cases()));
});

// Tests run against the shared dev database (in a rolled-back transaction), which
// may already hold other organisations, so assert what the seeder itself does.
it('seeds exactly one Ministry of Health and no vendor', function () {
    $vendorsBefore = Organisation::where('type', OrganisationType::Vendor)->count();

    $this->seed(OrganisationSeeder::class);

    $moh = Organisation::where('name', 'Ministry of Health')->get();

    expect($moh)->toHaveCount(1)
        ->and($moh->first()->type)->toBe(OrganisationType::Moh)
        ->and($moh->first()->active)->toBeTrue()
        ->and(Organisation::where('type', OrganisationType::Vendor)->count())->toBe($vendorsBefore);
});
