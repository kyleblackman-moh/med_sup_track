<?php

namespace App\Queries;

use App\Enums\QueryStatus;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\User;
use App\Workflow\StageWording;
use Carbon\CarbonInterface;

/**
 * OVERNIGHT-2 (D2, D3): the query DTOs. Every label, status and button text is
 * built here; React only renders. Staff see the messages QueryAccess allows
 * (held ones only for their author and the CMO's desk). Vendors get a
 * separate, smaller shape: external delivered messages only, no staff names,
 * no roles, no held or internal items.
 */
final class QueryView
{
    public const TIMEZONE = 'America/Port_of_Spain';

    /** @return array{queries: list<array<string, mixed>>, raise: array{routes: list<array{id: int, label: string}>}|null} */
    public static function forStaff(ProcurementCase $case, User $user): array
    {
        $case->loadMissing('organisation:id,name');

        $queries = QueryAccess::visibleQueries($user, $case)
            ->map(fn (CaseQuery $q) => self::staffQuery($case, $q, $user))
            ->values()->all();

        $routes = QueryAccess::raisableRoutes($user, $case)
            ->map(fn ($r) => ['id' => (int) $r->getKey(), 'label' => QueryWording::routeLabel($r, $case)])
            ->values()->all();

        return ['queries' => $queries, 'raise' => $routes === [] ? null : ['routes' => $routes]];
    }

    /** @return array<string, mixed> */
    private static function staffQuery(ProcurementCase $case, CaseQuery $query, User $user): array
    {
        $messages = QueryAccess::visibleMessages($user, $case, $query);
        $side = QueryAccess::side($user, $query);
        $from = QueryWording::office($query->raised_by_role, $case);
        $to = QueryWording::office($query->raised_to_role, $case);

        return [
            'id' => (string) $query->getKey(),
            'title' => $query->type->label().': '.$from.' to '.$to,
            'route_label' => $query->via_cmo ? 'Via the '.StageWording::CMO_OFFICE : 'Direct',
            'status_label' => $query->status->label(),
            'status_tone' => $query->status->tone(),
            'raised_by' => $query->raisedBy?->name,
            'raised_on' => self::day($query->created_at),
            'waiting_on' => self::waitingOn($case, $query),
            // The CMO's desk reading a query it takes no part in (for example a direct Technocrat to vendor query).
            'read_only' => $side === null,
            'can_reply' => QueryAccess::canReply($user, $case, $query),
            // D4: the same predicates the Next Step card and the work queue use.
            'needs_my_answer' => QueryAccess::needsAnswer($user, $case, $query),
            'needs_my_forward' => QueryAccess::forwardable($user, $case, $query) !== null,
            'reply_label' => $side === QueryAccess::RECIPIENT ? 'Answer Query' : 'Add a Message',
            'can_close' => QueryAccess::canClose($user, $case, $query),
            'messages' => $messages->map(fn (QueryMessage $m) => self::staffMessage($case, $query, $m, $user))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function staffMessage(ProcurementCase $case, CaseQuery $query, QueryMessage $message, User $user): array
    {
        $to = QueryWording::office($message->recipient_role, $case);
        $canForward = QueryAccess::canForward($user, $case, $query, $message);

        return [
            'id' => (string) $message->getKey(),
            'author' => $message->author?->name,
            'from_label' => QueryWording::office($message->author_role, $case),
            'to_label' => $to,
            'body' => $message->body,
            'sent_on' => self::day($message->created_at),
            'held' => $message->isHeld(),
            'state' => match (true) {
                $message->isHeld() => 'Held for the '.StageWording::CMO_OFFICE.' to forward to '.$to,
                $message->forwarded_at !== null => 'Forwarded to '.$to.' by '.(User::query()->whereKey($message->forwarded_by_cmo_id)->value('name') ?? 'the '.StageWording::CMO_OFFICE).' on '.self::day($message->forwarded_at),
                default => 'Sent to '.$to,
            },
            'external' => $message->isExternal(),
            'forward' => $canForward ? [
                'label' => QueryWording::forwardLabel($message->recipient_role, $case),
                'title' => 'Forward this message to '.$to.'?',
                'body' => 'The message goes to '.$to.' as written. You are recorded as the person who forwarded it.',
            ] : null,
        ];
    }

    /**
     * D4: the open queries for the Next Step card, each with the one thing
     * this user does next (forward or answer), or who it waits on.
     *
     * @return list<array{id: string, title: string, status_label: string, waiting_on: string|null, action: string|null}>
     */
    public static function forCard(ProcurementCase $case, User $user): array
    {
        $case->loadMissing('organisation:id,name');

        return QueryAccess::visibleQueries($user, $case)
            ->filter(fn (CaseQuery $q) => $q->status !== QueryStatus::CLOSED)
            ->map(function (CaseQuery $q) use ($case, $user) {
                $held = QueryAccess::forwardable($user, $case, $q);
                $action = match (true) {
                    $held !== null => QueryWording::forwardLabel($held->recipient_role, $case),
                    QueryAccess::needsAnswer($user, $case, $q) => 'Answer Query',
                    default => null,
                };

                return [
                    'id' => (string) $q->getKey(),
                    'title' => $q->type->label().': '.QueryWording::office($q->raised_by_role, $case).' to '.QueryWording::office($q->raised_to_role, $case),
                    'status_label' => $q->status->label(),
                    'waiting_on' => $action === null ? self::waitingOn($case, $q) : null,
                    'action' => $action,
                ];
            })->values()->all();
    }

    /** Plain words for who the query waits on; null once closed. */
    public static function waitingOn(ProcurementCase $case, CaseQuery $query): ?string
    {
        return match ($query->status) {
            QueryStatus::CLOSED => null,
            QueryStatus::AWAITING_FORWARD => 'Waiting on the '.StageWording::CMO_OFFICE.' to forward it',
            QueryStatus::OPEN => 'Waiting on '.self::the($query->raised_to_role, $case).' to answer',
            QueryStatus::ANSWERED => 'Answered. Waiting on '.self::the($query->raised_by_role, $case).' to close it or reply',
        };
    }

    /**
     * Vendor: "Queries From the Ministry". Only queries addressed to the
     * vendor's role, only external messages already delivered; the sender is
     * "Ministry of Health" or "You".
     *
     * @return list<array<string, mixed>>
     */
    public static function forVendor(ProcurementCase $case, User $vendor): array
    {
        return QueryAccess::visibleQueries($vendor, $case)
            ->map(function (CaseQuery $query) use ($case, $vendor) {
                $messages = QueryAccess::visibleMessages($vendor, $case, $query);

                return [
                    'id' => (string) $query->getKey(),
                    'title' => 'Query From the '.QueryWording::MINISTRY,
                    'status_label' => $query->status === QueryStatus::CLOSED ? 'Closed' : ($query->status === QueryStatus::OPEN ? 'Waiting on Your Reply' : 'Replied'),
                    'raised_on' => self::day($query->created_at),
                    'can_reply' => QueryAccess::canReply($vendor, $case, $query),
                    'messages' => $messages->map(fn (QueryMessage $m) => [
                        'id' => (string) $m->getKey(),
                        'from_label' => QueryAccess::isVendorRole($m->author_role) ? 'You' : QueryWording::MINISTRY,
                        'body' => $m->body,
                        'sent_on' => self::day($m->created_at),
                    ])->all(),
                ];
            })->values()->all();
    }

    /** "the Technocrats", "the Legal Unit"; a vendor is its organisation's name. */
    private static function the(string $role, ProcurementCase $case): string
    {
        $office = QueryWording::office($role, $case);

        return QueryAccess::isVendorRole($role) ? $office : 'the '.$office;
    }

    private static function day(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(self::TIMEZONE)->format('j M Y');
    }
}
