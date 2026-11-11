<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

        // Explicit login pipeline. The two-factor step is left out on purpose: MFA
        // is a later slice, and with it present Fortify would run the
        // authenticateUsing callback twice per login. Throttling is applied by
        // the "login" rate limiter below (route middleware), not in the pipeline.
        Fortify::authenticateThrough(fn (Request $request) => [
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // The throttle middleware does not fire Lockout by itself. Fire it here
            // (AuthEventAuditor records it as auth.rate_limited), then throw the same
            // 429 exception the middleware would have thrown.
            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers) {
                event(new Lockout($request));

                throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
            });
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
