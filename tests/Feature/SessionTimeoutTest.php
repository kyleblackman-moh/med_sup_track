<?php

use App\Enums\Role;
use App\Http\Middleware\SessionTimeout;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

// SEC-1 (B2, B6): idle timeouts (staff 30, vendors 20 minutes), the absolute
// 12 hours, the message on the sign-in page, and a disabled user rejected.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function expectSignedOut($response, string $message = SessionTimeout::IDLE_MESSAGE): void
{
    $response->assertRedirect(route('login'))->assertSessionHas('status', $message);
    test()->assertGuest();
}

it('keeps an active staff session and signs staff out after 30 idle minutes', function () {
    $this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk();

    $this->travel(29)->minutes();
    $this->get(route('dashboard'))->assertOk();

    $this->travel(31)->minutes();
    expectSignedOut($this->get(route('dashboard')));
});

it('signs a vendor out after 20 idle minutes', function () {
    $vendor = vendorUser(vendorOrg());
    $this->actingAs($vendor)->get(route('requests.index'))->assertOk();

    $this->travel(19)->minutes();
    $this->get(route('requests.index'))->assertOk();

    $this->travel(21)->minutes();
    expectSignedOut($this->get(route('requests.index')));
});

it('signs anyone out 12 hours after signing in, however active', function (Closure $user) {
    $this->actingAs($user())
        ->withSession([SessionTimeout::STARTED => time() - 12 * 3600 - 60, SessionTimeout::LAST_ACTIVITY => time()])
        ->get(route('dashboard'));

    $this->assertGuest();
})->with(['staff' => fn () => caseUser(Role::Cmo), 'vendor' => fn () => vendorUser(vendorOrg())]);

it('tells the user why on the sign-in page', function () {
    $this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'));
    $this->travel(31)->minutes();
    $this->get(route('dashboard'));

    expect(pageProps($this->get(route('login'))->assertOk())['status'])->toBe('You were signed out because you were inactive.');
});

it('reads the limits from config', function () {
    config(['security.session.idle_minutes.staff' => 5]);
    $this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk();

    $this->travel(6)->minutes();
    expectSignedOut($this->get(route('dashboard')));
});

it('rejects the session of a user who has been disabled', function () {
    $user = caseUser(Role::Cmo);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['status' => User::STATUS_DISABLED])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});
