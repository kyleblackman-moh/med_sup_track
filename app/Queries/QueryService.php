<?php

namespace App\Queries;

use App\Enums\AuditAction;
use App\Enums\QueryStatus;
use App\Enums\UserType;
use App\Events\QueryMessageDelivered;
use App\Events\QueryMessageHeld;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OVERNIGHT-2 (D2, D3): raise, reply, forward and close. Each re-checks
 * QueryAccess inside its transaction (the controller's checks are for the
 * screen). A query is an overlay on the case: nothing here changes the case's
 * status, and an open query blocks no transition.
 *
 * Messages are append-only. A reply travels by the reverse of the route the
 * query came by; on a via-CMO route every message is held until the CMO
 * forwards it. Audit rows carry ids, counts and fixed codes only, never text.
 */
final class QueryService
{
    public const BODY_MIN = 2;

    public const BODY_MAX = 2000;

    public function raise(ProcurementCase $case, User $user, int $routeId, string $body): CaseQuery
    {
        return DB::transaction(function () use ($case, $user, $routeId, $body) {
            $case = $this->lockCase($case);

            /** @var QueryRoute|null $route */
            $route = QueryAccess::raisableRoutes($user, $case)->firstWhere('id', $routeId);
            if ($route === null) {
                throw ValidationException::withMessages(['route' => 'Choose who the query goes to from the list.']);
            }

            $query = (new CaseQuery)->forceFill([
                'case_id' => $case->getKey(),
                'type' => $route->query_type,
                'subject' => $route->query_type->label(),
                'raised_by_role' => $route->from_role,
                'raised_by_user_id' => $user->getKey(),
                'raised_to_role' => $route->to_role,
                'via_cmo' => $route->via_cmo,
                'status' => $route->via_cmo ? QueryStatus::AWAITING_FORWARD : QueryStatus::OPEN,
            ]);
            $query->save();

            $message = $this->append($query, $user, $route->from_role, $route->to_role, $body);

            Audit::record(AuditAction::QueryRaised, ['type' => 'query', 'id' => $query->getKey()], $case->getKey(), [
                'route_id' => $route->getKey(),
                'query_type' => $route->query_type->value,
                'via_cmo' => $route->via_cmo,
                'message_id' => $message->getKey(),
                'body_length' => mb_strlen($body),
            ], $user);

            $this->announce($case, $query, $message);

            return $query;
        });
    }

    /** An answer or a follow-up from either side, addressed to the other side. */
    public function reply(ProcurementCase $case, CaseQuery $query, User $user, string $body): QueryMessage
    {
        return DB::transaction(function () use ($case, $query, $user, $body) {
            $case = $this->lockCase($case);
            $query = $this->lockQuery($query);

            if (! QueryAccess::canReply($user, $case, $query)) {
                throw ValidationException::withMessages(['reply' => 'You cannot reply to this query now.']);
            }

            $side = QueryAccess::side($user, $query);
            [$from, $to] = $side === QueryAccess::RAISER
                ? [$query->raised_by_role, $query->raised_to_role]
                : [$query->raised_to_role, $query->raised_by_role];

            $message = $this->append($query, $user, $from, $to, $body);
            $this->settle($query, $user);

            Audit::record(AuditAction::QueryMessagePosted, ['type' => 'query', 'id' => $query->getKey()], $case->getKey(), [
                'message_id' => $message->getKey(),
                'side' => $side,
                'held' => $message->isHeld(),
                'body_length' => mb_strlen($body),
            ], $user);

            $this->announce($case, $query, $message);

            return $message;
        });
    }

    /**
     * WORKFLOW-2: $officerId names the officer the message goes to. It may be
     * left out when someone on the receiving desk can already see the case;
     * otherwise it is required, so a forward never reaches nobody.
     */
    public function forward(ProcurementCase $case, CaseQuery $query, QueryMessage $message, User $user, ?int $officerId = null): void
    {
        DB::transaction(function () use ($case, $query, $message, $user, $officerId) {
            $case = $this->lockCase($case);
            $query = $this->lockQuery($query);
            $message = QueryMessage::query()->whereKey($message->getKey())->where('query_id', $query->getKey())->lockForUpdate()->firstOrFail();

            if (! QueryAccess::canForward($user, $case, $query, $message)) {
                throw ValidationException::withMessages(['forward' => 'This message cannot be forwarded now. It may already have been forwarded.']);
            }

            $officer = $this->namedOfficer($case, $query, $message, $officerId);

            $message->forceFill([
                'forwarded_by_cmo_id' => $user->getKey(),
                'forwarded_to_user_id' => $officer?->getKey(),
                'forwarded_at' => CarbonImmutable::now('UTC'),
            ])->save();
            $this->settle($query, $user);

            Audit::record(AuditAction::QueryForwarded, ['type' => 'query', 'id' => $query->getKey()], $case->getKey(), [
                'message_id' => $message->getKey(),
                'named_officer_id' => $officer?->getKey(),
            ], $user);

            QueryMessageDelivered::dispatch($case->getKey(), $query->getKey(), $message->getKey());
        });
    }

    public function close(ProcurementCase $case, CaseQuery $query, User $user): void
    {
        DB::transaction(function () use ($case, $query, $user) {
            $case = $this->lockCase($case);
            $query = $this->lockQuery($query);

            if (! QueryAccess::canClose($user, $case, $query)) {
                throw ValidationException::withMessages(['close' => 'You cannot close this query.']);
            }

            $query->forceFill([
                'status' => QueryStatus::CLOSED,
                'resolved_at' => CarbonImmutable::now('UTC'),
                'resolved_by' => $user->getKey(),
            ])->save();

            Audit::record(AuditAction::QueryClosed, ['type' => 'query', 'id' => $query->getKey()], $case->getKey(), [
                'messages' => $query->messages()->count(),
            ], $user);
        });
    }

    /**
     * The query's status from its messages: a held message means Awaiting
     * Forward; otherwise the last delivered message decides (from the
     * recipient side: Answered; from the raiser side: Open).
     */
    public static function statusFor(CaseQuery $query): QueryStatus
    {
        if ($query->status === QueryStatus::CLOSED) {
            return QueryStatus::CLOSED;
        }

        $messages = $query->messages()->get();
        if ($messages->contains(fn (QueryMessage $m) => $m->isHeld())) {
            return QueryStatus::AWAITING_FORWARD;
        }

        $last = $messages->last();

        return $last !== null && $last->author_role === $query->raised_to_role && $last->author_role !== $query->raised_by_role
            ? QueryStatus::ANSWERED
            : QueryStatus::OPEN;
    }

    private function settle(CaseQuery $query, User $user): void
    {
        $status = self::statusFor($query);
        $query->forceFill([
            'status' => $status,
            'answered_at' => $status === QueryStatus::ANSWERED ? CarbonImmutable::now('UTC') : $query->answered_at,
        ])->save();
    }

    /**
     * WORKFLOW-2: the officer named on a forward, checked: an active staff
     * account holding the role the message is addressed to (route data). A
     * message to the raiser's side or to a vendor needs no name (the raiser
     * always sees their thread; vendors are never named). With no name, the
     * receiving desk must already have someone who can see the case.
     */
    private function namedOfficer(ProcurementCase $case, CaseQuery $query, QueryMessage $message, ?int $officerId): ?User
    {
        $toRecipient = $message->recipient_role === $query->raised_to_role && $message->recipient_role !== $query->raised_by_role;
        $office = QueryWording::office($message->recipient_role, $case);

        if ($officerId === null) {
            if ($toRecipient && ! QueryAccess::isVendorRole($message->recipient_role) && QueryAccess::deskOnCase($message->recipient_role, $case)->isEmpty()) {
                throw ValidationException::withMessages(['officer_id' => "Nobody in {$office} is assigned to this case. Choose the officer to forward it to."]);
            }

            return null;
        }

        if (! $toRecipient || QueryAccess::isVendorRole($message->recipient_role)) {
            throw ValidationException::withMessages(['officer_id' => 'This message goes back to the person who raised the query. Forward it without naming an officer.']);
        }

        $officer = User::query()->whereKey($officerId)
            ->where('status', User::STATUS_ACTIVE)
            ->where('user_type', UserType::Staff->value)
            ->first();

        if ($officer === null || ! $officer->hasRole($message->recipient_role)) {
            throw ValidationException::withMessages(['officer_id' => "Choose an officer in {$office}."]);
        }

        return $officer;
    }

    private function append(CaseQuery $query, User $user, string $from, string $to, string $body): QueryMessage
    {
        $body = trim($body);
        if (mb_strlen($body) < self::BODY_MIN || mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => 'Enter a message of '.self::BODY_MIN.' to '.self::BODY_MAX.' characters.']);
        }

        $external = QueryAccess::isVendorRole($from) || QueryAccess::isVendorRole($to);

        $message = (new QueryMessage)->forceFill([
            'query_id' => $query->getKey(),
            'author_id' => $user->getKey(),
            'author_role' => $from,
            'recipient_role' => $to,
            'body' => $body,
            'visibility' => $external ? QueryMessage::VISIBILITY_EXTERNAL : QueryMessage::VISIBILITY_INTERNAL,
            'requires_forward' => $query->via_cmo,
        ]);
        $message->save();

        return $message;
    }

    /** Held: the CMO's desk is asked to forward. Delivered: the other side hears now. */
    private function announce(ProcurementCase $case, CaseQuery $query, QueryMessage $message): void
    {
        $message->isHeld()
            ? QueryMessageHeld::dispatch($case->getKey(), $query->getKey(), $message->getKey())
            : QueryMessageDelivered::dispatch($case->getKey(), $query->getKey(), $message->getKey());
    }

    private function lockCase(ProcurementCase $case): ProcurementCase
    {
        return ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockQuery(CaseQuery $query): CaseQuery
    {
        return CaseQuery::withoutGlobalScopes()->whereKey($query->getKey())->lockForUpdate()->firstOrFail();
    }
}
