<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 1: Admin > Users list and detail. DatabaseTransactions only;
// the dev database holds other users, so list assertions filter by a
// per-test vendor organisation or a unique search token.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    confirmAdminPassword();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Rows of the Users list for the admin, with the given query. */
function adminUserRows(User $admin, array $query = []): array
{
    return pageProps(test()->actingAs($admin)->get(route('admin.users.index', $query))->assertOk())['users']['data'];
}

it('lists users with name, email, organisation, role display name, status and last sign-in', function () {
    $admin = caseUser(Role::SystemAdmin);
    $org = vendorOrg();
    $vendor = vendorUser($org, ['name' => 'Alpha Vendor', 'last_login_at' => '2026-11-18 14:05:00']);

    $rows = adminUserRows($admin, ['org' => $org->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe([
            'id' => $vendor->id,
            'name' => 'Alpha Vendor',
            'email' => $vendor->email,
            'type' => 'Vendor',
            'organisation' => $org->name,
            'role' => 'Vendor',
            'status_label' => 'Active',
            'status_tone' => 'success',
            'mfa_label' => 'Enrolled',
            'mfa_tone' => 'success',
            // Stored UTC, shown in America/Port_of_Spain (UTC-4).
            'last_sign_in' => '18 Nov 2026, 10:05 am',
        ]);
});

it('shows Never for a user who has not signed in', function () {
    $org = vendorOrg();
    vendorUser($org, ['last_login_at' => null]);

    expect(adminUserRows(caseUser(Role::SystemAdmin), ['org' => $org->id])[0]['last_sign_in'])->toBe('Never');
});

it('searches by name or email', function () {
    $admin = caseUser(Role::SystemAdmin);
    $token = Str::lower(Str::random(10));
    $byName = caseUser(Role::Cmo, ['name' => "Name {$token}"]);
    $byEmail = caseUser(Role::Cmo, ['email' => "mail-{$token}@example.test"]);
    caseUser(Role::Cmo, ['name' => 'Someone Else']);

    expect(collect(adminUserRows($admin, ['q' => $token]))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$byName->id, $byEmail->id])->sort()->values()->all());
});

it('treats LIKE wildcards in the search as literal text', function () {
    $admin = caseUser(Role::SystemAdmin);
    $org = vendorOrg();
    vendorUser($org, ['name' => 'Plain Name']);

    expect(adminUserRows($admin, ['q' => '%%', 'org' => $org->id]))->toBe([]);
});

it('filters by organisation, role and status', function () {
    $admin = caseUser(Role::SystemAdmin);
    $org = vendorOrg();
    $active = vendorUser($org);
    $disabled = vendorUser($org, ['status' => User::STATUS_DISABLED]);
    $token = Str::lower(Str::random(10));
    $cmo = caseUser(Role::Cmo, ['name' => "Filter {$token}"]);
    caseUser(Role::Technocrat, ['name' => "Filter {$token}"]);

    expect(collect(adminUserRows($admin, ['org' => $org->id]))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active->id, $disabled->id])->sort()->values()->all())
        ->and(collect(adminUserRows($admin, ['org' => $org->id, 'status' => 'disabled']))->pluck('id')->all())->toBe([$disabled->id])
        ->and(collect(adminUserRows($admin, ['org' => $org->id, 'status' => 'active']))->pluck('id')->all())->toBe([$active->id])
        ->and(collect(adminUserRows($admin, ['q' => $token, 'role' => 'cmo']))->pluck('id')->all())->toBe([$cmo->id]);

    $disabledRow = adminUserRows($admin, ['org' => $org->id, 'status' => 'disabled'])[0];
    expect($disabledRow['status_label'])->toBe('Disabled')->and($disabledRow['status_tone'])->toBe('neutral');
});

it('pages the list on the server, 25 a page', function () {
    $admin = caseUser(Role::SystemAdmin);
    $org = vendorOrg();
    foreach (range(1, 27) as $i) {
        vendorUser($org, ['name' => sprintf('Paged %02d', $i)]);
    }

    $first = pageProps($this->actingAs($admin)->get(route('admin.users.index', ['org' => $org->id]))->assertOk())['users'];
    $second = pageProps($this->actingAs($admin)->get(route('admin.users.index', ['org' => $org->id, 'page' => 2]))->assertOk())['users'];

    expect($first['data'])->toHaveCount(25)
        ->and($first['total'])->toBe(27)
        ->and($first['next_page_url'])->toContain('page=2')
        ->and($first['next_page_url'])->toContain('org='.$org->id)
        ->and($second['data'])->toHaveCount(2)
        ->and($second['data'][1]['name'])->toBe('Paged 27');
});

it('reports a bad filter instead of ignoring it', function (array $query, string $key) {
    $this->actingAs(caseUser(Role::SystemAdmin))
        ->get(route('admin.users.index', $query))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors($key);
})->with([
    'unknown role' => [['role' => 'superuser'], 'role'],
    'unknown status' => [['status' => 'superuser'], 'status'],
    'unknown type' => [['type' => 'robot'], 'type'],
    'unknown MFA value' => [['mfa' => 'maybe'], 'mfa'],
    'unknown organisation' => [['org' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'], 'org'],
    'one-character search' => [['q' => 'a'], 'q'],
]);

it('sends the filter options with role display names and the vendor flag', function () {
    $props = pageProps($this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.users.index'))->assertOk());

    expect(collect($props['options']['roles'])->firstWhere('value', 'vendor_applicant'))->toBe(['value' => 'vendor_applicant', 'label' => 'Vendor', 'vendor' => true])
        ->and(collect($props['options']['roles'])->firstWhere('value', 'cmo'))->toBe(['value' => 'cmo', 'label' => 'CMO', 'vendor' => false])
        ->and($props['options']['statuses'])->toBe([
            ['value' => 'invited', 'label' => 'Pending activation'], ['value' => 'active', 'label' => 'Active'],
            ['value' => 'disabled', 'label' => 'Disabled'], ['value' => 'locked', 'label' => 'Locked'],
        ])
        ->and($props['options']['organisations'][0]['value'])->toBe(mohOrg()->id);
});

it('never sends secrets or the model to the browser', function () {
    $admin = caseUser(Role::SystemAdmin);
    $org = vendorOrg();
    $vendor = vendorUser($org);

    $list = json_encode(pageProps($this->actingAs($admin)->get(route('admin.users.index', ['org' => $org->id]))->assertOk())['users']);
    $detail = json_encode(pageProps($this->actingAs($admin)->get(route('admin.users.show', $vendor))->assertOk())['user']);

    foreach (['password', 'remember_token', 'two_factor', 'failed_login_attempts', 'last_login_ip', 'sso_identifier'] as $secret) {
        expect($list)->not->toContain($secret)->and($detail)->not->toContain($secret);
    }
});

it('shows one user on the detail page', function () {
    $admin = caseUser(Role::SystemAdmin);
    $cmo = caseUser(Role::Cmo, ['name' => 'Detail Person']);

    $user = pageProps($this->actingAs($admin)->get(route('admin.users.show', $cmo))->assertOk())['user'];

    expect($user['name'])->toBe('Detail Person')
        ->and($user['role'])->toBe('CMO')
        ->and($user['role_value'])->toBe('cmo')
        ->and($user['organisation'])->toBe(mohOrg()->name)
        ->and($user['is_active'])->toBeTrue()
        ->and($user['disabled_on'])->toBeNull();
});

it('answers 404 for a user that does not exist', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))->get('/admin/users/999999999')->assertNotFound();
});

it('shares nav.users only with the console, never under can', function () {
    $adminProps = pageProps($this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.users.index'))->assertOk());
    $cmoProps = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk());

    expect($adminProps['nav']['users'])->toBeTrue()
        ->and($adminProps['nav']['section'])->toBe('admin')
        ->and($cmoProps['nav']['users'])->toBeFalse()
        ->and(array_keys($adminProps['can']))->toBe(['requests', 'cases']);
});

it('filters by type, pending activation, locked and MFA, with the status and MFA shown on each row', function () {
    $admin = caseUser(Role::SystemAdmin);
    $token = Str::lower(Str::random(10));
    $org = vendorOrg();
    $pending = caseUser(Role::Cmo, ['name' => "IT {$token} pending", 'status' => User::STATUS_INVITED]);
    $locked = caseUser(Role::Cmo, ['name' => "IT {$token} locked", 'locked_until' => now()->addMinutes(10)]);
    $noMfa = withoutMfa(caseUser(Role::Cmo, ['name' => "IT {$token} nomfa"]));
    $vendor = vendorUser($org, ['name' => "IT {$token} vendor"]);
    $expiredLock = caseUser(Role::Cmo, ['name' => "IT {$token} expired lock", 'locked_until' => now()->subMinute()]);

    $ids = fn (array $query) => collect(adminUserRows($admin, ['q' => $token, ...$query]))->pluck('id')->sort()->values()->all();

    expect($ids(['status' => 'invited']))->toBe([$pending->id])
        ->and($ids(['status' => 'locked']))->toBe([$locked->id])
        ->and($ids(['status' => 'active']))->toBe(collect([$noMfa->id, $vendor->id, $expiredLock->id])->sort()->values()->all())
        ->and($ids(['mfa' => 'no']))->toBe([$noMfa->id])
        ->and($ids(['type' => 'vendor']))->toBe([$vendor->id])
        ->and($ids(['type' => 'staff']))->not->toContain($vendor->id);

    $rows = collect(adminUserRows($admin, ['q' => $token]))->keyBy('id');
    expect($rows[$pending->id]['status_label'])->toBe('Pending activation')
        ->and($rows[$locked->id]['status_label'])->toBe('Locked')
        ->and($rows[$locked->id]['status_tone'])->toBe('warning')
        ->and($rows[$noMfa->id]['mfa_label'])->toBe('Not enrolled')
        ->and($rows[$noMfa->id]['mfa_tone'])->toBe('warning');
});

it('answers 404 for the console to anyone without admin.access', function (Role $role) {
    $user = $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);
    $target = caseUser(Role::Technocrat);

    $this->actingAs($user)->get(route('admin.users.index'))->assertNotFound();
    $this->actingAs($user)->get(route('admin.users.show', $target))->assertNotFound();
})->with(fn () => array_values(array_filter(Role::cases(), fn (Role $r) => $r !== Role::SystemAdmin)));
