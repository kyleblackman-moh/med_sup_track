<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

// MFA (spec 8.2): mandatory TOTP with recovery codes, on Fortify.
// phpunit.xml sets MFA_ENFORCE=false so the rest of the suite (actingAs users
// without MFA) is unaffected; the enforcement tests switch it on here.
// DatabaseTransactions only (tests/Pest.php): every test rolls back.

const MFA_PASSWORD = 'Mfa-Correct-Horse-2026';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['auth.mfa_enforce' => false]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A local CMO account with a known password. */
function mfaUser(array $attributes = []): User
{
    return caseUser(Role::Cmo, ['password' => Hash::make(MFA_PASSWORD), ...$attributes]);
}

/** Give the user a confirmed authenticator; returns [plain secret, plain recovery codes]. */
function mfaConfirm(User $user): array
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $codes = array_map(fn () => RecoveryCode::generate(), range(1, 8));

    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return [$secret, $codes];
}

function mfaOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

function mfaLogin(User $user, string $password = MFA_PASSWORD)
{
    return test()->post('/login', ['email' => $user->email, 'password' => $password]);
}

/** Audit rows for this user and action. */
function mfaAudit(User $user, AuditAction $action)
{
    return DB::table('audit_logs')->where('actor_user_id', $user->id)->where('action', $action->value)->get();
}

/** Count calls to Fortify's authenticateUsing callback (AuthenticateUser) while $run runs. */
function mfaCountingAuthenticate(callable $run): int
{
    $original = Fortify::$authenticateUsingCallback;
    $calls = 0;
    Fortify::authenticateUsing(function ($request) use (&$calls, $original) {
        $calls++;

        return $original($request);
    });

    try {
        $run();
    } finally {
        Fortify::authenticateUsing($original);
    }

    return $calls;
}

function mfaConfirmedSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

// Configuration ---------------------------------------------------------------

it('enforces MFA by default when MFA_ENFORCE is absent', function () {
    $config = file_get_contents(config_path('auth.php'));

    expect($config)->toContain("'mfa_enforce' => (bool) env('MFA_ENFORCE', true)");
});

// Enforcement -----------------------------------------------------------------

it('sends a local user without MFA to the setup page from every page while enforced', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    $this->actingAs($user)->get(route('cases.index'))->assertRedirect(route('two-factor.setup'));
    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('two-factor.setup'));
    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('two-factor.setup'));
});

it('lets that user open the setup page and sign out', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();

    $this->actingAs($user)->withSession(mfaConfirmedSession())->get(route('two-factor.setup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/two-factor-setup')->where('state', 'off')->where('required', true));

    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('answers background JSON polls with 409, never a redirect', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();

    $this->actingAs($user)->getJson(route('notifications.unread-count'))
        ->assertStatus(409)
        ->assertJsonPath('two_factor_setup', route('two-factor.setup'));

    // Even without a JSON Accept header, the poll routes are not redirected.
    $this->actingAs($user)->get(route('notifications.unread-count'))->assertStatus(409);
});

it('allows the setup and confirmation routes it lists', function () {
    expect(EnsureTwoFactorEnabled::ALLOWED)->toContain('two-factor.setup', 'logout', 'two-factor.enable', 'two-factor.confirm', 'password.confirm');
});

it('does not redirect when MFA is not enforced', function () {
    $user = mfaUser();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->withSession(mfaConfirmedSession())->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mfa.required', false)->where('mfa.state', 'off'));
});

it('exempts SSO accounts from enforcement', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();
    $user->forceFill(['auth_source' => User::AUTH_SOURCE_SSO])->save();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

it('lets a user who has confirmed MFA through while enforced', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();
    mfaConfirm($user);

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

it('puts a forced password change before MFA setup, without a redirect loop', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();
    $user->forceFill(['password_change_required' => true])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.edit'));
    $this->actingAs($user)->get(route('password.edit'))->assertOk();
});

it('signs a user without MFA in and then sends them to setup without asking for the password again', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();

    mfaLogin($user)->assertRedirect();
    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    $this->get(route('two-factor.setup'))->assertOk();
});

// Login challenge -------------------------------------------------------------

it('sends a user with confirmed MFA to the challenge instead of signing them in', function () {
    $user = mfaUser();
    mfaConfirm($user);

    mfaLogin($user)->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
});

it('signs in with a valid authenticator code', function () {
    $user = mfaUser();
    [$secret] = mfaConfirm($user);

    mfaLogin($user)->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => mfaOtp($secret)])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(mfaAudit($user, AuditAction::LoginSuccess))->toHaveCount(1);
});

it('signs in with a recovery code and consumes it', function () {
    $user = mfaUser();
    [, $codes] = mfaConfirm($user);

    mfaLogin($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $codes[3]])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    $remaining = $user->fresh()->recoveryCodes();
    expect($remaining)->toHaveCount(8)->not->toContain($codes[3])
        ->and(mfaAudit($user, AuditAction::MfaRecoveryCodeUsed))->toHaveCount(1);

    // The same code does not work twice.
    $this->post(route('logout'));
    mfaLogin($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $codes[3]])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('rejects a wrong authenticator code inline and audits the failure', function () {
    $user = mfaUser();
    [$secret] = mfaConfirm($user);
    $wrong = mfaOtp($secret) === '000000' ? '111111' : '000000';

    mfaLogin($user);
    $this->post(route('two-factor.login.store'), ['code' => $wrong])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    $rows = mfaAudit($user, AuditAction::MfaChallengeFailed);
    expect($rows)->toHaveCount(1)
        ->and(json_decode($rows[0]->metadata, true))->toBe(['method' => 'authenticator']);
});

it('rejects a wrong recovery code', function () {
    $user = mfaUser();
    mfaConfirm($user);

    mfaLogin($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-real-code'])
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
    expect(json_decode(mfaAudit($user, AuditAction::MfaChallengeFailed)[0]->metadata, true))->toBe(['method' => 'recovery']);
});

// One credential check per login ----------------------------------------------

it('runs AuthenticateUser once for a login that goes to the challenge', function () {
    $user = mfaUser();
    mfaConfirm($user);

    $calls = mfaCountingAuthenticate(fn () => mfaLogin($user)->assertRedirect(route('two-factor.login')));

    expect($calls)->toBe(1);
});

it('runs AuthenticateUser once for a login without MFA', function () {
    $user = mfaUser();

    $calls = mfaCountingAuthenticate(fn () => mfaLogin($user)->assertRedirect());

    expect($calls)->toBe(1);
    $this->assertAuthenticatedAs($user);
});

it('counts one bad password once, with one audit row, for an MFA user', function () {
    $user = mfaUser();
    mfaConfirm($user);

    $calls = mfaCountingAuthenticate(fn () => mfaLogin($user, 'Wrong-Password-123')->assertSessionHasErrors(['email' => __('auth.failed')]));

    expect($calls)->toBe(1)
        ->and($user->fresh()->failed_login_attempts)->toBe(1)
        ->and(mfaAudit($user, AuditAction::LoginFailed))->toHaveCount(1);
    $this->assertGuest();
});

// Setup -----------------------------------------------------------------------

it('sets up MFA end to end and shows the recovery codes once', function () {
    $user = mfaUser();
    $this->actingAs($user)->withSession(mfaConfirmedSession());

    $this->from(route('two-factor.setup'))->post(route('two-factor.enable'))->assertRedirect(route('two-factor.setup'));

    $pending = $this->get(route('two-factor.setup'))->assertOk();
    $pending->assertInertia(fn (Assert $page) => $page->where('state', 'pending')->where('recoveryCodes', null));
    $props = pageProps($pending);
    expect($props['qrCodeSvg'])->toStartWith('<svg')
        ->and($props['secretKey'])->toBe(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));

    $this->from(route('two-factor.setup'))->post(route('two-factor.confirm'), ['code' => mfaOtp($props['secretKey'])])
        ->assertRedirect(route('two-factor.setup'))
        ->assertSessionHasNoErrors();

    $this->get(route('two-factor.setup'))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'on')->has('recoveryCodes', 8)->where('qrCodeSvg', null)->where('secretKey', null));

    // Shown once: a reload no longer carries them.
    $this->get(route('two-factor.setup'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));

    $fresh = $user->fresh();
    expect($fresh->two_factor_confirmed_at)->not->toBeNull()
        ->and($fresh->mfa_enabled)->toBeTrue()
        ->and(mfaAudit($user, AuditAction::MfaEnabled))->toHaveCount(1);
});

it('rejects a wrong code at setup and leaves MFA unconfirmed', function () {
    $user = mfaUser();
    $this->actingAs($user)->withSession(mfaConfirmedSession());
    $this->post(route('two-factor.enable'));

    $this->from(route('two-factor.setup'))->post(route('two-factor.confirm'), ['code' => '12'])
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and(mfaAudit($user, AuditAction::MfaEnabled))->toHaveCount(0);
});

it('refuses to start setup again once MFA is confirmed (no silent secret swap)', function () {
    $user = mfaUser();
    mfaConfirm($user);
    $before = $user->fresh()->two_factor_secret;

    $this->actingAs($user)->withSession(mfaConfirmedSession())->post(route('two-factor.enable'), ['force' => true])->assertStatus(409);

    expect($user->fresh()->two_factor_secret)->toBe($before);
});

// Security page ---------------------------------------------------------------

it('shows the security page with the MFA status and the date in Port of Spain', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();
    mfaConfirm($user);
    $user->forceFill(['two_factor_confirmed_at' => CarbonImmutable::parse('2026-11-03 13:14:00', 'UTC')])->save();

    $response = $this->actingAs($user->fresh())->withSession(mfaConfirmedSession())->get(route('security.edit'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('settings/security')
        ->where('mfa.state', 'on')
        ->where('mfa.confirmed_at', '3 Nov 2026, 9:14 am')
        ->where('mfa.required', true)
        ->where('recoveryCodes', null));

    $props = pageProps($response);
    expect($props)->not->toHaveKey('can.mfa')
        ->and(json_encode($props))->not->toContain(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));
});

it('asks for the password before the security and setup pages', function () {
    $user = mfaUser();

    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->get(route('two-factor.setup'))->assertRedirect(route('password.confirm'));
});

it('regenerates recovery codes, shows the new set once and audits it', function () {
    $user = mfaUser();
    [, $old] = mfaConfirm($user);
    $this->actingAs($user)->withSession(mfaConfirmedSession());

    $this->from(route('security.edit'))->post(route('two-factor.regenerate-recovery-codes'))->assertRedirect(route('security.edit'));

    $new = $user->fresh()->recoveryCodes();
    expect(array_intersect($new, $old))->toBe([]);

    $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', $new));
    $this->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));

    expect(mfaAudit($user, AuditAction::MfaRecoveryCodesRegenerated))->toHaveCount(1);
});

it('refuses to turn MFA off while it is enforced', function () {
    config(['auth.mfa_enforce' => true]);
    $user = mfaUser();
    mfaConfirm($user);

    $this->actingAs($user->fresh())->withSession(mfaConfirmedSession())->delete(route('two-factor.disable'))->assertForbidden();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('turns MFA off when it is optional and audits it', function () {
    $user = mfaUser();
    mfaConfirm($user);

    $this->actingAs($user->fresh())->withSession(mfaConfirmedSession())
        ->from(route('security.edit'))->delete(route('two-factor.disable'))
        ->assertRedirect(route('security.edit'));

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull()
        ->and($fresh->mfa_enabled)->toBeFalse()
        ->and(mfaAudit($user, AuditAction::MfaDisabled))->toHaveCount(1);
});

// Audit payloads --------------------------------------------------------------

it('never writes a secret, TOTP code or recovery code into the audit log', function () {
    $user = mfaUser();
    [$secret, $codes] = mfaConfirm($user);

    // A failed challenge, a recovery-code login, a regeneration and a TOTP login.
    mfaLogin($user);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong-wrong']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $codes[0]]);
    $this->withSession(mfaConfirmedSession())->post(route('two-factor.regenerate-recovery-codes'));
    $regenerated = $user->fresh()->recoveryCodes();
    $this->post(route('logout'));
    mfaLogin($user);
    $otp = mfaOtp($secret);
    $this->post(route('two-factor.login.store'), ['code' => $otp]);

    $rows = DB::table('audit_logs')->where('actor_user_id', $user->id)->where('action', 'like', 'auth.mfa.%')->get();
    expect($rows->pluck('action')->unique()->values()->all())
        ->toContain(AuditAction::MfaChallengeFailed->value, AuditAction::MfaRecoveryCodeUsed->value, AuditAction::MfaRecoveryCodesRegenerated->value);

    $everything = DB::table('audit_logs')->where('actor_user_id', $user->id)->pluck('metadata')->filter()->implode(' ');
    foreach ([$secret, $otp, ...$codes, ...$regenerated] as $value) {
        expect(str_contains($everything, $value))->toBeFalse();
    }
});
