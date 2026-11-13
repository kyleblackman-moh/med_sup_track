<?php

namespace App\Http\Responses;

use App\Support\LandingPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Fortify's login response with a per-user landing page: the intended URL
 * still wins; otherwise LandingPage decides. Also bound for the two-factor
 * challenge, so the landing stays the same once MFA is switched on.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 200);
        }

        return redirect()->intended(LandingPage::for($request->user()));
    }
}
