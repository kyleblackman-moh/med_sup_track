<?php

namespace App\Http\Controllers\Auth;

use App\Accounts\AccountActivations;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IT-ADMIN (spec 8.2): the emailed activation link. GET checks the signature
 * (signed middleware: tampered or past expires_at is 403), then that the link
 * is still open; POST sets the password once (AccountActivations::accept).
 * The person then signs in and EnsureMfaEnrolled sends them to enrol MFA
 * before anything else.
 *
 * Passwords: the app policy (Password::defaults(): 12+ characters, mixed case,
 * a number) plus, behind admin.passwords.check_breached (default on), a
 * breached-password check.
 */
class ActivationController extends Controller
{
    public const INVALID = 'This activation link has expired or has already been used. Ask your administrator to send a new one.';

    public function show(Request $request, string $invitation, AccountActivations $activations): Response
    {
        $open = $activations->find($invitation, (string) $request->query('token'));

        return Inertia::render('auth/activate', [
            'valid' => $open !== null,
            'invitation' => $open?->id,
            'token' => $open !== null ? (string) $request->query('token') : null,
            'email' => $open?->user?->email,
            'message' => $open === null ? self::INVALID : null,
        ]);
    }

    public function store(Request $request, string $invitation, AccountActivations $activations): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:'.AccountActivations::TOKEN_LENGTH],
            'password' => ['required', 'confirmed', self::passwordRule()],
        ]);

        if ($activations->accept($invitation, (string) $request->input('token'), (string) $request->input('password')) === null) {
            throw ValidationException::withMessages(['token' => self::INVALID]);
        }

        return to_route('login')->with('status', 'Your account is active. Sign in to set up multi-factor authentication.');
    }

    public static function passwordRule(): Password
    {
        $rule = Password::default();

        return config('admin.passwords.check_breached', true) ? $rule->uncompromised() : $rule;
    }
}
