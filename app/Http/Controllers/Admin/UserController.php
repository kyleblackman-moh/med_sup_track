<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\MfaReset;
use App\Accounts\UserAdministration;
use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserView;
use App\Models\Organisation;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\AccessDenials;
use App\Support\SqlLike;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADMIN-1, IT-ADMIN: Admin > Users. Every action authorises itself through
 * UserPolicy. The console routes sit behind EnsureAdminConsole (404 without
 * admin.access); create and store are outside it, so the CMO can invite
 * vendor users (users.invite_vendor), and answer 404 to anyone who may create
 * neither kind of account. Every change needs a recently confirmed password
 * (ConfirmRecentPassword) and, for role, disable and enable, a reason.
 */
class UserController extends Controller
{
    private const PER_PAGE = 25;

    public const REASON_MAX = 1000;

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize('viewAny', User::class);

        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(['staff', 'vendor'])],
            'org' => ['nullable', 'string', 'max:26', Rule::exists('organisations', 'id')],
            'role' => ['nullable', 'string', Rule::in(array_map(fn (Role $r) => $r->value, Role::cases()))],
            'status' => ['nullable', 'string', Rule::in(UserView::statusFilterValues())],
            'mfa' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'q.min' => 'Enter at least 2 characters of the name or email.',
        ]);

        // A bad filter is reported, never silently ignored (as the case register).
        if ($validator->fails()) {
            return to_route('admin.users.index')->withErrors($validator);
        }

        $filters = $validator->validated();
        $now = now();

        $page = User::query()
            ->with(['organisation:id,name', 'roles:id,name'])
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw(...SqlLike::contains('users.name', $v))
                ->orWhereRaw(...SqlLike::contains('users.email', $v))))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('users.user_type', $v))
            ->when($filters['org'] ?? null, fn ($q, $v) => $q->where('users.org_id', $v))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->role($v))
            ->when($filters['status'] ?? null, fn ($q, $v) => match ($v) {
                UserView::FILTER_LOCKED => $q->where('users.status', User::STATUS_ACTIVE)->where('users.locked_until', '>', $now),
                // "Active" means able to sign in now: not inside a lockout window.
                User::STATUS_ACTIVE => $q->where('users.status', User::STATUS_ACTIVE)
                    ->where(fn ($w) => $w->whereNull('users.locked_until')->orWhere('users.locked_until', '<=', $now)),
                default => $q->where('users.status', $v),
            })
            ->when($filters['mfa'] ?? null, fn ($q, $v) => $v === 'yes'
                ? $q->whereNotNull('users.two_factor_confirmed_at')
                : $q->whereNull('users.two_factor_confirmed_at'))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/users/index', [
            'users' => [
                'data' => $page->getCollection()->map(fn (User $u) => UserView::row($u))->values()->all(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filters' => [
                'q' => $filters['q'] ?? '',
                'type' => $filters['type'] ?? '',
                'org' => $filters['org'] ?? '',
                'role' => $filters['role'] ?? '',
                'status' => $filters['status'] ?? '',
                'mfa' => $filters['mfa'] ?? '',
            ],
            'options' => [
                'types' => UserView::typeOptions(),
                'organisations' => self::organisationOptions(),
                'roles' => UserView::roleOptions(),
                'statuses' => UserView::statusOptions(),
                'mfa' => UserView::mfaOptions(),
            ],
            // Page-level hints only; not 'can' (that would replace the shared nav flags).
            'abilities' => [
                'create' => $request->user()->can('create', User::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeCreateOr404($request->user());

        return Inertia::render('admin/users/create', [
            'options' => [
                'roles' => self::grantableRoles($request->user()),
                'organisations' => $request->user()->can('inviteVendor', User::class) ? self::vendorOrganisationOptions() : [],
            ],
        ]);
    }

    /**
     * A staff account (users.create) or a vendor user (users.invite_vendor):
     * name, email, role and, for a vendor role, the vendor organisation. The
     * account waits for activation; the person gets a single-use link.
     */
    public function store(Request $request, UserAdministration $admin): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeCreateOr404($actor);

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => Str::lower(trim((string) $request->input('email'))),
            'organisation' => $request->filled('organisation') ? (string) $request->input('organisation') : null,
            ...self::trimmedDetails($request),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::in(array_column(self::grantableRoles($actor), 'value'))],
            'organisation' => ['nullable', 'string', 'max:26'],
            ...self::detailRules(),
        ], [
            'email.unique' => 'A user with this email already exists.',
            'role.required' => 'Choose a role.',
            'role.in' => 'Choose a role from the list.',
        ]);

        [$user, $sent] = $admin->create($actor, $data['name'], $data['email'], Role::from($data['role']), $data['organisation'], $data['job_title'], $data['division']);

        // The CMO (no access to the console) goes back to the form to invite the next one.
        $to = $actor->can('view', $user) ? to_route('admin.users.show', $user) : to_route('admin.users.create');

        return $to->with(...self::activationFlash($sent, 'User created.'));
    }

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        $user->load(['organisation:id,name', 'roles:id,name']);
        $actor = $request->user();

        return Inertia::render('admin/users/show', [
            'user' => UserView::detail($user),
            // Roles of the same kind as the account (vendor or staff), never system_admin.
            'role_options' => array_values(array_filter(UserView::roleOptions(grantable: true), fn (array $r) => $r['vendor'] === $user->isVendor())),
            // Page-level hints only (not 'can'); every action authorises itself.
            'abilities' => [
                'change_role' => $actor->can('changeRole', $user),
                'update_details' => $actor->can('updateDetails', $user),
                'disable' => $user->status !== User::STATUS_DISABLED && $actor->can('disable', $user),
                'enable' => $user->status === User::STATUS_DISABLED && $actor->can('enable', $user),
                'resend_activation' => $user->isPendingActivation() && $actor->can('resendActivation', $user),
                'mfa_reset' => $user->hasMfa() && MfaReset::allows($actor, $user),
            ],
            'reason_max' => self::REASON_MAX,
        ]);
    }

    /** Backlog, 2026-11-25: job title and division (the letter's sign-off lines). Both optional. */
    public function updateDetails(Request $request, User $user, UserAdministration $admin): RedirectResponse
    {
        Gate::authorize('updateDetails', $user);

        $request->merge(self::trimmedDetails($request));
        $data = $request->validate(self::detailRules());

        $admin->updateDetails($request->user(), $user, $data['job_title'], $data['division']);

        return to_route('admin.users.show', $user)->with('success', 'Details saved.');
    }

    /** @return array{job_title: string|null, division: string|null} blank is null */
    private static function trimmedDetails(Request $request): array
    {
        $clean = fn (string $key) => trim((string) $request->input($key)) === '' ? null : trim((string) $request->input($key));

        return ['job_title' => $clean('job_title'), 'division' => $clean('division')];
    }

    /** @return array<string, list<string>> */
    private static function detailRules(): array
    {
        return [
            'job_title' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** Change the role (same kind of account; never system_admin). A reason is required. */
    public function updateRole(Request $request, User $user, UserAdministration $admin): RedirectResponse
    {
        Gate::authorize('changeRole', $user);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(array_column(UserView::roleOptions(grantable: true), 'value'))],
            ...self::reasonRule(),
        ], [
            'role.required' => 'Choose a role.',
            'role.in' => 'Choose a role from the list.',
            ...self::reasonMessages(),
        ]);

        $admin->changeRole($request->user(), $user, Role::from($data['role']), $data['reason']);

        return to_route('admin.users.show', $user)->with('success', 'Role saved. The user has been signed out and has the new role from their next sign-in.');
    }

    /** Block sign-in and end the user's sessions now. A reason is required. */
    public function disable(Request $request, User $user, UserAdministration $admin): RedirectResponse
    {
        Gate::authorize('disable', $user);

        $data = $request->validate(self::reasonRule(), self::reasonMessages());

        $admin->disable($request->user(), $user, $data['reason']);

        return to_route('admin.users.show', $user)->with('success', 'User disabled. They have been signed out and cannot sign in.');
    }

    public function enable(Request $request, User $user, UserAdministration $admin): RedirectResponse
    {
        Gate::authorize('enable', $user);

        $data = $request->validate(self::reasonRule(), self::reasonMessages());

        $admin->enable($request->user(), $user, $data['reason']);

        return to_route('admin.users.show', $user)->with('success', $user->fresh()->isPendingActivation()
            ? 'User enabled. They have not activated their account yet: send them a new activation link.'
            : 'User enabled. They can sign in again.');
    }

    /** Email a new activation link; the earlier one stops working. */
    public function resendActivation(Request $request, User $user, UserAdministration $admin): RedirectResponse
    {
        Gate::authorize('resendActivation', $user);

        $sent = $admin->resendActivation($request->user(), $user);

        return to_route('admin.users.show', $user)->with(...self::activationFlash($sent, 'Activation link sent.'));
    }

    /** @return array<string, list<string>> */
    public static function reasonRule(bool $required = true): array
    {
        return ['reason' => [$required ? 'required' : 'nullable', 'string', 'max:'.self::REASON_MAX]];
    }

    /** @return array<string, string> */
    public static function reasonMessages(): array
    {
        return [
            'reason.required' => 'Enter the reason.',
            'reason.max' => 'Keep the reason to '.self::REASON_MAX.' characters or fewer.',
        ];
    }

    /** 404 for anyone who may create neither kind of account (the area does not exist for them). */
    private function authorizeCreateOr404(User $actor): void
    {
        if (! $actor->can('create', User::class)) {
            AccessDenials::record(request(), 404, 'admin_console');
            abort(404);
        }
    }

    /** The roles this actor may give a new account (never system_admin). */
    private static function grantableRoles(User $actor): array
    {
        return array_values(array_filter(
            UserView::roleOptions(grantable: true),
            fn (array $r) => UserPolicy::mayGrant($actor, Role::from($r['value'])),
        ));
    }

    /**
     * The flash after an activation link was (or was not) sent. When the
     * server's mail goes to the log, the Admin is told so instead of being told it was emailed.
     *
     * @return array{0: string, 1: string}
     */
    private static function activationFlash(bool $sent, string $lead): array
    {
        if (! $sent) {
            return ['error', "{$lead} The email with the activation link could not be sent. Try Resend Activation Link."];
        }

        if (UserAdministration::mailIsNotDelivered()) {
            return ['info', "{$lead} Email is not set up on this server, so the activation link was written to the application log instead of being emailed."];
        }

        return ['success', "{$lead} An email with a link to activate the account has been sent."];
    }

    /** Active vendor organisations, by name (the create form). */
    private static function vendorOrganisationOptions(): array
    {
        return Organisation::query()
            ->where('type', OrganisationType::Vendor->value)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organisation $o) => ['value' => $o->id, 'label' => $o->name])
            ->all();
    }

    /** Every organisation, the MOH first, then vendors by name. */
    private static function organisationOptions(): array
    {
        return Organisation::query()
            ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [OrganisationType::Moh->value])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organisation $o) => ['value' => $o->id, 'label' => $o->name])
            ->all();
    }
}
