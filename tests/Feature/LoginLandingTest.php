<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

// Login landing (UI-1 section E): decided by the shared can flags (permissions
// and policy), never by role names. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const LANDING_PASSWORD = 'Landing-Horse-Battery-12';

function landingUser(?string $role): User
{
    // MFA-1: this test exercises the password step; MfaTest covers the two-factor challenge.
    if ($role === 'vendor') {
        return withoutMfa(vendorUser(vendorOrg(), ['password' => LANDING_PASSWORD]));
    }

    return withoutMfa(caseUser($role, ['password' => LANDING_PASSWORD]));
}

it('lands each user on the page their permissions lead to', function (?string $role, string $landing) {
    $user = landingUser($role);

    $this->post('/login', ['email' => $user->email, 'password' => LANDING_PASSWORD])->assertRedirect($landing);

    $this->assertAuthenticatedAs($user);
})->with([
    'vendor applicant' => ['vendor', '/requests'],
    // Every staff role with work-queue steps starts on the dashboard (BUNDLE-1 Part 4).
    'cmo' => [Role::Cmo->value, '/dashboard'],
    'cmo registry' => [Role::CmoRegistry->value, '/dashboard'],
    'technocrat' => [Role::Technocrat->value, '/dashboard'],
    'cab officer' => [Role::CabOfficer->value, '/dashboard'],
    'legal officer' => [Role::LegalOfficer->value, '/dashboard'],
    'ps office' => [Role::PsOffice->value, '/dashboard'],
    'system admin (neither)' => [Role::SystemAdmin->value, '/dashboard'],
    'auditor (neither)' => [Role::Auditor->value, '/dashboard'],
    'no role (neither)' => [null, '/dashboard'],
]);

it('still sends the user to the intended URL first', function (?string $role, string $intended) {
    $user = landingUser($role);

    $this->get($intended)->assertRedirect('/login');
    $this->post('/login', ['email' => $user->email, 'password' => LANDING_PASSWORD])->assertRedirect($intended);
})->with([
    'vendor to their profile' => ['vendor', '/settings/profile'],
    'cmo to the dashboard' => [Role::Cmo->value, '/dashboard'],
    'admin to their profile' => [Role::SystemAdmin->value, '/settings/profile'],
]);

it('follows the permission, not the role name', function () {
    // A user with no role but the case.submit permission lands like a vendor would.
    $user = withoutMfa(caseUser(null, ['password' => LANDING_PASSWORD]));
    $user->givePermissionTo('case.submit');

    $this->post('/login', ['email' => $user->email, 'password' => LANDING_PASSWORD])->assertRedirect('/requests');
});

it('keeps the dashboard free of permission names', function () {
    $source = file_get_contents(resource_path('js/pages/dashboard.tsx'));

    expect($source)->not->toContain('auth.can')
        ->and($source)->not->toContain('permission}');
});
