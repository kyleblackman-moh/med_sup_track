<?php

use App\Enums\Role;
use App\Support\LandingPage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

// "/" has no page of its own: guests go to sign in, signed-in users to the
// same landing page they get after login (LandingPage).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('sends a guest from / to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends a signed-in user from / to their landing page', function (string $who) {
    $user = $who === 'vendor' ? vendorUser(vendorOrg()) : caseUser($who);

    $this->actingAs($user)->get('/')->assertRedirect(LandingPage::for($user));
})->with(['vendor', Role::Cmo->value, Role::Technocrat->value, Role::Auditor->value]);
