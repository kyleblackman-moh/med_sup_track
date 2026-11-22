<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LandingPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

/**
 * MFA (spec 8.2): Settings > Security and the two-factor setup page.
 *
 * Both pages sit behind password.confirm, so Fortify's own endpoints (enable,
 * confirm, regenerate, disable), which require a recent password confirmation,
 * work from them. The writes themselves are Fortify's; this controller only
 * reads state. Recovery codes are sent only straight after Fortify flashes that
 * it made them (confirmed or regenerated), so they are shown once.
 */
class SecurityController extends Controller
{
    public const TIMEZONE = 'America/Port_of_Spain';

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $status = $request->session()->get('status');

        return Inertia::render('settings/security', [
            'mfa' => [
                'state' => self::state($user),
                'confirmed_at' => $user->two_factor_confirmed_at
                    ?->copy()->setTimezone(self::TIMEZONE)->format('j M Y, g:i a'),
                'required' => $user->mustUseTwoFactor(),
            ],
            'recoveryCodes' => $status === Fortify::RECOVERY_CODES_GENERATED && self::state($user) === 'on'
                ? $user->recoveryCodes()
                : null,
            'status' => is_string($status) ? $status : null,
        ]);
    }

    public function setup(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $state = self::state($user);
        $justConfirmed = $request->session()->get('status') === Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED;

        return Inertia::render('settings/two-factor-setup', [
            'state' => $state,
            'qrCodeSvg' => $state === 'pending' ? $user->twoFactorQrCodeSvg() : null,
            'secretKey' => $state === 'pending' ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $state === 'on' && $justConfirmed ? $user->recoveryCodes() : null,
            'required' => $user->mustUseTwoFactor(),
            'continueUrl' => LandingPage::for($user),
        ]);
    }

    /** off: no secret; pending: a secret not yet confirmed with a code; on: confirmed. */
    public static function state(User $user): string
    {
        if ($user->two_factor_secret === null) {
            return 'off';
        }

        return $user->two_factor_confirmed_at === null ? 'pending' : 'on';
    }
}
