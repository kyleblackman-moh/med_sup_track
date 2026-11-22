<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateOrChallenge;
use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\IgnoreRememberMe;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-user landing after login (requests, cases or dashboard). See LandingPage.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // No Fortify::createUsersUsing(): registration is disabled; admins create accounts.
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::authenticateUsing(new AuthenticateUser);

        // Explicit login pipeline. MFA-1: AuthenticateOrChallenge replaces Fortify's
        // RedirectIfTwoFactorAuthenticatable + AttemptToAuthenticate, so the
        // authenticateUsing callback runs once per login; an enrolled user goes to
        // the two-factor challenge. Throttling is applied by the "login" rate
        // limiter (route middleware), not in the pipeline.
        Fortify::authenticateThrough(fn (Request $request) => array_values(array_filter([
            IgnoreRememberMe::class, // FIX-1: no remember-me cookie
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            AuthenticateOrChallenge::class,
            PrepareAuthenticatedSession::class,
        ])));

        // MFA-1: the code (or recovery code) step after the password.
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        // SEC-1: the "login" limiter (and the other named limiters) live in App\Support\RateLimits,
        // registered by AppServiceProvider. It still fires Lockout (audited as auth.rate_limited).

        // MFA-1: 5 a minute per login id and 5 a minute per session, counted
        // separately, so a new session does not reset the count for an account.
        RateLimiter::for('two-factor', function (Request $request) {
            return [
                Limit::perMinute(5)->by('2fa-login:'.$request->session()->get('login.id')),
                Limit::perMinute(5)->by('2fa-session:'.$request->session()->getId()),
            ];
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
