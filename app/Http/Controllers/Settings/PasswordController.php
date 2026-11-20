<?php

namespace App\Http\Controllers\Settings;

use App\Events\PasswordChanged;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $forced = (bool) $user->password_change_required;

        // A forced change must actually change the password: current_password is
        // checked against the stored hash, so differing from it is enough.
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed', ...($forced ? ['different:current_password'] : [])],
        ], [
            'password.different' => 'Choose a password different from your current one.',
        ]);

        // Validation passed, so the flag is cleared together with the new hash.
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_change_required' => false,
        ])->save();

        // NOTIF-1: the email itself is sent by NotificationSubscriber.
        PasswordChanged::dispatch((int) $user->getKey());

        return back();
    }
}
