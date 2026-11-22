<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\MfaReset;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * MFA-1: POST admin/users/{user}/mfa-reset. Outside the Admin > Users group
 * (users.manage) because the CMO may reset vendor users too; MfaReset decides
 * (users.mfa.reset, vendor-only without users.manage, never yourself): 403
 * otherwise.
 */
class MfaResetController extends Controller
{
    public function __invoke(Request $request, User $user, MfaReset $reset): RedirectResponse
    {
        $reset->handle($request->user(), $user);

        return back()->with('success', 'Multi-factor authentication reset. '.$user->name.' will set it up again at their next sign-in.');
    }
}
