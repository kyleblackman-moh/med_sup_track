<?php

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: every sidebar page has a permission the UI can check
// (nav flags) and the page checks it. The new ones are held by exactly the
// roles that could open the page before. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const NAV_STAFF_CASE_ROLES = ['cmo_registry', 'cmo', 'technocrat', 'cab_officer', 'legal_officer', 'ps_office'];

it('grants the sidebar permissions to the roles that could open each page', function (Permission $permission, array $roles) {
    $holders = collect(Role::cases())->filter(fn (Role $r) => in_array($permission, $r->permissions(), true))->map->value->values()->all();

    expect($holders)->toEqualCanonicalizing($roles);
})->with([
    'cases.list' => [Permission::CasesList, NAV_STAFF_CASE_ROLES],
    'tracking.view' => [Permission::TrackingView, NAV_STAFF_CASE_ROLES],
    'analytics.view' => [Permission::AnalyticsView, ['cmo', 'ps_office']],
    'audit.view' => [Permission::AuditView, ['cmo', 'legal_officer', 'system_admin']],
    'users.view' => [Permission::UsersView, ['system_admin']],
    'system.health.view' => [Permission::SystemHealthView, ['system_admin']],
]);

it('shares a nav flag per sidebar item, matching the routes', function (string $role, array $flags) {
    $user = $role === 'vendor_applicant' ? vendorUser(vendorOrg()) : caseUser(Role::from($role));
    $nav = pageProps($this->actingAs($user)->get(homeUrl($user))->assertOk())['nav'];

    expect(collect($nav)->only(['cases', 'tracking', 'queries', 'analytics', 'audit', 'users', 'it_health'])->all())->toEqual($flags);

    $this->actingAs($user)->get(route('cases.index'))->assertStatus($flags['cases'] ? 200 : 403);
    $this->actingAs($user)->getJson(route('tracking.index'))->assertStatus($flags['tracking'] ? 200 : 403);
    $this->actingAs($user)->get(route('analytics'))->assertStatus($flags['analytics'] ? 200 : 403);
})->with([
    'cmo' => ['cmo', ['cases' => true, 'tracking' => true, 'queries' => true, 'analytics' => true, 'audit' => true, 'users' => false, 'it_health' => false]],
    'technocrat' => ['technocrat', ['cases' => true, 'tracking' => true, 'queries' => true, 'analytics' => false, 'audit' => false, 'users' => false, 'it_health' => false]],
    'ps_office' => ['ps_office', ['cases' => true, 'tracking' => true, 'queries' => true, 'analytics' => true, 'audit' => false, 'users' => false, 'it_health' => false]],
    'vendor' => ['vendor_applicant', ['cases' => false, 'tracking' => false, 'queries' => true, 'analytics' => false, 'audit' => false, 'users' => false, 'it_health' => false]],
]);
