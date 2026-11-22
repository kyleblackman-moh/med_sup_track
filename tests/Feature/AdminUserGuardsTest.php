<?php

use App\Accounts\UserAdministration;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 4, IT-ADMIN: self-edit guards, the last admin.access holder
// guard, and the admin and auditor roles kept out of case content.
//
// Over HTTP the acting admin is always an active holder of admin.access and
// cannot act on themselves, so the target is never the last holder there. The
// last-holder guard is the service's own rule (for any other caller), and is
// tested on UserAdministration directly.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    confirmAdminPassword();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Leave $keep as the only active holders of admin.access (other holders in the dev database are disabled inside the test transaction). */
function onlyAdmins(User ...$keep): void
{
    User::permission(Permission::AdminAccess->value)
        ->whereNotIn('users.id', array_map(fn (User $u) => $u->id, $keep))
        ->update(['status' => User::STATUS_DISABLED]);
}

it('does not let an admin change their own role (403)', function () {
    $admin = caseUser(Role::SystemAdmin);

    $this->actingAs($admin)->put(route('admin.users.role', $admin), ['role' => 'cmo', 'reason' => 'Me.'])->assertForbidden();

    expect($admin->fresh()->getRoleNames()->all())->toBe(['system_admin']);
});

it('does not let an admin disable themselves (403)', function () {
    $admin = caseUser(Role::SystemAdmin);

    $this->actingAs($admin)->post(route('admin.users.disable', $admin), ['reason' => 'Me.'])->assertForbidden();

    expect($admin->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

it('does not let an admin reset their own MFA (403)', function () {
    $admin = caseUser(Role::SystemAdmin);

    $this->actingAs($admin)->post(route('admin.users.mfa-reset', $admin))->assertForbidden();

    expect($admin->fresh()->hasMfa())->toBeTrue();
});

it('hides the role, disable and MFA reset actions on the admin\'s own page', function () {
    $admin = caseUser(Role::SystemAdmin);

    $abilities = pageProps($this->actingAs($admin)->get(route('admin.users.show', $admin))->assertOk())['abilities'];

    expect($abilities['change_role'])->toBeFalse()
        ->and($abilities['disable'])->toBeFalse()
        ->and($abilities['mfa_reset'])->toBeFalse();
});

it('refuses to disable the last active System Admin', function () {
    $target = caseUser(Role::SystemAdmin);
    onlyAdmins($target);

    expect(fn () => app(UserAdministration::class)->disable(caseUser(Role::Cmo), $target, 'Test.'))
        ->toThrow(ValidationException::class, 'last active account');

    expect($target->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

it('refuses to demote the last active System Admin', function () {
    $target = caseUser(Role::SystemAdmin);
    onlyAdmins($target);

    expect(fn () => app(UserAdministration::class)->changeRole(caseUser(Role::Cmo), $target, Role::Cmo, 'Test.'))
        ->toThrow(ValidationException::class, 'last active account');

    expect($target->fresh()->getRoleNames()->all())->toBe(['system_admin']);
});

it('does not count a disabled holder as another admin', function () {
    $target = caseUser(Role::SystemAdmin);
    caseUser(Role::SystemAdmin, ['status' => User::STATUS_DISABLED]);
    onlyAdmins($target);

    expect(fn () => app(UserAdministration::class)->disable(caseUser(Role::Cmo), $target, 'Test.'))
        ->toThrow(ValidationException::class);
});

it('allows disabling or demoting an admin when another active admin remains', function () {
    $actor = caseUser(Role::SystemAdmin);
    $first = caseUser(Role::SystemAdmin);
    $second = caseUser(Role::SystemAdmin);
    onlyAdmins($actor, $first, $second);

    $this->actingAs($actor)->post(route('admin.users.disable', $first), ['reason' => 'Left.'])->assertSessionDoesntHaveErrors();
    $this->actingAs($actor)->put(route('admin.users.role', $second), ['role' => 'cmo', 'reason' => 'Moved.'])->assertSessionDoesntHaveErrors();

    expect($first->fresh()->status)->toBe(User::STATUS_DISABLED)
        ->and($second->fresh()->getRoleNames()->all())->toBe(['cmo']);
});

it('refuses system_admin in UserAdministration itself, whoever calls it', function () {
    $target = caseUser(Role::Technocrat);

    expect(fn () => app(UserAdministration::class)->changeRole(caseUser(Role::SystemAdmin), $target, Role::SystemAdmin, 'Test.'))
        ->toThrow(ValidationException::class, 'command line only')
        ->and(fn () => app(UserAdministration::class)->create(caseUser(Role::SystemAdmin), 'X', 'x-'.Str::lower(Str::ulid()).'@example.test', Role::SystemAdmin, null))
        ->toThrow(ValidationException::class, 'command line only');

    expect($target->fresh()->getRoleNames()->all())->toBe(['technocrat']);
});

it('ignores the guard for a user who does not hold admin.access', function () {
    $target = caseUser(Role::Cmo);
    onlyAdmins();

    app(UserAdministration::class)->disable(caseUser(Role::Cmo), $target, 'Test.');

    expect($target->fresh()->status)->toBe(User::STATUS_DISABLED);
});

it('still keeps system_admin and auditor out of case content (404)', function (Role $role) {
    $case = makeCase([
        'org_id' => vendorOrg()->id,
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now(),
        'reference_no' => 'NR-ADM-'.Str::upper(Str::random(8)),
    ]);
    $user = caseUser($role);

    $this->actingAs($user)->get(route('cases.show', $case))->assertNotFound();
    $this->actingAs($user)->get(route('requests.show', $case))->assertNotFound();
    $this->actingAs($user)->get(route('requests.edit', $case))->assertNotFound();
})->with([Role::SystemAdmin, Role::Auditor]);
