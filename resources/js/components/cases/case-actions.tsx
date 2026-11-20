import { ActionFacts, ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import { PsOutcomeStatement } from '@/components/cases/ps-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { type HardcopyRecord, type PsOutcome, type StaffCaseAbilities } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { PackageCheck, PackageX } from 'lucide-react';
import { FormEvent, useState } from 'react';

/** Field label inside an action panel (daisyUI fieldset label). */
const LABEL = 'label text-base-content text-sm font-medium capitalize';

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
 * NEXT-STEP-1: Date Received and Save, shown with the Next Step card when its
 * checklist carries the hardcopy form (it replaced the separate Hardcopy
 * block). Only rendered when the server sends the form bounds; the server
 * checks every date again.
 */
export function HardcopyRecordForm({ caseId, dates }: { caseId: string; dates: { today: string; earliest: string | null } }) {
    const mark = useForm({ received_on: dates.today });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        mark.post(route('cases.hardcopy.store', caseId), { preserveScroll: true });
    };

    return (
        <ActionPanel
            title="Record the Hardcopy"
            icon={PackageCheck}
            description="The date the paper file reached the office."
            onSubmit={submit}
            actions={
                <Button type="submit" size="sm" className="active:scale-[0.97]" disabled={mark.processing}>
                    {mark.processing ? 'Saving…' : 'Save'}
                </Button>
            }
        >
            <fieldset className="fieldset py-0">
                <label className={LABEL} htmlFor="hardcopy-received-on">
                    Date Received
                </label>
                <Input
                    id="hardcopy-received-on"
                    aria-required="true"
                    type="date"
                    className="input-sm w-44"
                    value={mark.data.received_on}
                    max={dates.today}
                    min={dates.earliest ?? undefined}
                    onChange={(e) => mark.setData('received_on', e.target.value)}
                    aria-invalid={mark.errors.received_on ? true : undefined}
                    aria-describedby={mark.errors.received_on ? 'hardcopy-received-on-error' : undefined}
                />
                <InputError id="hardcopy-received-on-error" message={mark.errors.received_on ?? (mark.errors as Record<string, string>).hardcopy} />
            </fieldset>
        </ActionPanel>
    );
}

interface Props {
    caseId: string;
    hardcopy: HardcopyRecord | null;
    can: StaffCaseAbilities;
    psOutcome: PsOutcome | null;
}

/**
 * The less common actions (NEXT-STEP-1), each one ActionPanel in the action
 * column under the Next Step card. The step that moves the file on is in the
 * Next Step card, so it is not repeated here. Buttons follow the server's can
 * flags; the server authorises every request again.
 */
export function CaseActions({ caseId, hardcopy, can, psOutcome }: Props) {
    const [confirmReverse, setConfirmReverse] = useState(false);
    const reverse = useForm({ reason: '' });

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

    const canCorrectHardcopy = can.record_hardcopy && hardcopy !== null;
    const showPsOutcome = !can.record_ps_decision && psOutcome !== null;

    if (!canCorrectHardcopy && !showPsOutcome) {
        return (
            <p className="text-base-content/70 px-1 text-sm">
                There are no other actions on this case. The step that moves the file on is in the Next Step card.
            </p>
        );
    }

    return (
        <>
            {canCorrectHardcopy && hardcopy && (
                <ActionPanel
                    title="Correct the Hardcopy Record"
                    icon={PackageX}
                    description="If the hardcopy was recorded by mistake, mark it as not received. The file does not move; the reason is kept in the audit log."
                    actions={
                        <Button type="button" variant="outline" size="sm" className="active:scale-[0.97]" onClick={() => setConfirmReverse(true)}>
                            Mark Not Received
                        </Button>
                    }
                >
                    <ActionFacts items={[['Hardcopy', hardcopyText(hardcopy)]]} />
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
                                    <label className={LABEL} htmlFor="hardcopy-reason">
                                        Reason
                                    </label>
                                    <textarea
                                        id="hardcopy-reason"
                                        aria-required="true"
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
                                        {reverse.processing ? 'Saving…' : 'Mark Not Received'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </ActionPanel>
            )}

            {/* A record, not an action: the decision already taken, which cannot be changed here. */}
            {showPsOutcome && psOutcome && <PsOutcomeStatement outcome={psOutcome} />}
        </>
    );
}
