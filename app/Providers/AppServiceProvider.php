<?php

namespace App\Providers;

use App\Documents\Scanning\ClamdScanner;
use App\Documents\Scanning\EicarDevScanner;
use App\Documents\Scanning\Scanner;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\NotificationSubscriber;
use App\Policies\CasePolicy;
use App\Policies\DocumentPolicy;
use App\Policies\UserPolicy;
use App\Support\AuthEventAuditor;
use App\Support\MfaEnforcement;
use App\Support\MfaEventAuditor;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Malware scanner, chosen by config; an unknown value fails loudly.
        $this->app->bind(Scanner::class, fn () => match (config('documents.scanner')) {
            'clamd' => new ClamdScanner(
                (string) config('documents.clamd.host'),
                (int) config('documents.clamd.port'),
                (int) config('documents.clamd.connect_timeout'),
                (int) config('documents.clamd.read_timeout'),
            ),
            'eicar_dev' => new EicarDevScanner,
            default => throw new InvalidArgumentException('Unknown documents.scanner: '.var_export(config('documents.scanner'), true)),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password policy (spec 8.1). No ->uncompromised(): it calls an external
        // service (Have I Been Pwned) that the server may not be able to reach.
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());

        // Audit auth events. Registered here once; see AuthEventAuditor for why it
        // is not in app/Listeners.
        Event::subscribe(AuthEventAuditor::class);

        // MFA-1: audit the two-factor challenge; refuse to boot with MFA off outside local.
        Event::subscribe(MfaEventAuditor::class);
        MfaEnforcement::assertAllowed((string) $this->app->environment(), MfaEnforcement::enforced());

        // NOTIF-1: the one place emails are hooked in (queued, after commit).
        Event::subscribe(NotificationSubscriber::class);

        // SEC-1: the named rate limiters (login, password-reset, activation, create-request, uploads).
        RateLimits::register();

        // Named CasePolicy, not ProcurementCasePolicy, so auto-discovery would miss it.
        Gate::policy(ProcurementCase::class, CasePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(User::class, UserPolicy::class); // ADMIN-1: Admin > Users
    }
}
