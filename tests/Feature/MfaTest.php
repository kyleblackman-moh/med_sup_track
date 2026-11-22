<?php

use App\Enums\Role;
use App\Http\Controllers\Security\MfaController;
use App\Models\User;
use App\Notifications\MfaSecurityNotice;
use App\Support\MfaEnforcement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

// MFA-1: mandatory TOTP. Real middleware (never disabled in tests); the user
// factory makes MFA-confirmed users by default, withoutMfa() makes a fresh one.

const MFA_PASSWORD = 'Correct-Horse-Battery-12';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function mfaFresh(?Role $role = Role::Cmo): User
{
    $user = $role === Role::VendorApplicant
        ? vendorUser(vendorOrg(), ['password' => MFA_PASSWORD])
        : caseUser($role, ['password' => MFA_PASSWORD]);

    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    return $user->refresh();
}

function mfaOtp(User $user, int $offsetSeconds = 0): string
{
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $g = app(Google2FA::class);

    return $g->oathTotp($secret, (int) floor((time() + $offsetSeconds) / 30));
}

function mfaRecoveryCodes(User $user): array
{
    return json_decode(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_recovery_codes), true);
}

function mfaAudit(User $user, string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->where('subject_id', (string) $user->id)
        ->orWhere(fn ($q) => $q->where('action', $action)->where('actor_user_id', $user->id))->count();
}

// 1. Enforcement -----------------------------------------------------------------------

it('sends an unenrolled user from any protected route to MFA setup (staff and vendor)', function (Role $role, string $route) {
    $user = mfaFresh($role);

    $this->actingAs($user)->get(route($route))->assertRedirect(route('security.mfa.setup'));
    $this->actingAs($user)->getJson(route('notifications.unread-count'))->assertForbidden();
    $this->actingAs($user)->get(route('security.mfa.setup'))->assertOk();
})->with([
    'staff' => [Role::Cmo, 'cases.index'],
    'staff dashboard' => [Role::Technocrat, 'dashboard'],
    'vendor' => [Role::VendorApplicant, 'requests.index'],
]);

it('lets an enrolled user (the factory default) straight through', function () {
    $user = caseUser(Role::Cmo);

    expect(MfaEnforcement::enrolled($user))->toBeTrue();
    $this->actingAs($user)->get(route('cases.index'))->assertOk();
});

it('enrols with the QR and secret, confirms a code, shows the codes once, then lets the user in', function (Role $role) {
    $user = mfaFresh($role);

    $props = pageProps($this->actingAs($user)->get(route('security.mfa.setup'))->assertOk());
    expect($props['qr_svg'])->toContain('<svg')
        ->and($props['secret'])->toBe(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret))
        ->and($props['apps'])->toBe(['Google Authenticator', 'Microsoft Authenticator']);

    // A reload keeps the same secret.
    expect(pageProps($this->actingAs($user)->get(route('security.mfa.setup')))['secret'])->toBe($props['secret']);

    $this->actingAs($user)->post(route('security.mfa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($user)->post(route('security.mfa.confirm'), ['code' => mfaOtp($user)])
        ->assertRedirect(route('security.mfa.recovery-codes'));

    expect(MfaEnforcement::enrolled($user->fresh()))->toBeTrue()
        ->and(mfaAudit($user, 'mfa.enrolled'))->toBe(1);
    Notification::assertSentTo($user, MfaSecurityNotice::class, fn ($n) => $n->kind === MfaSecurityNotice::ENROLLED);

    // Held on the codes page until "I have saved these codes".
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.mfa.recovery-codes'));
    $codes = pageProps($this->actingAs($user)->get(route('security.mfa.recovery-codes'))->assertOk())['codes'];
    expect($codes)->toHaveCount(8)->and($codes)->toBe(mfaRecoveryCodes($user));

    $download = $this->actingAs($user)->get(route('security.mfa.recovery-codes.download'))->assertOk();
    expect($download->headers->get('content-type'))->toStartWith('text/plain')
        ->and($download->streamedContent())->toContain($codes[0]);

    $this->actingAs($user)->post(route('security.mfa.recovery-codes.acknowledge'), [])->assertSessionHasErrors('saved');
    $this->actingAs($user)->post(route('security.mfa.recovery-codes.acknowledge'), ['saved' => '1'])->assertRedirect();

    // Shown once: the page is gone after the acknowledgement.
    $this->actingAs($user)->get(route('security.mfa.recovery-codes'))->assertRedirect(route('security.edit'));
    $this->actingAs($user)->get(route('security.mfa.recovery-codes.download'))->assertRedirect(route('security.edit'));
})->with([Role::Cmo, Role::VendorApplicant]);

it('regenerates the session id when enrolment is confirmed', function () {
    $user = mfaFresh();
    $this->actingAs($user)->get(route('security.mfa.setup'));
    $before = session()->getId();

    $this->actingAs($user)->post(route('security.mfa.confirm'), ['code' => mfaOtp($user)]);

    expect(session()->getId())->not->toBe($before);
});

// 2. Login challenge --------------------------------------------------------------------

it('asks an enrolled user for a code after the password, and signs them in with a valid one', function () {
    $user = mfaFresh();
    $this->actingAs($user)->get(route('security.mfa.setup'));
    $this->actingAs($user)->post(route('security.mfa.confirm'), ['code' => mfaOtp($user, -30)]);
    auth()->logout();
    session()->flush();

    $this->post('/login', ['email' => $user->email, 'password' => MFA_PASSWORD])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['code' => mfaOtp($user)])->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
});

it('rejects an invalid code and audits it; the 6th attempt in a minute is throttled', function () {
    $user = caseUser(Role::Cmo, ['password' => MFA_PASSWORD]);

    $this->post('/login', ['email' => $user->email, 'password' => MFA_PASSWORD])->assertRedirect(route('two-factor.login'));

    for ($i = 1; $i <= 5; $i++) {
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertRedirect(route('two-factor.login'));
    }
    $this->assertGuest();
    expect(DB::table('audit_logs')->where('action', 'mfa.challenge_failed')->where('actor_user_id', $user->id)->count())->toBe(5);

    $this->post(route('two-factor.login.store'), ['code' => mfaOtp($user)])->assertStatus(429);
    $this->assertGuest();
});

it('accepts a recovery code once only, audits it and emails the user', function () {
    $user = caseUser(Role::Cmo, ['password' => MFA_PASSWORD]);
    $code = mfaRecoveryCodes($user)[0];

    $this->post('/login', ['email' => $user->email, 'password' => MFA_PASSWORD]);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $code])->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
    expect(mfaRecoveryCodes($user))->not->toContain($code)
        ->and(DB::table('audit_logs')->where('action', 'mfa.recovery_code_used')->where('actor_user_id', $user->id)->count())->toBe(1);
    Notification::assertSentTo($user, MfaSecurityNotice::class, fn ($n) => $n->kind === MfaSecurityNotice::RECOVERY_USED);

    auth()->logout();
    session()->flush();
    $this->post('/login', ['email' => $user->email, 'password' => MFA_PASSWORD]);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $code])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

it('runs the password check once per login (no doubled failure count)', function () {
    $user = caseUser(Role::Cmo, ['password' => MFA_PASSWORD]);

    $this->post('/login', ['email' => $user->email, 'password' => 'Wrong-Password-1'])->assertSessionHasErrors('email');

    expect((int) $user->fresh()->failed_login_attempts)->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'auth.login.failed')->where('actor_user_id', $user->id)->count())->toBe(1);
});

// 3. No self-disable; settings; admin reset ------------------------------------------------

it('never lets a user disable or replace their own MFA through Fortify\'s endpoints', function () {
    $user = caseUser(Role::Cmo);
    $secret = $user->two_factor_secret;

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')->assertForbidden();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/user/two-factor-authentication', ['force' => true])->assertForbidden();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/user/two-factor-recovery-codes')->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBe($secret)
        ->and(MfaEnforcement::enrolled($user->fresh()))->toBeTrue();
});

it('regenerates recovery codes only after a password confirm, and audits it', function () {
    $user = caseUser(Role::Cmo);
    $before = mfaRecoveryCodes($user);

    $this->actingAs($user)->post(route('security.mfa.regenerate'))->assertRedirect(route('password.confirm'));
    expect(mfaRecoveryCodes($user))->toBe($before);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('security.mfa.regenerate'))->assertRedirect(route('security.mfa.recovery-codes'));

    expect(mfaRecoveryCodes($user))->not->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'mfa.recovery_regenerated')->where('actor_user_id', $user->id)->count())->toBe(1);

    $props = pageProps($this->actingAs($user)->withSession([MfaController::SESSION_SHOW_CODES => false])->get(route('security.edit'))->assertOk());
    expect($props['mfa'])->toMatchArray(['enrolled' => true, 'recovery_codes_left' => 8]);
});

it('lets an admin reset MFA: columns cleared, sessions ended, audit and email, re-enrolment forced', function () {
    confirmAdminPassword();
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->post(route('admin.users.mfa-reset', $user))->assertRedirect()->assertSessionHas('success');

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $audit = DB::table('audit_logs')->where('action', 'mfa.reset_by_admin')->where('subject_id', (string) $user->id)->sole();
    expect((int) $audit->actor_user_id)->toBe($admin->id)
        ->and(json_decode($audit->metadata, true))->toBe(['user_id' => $user->id, 'sessions_ended' => 1]);
    Notification::assertSentTo($user, MfaSecurityNotice::class, fn ($n) => $n->kind === MfaSecurityNotice::RESET_BY_ADMIN);

    $this->actingAs($fresh)->get(route('dashboard'))->assertRedirect(route('security.mfa.setup'));
});

it('lets the CMO reset vendor users only, and nobody reset themselves', function () {
    confirmAdminPassword();
    $cmo = caseUser(Role::Cmo);
    $vendor = vendorUser(vendorOrg());
    $staff = caseUser(Role::Technocrat);

    $this->actingAs($cmo)->post(route('admin.users.mfa-reset', $staff))->assertForbidden();
    $this->actingAs($cmo)->post(route('admin.users.mfa-reset', $cmo))->assertForbidden();
    $this->actingAs(caseUser(Role::Technocrat))->post(route('admin.users.mfa-reset', $vendor))->assertForbidden();
    expect(MfaEnforcement::enrolled($staff->fresh()))->toBeTrue();

    $this->actingAs($cmo)->post(route('admin.users.mfa-reset', $vendor))->assertSessionHas('success');
    expect(MfaEnforcement::enrolled($vendor->fresh()))->toBeFalse();

    $admin = caseUser(Role::SystemAdmin);
    $this->actingAs($admin)->post(route('admin.users.mfa-reset', $admin))->assertForbidden();
});

// 4. Configuration -----------------------------------------------------------------------

it('refuses mfa.enforce=false outside local', function (string $env) {
    expect(fn () => MfaEnforcement::assertAllowed($env, false))->toThrow(RuntimeException::class);
})->with(['production', 'staging', 'testing']);

it('allows mfa.enforce=false in local, and true anywhere', function () {
    MfaEnforcement::assertAllowed('local', false);
    MfaEnforcement::assertAllowed('production', true);

    expect(true)->toBeTrue();
});
