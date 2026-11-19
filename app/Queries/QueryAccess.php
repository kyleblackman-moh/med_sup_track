<?php

namespace App\Queries;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\QueryStatus;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * OVERNIGHT-2 (D2): who may see and do what on a query. Every rule starts from
 * the case: a user who cannot see the case (CasePolicy::view: org scope for
 * vendors, assignment or case.view_all for staff) sees no query on it.
 *
 * - The CMO's desk: holders of query.forward. Sees every query on a visible
 *   case, including held messages; forwards held messages; may close; reads
 *   direct queries without taking part.
 * - A side: the user raised it, or holds the role it was raised by (raiser
 *   side) or to (recipient side). Roles are matched against the route data on
 *   the query, never named in code.
 * - Held (via-CMO, not yet forwarded) messages: only their author and the
 *   CMO's desk see them.
 * - Vendors: external messages only, and only once delivered.
 */
final class QueryAccess
{
    public const RAISER = 'raiser';

    public const RECIPIENT = 'recipient';

    /** @var array<string, bool> role name => does it hold case.submit (a vendor role) */
    private static array $vendorRoles = [];

    public static function isCmoDesk(User $user): bool
    {
        return ! $user->isVendor() && $user->can(Permission::QueryForward->value);
    }

    /**
     * A role whose holders submit cases is a vendor role: messages to or from it
     * are external. Decided by permission (case.submit), not by role name.
     */
    public static function isVendorRole(string $role): bool
    {
        return self::$vendorRoles[$role] ??= (bool) RoleModel::query()->where('name', $role)->first()?->hasPermissionTo(Permission::CaseSubmit->value);
    }

    public static function forgetVendorRoles(): void
    {
        self::$vendorRoles = [];
    }

    public static function canSeeCase(User $user, ProcurementCase $case): bool
    {
        return Gate::forUser($user)->allows('view', $case);
    }

    /** Which side of the query $user is on, or null. */
    public static function side(User $user, CaseQuery $query): ?string
    {
        if ((int) $query->raised_by_user_id === (int) $user->getKey()) {
            return self::RAISER;
        }

        $roles = $user->getRoleNames()->all();

        return match (true) {
            in_array($query->raised_by_role, $roles, true) => self::RAISER,
            in_array($query->raised_to_role, $roles, true) => self::RECIPIENT,
            default => null,
        };
    }

    public static function canSeeMessage(User $user, ProcurementCase $case, CaseQuery $query, QueryMessage $message): bool
    {
        if (! self::canSeeCase($user, $case)) {
            return false;
        }

        if ($user->isVendor()) {
            return $message->isExternal() && ! $message->isHeld() && self::side($user, $query) !== null;
        }

        if (self::isCmoDesk($user) || (int) $message->author_id === (int) $user->getKey()) {
            return true;
        }

        return ! $message->isHeld() && self::side($user, $query) !== null;
    }

    /**
     * The messages of $query that $user may see, oldest first.
     *
     * @return Collection<int, QueryMessage>
     */
    public static function visibleMessages(User $user, ProcurementCase $case, CaseQuery $query): Collection
    {
        return $query->messages->filter(fn (QueryMessage $m) => self::canSeeMessage($user, $case, $query, $m))->values();
    }

    /**
     * The queries on $case that $user may see (at least one visible message), newest first.
     *
     * @return Collection<int, CaseQuery>
     */
    public static function visibleQueries(User $user, ProcurementCase $case): Collection
    {
        if (! self::canSeeCase($user, $case)) {
            return collect();
        }

        return CaseQuery::withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            ->with(['messages.author:id,name', 'raisedBy:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CaseQuery $q) => self::visibleMessages($user, $case, $q)->isNotEmpty())
            ->values();
    }

    /** Is the case still open for query work? (Not a draft, not closed, withdrawn or rejected.) */
    public static function caseOpen(ProcurementCase $case): bool
    {
        return $case->status !== CaseStatus::DRAFT && ! $case->status->isTerminal();
    }

    /**
     * The routes $user may raise a query on for $case: the route table by role,
     * the case visible and open, and a route to a vendor role also needs
     * query.raise_to_vendor.
     *
     * @return Collection<int, QueryRoute>
     */
    public static function raisableRoutes(User $user, ProcurementCase $case): Collection
    {
        if ($user->isVendor() || ! self::caseOpen($case) || ! self::canSeeCase($user, $case)) {
            return collect();
        }

        return QueryRoutes::forRaiser($user)
            ->filter(fn (QueryRoute $r) => ! self::isVendorRole($r->to_role) || $user->can(Permission::QueryRaiseToVendor->value))
            ->values();
    }

    /** May $user add a message (answer or follow-up) to $query now? */
    public static function canReply(User $user, ProcurementCase $case, CaseQuery $query): bool
    {
        return $query->status !== QueryStatus::CLOSED
            && self::caseOpen($case)
            && self::side($user, $query) !== null
            && self::visibleMessages($user, $case, $query)->isNotEmpty();
    }

    public static function canForward(User $user, ProcurementCase $case, CaseQuery $query, QueryMessage $message): bool
    {
        return $message->isHeld()
            && $query->status !== QueryStatus::CLOSED
            && self::caseOpen($case)
            && self::isCmoDesk($user)
            && self::canSeeCase($user, $case);
    }

    /** Either side (staff) or the CMO's desk; vendors reply only. */
    public static function canClose(User $user, ProcurementCase $case, CaseQuery $query): bool
    {
        return ! $user->isVendor()
            && $query->status !== QueryStatus::CLOSED
            && self::canSeeCase($user, $case)
            && (self::isCmoDesk($user) || (self::side($user, $query) !== null && self::visibleMessages($user, $case, $query)->isNotEmpty()));
    }
}
