<?php

use App\Accounts\AccountActivations;
use App\Accounts\UserAdministration;
use App\Enums\Role;
use App\Http\Middleware\ConfirmRecentPassword;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\AccountActivation;
use App\Notifications\AccountSecurityNotice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 3, IT-ADMIN: change role, disable and enable (each with a
// reason), resend the activation link of a pending account.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    confirmAdminPassword();
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

/** A pending account created through UserAdministration (it has an open activation link). */
function pendingUser(Role $role = Role::Cmo): User
{
    $user = caseUser($role, ['status' => User::STATUS_INVITED]);
    app(AccountActivations::class)->issue($user, null);

    return $user;
}

it('changes a staff user to another staff role, with a reason', function () {
    $user = caseUser(Role::Technocrat);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => 'cab_officer', 'reason' => 'Moved to Cost and Budgeting (ticket 42).'])
        ->assertRedirect(route('admin.users.show', $user))
        ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Role saved.'));

    expect($user->fresh()->getRoleNames()->all())->toBe(['cab_officer']);
});

it('requires a reason to change a role, disable or enable', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat);
    $disabled = caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'cab_officer'])->assertSessionHasErrors(['reason' => 'Enter the reason.']);
    $this->put(route('admin.users.role', $user), ['role' => 'cab_officer', 'reason' => '   '])->assertSessionHasErrors('reason');
    $this->post(route('admin.users.disable', $user))->assertSessionHasErrors('reason');
    $this->post(route('admin.users.enable', $disabled))->assertSessionHasErrors('reason');
    $this->post(route('admin.users.disable', $user), ['reason' => str_repeat('x', 1001)])->assertSessionHasErrors('reason');

    expect($user->fresh()->getRoleNames()->all())->toBe(['technocrat'])
        ->and($user->fresh()->status)->toBe(User::STATUS_ACTIVE)
        ->and($disabled->fresh()->status)->toBe(User::STATUS_DISABLED);
});

it('ends the user\'s sessions and emails them when their role changes', function () {
    $user = caseUser(Role::Technocrat);
    $oldToken = $user->remember_token;
    $session = sessionRowFor($user);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => 'cab_officer', 'reason' => 'Reassigned.']);

    expect(DB::table('sessions')->where('id', $session)->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe($oldToken);
    Notification::assertSentTo($user, AccountSecurityNotice::class, fn (AccountSecurityNotice $n) => $n->kind === AccountSecurityNotice::ROLE_CHANGED
        && $n->role === 'cab_officer'
        && ! str_contains(implode(' ', $n->toMail($user)->introLines), 'Reassigned'));
});

it('takes the old permissions away at once', function () {
    $user = caseUser(Role::Cmo);
    expect($user->can('case.view_all'))->toBeTrue();

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => 'cab_officer', 'reason' => 'No longer in the CMO\'s office.']);

    // The next request loads the user afresh, as a real one does.
    Auth::forgetGuards();
    $this->actingAs($user->fresh())->get(route('analytics'))->assertForbidden();
    expect($user->fresh()->can('case.view_all'))->toBeFalse();
});

it('never gives the System Admin role from the UI', function () {
    $user = caseUser(Role::Technocrat);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => 'system_admin', 'reason' => 'Please.'])
        ->assertSessionHasErrors('role');

    expect($user->fresh()->getRoleNames()->all())->toBe(['technocrat']);
});

it('keeps the role and organisation pairing when changing a role', function (Closure $makeUser, string $role) {
    $user = $makeUser();
    $before = $user->getRoleNames()->all();

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->put(route('admin.users.role', $user), ['role' => $role, 'reason' => 'Pairing.'])
        ->assertSessionHasErrors('role');

    expect($user->fresh()->getRoleNames()->all())->toBe($before);
})->with([
    'vendor account to a staff role' => [fn () => vendorUser(vendorOrg()), 'cmo'],
    'staff account to the vendor role' => [fn () => caseUser(Role::Cmo), 'vendor_applicant'],
    'unknown role' => [fn () => caseUser(Role::Cmo), 'superuser'],
]);

it('offers only roles of the same kind on the detail page, never system_admin', function () {
    $admin = caseUser(Role::SystemAdmin);

    $staff = pageProps($this->actingAs($admin)->get(route('admin.users.show', caseUser(Role::Cmo)))->assertOk());
    $vendor = pageProps($this->actingAs($admin)->get(route('admin.users.show', vendorUser(vendorOrg())))->assertOk());

    expect(collect($staff['role_options'])->pluck('value'))->not->toContain('vendor_applicant')
        ->and(collect($staff['role_options'])->pluck('value'))->toContain('cmo')
        ->and(collect($staff['role_options'])->pluck('value'))->not->toContain('system_admin')
        ->and(collect($vendor['role_options'])->pluck('value')->all())->toBe(['vendor_applicant']);
});

it('disables a user: status, who and when, and a new remember token', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);
    $oldToken = $user->remember_token;

    $this->actingAs($admin)->post(route('admin.users.disable', $user), ['reason' => 'Left the Ministry.'])
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

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.disable', $user), ['reason' => 'Left.']);

    expect(DB::table('sessions')->whereIn('id', [$first, $second])->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $kept)->exists())->toBeTrue();
});

it('blocks sign-in for a disabled user', function () {
    $user = caseUser(Role::Cmo);
    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.disable', $user), ['reason' => 'Left.']);
    auth()->logout();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

it('signs a disabled user out in the middle of their session', function () {
    $user = caseUser(Role::Cmo);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    // An admin disables them while they are signed in.
    app(UserAdministration::class)->disable(caseUser(Role::SystemAdmin), $user->fresh(), 'Compromised account.');
    Auth::forgetGuards();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('disables a pending account and revokes its activation link', function () {
    $user = pendingUser();

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.disable', $user), ['reason' => 'Created by mistake.']);

    expect($user->fresh()->status)->toBe(User::STATUS_DISABLED)
        ->and(UserInvitation::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('enables a disabled user, who can sign in again', function () {
    // MFA-1: this test exercises the password step; MfaTest covers the two-factor challenge.
    $user = withoutMfa(caseUser(Role::Cmo));
    $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now(), 'disabled_by' => caseUser(Role::SystemAdmin)->id])->save();

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.enable', $user), ['reason' => 'Returned from leave.'])
        ->assertSessionHas('success');

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->disabled_at)->toBeNull()
        ->and($user->disabled_by)->toBeNull();

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('puts a re-enabled account that never activated back to pending activation', function () {
    $user = pendingUser();
    $admin = caseUser(Role::SystemAdmin);
    $this->actingAs($admin)->post(route('admin.users.disable', $user), ['reason' => 'Hold.']);
    $this->post(route('admin.users.enable', $user), ['reason' => 'Go ahead.']);

    expect($user->fresh()->status)->toBe(User::STATUS_INVITED);
});

it('resends the activation link of a pending account, and the old link stops working', function () {
    $user = pendingUser();
    $first = UserInvitation::where('user_id', $user->id)->sole();

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.activation', $user))
        ->assertRedirect(route('admin.users.show', $user));

    Notification::assertSentTo($user, AccountActivation::class);
    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and(UserInvitation::where('user_id', $user->id)->get()->filter->isOpen()->count())->toBe(1);
});

it('does not send an activation link to an active or disabled account', function (string $status) {
    $user = caseUser(Role::Cmo, ['status' => $status]);

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.activation', $user))
        ->assertSessionHasErrors('account');

    Notification::assertNothingSentTo($user);
})->with([User::STATUS_ACTIVE, User::STATUS_DISABLED]);

it('sends the detail page the actions the admin may take', function () {
    $admin = caseUser(Role::SystemAdmin);

    $active = pageProps($this->actingAs($admin)->get(route('admin.users.show', caseUser(Role::Cmo)))->assertOk())['abilities'];
    $disabled = pageProps($this->actingAs($admin)->get(route('admin.users.show', caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]))))['abilities'];
    $pending = pageProps($this->actingAs($admin)->get(route('admin.users.show', pendingUser())))['abilities'];

    expect($active)->toBe(['change_role' => true, 'disable' => true, 'enable' => false, 'resend_activation' => false, 'mfa_reset' => true])
        ->and($disabled)->toBe(['change_role' => true, 'disable' => false, 'enable' => true, 'resend_activation' => false, 'mfa_reset' => true])
        ->and($pending)->toBe(['change_role' => true, 'disable' => true, 'enable' => false, 'resend_activation' => true, 'mfa_reset' => true]);
});

it('answers 404 for an action on a user that does not exist', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))->post('/admin/users/999999999/disable', ['reason' => 'x'])->assertNotFound();
});

it('asks for the password again before any admin action, and changes nothing', function (Closure $call) {
    $target = caseUser(Role::Technocrat, ['status' => User::STATUS_ACTIVE]);
    $this->withSession([ConfirmRecentPassword::SESSION_KEY => now()->subMinutes(16)->getTimestamp()]);
    $this->actingAs(caseUser(Role::SystemAdmin));

    $call($target)->assertRedirect(route('password.confirm'));

    $target->refresh();
    expect($target->status)->toBe(User::STATUS_ACTIVE)
        ->and($target->getRoleNames()->all())->toBe(['technocrat'])
        ->and($target->hasMfa())->toBeTrue();
})->with([
    'detail page' => [fn (User $t) => test()->get(route('admin.users.show', $t))],
    'change role' => [fn (User $t) => test()->put(route('admin.users.role', $t), ['role' => 'cab_officer', 'reason' => 'x'])],
    'disable' => [fn (User $t) => test()->post(route('admin.users.disable', $t), ['reason' => 'x'])],
    'enable' => [fn (User $t) => test()->post(route('admin.users.enable', $t), ['reason' => 'x'])],
    'resend activation' => [fn (User $t) => test()->post(route('admin.users.activation', $t))],
    'MFA reset' => [fn (User $t) => test()->post(route('admin.users.mfa-reset', $t))],
]);

it('returns to the page the admin acted from after confirming a POST', function () {
    $target = caseUser(Role::Technocrat);
    $this->withSession([ConfirmRecentPassword::SESSION_KEY => 0]);
    $show = route('admin.users.show', $target);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->from($show)
        ->post(route('admin.users.disable', $target), ['reason' => 'x'])
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe($show);
});
