<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Backlog, 2026-11-25: Job Title and Division, the request letter's sign-off
// lines under the name. Set on create and on the user page; the audit holds
// which changed, never the text. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    confirmAdminPassword();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('stores the job title and division given on create, trimmed, and blank as null', function () {
    $admin = caseUser(Role::SystemAdmin);
    $email = 'details-'.Str::lower(Str::ulid()).'@example.test';

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Signing Officer', 'email' => $email, 'role' => 'technocrat', 'organisation' => '',
        'job_title' => '  Senior Pharmacist  ', 'division' => '   ',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', $email)->firstOrFail();
    expect($user->job_title)->toBe('Senior Pharmacist')
        ->and($user->division)->toBeNull();
});

it('updates the details from the user page and audits flags only', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat, ['job_title' => 'Pharmacist']);

    $this->actingAs($admin)->put(route('admin.users.details', $user), ['job_title' => 'Chief Pharmacist', 'division' => 'Pharmaceutical Division'])
        ->assertRedirect(route('admin.users.show', $user));

    $user->refresh();
    expect($user->job_title)->toBe('Chief Pharmacist')->and($user->division)->toBe('Pharmaceutical Division');

    $row = DB::table('audit_logs')->where('action', 'user.details_changed')->where('subject_id', (string) $user->id)->sole();
    expect(json_decode($row->metadata, true))->toBe(['user_id' => $user->id, 'job_title_changed' => true, 'division_changed' => true])
        ->and($row->metadata)->not->toContain('Chief Pharmacist')
        ->and($row->metadata)->not->toContain('Pharmaceutical Division');
});

it('writes no audit row when nothing changed', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat, ['job_title' => 'Pharmacist']);

    $this->actingAs($admin)->put(route('admin.users.details', $user), ['job_title' => 'Pharmacist', 'division' => ''])->assertRedirect();

    expect(DB::table('audit_logs')->where('action', 'user.details_changed')->where('subject_id', (string) $user->id)->count())->toBe(0);
});

it('sends the details and the update_details hint on the user page', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat, ['job_title' => 'Pharmacist', 'division' => 'Drug Inspectorate']);

    $props = pageProps($this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk());

    expect($props['user']['job_title'])->toBe('Pharmacist')
        ->and($props['user']['division'])->toBe('Drug Inspectorate')
        ->and($props['abilities']['update_details'])->toBeTrue();
});

it('refuses a user without the role permission and rejects over-long values', function () {
    $user = caseUser(Role::Technocrat);

    $this->actingAs(caseUser(Role::SystemAdmin))->put(route('admin.users.details', $user), ['job_title' => str_repeat('x', 256), 'division' => str_repeat('y', 151)])
        ->assertSessionHasErrors(['job_title', 'division']);

    // No admin console: 404 (EnsureAdminConsole); console without users.update_role: 403.
    expect($this->actingAs(caseUser(Role::Auditor))->put(route('admin.users.details', $user), ['job_title' => 'X'])->status())->toBeIn([403, 404])
        ->and($user->fresh()->job_title)->toBeNull();
});

// Backlog, 2026-11-25: auth redirects go to the user's landing page, not always the dashboard.
it('sends a vendor to Requests after confirming the password, not the dashboard', function () {
    $vendor = vendorUser(vendorOrg());

    $this->actingAs($vendor)->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect(route('requests.index', absolute: false));
});
