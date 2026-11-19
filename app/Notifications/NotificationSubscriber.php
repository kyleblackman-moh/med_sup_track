<?php

namespace App\Notifications;

use App\Enums\CaseStatus;
use App\Events\CaseTransitioned;
use App\Events\DocumentQuarantined;
use App\Events\HardcopyRecorded;
use App\Events\PasswordChanged as PasswordChangedEvent;
use App\Models\CaseAssignment;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\Cases\CaseAssigned;
use App\Notifications\Cases\CaseNotification;
use App\Notifications\Cases\CaseSubmitted;
use App\Notifications\Cases\FileSentToPs;
use App\Notifications\Cases\HardcopyReceived;
use App\Notifications\Cases\LetterIssued;
use App\Notifications\Cases\NonObjectionGranted;
use App\Notifications\Cases\ReturnedToCmo;
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
        ];
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
                'case_id' => $notification instanceof CaseNotification ? $notification->caseId : null,
                'exception' => $e::class,
            ]);
        }
    }
}
