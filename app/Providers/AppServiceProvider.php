<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        // Password policy (spec 8.1). No ->uncompromised(): it calls an external
        // service (Have I Been Pwned) that the server may not be able to reach.
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
    }
}
