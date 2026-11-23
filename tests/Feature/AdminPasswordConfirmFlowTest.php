<?php

use App\Enums\Role;
use App\Http\Middleware\ConfirmRecentPassword;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// The whole confirm-password round trip for an admin action, following every
// redirect: the page behind route('password.confirm') must render (it is
// Fortify's /user/confirm-password, which needs Fortify::confirmPasswordView),
// the form's POST must confirm, and the admin then lands back where they were.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('renders the confirm page that route(password.confirm) points to', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('password.confirm'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/confirm-password'));
    $this->get('/confirm-password')->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/confirm-password'));
});

it('confirms the password, then the admin can create a user', function () {
    $admin = caseUser(Role::SystemAdmin);
    $email = 'flow-'.Str::lower(Str::ulid()).'@example.test';

    // Not confirmed yet: the create form sends the admin to confirm, and that page renders.
    $this->actingAs($admin)->get(route('admin.users.create'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk();

    // The confirm form posts to route('password.confirm') (the page's own code).
    $this->post(route('password.confirm'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post(route('password.confirm'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.create'));
    expect(ConfirmRecentPassword::isRecent(request()))->toBeTrue();

    $this->get(route('admin.users.create'))->assertOk();
    $this->post(route('admin.users.store'), ['name' => 'Flow User', 'email' => $email, 'role' => 'technocrat'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(User::where('email', $email)->value('status'))->toBe(User::STATUS_INVITED);
});

it('renders the confirm page for recovery-code regeneration too', function () {
    $this->actingAs(caseUser(Role::Cmo))->post(route('security.mfa.regenerate'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk();
});
