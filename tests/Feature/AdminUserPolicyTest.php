<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 6: every role x every Admin > Users action. Only system_admin
// (the one role with users.manage) is allowed; every other role gets 403 (a
// missing permission, the existing convention), and a guest is sent to sign in.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A signed-in user holding $role (a vendor role gets a vendor organisation). */
function policyActor(Role $role): User
{
    return $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);
}

/** Each action: [HTTP call against a fresh target, policy ability, takes the target]. */
function adminUserActions(): array
{
    return [
        'list' => [fn (User $t) => test()->get(route('admin.users.index')), 'viewAny', false],
        'view' => [fn (User $t) => test()->get(route('admin.users.show', $t)), 'view', true],
        'create form' => [fn (User $t) => test()->get(route('admin.users.create')), 'create', false],
        'create' => [fn (User $t) => test()->post(route('admin.users.store'), [
            'name' => 'Policy Created', 'email' => 'policy-'.Str::lower(Str::ulid()).'@example.test', 'role' => 'cmo',
        ]), 'create', false],
        'edit role' => [fn (User $t) => test()->put(route('admin.users.role', $t), ['role' => 'cab_officer']), 'changeRole', true],
        'disable' => [fn (User $t) => test()->post(route('admin.users.disable', $t)), 'disable', true],
        'enable' => [fn (User $t) => test()->post(route('admin.users.enable', tap($t, fn ($u) => $u->forceFill(['status' => User::STATUS_DISABLED])->save()))), 'enable', true],
        'resend link' => [fn (User $t) => test()->post(route('admin.users.activation', $t)), 'resendActivation', true],
    ];
}

/** Every role crossed with every action. */
function roleActionMatrix(): array
{
    $rows = [];
    foreach (Role::cases() as $role) {
        foreach (array_keys(adminUserActions()) as $action) {
            $rows["{$role->value} / {$action}"] = [$role, $action, $role === Role::SystemAdmin];
        }
    }

    return $rows;
}

it('allows only system_admin over HTTP', function (Role $role, string $action, bool $allowed) {
    [$call] = adminUserActions()[$action];
    $target = caseUser(Role::Technocrat);
    test()->actingAs(policyActor($role));

    /** @var TestResponse $response */
    $response = $call($target);

    if ($allowed) {
        expect($response->status())->toBeIn([200, 302])
            ->and($response->status() === 302 ? $response->headers->get('Location') : '')->not->toContain('/login');
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertForbidden();
    }
})->with(roleActionMatrix());

it('gives the same answer from the policy itself', function (Role $role, string $action, bool $allowed) {
    [, $ability, $takesTarget] = adminUserActions()[$action];
    $actor = policyActor($role);

    expect(Gate::forUser($actor)->allows($ability, $takesTarget ? caseUser(Role::Technocrat) : User::class))->toBe($allowed);
})->with(roleActionMatrix());

it('changes nothing when a denied role tries', function (Role $role) {
    $target = caseUser(Role::Technocrat);
    test()->actingAs(policyActor($role));

    $this->put(route('admin.users.role', $target), ['role' => 'cab_officer'])->assertForbidden();
    $this->post(route('admin.users.disable', $target))->assertForbidden();

    $target->refresh();
    expect($target->getRoleNames()->all())->toBe(['technocrat'])
        ->and($target->status)->toBe(User::STATUS_ACTIVE);
})->with(array_values(array_filter(Role::cases(), fn (Role $r) => $r !== Role::SystemAdmin)));

it('sends a guest to sign in', function (string $action) {
    [$call] = adminUserActions()[$action];

    $call(caseUser(Role::Technocrat))->assertRedirect(route('login'));
})->with(array_keys(adminUserActions()));
