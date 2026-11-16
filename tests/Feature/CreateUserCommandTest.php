<?php

use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// app:user-create: the real way to make accounts. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    mohOrg();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function cuEmail(): string
{
    return 'new-'.Str::lower(Str::ulid()).'@example.test';
}

/** Runs the command; returns [exit code, output, printed password or null]. */
function cuRun(array $args): array
{
    $code = Artisan::call('app:user-create', $args);
    $output = Artisan::output();
    preg_match('/shown once, not stored anywhere else\): (\S+)/', $output, $m);

    return [$code, $output, $m[1] ?? null];
}

function cuAuditRows(?int $userId = null)
{
    return DB::table('audit_logs')->where('action', 'user_created')
        ->when($userId !== null, fn ($q) => $q->where('subject_id', (string) $userId))->get();
}

it('creates a user for each role with a hashed one-time password', function (Role $role) {
    $email = cuEmail();
    $isVendor = $role === Role::VendorApplicant;
    $org = $isVendor ? vendorOrg() : Organisation::where('type', OrganisationType::Moh->value)->sole();

    [$code, $output, $password] = cuRun([
        'name' => 'New Person', 'email' => $email, 'role' => $role->value,
        ...($isVendor ? ['--organisation' => $org->id] : []),
    ]);

    expect($code)->toBe(0, $output)->and($password)->not->toBeNull();

    $user = User::where('email', $email)->sole();
    expect($user->getRoleNames()->all())->toBe([$role->value])
        ->and($user->getDirectPermissions())->toHaveCount(0)
        ->and($user->org_id)->toBe($org->id)
        ->and($user->user_type)->toBe($isVendor ? UserType::Vendor->value : UserType::Staff->value)
        ->and($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->password_change_required)->toBeTrue()
        ->and(strlen($password))->toBeGreaterThanOrEqual(20)
        ->and($user->password)->not->toBe($password)
        ->and(Hash::info($user->password)['algoName'])->toBe('argon2id')
        ->and(Hash::check($password, $user->password))->toBeTrue();
})->with(Role::cases());

it('accepts the vendor organisation by exact name', function () {
    $org = vendorOrg();
    $email = cuEmail();

    [$code] = cuRun(['name' => 'Vendor Person', 'email' => $email, 'role' => 'vendor_applicant', '--organisation' => $org->name]);

    expect($code)->toBe(0)->and(User::where('email', $email)->sole()->org_id)->toBe($org->id);
});

it('refuses and changes nothing', function (Closure $args, string $message) {
    $users = User::count();
    $audits = cuAuditRows()->count();

    [$code, $output, $password] = cuRun($args());

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and($password)->toBeNull()
        ->and(User::count())->toBe($users)
        ->and(cuAuditRows()->count())->toBe($audits);
})->with([
    'unknown role' => [fn () => ['name' => 'A', 'email' => cuEmail(), 'role' => 'superuser'], 'Valid roles: vendor_applicant, cmo_registry, cmo, technocrat'],
    'vendor without organisation' => [fn () => ['name' => 'A', 'email' => cuEmail(), 'role' => 'vendor_applicant'], 'needs --organisation'],
    'vendor with unknown organisation' => [fn () => ['name' => 'A', 'email' => cuEmail(), 'role' => 'vendor_applicant', '--organisation' => 'No Such Vendor Ltd'], 'No vendor organisation'],
    'vendor with the MOH organisation' => [fn () => ['name' => 'A', 'email' => cuEmail(), 'role' => 'vendor_applicant', '--organisation' => mohOrg()->id], 'No vendor organisation'],
    'staff with organisation' => [fn () => ['name' => 'A', 'email' => cuEmail(), 'role' => 'cmo', '--organisation' => vendorOrg()->id], 'do not pass --organisation'],
    'invalid email' => [fn () => ['name' => 'A', 'email' => 'not-an-email', 'role' => 'cmo'], 'email'],
]);

it('refuses a duplicate email without changing the existing user', function () {
    $existing = caseUser(Role::Technocrat);
    $before = $existing->fresh()->getAttributes();
    $audits = cuAuditRows()->count();

    [$code, $output] = cuRun(['name' => 'Someone Else', 'email' => Str::upper($existing->email), 'role' => 'cmo']);

    expect($code)->toBe(1)->and($output)->toContain('already exists')
        ->and($existing->fresh()->getAttributes())->toBe($before)
        ->and($existing->fresh()->getRoleNames()->all())->toBe(['technocrat'])
        ->and(cuAuditRows()->count())->toBe($audits);
});

it('fails clearly unless there is exactly one MOH organisation', function (string $setup) {
    if ($setup === 'none') {
        Organisation::where('type', OrganisationType::Moh->value)->update(['type' => OrganisationType::Vendor->value]);
    } else {
        Organisation::create(['name' => 'Second Ministry '.Str::ulid(), 'type' => OrganisationType::Moh, 'active' => true]);
    }
    $users = User::count();

    [$code, $output] = cuRun(['name' => 'A', 'email' => cuEmail(), 'role' => 'cmo']);

    expect($code)->toBe(1)->and($output)->toContain('Expected exactly one MOH organisation')
        ->and(User::count())->toBe($users);
})->with(['none', 'two']);

it('audits user_created with only id, role, organisation and console', function () {
    $email = cuEmail();

    [, , $password] = cuRun(['name' => 'Distinctive Name', 'email' => $email, 'role' => 'legal_officer']);
    $user = User::where('email', $email)->sole();

    $rows = cuAuditRows($user->id);
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $metadata = json_decode($row->metadata, true);
    $raw = json_encode($row);

    expect($metadata)->toBe([
        'user_id' => $user->id,
        'role' => 'legal_officer',
        'organisation_id' => $user->org_id,
        'created_via' => 'console',
    ])
        ->and($row->actor_type)->toBe('system')
        ->and($raw)->not->toContain('Distinctive Name')
        ->and($raw)->not->toContain($email)
        ->and($raw)->not->toContain($password)
        ->and($raw)->not->toContain($user->password);
});

it('lets the new user sign in with the printed password and forces a change', function () {
    $email = cuEmail();
    [, , $password] = cuRun(['name' => 'First Login', 'email' => $email, 'role' => 'technocrat']);

    $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect();
    $this->assertAuthenticatedAs(User::where('email', $email)->sole());

    $this->get(route('dashboard'))->assertRedirect(route('password.edit'));
    $this->get(route('password.edit'))->assertOk();
});
