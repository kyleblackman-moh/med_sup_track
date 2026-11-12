<?php

namespace App\Providers;

use App\Documents\Scanning\ClamdScanner;
use App\Documents\Scanning\EicarDevScanner;
use App\Documents\Scanning\Scanner;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Policies\CasePolicy;
use App\Policies\DocumentPolicy;
use App\Support\AuthEventAuditor;
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

        // Named CasePolicy, not ProcurementCasePolicy, so auto-discovery would miss it.
        Gate::policy(ProcurementCase::class, CasePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
