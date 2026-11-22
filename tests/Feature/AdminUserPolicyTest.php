<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 6, IT-ADMIN: every role x every Admin > Users action. Only
// system_admin (admin.access and the users.* permissions) is allowed. Without
// admin.access the console does not exist: 404. The create form and store sit
// outside the console for the CMO (users.invite_vendor), who may open the form
// but not create a staff account (the payload here asks for one). A guest is
// sent to sign in.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    confirmAdminPassword();
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
        ]), 'createStaff', false],
        'edit role' => [fn (User $t) => test()->put(route('admin.users.role', $t), ['role' => 'cab_officer', 'reason' => 'Policy.']), 'changeRole', true],
        'disable' => [fn (User $t) => test()->post(route('admin.users.disable', $t), ['reason' => 'Policy.']), 'disable', true],
        'enable' => [fn (User $t) => test()->post(route('admin.users.enable', tap($t, fn ($u) => $u->forceFill(['status' => User::STATUS_DISABLED])->save())), ['reason' => 'Policy.']), 'enable', true],
        'resend link' => [fn (User $t) => test()->post(route('admin.users.activation', tap($t, fn ($u) => $u->forceFill(['status' => User::STATUS_INVITED])->save()))), 'resendActivation', true],
    ];
}

/** allowed | refused (validation, the CMO asking for a staff role) | 404. */
function expectedOutcome(Role $role, string $action): string
{
    if ($role === Role::SystemAdmin) {
        return 'allowed';
    }

    if ($role === Role::Cmo && $action === 'create form') {
        return 'allowed';
    }

    if ($role === Role::Cmo && $action === 'create') {
        return 'refused';
    }

    return '404';
}

/** Every role crossed with every action. */
function roleActionMatrix(): array
{
    $rows = [];
    foreach (Role::cases() as $role) {
        foreach (array_keys(adminUserActions()) as $action) {
            $rows["{$role->value} / {$action}"] = [$role, $action, expectedOutcome($role, $action)];
        }
    }

    return $rows;
}

it('allows only system_admin over HTTP; the console is 404 for everyone else', function (Role $role, string $action, string $outcome) {
    [$call] = adminUserActions()[$action];
    $target = caseUser(Role::Technocrat);
    test()->actingAs(policyActor($role));

    /** @var TestResponse $response */
    $response = $call($target);

    match ($outcome) {
        'allowed' => (function () use ($response) {
            expect($response->status())->toBeIn([200, 302])
                ->and($response->status() === 302 ? $response->headers->get('Location') : '')->not->toContain('/login');
            $response->assertSessionHasNoErrors();
        })(),
        'refused' => $response->assertSessionHasErrors('role'),
        '404' => $response->assertNotFound(),
    };
})->with(roleActionMatrix());

it('gives the same answer from the policy itself', function (Role $role, string $action, string $outcome) {
    [, $ability, $takesTarget] = adminUserActions()[$action];
    $actor = policyActor($role);
    $target = caseUser(Role::Technocrat, ['status' => $action === 'resend link' ? User::STATUS_INVITED : User::STATUS_ACTIVE]);

    expect(Gate::forUser($actor)->allows($ability, $takesTarget ? $target : User::class))->toBe($outcome === 'allowed');
})->with(roleActionMatrix());

it('changes nothing when a denied role tries', function (Role $role) {
    $target = caseUser(Role::Technocrat);
    test()->actingAs(policyActor($role));

    $this->put(route('admin.users.role', $target), ['role' => 'cab_officer', 'reason' => 'x'])->assertNotFound();
    $this->post(route('admin.users.disable', $target), ['reason' => 'x'])->assertNotFound();

    $target->refresh();
    expect($target->getRoleNames()->all())->toBe(['technocrat'])
        ->and($target->status)->toBe(User::STATUS_ACTIVE);
})->with(array_values(array_filter(Role::cases(), fn (Role $r) => $r !== Role::SystemAdmin)));

it('sends a guest to sign in', function (string $action) {
    [$call] = adminUserActions()[$action];

    $call(caseUser(Role::Technocrat))->assertRedirect(route('login'));
})->with(array_keys(adminUserActions()));
