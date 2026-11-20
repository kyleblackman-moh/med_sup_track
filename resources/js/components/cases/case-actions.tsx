import { ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import { ForwardForReview } from '@/components/cases/forward-for-review';
import { LegalDecisionForm, PrepareFileForm, SendToLegal } from '@/components/cases/legal-actions';
import { CloseCaseButton, IssueLetterForm } from '@/components/cases/letter-actions';
import { PsDecisionForm, PsOutcomeStatement } from '@/components/cases/ps-actions';
import { MarkReadyForFile, ReviewOutcomeForm } from '@/components/cases/review-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    type EligibleReviewers,
    type GateState,
    type HardcopyRecord,
    type LegalForm,
    type PsForm,
    type PsOutcome,
    type ReviewerOption,
    type ReviewForm,
    type StaffCaseAbilities,
} from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { CircleCheck, Inbox, PackageCheck, Undo2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Props {
    caseId: string;
    reference: string | null;
    hardcopy: HardcopyRecord | null;
    dates: { today: string; earliest: string | null };
    can: StaffCaseAbilities;
    reviewers: EligibleReviewers | null;
    gate: GateState | null;
    reviewForms: ReviewForm[];
    fileNoDefault: string | null;
    legalOfficers: ReviewerOption[] | null;
    legalForm: LegalForm | null;
    psForm: PsForm | null;
    psOutcome: PsOutcome | null;
    /** Slice 10e: opens the Send onward panel; null when the user may not route the case. */
    onSendOnward: (() => void) | null;
    /** Slice 10f: the latest date the issue-letter form accepts; null when the user may not issue it. */
    letterToday: string | null;
}

export function formatDay(day: string): string {
    // A calendar date (Y-m-d), shown as-is: no time zone shift.
    return new Date(`${day}T12:00:00Z`).toLocaleDateString('en-TT', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });
}

/** "Received on <date> by <name>" or "Not received". */
export function hardcopyText(hardcopy: HardcopyRecord | null): string {
    if (!hardcopy) {
        return 'Not received';
    }

    return `Received on ${formatDay(hardcopy.received_on)}${hardcopy.recorded_by ? ` by ${hardcopy.recorded_by}` : ''}`;
}

/**
 * Staff actions on one case (slice 9b): accept into intake and record or
 * correct the hardcopy. Buttons appear only for the server's can flags; the
 * server authorises every request again.
 */
export function CaseActions({
    caseId,
    reference,
    hardcopy,
    dates,
    can,
    reviewers,
    gate,
    reviewForms,
    fileNoDefault,
    legalOfficers,
    legalForm,
    psForm,
    psOutcome,
    onSendOnward,
    letterToday,
}: Props) {
    const [confirmIntake, setConfirmIntake] = useState(false);
    const [confirmReverse, setConfirmReverse] = useState(false);

    const intake = useForm({});
    const mark = useForm({ received_on: dates.today });
    const reverse = useForm({ reason: '' });

    const submitIntake = () => intake.post(route('cases.intake', caseId), { preserveScroll: true, onFinish: () => setConfirmIntake(false) });

    const submitMark = (e: FormEvent) => {
        e.preventDefault();
        mark.post(route('cases.hardcopy.store', caseId), { preserveScroll: true });
    };

    const submitReverse = (e: FormEvent) => {
        e.preventDefault();
        reverse.post(route('cases.hardcopy.reverse', caseId), {
            preserveScroll: true,
            onSuccess: () => {
                reverse.reset();
                setConfirmReverse(false);
            },
        });
    };

    const canForward = can.forward_for_review && reviewers !== null;
    const hasActions =
        can.intake ||
        can.record_hardcopy ||
        canForward ||
        can.record_review ||
        can.mark_ready_for_file ||
        can.prepare_file ||
        can.send_to_legal ||
        can.record_legal_decision ||
        can.record_ps_decision ||
        psOutcome !== null;
    const intakeError = (intake.errors as Record<string, string>).intake;

    return (
        <section aria-labelledby="actions-heading" className="flex flex-col gap-3">
            <h2 id="actions-heading" className="text-base-content/60 text-xs font-semibold tracking-wide uppercase">
                Your next step
            </h2>

            {!hasActions && (
                <ActionPanel
                    title="No action for you"
                    icon={CircleCheck}
                    description="There are no actions available to you on this case right now."
                />
            )}

            {can.intake && (
                <ActionPanel
                    title="Accept into intake"
                    icon={Inbox}
                    description="The case moves to CMO intake and is assigned to you."
                    actions={
                        <Button type="button" size="sm" onClick={() => setConfirmIntake(true)} disabled={intake.processing}>
                            Accept into intake
                        </Button>
                    }
                >
                    <InputError message={intakeError} />

                    <Dialog open={confirmIntake} onOpenChange={(open) => !intake.processing && setConfirmIntake(open)}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Accept {reference ?? 'this case'} into intake?</DialogTitle>
                                <DialogDescription>
                                    The case moves to CMO intake and is assigned to you. Other registry officers will no longer see it in their list.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <Button type="button" variant="ghost" onClick={() => setConfirmIntake(false)} disabled={intake.processing}>
                                    Cancel
                                </Button>
                                <Button type="button" onClick={submitIntake} disabled={intake.processing}>
                                    {intake.processing ? 'Accepting…' : 'Accept into intake'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </ActionPanel>
            )}

            {can.record_hardcopy &&
                (hardcopy === null ? (
                    <ActionPanel
                        title="Record hardcopy"
                        icon={PackageCheck}
                        description="Not received yet. Enter the date the physical file arrived."
                        onSubmit={submitMark}
                        actions={
                            <Button type="submit" size="sm" disabled={mark.processing}>
                                {mark.processing ? 'Saving…' : 'Save hardcopy date'}
                            </Button>
                        }
                    >
                        <fieldset className="fieldset py-0">
                            <label className="label text-base-content text-sm font-medium" htmlFor="hardcopy-received-on">
                                Date received
                            </label>
                            <input
                                id="hardcopy-received-on"
                                type="date"
                                className="input input-sm w-44"
                                value={mark.data.received_on}
                                max={dates.today}
                                min={dates.earliest ?? undefined}
                                onChange={(e) => mark.setData('received_on', e.target.value)}
                                aria-invalid={mark.errors.received_on ? true : undefined}
                                aria-describedby={mark.errors.received_on ? 'hardcopy-received-on-error' : undefined}
                            />
                            <InputError
                                id="hardcopy-received-on-error"
                                message={mark.errors.received_on ?? (mark.errors as Record<string, string>).hardcopy}
                            />
                        </fieldset>
                    </ActionPanel>
                ) : (
                    <ActionPanel
                        title="Hardcopy"
                        icon={PackageCheck}
                        description={hardcopyText(hardcopy)}
                        actions={
                            <Button type="button" variant="outline" size="sm" onClick={() => setConfirmReverse(true)}>
                                Mark not received
                            </Button>
                        }
                    >
                        <InputError message={(reverse.errors as Record<string, string>).hardcopy} />

                        <Dialog open={confirmReverse} onOpenChange={(open) => !reverse.processing && setConfirmReverse(open)}>
                            <DialogContent>
                                <form onSubmit={submitReverse} className="grid gap-4" noValidate>
                                    <DialogHeader>
                                        <DialogTitle>Mark the hardcopy as not received?</DialogTitle>
                                        <DialogDescription>
                                            This removes the recorded date ({formatDay(hardcopy.received_on)}). The reason is kept in the audit log.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <fieldset className="fieldset py-0">
                                        <label className="label text-base-content text-sm font-medium" htmlFor="hardcopy-reason">
                                            Reason
                                        </label>
                                        <textarea
                                            id="hardcopy-reason"
                                            rows={3}
                                            maxLength={500}
                                            className={actionTextareaClass}
                                            value={reverse.data.reason}
                                            onChange={(e) => reverse.setData('reason', e.target.value)}
                                            aria-invalid={reverse.errors.reason ? true : undefined}
                                            aria-describedby="hardcopy-reason-hint hardcopy-reason-error"
                                        />
                                        <p id="hardcopy-reason-hint" className="label">
                                            10 to 500 characters.
                                        </p>
                                        <InputError id="hardcopy-reason-error" message={reverse.errors.reason} />
                                    </fieldset>
                                    <DialogFooter className="gap-2">
                                        <Button type="button" variant="ghost" onClick={() => setConfirmReverse(false)} disabled={reverse.processing}>
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={reverse.processing || reverse.data.reason.trim().length < 10}
                                        >
                                            {reverse.processing ? 'Saving…' : 'Mark not received'}
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </ActionPanel>
                ))}

            {/* Slice 10b: a reviewer's own track, then the CMO's T4 action. */}
            {can.record_review && reviewForms.map((f) => <ReviewOutcomeForm key={f.track} caseId={caseId} form={f} />)}
            {can.mark_ready_for_file && <MarkReadyForFile caseId={caseId} gate={gate} />}

            {/* Slice 10c: prepare the file, send to Legal, the Legal decision. */}
            {can.prepare_file && fileNoDefault !== null && <PrepareFileForm caseId={caseId} defaultFileNo={fileNoDefault} />}
            {can.send_to_legal && legalOfficers && <SendToLegal caseId={caseId} reference={reference} officers={legalOfficers} />}
            {can.record_legal_decision && legalForm && <LegalDecisionForm caseId={caseId} form={legalForm} />}

            {/* Slice 10d: the PS decision, or once decided a plain statement of it. */}
            {can.record_ps_decision && psForm && <PsDecisionForm caseId={caseId} form={psForm} />}
            {!can.record_ps_decision && psOutcome && <PsOutcomeStatement outcome={psOutcome} />}

            {/* Slice 10e: a returned case goes onward through the side panel. */}
            {can.route_onward && onSendOnward && (
                <ActionPanel
                    title="Returned case"
                    icon={Undo2}
                    description="Choose where the case goes next."
                    actions={
                        <Button type="button" size="sm" onClick={onSendOnward}>
                            Send onward
                        </Button>
                    }
                />
            )}

            {/* Slice 10f: record the letter as issued, then close the case. */}
            {can.issue_letter && letterToday && <IssueLetterForm caseId={caseId} today={letterToday} />}
            {can.close_case && <CloseCaseButton caseId={caseId} reference={reference} />}

            {/* Last on purpose: send for review is the final step at intake. */}
            {can.forward_for_review && reviewers && <ForwardForReview caseId={caseId} reference={reference} reviewers={reviewers} />}
        </section>
    );
}
