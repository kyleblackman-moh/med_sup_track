<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\DashboardController;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\Bell;
use App\Queries\QueryInbox;
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
                    // V2-4: the line under the name in the sidebar user card. Display only.
                    'subtitle' => self::userSubtitle($user),
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
            /*
             * Sidebar sections, separate from `can` because a page prop named `can`
             * (the staff case page has one) replaces the shared `can` entirely.
             *
             * cmo_office: file.prepare stands in for "the CMO's office". The flag
             * only shows the Office of the CMO links; the /cases routes and
             * CasePolicy still guard every page and action behind them.
             *
             * section: the sidebar item to show as active, by URL prefix, so
             * /requests/{id}/edit activates Requests and /cases/{id} the cases
             * item. Display only.
             */
            'nav' => [
                // Kyle, 2026-11-24: vendors' home is Requests (/dashboard sends them there), so no Dashboard item.
                'dashboard' => $user !== null && ! DashboardController::requestsHome($user),
                'cmo_office' => $user !== null && ! $user->isVendor() && $user->can(Permission::FilePrepare->value),
                // ADMIN-1: the Administration group (Users). Same check as the /admin/users routes (UserPolicy::viewAny).
                'users' => $user !== null && Gate::forUser($user)->allows('viewAny', User::class),
                // IT-ADMIN: the IT health page (/admin) and the audit log. Show or hide only; the routes authorise.
                'it_health' => $user !== null && $user->can(Permission::AdminAccess->value) && $user->can(Permission::SystemHealthView->value),
                'audit' => $user !== null && $user->can(Permission::AuditView->value),
                // IT-ADMIN: where the Audit item goes: the console's copy for console users, /audit for the CMO and Legal.
                'audit_url' => self::auditUrl($user),
                // ANALYTICS: the Analytics page. Same checks as the /analytics route.
                'analytics' => $user !== null && ! $user->isVendor()
                    && Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)
                    && $user->can(Permission::CaseViewAll->value)
                    && $user->can(Permission::AnalyticsView->value),
                // 2026-11-24: the Cases and File Tracking items. Same checks as their routes.
                'cases' => $user !== null && Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)
                    && $user->can(Permission::CasesList->value),
                'tracking' => $user !== null && Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)
                    && $user->can(Permission::TrackingView->value),
                // QUERIES-1: the Queries item, for users who submit requests or see staff cases.
                'queries' => self::queriesNav($user),
                'section' => self::navSection($request),
            ],
            /*
             * V2-4: the Ministry crest for the sidebar header, only once Kyle has
             * supplied public/brand/coat-of-arms.png; null until then (the
             * sidebar shows the shield-plus tile). A top-level prop, never under `can`.
             */
            'brand' => [
                'logo' => self::brandLogo(),
            ],
            // NOTIF-2: the bell's badge. A closure, so it is counted at render time
            // (after a case page has marked its own notifications read).
            'unreadNotificationsCount' => fn () => $user === null ? 0 : Bell::unreadCount($user),
            // QUERIES-1: the sidebar Queries badge (queries waiting on this user).
            'openQueriesCount' => fn () => self::queriesNav($user) ? QueryInbox::count($user) : 0,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
            ],
        ]);
    }

    /**
     * V2-4 (design.md section 6): a vendor's organisation name; for staff, the
     * display names of their roles (Role::label). Display only: React shows it
     * as text and never decides anything from it.
     */
    public static function userSubtitle(User $user): ?string
    {
        if ($user->isVendor()) {
            return $user->organisation?->name;
        }

        $labels = $user->getRoleNames()
            ->map(fn (string $name) => Role::tryFrom($name)?->label())
            ->filter()
            ->sort()
            ->values();

        return $labels->isEmpty() ? null : $labels->implode(', ');
    }

    /** The path of the crest under public/. */
    public const BRAND_LOGO = 'brand/coat-of-arms.png';

    /** The crest's public URL when the file exists, else null. Never drawn or recreated (design.md section 11). */
    public static function brandLogo(): ?string
    {
        return is_file(public_path(self::BRAND_LOGO)) ? asset(self::BRAND_LOGO) : null;
    }

    /** The sidebar sections, by the first segment of the URL path. */
    private const NAV_SECTIONS = ['dashboard', 'analytics', 'requests', 'cases', 'tracking', 'queries', 'admin', 'audit'];

    /** QUERIES-1: show the Queries item (display only; the route authorises itself). */
    public static function queriesNav(?User $user): bool
    {
        return $user !== null && ($user->can(Permission::CaseSubmit->value)
            || Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class));
    }

    /** The section the current URL falls under (the path itself or anything below it), else null. */
    /** IT-ADMIN: the audit log URL for the sidebar, or null without audit.view. Display only; the routes authorise. */
    public static function auditUrl(?User $user): ?string
    {
        if ($user === null || ! $user->can(Permission::AuditView->value)) {
            return null;
        }

        return $user->can(Permission::AdminAccess->value)
            ? route('admin.audit.index', absolute: false)
            : route('audit.index', absolute: false);
    }

    public static function navSection(Request $request): ?string
    {
        $first = explode('/', trim($request->path(), '/'))[0];

        return in_array($first, self::NAV_SECTIONS, true) ? $first : null;
    }
}
