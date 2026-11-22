<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;

/**
 * MFA: Fortify's two-factor step (a user with a confirmed authenticator is
 * sent to the challenge instead of being signed in), but it reuses the user
 * ResolveLoginUser already accepted rather than running AuthenticateUser a
 * second time.
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @return User
     */
    protected function validateCredentials($request)
    {
        return ResolveLoginUser::user($request);
    }
}
