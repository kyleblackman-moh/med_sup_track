<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\MfaReset;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * MFA-1: POST admin/users/{user}/mfa-reset. Outside the admin console
 * (admin.access) because the CMO may reset vendor users too; MfaReset decides
 * (users.mfa.reset, vendor-only without admin.access, never yourself): 403
 * otherwise. IT-ADMIN: needs a recently confirmed password; a reason is optional.
 */
class MfaResetController extends Controller
{
    public function __invoke(Request $request, User $user, MfaReset $reset): RedirectResponse
    {
        $data = $request->validate(UserController::reasonRule(required: false), UserController::reasonMessages());

        $reset->handle($request->user(), $user, $data['reason'] ?? null);

        return back()->with('success', 'Multi-factor authentication reset. '.$user->name.' will set it up again at their next sign-in.');
    }
}
