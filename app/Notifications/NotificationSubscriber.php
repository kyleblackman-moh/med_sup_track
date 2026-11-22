<?php

namespace App\Notifications;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Events\CaseTransitioned;
use App\Events\DocumentQuarantined;
use App\Events\HardcopyRecorded;
use App\Events\PasswordChanged as PasswordChangedEvent;
use App\Events\QueryMessageDelivered;
use App\Events\QueryMessageHeld;
use App\Events\ReviewOutcomeRecorded;
use App\Models\CaseAssignment;
use App\Models\CaseQuery;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\User;
use App\Notifications\Cases\CaseAssigned;
use App\Notifications\Cases\CaseSubmitted;
use App\Notifications\Cases\CaseWithdrawn;
use App\Notifications\Cases\FileSentToLegal;
use App\Notifications\Cases\FileSentToPs;
use App\Notifications\Cases\FundingReviewRecorded;
use App\Notifications\Cases\HardcopyReceived;
use App\Notifications\Cases\LegalConfirmed;
use App\Notifications\Cases\NonObjectionGranted;
use App\Notifications\Cases\ReadyToPrepareFile;
use App\Notifications\Cases\ReturnedToCmo;
use App\Notifications\Cases\TechnicalReviewRecorded;
use App\Notifications\Queries\DirectQueryActivity;
use App\Notifications\Queries\ForwardRequired;
use App\Notifications\Queries\ForwardResponse;
use App\Notifications\Queries\QueryAnswered;
use App\Notifications\Queries\QueryRaised;
use App\Notifications\Vendor\VendorApplicationApproved;
use App\Notifications\Vendor\VendorApplicationRejected;
use App\Notifications\Vendor\VendorNotification;
use App\Notifications\Vendor\VendorQueryReceived;
use App\Notifications\Vendor\VendorQueryResponseSent;
use App\Queries\QueryAccess;
use App\Support\Audit;
use App\Workflow\TransitionRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * NOTIF-1, NOTIF-2: the ONE listener for notifications. It maps each domain
 * event to a notification class and its recipients; the mapping lives here,
 * in code. Every event it hears is dispatched after its transaction commits,
 * and every notification is also afterCommit, so a rolled-back action sends
 * nothing. Recipients come from NotificationRecipients (permission or
 * assignment, never a role name in code; query recipients from the query's
 * route). Sending never fails or rolls back the action.
 *
 * Vendors receive exactly four notifications (Vendor\*): a query made
 * visible to them, their own response sent, approved, rejected. Nothing else.
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
            ReviewOutcomeRecorded::class => 'onReviewOutcome',
            DocumentQuarantined::class => 'onQuarantine',
            PasswordReset::class => 'onPasswordReset',
            PasswordChangedEvent::class => 'onPasswordChanged',
            QueryMessageHeld::class => 'onQueryHeld',
            QueryMessageDelivered::class => 'onQueryDelivered',
        ];
    }

    public function onTransition(CaseTransitioned $event): void
    {
        $case = $event->case;
        $r = $this->recipients;

        match ($event->to) {
            CaseStatus::SUBMITTED => $this->send(NotificationRecipients::merge($r->intake(), $r->cmo()), CaseSubmitted::for($case)),
            CaseStatus::RETURNED_TO_CMO => $this->send($r->cmo(), ReturnedToCmo::for($case, $event->from->returnedBySource())),
            CaseStatus::WITH_LEGAL => $this->send($r->forStage($case, Permission::LegalConfirm), FileSentToLegal::for($case)),
            CaseStatus::WITH_PS => $this->withPs($case, $event->from),
            CaseStatus::NON_OBJECTION_GRANTED => $this->granted($case),
            CaseStatus::REJECTED_FINAL => $this->sendToVendor($case, VendorApplicationRejected::for($case)),
            // Staff never see a draft, so a draft withdrawn by its vendor tells nobody.
            CaseStatus::WITHDRAWN => $event->actor->isVendor() && $event->from !== CaseStatus::DRAFT
                ? $this->send($r->cmo(), CaseWithdrawn::for($case))
                : null,
            default => null,
        };
    }

    /** WITH_PS: the PS desk acts; after Legal confirmed, the CMO is told (info). */
    private function withPs(ProcurementCase $case, CaseStatus $from): void
    {
        $this->send($this->recipients->forStage($case, Permission::NonObjectionGrant), FileSentToPs::for($case));

        if ($from === CaseStatus::WITH_LEGAL) {
            $this->send($this->recipients->cmo(), LegalConfirmed::for($case));
        }
    }

    private function granted(ProcurementCase $case): void
    {
        $this->send($this->recipients->cmo(), NonObjectionGranted::for($case));
        $this->sendToVendor($case, VendorApplicationApproved::for($case));
    }

    /**
     * A named officer assigned to a review desk (Technical review, or the
     * parallel Funding confirmation request to CAB). Pool rows (no user) and
     * other purposes (intake, Legal: FileSentToLegal covers it) send nothing.
     */
    public function onAssignmentCreated(CaseAssignment $assignment): void
    {
        $desk = ReviewDesk::tryFrom((string) $assignment->purpose);
        if ($assignment->user_id === null || $desk === null) {
            return;
        }

        $case = ProcurementCase::withoutGlobalScopes()->find($assignment->case_id);
        if ($case !== null) {
            $this->send($this->recipients->user((int) $assignment->user_id)->reject->isVendor(), CaseAssigned::for($case, $desk->label()));
        }
    }

    public function onHardcopy(HardcopyRecorded $event): void
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($event->caseId);
        if ($case === null) {
            return;
        }

        $this->send($this->recipients->cmo(), HardcopyReceived::for($case));
        // The hardcopy was missing until now, so a complete gate is newly complete.
        $this->gateIfMet($case);
    }

    public function onReviewOutcome(ReviewOutcomeRecorded $event): void
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($event->caseId);
        if ($case === null) {
            return;
        }

        $note = $event->desk === ReviewDesk::Technical ? TechnicalReviewRecorded::class : FundingReviewRecorded::class;
        $this->send($this->recipients->cmo(), $note::for($case, $event->outcome->label()));

        if ($event->previousTrack !== $event->desk->positiveTrack()) {
            $this->gateIfMet($case);
        }
    }

    /** All three gate conditions met on a case in review: the CMO can prepare the file (spec 6.4). */
    private function gateIfMet(ProcurementCase $case): void
    {
        if ($case->status === CaseStatus::IN_REVIEW && TransitionRules::gateMissing($case) === []) {
            $this->send($this->recipients->cmo(), ReadyToPrepareFile::for($case));
        }
    }

    /**
     * A via-CMO message is held: the CMO's desk forwards it. From the raiser's
     * side it is a query to forward; from the recipient side, a response.
     */
    public function onQueryHeld(QueryMessageHeld $event): void
    {
        [$case, $query, $message] = $this->load($event->caseId, $event->queryId, $event->messageId);
        if ($message === null) {
            return;
        }

        $response = $message->author_role === $query->raised_to_role && $message->author_role !== $query->raised_by_role;
        $note = $response
            ? new ForwardResponse($case->getKey(), $case->reference_no)
            : new ForwardRequired($case->getKey(), $case->reference_no);
        $this->send($this->recipients->forQueryMessage($case, $query, $message), $note);

        $this->vendorReplied($case, $message);
    }

    /**
     * A message reached the other side (direct, or forwarded by the CMO).
     *
     * - To a vendor: the submitter (VendorQueryReceived).
     * - To the raiser's side (staff): Query Answered.
     * - To a staff desk: Query Raised (its holders on the case, plus the named officer).
     * - A direct query involving a vendor: the CMO's desk reads it (info).
     */
    public function onQueryDelivered(QueryMessageDelivered $event): void
    {
        [$case, $query, $message] = $this->load($event->caseId, $event->queryId, $event->messageId);
        if ($message === null) {
            return;
        }

        $to = $this->recipients->forQueryMessage($case, $query, $message);

        if (QueryAccess::isVendorRole($message->recipient_role)) {
            $this->sendToVendor($case, VendorQueryReceived::for($case), $to);
        } elseif ($message->recipient_role === $query->raised_by_role) {
            $this->send($to, new QueryAnswered($case->getKey(), $case->reference_no));
        } else {
            $this->send($to, new QueryRaised($case->getKey(), $case->reference_no));
        }

        $direct = ! $query->via_cmo && (QueryAccess::isVendorRole($query->raised_to_role) || QueryAccess::isVendorRole($query->raised_by_role));
        if ($direct) {
            $author = (int) $message->author_id;
            $this->send(
                $this->recipients->queryDesk()->reject(fn (User $u) => (int) $u->getKey() === $author)->values(),
                new DirectQueryActivity($case->getKey(), $case->reference_no),
            );
        }

        $this->vendorReplied($case, $message);
    }

    /** The vendor's own message was submitted (held or delivered): a receipt to the submitter. */
    private function vendorReplied(ProcurementCase $case, QueryMessage $message): void
    {
        if (QueryAccess::isVendorRole($message->author_role)) {
            $this->sendToVendor($case, VendorQueryResponseSent::for($case));
        }
    }

    /** @return array{0: ProcurementCase|null, 1: CaseQuery|null, 2: QueryMessage|null} */
    private function load(string $caseId, string $queryId, string $messageId): array
    {
        $case = ProcurementCase::withoutGlobalScopes()->find($caseId);
        $query = CaseQuery::withoutGlobalScopes()->find($queryId);
        $message = QueryMessage::query()->find($messageId);

        return $case === null || $query === null || $message === null ? [null, null, null] : [$case, $query, $message];
    }

    /**
     * FIX-2 (1): a vendor notification goes to the submitter. With nobody
     * eligible, record notification.no_recipient (case id and event code only,
     * never an address) and log a warning, so the gap is visible; never fail
     * the action.
     *
     * @param  Collection<int, User>|null  $users  the submitter unless given
     */
    private function sendToVendor(ProcurementCase $case, VendorNotification $notification, ?Collection $users = null): void
    {
        $users ??= $this->recipients->submitter($case);

        if ($users->isNotEmpty()) {
            $this->send($users, $notification);

            return;
        }

        Log::warning('Vendor notification has no recipient', ['case_id' => $case->getKey(), 'event' => $notification->event()]);

        try {
            Audit::record(AuditAction::NotificationNoRecipient, null, $case->getKey(), ['event' => $notification->event()], Audit::ACTOR_SYSTEM);
        } catch (Throwable $e) {
            Log::error('Audit write failed for a notification with no recipient', ['case_id' => $case->getKey(), 'exception' => $e::class]);
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

        // A staff notification never reaches a vendor account, whatever a resolver returns.
        if (! $notification instanceof VendorNotification && $notification instanceof CaseNotification) {
            $users = $users->reject(fn (User $u) => $u->isVendor())->values();
            if ($users->isEmpty()) {
                return;
            }
        }

        try {
            Notification::send($users, $notification);
        } catch (Throwable $e) {
            // Never let a notification break the action; no recipient address in the log.
            Log::error('Notification could not be sent', [
                'notification' => $notification::class,
                'case_id' => $notification instanceof CaseNotification ? $notification->caseId : null,
                'exception' => $e::class,
            ]);
        }
    }
}
