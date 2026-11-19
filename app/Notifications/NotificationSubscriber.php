<?php

namespace App\Notifications;

use App\Enums\CaseStatus;
use App\Events\CaseTransitioned;
use App\Events\DocumentQuarantined;
use App\Events\HardcopyRecorded;
use App\Events\PasswordChanged as PasswordChangedEvent;
use App\Events\QueryMessageDelivered;
use App\Events\QueryMessageHeld;
use App\Models\CaseAssignment;
use App\Models\CaseQuery;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\User;
use App\Notifications\Cases\CaseAssigned;
use App\Notifications\Cases\CaseNotification;
use App\Notifications\Cases\CaseSubmitted;
use App\Notifications\Cases\FileSentToPs;
use App\Notifications\Cases\HardcopyReceived;
use App\Notifications\Cases\LetterIssued;
use App\Notifications\Cases\NonObjectionGranted;
use App\Notifications\Cases\ReturnedToCmo;
use App\Notifications\Queries\ForwardRequired;
use App\Notifications\Queries\QueryAnswered;
use App\Notifications\Queries\QueryNotification;
use App\Notifications\Queries\QueryRaised;
use App\Queries\QueryAccess;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * NOTIF-1: the ONE place emails are hooked in. Every case email follows a
 * committed transition (CaseTransitioned is dispatched after commit) or a
 * domain event; nothing is sent from a controller. Recipients come from
 * NotificationRecipients (by permission, active users, once each). Sending
 * never fails or rolls back the action: a failure to queue is logged and
 * swallowed, and queued mail runs after commit.
 */
final class NotificationSubscriber
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    /** @return array<string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            CaseTransitioned::class => 'onTransition',
            'eloquent.created: '.CaseAssignment::class => 'onAssignmentCreated',
            HardcopyRecorded::class => 'onHardcopy',
            DocumentQuarantined::class => 'onQuarantine',
            PasswordReset::class => 'onPasswordReset',
            PasswordChangedEvent::class => 'onPasswordChanged',
            QueryMessageHeld::class => 'onQueryHeld',
            QueryMessageDelivered::class => 'onQueryDelivered',
        ];
    }

    /** OVERNIGHT-2 (D4): a via-CMO message is held: the CMO's desk is asked to forward it. */
    public function onQueryHeld(QueryMessageHeld $event): void
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($event->caseId);
        if ($case !== null) {
            $this->send($this->recipients->queryDesk(), new ForwardRequired($case->getKey(), $case->reference_no));
        }
    }

    /**
     * OVERNIGHT-2 (D4): a message reached the other side (direct, or forwarded).
     * To the raiser's side: Query Answered to the person who raised it. To the
     * recipient side: Query Raised to the holders of that role who can see the
     * case, or the vendor applicant for a vendor role. A direct query to a
     * vendor is also sent to the CMO's desk, which reads it.
     */
    public function onQueryDelivered(QueryMessageDelivered $event): void
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($event->caseId);
        $query = CaseQuery::withoutGlobalScopes()->find($event->queryId);
        $message = QueryMessage::query()->find($event->messageId);
        if ($case === null || $query === null || $message === null) {
            return;
        }

        $r = $this->recipients;
        $author = (int) $message->author_id;

        if ($message->recipient_role === $query->raised_by_role) {
            $this->send($r->user((int) $query->raised_by_user_id)->reject(fn (User $u) => $u->getKey() === $author), new QueryAnswered($case->getKey(), $case->reference_no));

            return;
        }

        $to = QueryAccess::isVendorRole($message->recipient_role)
            ? $r->vendorApplicant($case)
            : $r->roleOnCase($message->recipient_role, $case);

        if (QueryAccess::isVendorRole($message->recipient_role) && ! $query->via_cmo) {
            $this->send($r->queryDesk()->reject(fn (User $u) => $u->getKey() === $author), new QueryRaised($case->getKey(), $case->reference_no));
        }

        $this->send($to->reject(fn (User $u) => $u->getKey() === $author), new QueryRaised($case->getKey(), $case->reference_no));
    }

    public function onTransition(CaseTransitioned $event): void
    {
        $case = $event->case;
        $r = $this->recipients;

        [$to, $notification] = match ($event->to) {
            CaseStatus::SUBMITTED => [NotificationRecipients::merge($r->intake(), $r->cmo()), CaseSubmitted::class],
            CaseStatus::RETURNED_TO_CMO => [$r->cmo(), ReturnedToCmo::class],
            CaseStatus::WITH_PS => [$r->psOffice(), FileSentToPs::class],
            CaseStatus::NON_OBJECTION_GRANTED => [$r->cmo(), NonObjectionGranted::class],
            CaseStatus::LETTER_ISSUED => [$r->vendorApplicant($case), LetterIssued::class],
            default => [collect(), null],
        };

        if ($notification !== null) {
            $this->send($to, $notification::for($case));
        }
    }

    /** A named user assigned to a case (pool rows, with no user, are not emailed). */
    public function onAssignmentCreated(CaseAssignment $assignment): void
    {
        if ($assignment->user_id === null) {
            return;
        }

        $case = ProcurementCase::withoutGlobalScopes()->find($assignment->case_id);
        if ($case !== null) {
            $this->send($this->recipients->user((int) $assignment->user_id)->reject->isVendor(), CaseAssigned::for($case));
        }
    }

    public function onHardcopy(HardcopyRecorded $event): void
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($event->caseId);
        if ($case !== null) {
            $this->send(NotificationRecipients::merge($this->recipients->cmo(), $this->recipients->vendorApplicant($case)), HardcopyReceived::for($case));
        }
    }

    public function onQuarantine(DocumentQuarantined $event): void
    {
        $document = Document::withoutGlobalScopes()->find($event->documentId);
        $case = $document === null ? null : ProcurementCase::withoutGlobalScopes()->find($document->case_id);

        $this->send($this->recipients->admins(), new InfectedUpload($event->documentId, $case?->getKey(), $case?->reference_no));
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->send($this->recipients->user((int) $event->user->getAuthIdentifier()), new PasswordChanged((int) $event->user->getAuthIdentifier()));
    }

    public function onPasswordChanged(PasswordChangedEvent $event): void
    {
        $this->send($this->recipients->user($event->userId), new PasswordChanged($event->userId));
    }

    /** @param  Collection<int, User>  $users */
    private function send(Collection $users, BaseNotification $notification): void
    {
        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::send($users, $notification);
        } catch (Throwable $e) {
            // Never let an email break the action; no recipient address in the log.
            Log::error('Notification could not be sent', [
                'notification' => $notification::class,
                'case_id' => $notification instanceof CaseNotification || $notification instanceof QueryNotification ? $notification->caseId : null,
                'exception' => $e::class,
            ]);
        }
    }
}
