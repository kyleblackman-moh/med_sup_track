<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\UserAdministration;
use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserView;
use App\Models\Organisation;
use App\Models\User;
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
 * ADMIN-1: Admin > Users. Every action authorises itself through UserPolicy
 * (users.manage, plus user.create or user.reset_credentials where they apply).
 */
class UserController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize('viewAny', User::class);

        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'org' => ['nullable', 'string', 'max:26', Rule::exists('organisations', 'id')],
            'role' => ['nullable', 'string', Rule::in(array_map(fn (Role $r) => $r->value, Role::cases()))],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'q.min' => 'Enter at least 2 characters of the name or email.',
        ]);

        // A bad filter is reported, never silently ignored (as the case register).
        if ($validator->fails()) {
            return to_route('admin.users.index')->withErrors($validator);
        }

        $filters = $validator->validated();

        $page = User::query()
            ->with(['organisation:id,name', 'roles:id,name'])
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->whereRaw(...SqlLike::contains('users.name', $v))
                ->orWhereRaw(...SqlLike::contains('users.email', $v))))
            ->when($filters['org'] ?? null, fn ($q, $v) => $q->where('users.org_id', $v))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->role($v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('users.status', $v))
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
                'org' => $filters['org'] ?? '',
                'role' => $filters['role'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'options' => [
                'organisations' => self::organisationOptions(),
                'roles' => UserView::roleOptions(),
                'statuses' => UserView::statusOptions(),
            ],
            // Page-level hints only; not 'can' (that would replace the shared nav flags).
            'abilities' => [
                'create' => $request->user()->can('create', User::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/create', [
            'options' => [
                'roles' => UserView::roleOptions(),
                'organisations' => self::vendorOrganisationOptions(),
            ],
        ]);
    }

    /**
     * Step 2: name, email, role and (vendor roles only) the vendor
     * organisation. No password is shown or emailed: the person gets a link to
     * set their own (UserAdministration::create).
     */
    public function store(Request $request, UserAdministration $admin): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => Str::lower(trim((string) $request->input('email'))),
            'organisation' => $request->filled('organisation') ? (string) $request->input('organisation') : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::in(array_map(fn (Role $r) => $r->value, Role::cases()))],
            'organisation' => ['nullable', 'string', 'max:26'],
        ], [
            'email.unique' => 'A user with this email already exists.',
            'role.required' => 'Choose a role.',
            'role.in' => 'Choose a role from the list.',
        ]);

        [$user, $sent] = $admin->create($request->user(), $data['name'], $data['email'], Role::from($data['role']), $data['organisation']);

        return to_route('admin.users.show', $user)->with(...self::activationFlash($sent, 'User created.'));
    }

    public function show(User $user): Response
    {
        Gate::authorize('view', $user);

        $user->load(['organisation:id,name', 'roles:id,name']);

        return Inertia::render('admin/users/show', [
            'user' => UserView::detail($user),
        ]);
    }

    /**
     * The flash after a sign-in link was (or was not) sent. When the server's
     * mail goes to the log, the Admin is told so instead of being told it was emailed.
     *
     * @return array{0: string, 1: string}
     */
    private static function activationFlash(bool $sent, string $lead): array
    {
        if (! $sent) {
            return ['error', "{$lead} The email with the sign-in link could not be sent. Try Resend Sign-In Link."];
        }

        if (UserAdministration::mailIsNotDelivered()) {
            return ['info', "{$lead} Email is not set up on this server, so the sign-in link was written to the application log instead of being emailed."];
        }

        return ['success', "{$lead} An email with a link to set their password has been sent."];
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
