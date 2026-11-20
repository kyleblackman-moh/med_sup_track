<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountActivation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 3: change role, disable and enable, resend the sign-in link.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A row in the database sessions table for $user (tests use the array driver, so rows are inserted by hand). */
function sessionRowFor(User $user): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp(),
    ]);

    return $id;
}

it('changes a staff user to another staff role', function () {
    $user = caseUser(Role::Technocrat);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => 'cab_officer'])
        ->assertRedirect(route('admin.users.show', $user))
        ->assertSessionHas('success', 'Role saved.');

    expect($user->fresh()->getRoleNames()->all())->toBe(['cab_officer']);
});

it('keeps the role and organisation pairing when changing a role', function (Closure $makeUser, string $role) {
    $user = $makeUser();
    $before = $user->getRoleNames()->all();

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => $role])
        ->assertSessionHasErrors('role');

    expect($user->fresh()->getRoleNames()->all())->toBe($before);
})->with([
    'vendor account to a staff role' => [fn () => vendorUser(vendorOrg()), 'cmo'],
    'staff account to the vendor role' => [fn () => caseUser(Role::Cmo), 'vendor_applicant'],
    'unknown role' => [fn () => caseUser(Role::Cmo), 'superuser'],
]);

it('offers only roles of the same kind on the detail page', function () {
    $admin = caseUser(Role::SystemAdmin);

    $staff = pageProps($this->actingAs($admin)->get(route('admin.users.show', caseUser(Role::Cmo)))->assertOk());
    $vendor = pageProps($this->actingAs($admin)->get(route('admin.users.show', vendorUser(vendorOrg())))->assertOk());

    expect(collect($staff['role_options'])->pluck('value'))->not->toContain('vendor_applicant')
        ->and(collect($staff['role_options'])->pluck('value'))->toContain('cmo', 'system_admin')
        ->and(collect($vendor['role_options'])->pluck('value')->all())->toBe(['vendor_applicant']);
});

it('disables a user: status, who and when, and a new remember token', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);
    $oldToken = $user->remember_token;

    $this->actingAs($admin)->post(route('admin.users.disable', $user))
        ->assertRedirect(route('admin.users.show', $user))
        ->assertSessionHas('success');

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_DISABLED)
        ->and($user->disabled_at)->not->toBeNull()
        ->and((int) $user->disabled_by)->toBe($admin->id)
        ->and($user->remember_token)->not->toBe($oldToken);
});

it('deletes only that user\'s rows from the sessions table when disabling', function () {
    $user = caseUser(Role::Cmo);
    $other = caseUser(Role::Cmo);
    $first = sessionRowFor($user);
    $second = sessionRowFor($user);
    $kept = sessionRowFor($other);

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.disable', $user));

    expect(DB::table('sessions')->whereIn('id', [$first, $second])->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $kept)->exists())->toBeTrue();
});

it('blocks sign-in for a disabled user', function () {
    $user = caseUser(Role::Cmo);
    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.disable', $user));
    auth()->logout();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

it('signs a disabled user out on their next request', function () {
    $user = caseUser(Role::Cmo);
    $user->forceFill(['status' => User::STATUS_DISABLED])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('enables a disabled user, who can sign in again', function () {
    $user = caseUser(Role::Cmo);
    $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now(), 'disabled_by' => caseUser(Role::SystemAdmin)->id])->save();

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.enable', $user))
        ->assertSessionHas('success');

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->disabled_at)->toBeNull()
        ->and($user->disabled_by)->toBeNull();

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('resends the sign-in link', function () {
    $user = caseUser(Role::Cmo);

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.activation', $user))
        ->assertRedirect(route('admin.users.show', $user));

    Notification::assertSentTo($user, AccountActivation::class);
});

it('does not send a sign-in link to a disabled user', function () {
    $user = caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.activation', $user))
        ->assertSessionHasErrors('account');

    Notification::assertNothingSentTo($user);
});

it('sends the detail page the actions the admin may take', function () {
    $admin = caseUser(Role::SystemAdmin);

    $active = pageProps($this->actingAs($admin)->get(route('admin.users.show', caseUser(Role::Cmo)))->assertOk())['abilities'];
    $disabled = pageProps($this->actingAs($admin)->get(route('admin.users.show', caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]))))['abilities'];

    expect($active)->toBe(['change_role' => true, 'disable' => true, 'enable' => false, 'resend_activation' => true])
        ->and($disabled)->toBe(['change_role' => true, 'disable' => false, 'enable' => true, 'resend_activation' => false]);
});

it('answers 404 for an action on a user that does not exist', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))->post('/admin/users/999999999/disable')->assertNotFound();
});
