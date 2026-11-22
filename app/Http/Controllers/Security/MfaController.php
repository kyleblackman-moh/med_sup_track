<?php

namespace App\Http\Controllers\Security;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\MfaSecurityNotice;
use App\Support\Audit;
use App\Support\LandingPage;
use App\Support\MfaEnforcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MFA-1: enrolment (authenticator QR, secret key, 6-digit confirm), the
 * recovery codes (shown once, until the user ticks "I have saved these
 * codes"), and Security settings (regenerate codes, behind password confirm).
 *
 * There is no "disable": users cannot turn MFA off. Fortify's own two-factor
 * management endpoints are refused in routes/web.php; only an administrator
 * can reset a user's MFA (App\Accounts\MfaReset).
 */
class MfaController extends Controller
{
    /** Session flag: recovery codes waiting to be acknowledged. */
    public const SESSION_SHOW_CODES = 'mfa.show_recovery_codes';

    /** Examples named on the setup page. */
    public const APP_EXAMPLES = ['Google Authenticator', 'Microsoft Authenticator'];

    public function setup(Request $request, EnableTwoFactorAuthentication $enable): Response|RedirectResponse
    {
        $user = $request->user();

        if (MfaEnforcement::enrolled($user)) {
            return redirect()->route('security.edit');
        }

        // The secret is created once and kept until confirmed, so a reload shows the same QR.
        if (blank($user->two_factor_secret)) {
            $enable($user);
            $user->refresh();
        }

        return Inertia::render('security/mfa-setup', [
            'qr_svg' => $user->twoFactorQrCodeSvg(),
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'apps' => self::APP_EXAMPLES,
        ]);
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $validated = $request->validate(
            ['code' => ['required', 'string', 'regex:/^\d{6}$/']],
            ['code.required' => 'Enter the 6-digit code from your authenticator app.', 'code.regex' => 'Enter the 6-digit code from your authenticator app.'],
        );

        $user = $request->user();
        if (MfaEnforcement::enrolled($user)) {
            return redirect()->route('security.edit');
        }

        try {
            DB::transaction(function () use ($confirm, $user, $validated) {
                $confirm($user, $validated['code']);

                Audit::record(AuditAction::MfaEnrolled, ['type' => 'user', 'id' => $user->id], null, ['user_id' => $user->id], $user);
            });
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => 'That code did not match. Check the time on your phone and try the newest code.']);
        }

        // A new session id once the second factor is in place.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_SHOW_CODES, true);

        $user->notify(new MfaSecurityNotice($user->id, MfaSecurityNotice::ENROLLED));

        return redirect()->route('security.mfa.recovery-codes');
    }

    /** Shown only while the codes wait to be acknowledged (after enrolment or a regenerate). */
    public function recoveryCodes(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(self::SESSION_SHOW_CODES) !== true) {
            return redirect()->route('security.edit');
        }

        return Inertia::render('security/mfa-recovery-codes', [
            'codes' => $request->user()->recoveryCodes(),
        ]);
    }

    public function downloadRecoveryCodes(Request $request): StreamedResponse|RedirectResponse
    {
        if ($request->session()->get(self::SESSION_SHOW_CODES) !== true) {
            return redirect()->route('security.edit');
        }

        $user = $request->user();
        $body = implode("\r\n", [
            config('app.name').': recovery codes',
            'Account: '.$user->email,
            'Each code works once. Keep them somewhere safe.',
            '',
            ...$user->recoveryCodes(),
            '',
        ]);

        return response()->streamDownload(fn () => print ($body), 'recovery-codes.txt', [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $request->validate(
            ['saved' => ['accepted']],
            ['saved.accepted' => 'Tick "I have saved these codes" to continue.'],
        );

        $request->session()->forget(self::SESSION_SHOW_CODES);

        return redirect(LandingPage::for($request->user()));
    }

    /** Security settings: when MFA was set up and how many recovery codes are left. */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/security', [
            'mfa' => [
                'enrolled' => MfaEnforcement::enrolled($user),
                'confirmed_at' => $user->two_factor_confirmed_at?->copy()->utc()->toIso8601ZuluString(),
                'recovery_codes_left' => MfaEnforcement::enrolled($user) ? count($user->recoveryCodes()) : 0,
            ],
        ]);
    }

    /** Regenerate recovery codes. Behind password.confirm (routes/web.php). */
    public function regenerate(Request $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(MfaEnforcement::enrolled($user), 403);

        DB::transaction(function () use ($generate, $user) {
            $generate($user);

            Audit::record(AuditAction::MfaRecoveryRegenerated, ['type' => 'user', 'id' => $user->id], null, ['user_id' => $user->id], $user);
        });

        $request->session()->put(self::SESSION_SHOW_CODES, true);

        return redirect()->route('security.mfa.recovery-codes');
    }
}
