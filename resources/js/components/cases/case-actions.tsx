import { ForwardForReview } from '@/components/cases/forward-for-review';
import { LegalDecisionForm, PrepareFileForm, SendToLegal } from '@/components/cases/legal-actions';
import { PsDecisionForm, PsOutcomeStatement } from '@/components/cases/ps-actions';
import { MarkReadyForFile, ReviewOutcomeForm } from '@/components/cases/review-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
        <section aria-labelledby="actions-heading" className="bg-card space-y-4 rounded-lg border p-4">
            <h2 id="actions-heading" className="text-lg font-semibold">
                Actions
            </h2>

            {!hasActions && <p className="text-muted-foreground text-sm">There are no actions available to you on this case.</p>}

            {can.intake && (
                <div className="space-y-2">
                    <Button type="button" onClick={() => setConfirmIntake(true)} disabled={intake.processing}>
                        Accept into intake
                    </Button>
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
                </div>
            )}

            {can.record_hardcopy && (
                <div className="space-y-3 border-t pt-4">
                    <h3 className="font-medium">Hardcopy</h3>
                    <p className="text-sm">{hardcopyText(hardcopy)}</p>

                    {hardcopy === null ? (
                        <form onSubmit={submitMark} className="flex flex-wrap items-end gap-3" noValidate>
                            <div className="grid gap-1.5">
                                <Label htmlFor="hardcopy-received-on">Date received</Label>
                                <Input
                                    id="hardcopy-received-on"
                                    type="date"
                                    className="w-44"
                                    value={mark.data.received_on}
                                    max={dates.today}
                                    min={dates.earliest ?? undefined}
                                    onChange={(e) => mark.setData('received_on', e.target.value)}
                                    aria-invalid={mark.errors.received_on ? true : undefined}
                                    aria-describedby={mark.errors.received_on ? 'hardcopy-received-on-error' : undefined}
                                />
                            </div>
                            <Button type="submit" disabled={mark.processing}>
                                {mark.processing ? 'Saving…' : 'Save'}
                            </Button>
                            <div className="w-full">
                                <InputError
                                    id="hardcopy-received-on-error"
                                    message={mark.errors.received_on ?? (mark.errors as Record<string, string>).hardcopy}
                                />
                            </div>
                        </form>
                    ) : (
                        <>
                            <Button type="button" variant="outline" size="sm" onClick={() => setConfirmReverse(true)}>
                                Mark not received
                            </Button>
                            <InputError message={(reverse.errors as Record<string, string>).hardcopy} />

                            <Dialog open={confirmReverse} onOpenChange={(open) => !reverse.processing && setConfirmReverse(open)}>
                                <DialogContent>
                                    <form onSubmit={submitReverse} className="grid gap-4" noValidate>
                                        <DialogHeader>
                                            <DialogTitle>Mark the hardcopy as not received?</DialogTitle>
                                            <DialogDescription>
                                                This removes the recorded date ({formatDay(hardcopy.received_on)}). The reason is kept in the audit
                                                log.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="hardcopy-reason">Reason</Label>
                                            <textarea
                                                id="hardcopy-reason"
                                                rows={3}
                                                maxLength={500}
                                                className="border-input placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                                value={reverse.data.reason}
                                                onChange={(e) => reverse.setData('reason', e.target.value)}
                                                aria-invalid={reverse.errors.reason ? true : undefined}
                                                aria-describedby="hardcopy-reason-hint hardcopy-reason-error"
                                            />
                                            <p id="hardcopy-reason-hint" className="text-muted-foreground text-xs">
                                                10 to 500 characters.
                                            </p>
                                            <InputError id="hardcopy-reason-error" message={reverse.errors.reason} />
                                        </div>
                                        <DialogFooter className="gap-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => setConfirmReverse(false)}
                                                disabled={reverse.processing}
                                            >
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
                        </>
                    )}
                </div>
            )}

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

            {/* Last on purpose: send for review is the final step at intake. */}
            {can.forward_for_review && reviewers && <ForwardForReview caseId={caseId} reference={reference} reviewers={reviewers} />}
        </section>
    );
}
