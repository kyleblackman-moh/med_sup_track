<?php

namespace App\Http\Controllers\Admin;

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

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        $user->load(['organisation:id,name', 'roles:id,name']);

        return Inertia::render('admin/users/show', [
            'user' => UserView::detail($user),
        ]);
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
