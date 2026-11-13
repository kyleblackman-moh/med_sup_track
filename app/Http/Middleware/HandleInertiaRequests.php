<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\ProcurementCase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

/**
 * Shared Inertia props.
 *
 * SERVER-SIDE-FIRST (spec 15.4): `auth.can` is the user's permission names and is
 * the ONLY thing React may use to decide what to show or hide. React must never
 * check role names, user_type or anything else to make access decisions, and
 * hiding a control is not security: every endpoint authorises independently on
 * the server. Role names and models are deliberately not sent to the browser.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'user_type' => $user->user_type,
                ] : null,
                'can' => $user
                    ? $user->getAllPermissions()->pluck('name')->sort()->values()->all()
                    : [],
            ],
            // Feature flags for navigation (show/hide only; routes authorise).
            'can' => [
                'requests' => $user?->can(Permission::CaseSubmit->value) ?? false,
                // Same policy as the /cases routes.
                'cases' => $user !== null && Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }
}
