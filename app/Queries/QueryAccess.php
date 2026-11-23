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
use App\Notifications\NotificationRecipients;
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
 * - WORKFLOW-2: the officer the CMO named when forwarding a message is a party
 *   to that query (the side the message went to), even when not assigned to
 *   the case. That reaches the query only: every other part of the case keeps
 *   the assignment rules (CasePolicy::view).
 * - WORKFLOW-2: a direct route to a staff desk is offered only when someone on
 *   that desk can see the case; a via-CMO forward to a desk with nobody on the
 *   case needs a named officer. A query never goes to nobody.
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

    /**
     * WORKFLOW-2: the route binding for the staff query actions ({queryCase}).
     * The case if $user can see it, or was named on a forwarded message of one
     * of its queries; null (404) otherwise, before any middleware runs, the
     * same answer the scoped case binding gives.
     */
    public static function caseForQueryWork(string $id, ?User $user): ?ProcurementCase
    {
        $case = $user === null ? null : ProcurementCase::withoutGlobalScopes()->find($id);
        if ($case === null) {
            return null;
        }

        if (self::canSeeCase($user, $case)) {
            return $case;
        }

        $named = ! $user->isVendor() && $case->status !== CaseStatus::DRAFT && QueryMessage::query()
            ->where('forwarded_to_user_id', $user->getKey())->whereNotNull('forwarded_at')
            ->whereIn('query_id', CaseQuery::withoutGlobalScopes()->select('id')->where('case_id', $case->getKey()))
            ->exists();

        return $named ? $case : null;
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
            default => self::namedSide($user, $query),
        };
    }

    /**
     * WORKFLOW-2: the side of $query a forwarded message named $user on, or
     * null. Staff only: a vendor is never named.
     */
    public static function namedSide(User $user, CaseQuery $query): ?string
    {
        if ($user->isVendor()) {
            return null;
        }

        $named = $query->messages->first(fn (QueryMessage $m) => $m->forwarded_at !== null
            && $m->forwarded_to_user_id !== null
            && (int) $m->forwarded_to_user_id === (int) $user->getKey());

        if ($named === null) {
            return null;
        }

        return $named->recipient_role === $query->raised_to_role ? self::RECIPIENT : self::RAISER;
    }

    /**
     * WORKFLOW-2: may $user reach $query at all? Anyone who can see the case,
     * or a named officer (staff, a submitted case) who cannot.
     */
    private static function reaches(User $user, ProcurementCase $case, CaseQuery $query): bool
    {
        if (self::canSeeCase($user, $case)) {
            return true;
        }

        return $case->status !== CaseStatus::DRAFT
            && (string) $query->case_id === (string) $case->getKey()
            && self::namedSide($user, $query) !== null;
    }

    /**
     * WORKFLOW-2: the staff on $role's desk who can see $case (assigned on that
     * track, or case.view_all). The role name is route data, never a check.
     *
     * @return Collection<int, User>
     */
    public static function deskOnCase(string $role, ProcurementCase $case): Collection
    {
        return app(NotificationRecipients::class)->roleOnCase($role, $case);
    }

    public static function canSeeMessage(User $user, ProcurementCase $case, CaseQuery $query, QueryMessage $message): bool
    {
        if (! self::reaches($user, $case, $query)) {
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
        $whole = self::canSeeCase($user, $case);
        if (! $whole && ($user->isVendor() || $case->status === CaseStatus::DRAFT)) {
            return collect();
        }

        return CaseQuery::withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            // WORKFLOW-2: without the case, only the queries this officer was named on.
            ->when(! $whole, fn ($q) => $q->whereIn('id', QueryMessage::query()->select('query_id')
                ->where('forwarded_to_user_id', $user->getKey())->whereNotNull('forwarded_at')))
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

        $open = self::openRouteKeys($case);

        return QueryRoutes::forRaiser($user)
            ->filter(fn (QueryRoute $r) => ! self::isVendorRole($r->to_role) || $user->can(Permission::QueryRaiseToVendor->value))
            // WORKFLOW-2: a direct route to a staff desk only when someone there can see the case.
            ->filter(fn (QueryRoute $r) => $r->via_cmo || self::isVendorRole($r->to_role) || self::deskOnCase($r->to_role, $case)->isNotEmpty())
            // One query at a time per route on a case (Kyle, 2026-11-23): while one is still
            // open (not closed or cancelled), follow-ups are replies in that thread, not a new query.
            ->reject(fn (QueryRoute $r) => isset($open[self::routeKey($r->from_role, $r->to_role, $r->query_type->value)]))
            ->values();
    }

    /** @return array<string, true> the routes (from, to, type) with a query not yet ended on $case */
    private static function openRouteKeys(ProcurementCase $case): array
    {
        return CaseQuery::query()
            ->where('case_id', $case->getKey())
            ->whereNotIn('status', QueryStatus::endedValues())
            ->get(['raised_by_role', 'raised_to_role', 'type'])
            ->mapWithKeys(fn (CaseQuery $q) => [self::routeKey($q->raised_by_role, $q->raised_to_role, $q->type->value) => true])
            ->all();
    }

    private static function routeKey(string $from, string $to, string $type): string
    {
        return $from.'|'.$to.'|'.$type;
    }

    /** May $user add a message (answer or follow-up) to $query now? */
    public static function canReply(User $user, ProcurementCase $case, CaseQuery $query): bool
    {
        return ! $query->status->isEnded()
            && self::caseOpen($case)
            && self::side($user, $query) !== null
            && self::visibleMessages($user, $case, $query)->isNotEmpty();
    }

    public static function canForward(User $user, ProcurementCase $case, CaseQuery $query, QueryMessage $message): bool
    {
        return $message->isHeld()
            && ! $query->status->isEnded()
            && self::caseOpen($case)
            && self::isCmoDesk($user)
            && self::canSeeCase($user, $case);
    }

    /** D4: does $query wait on $user's answer (recipient side, delivered, open)? Card, tab and queue use this. */
    public static function needsAnswer(User $user, ProcurementCase $case, CaseQuery $query): bool
    {
        return $query->status === QueryStatus::OPEN
            && self::side($user, $query) === self::RECIPIENT
            && self::canReply($user, $case, $query);
    }

    /** D4: the held message of $query that $user (the CMO's desk) may forward now, if any. */
    public static function forwardable(User $user, ProcurementCase $case, CaseQuery $query): ?QueryMessage
    {
        return $query->messages->first(fn (QueryMessage $m) => self::canForward($user, $case, $query, $m));
    }

    /** Either side (staff) or the CMO's desk; vendors reply only. */
    public static function canClose(User $user, ProcurementCase $case, CaseQuery $query): bool
    {
        return ! $user->isVendor()
            && ! $query->status->isEnded()
            && self::canSeeCase($user, $case)
            && (self::isCmoDesk($user) || (self::side($user, $query) !== null && self::visibleMessages($user, $case, $query)->isNotEmpty()));
    }
}
