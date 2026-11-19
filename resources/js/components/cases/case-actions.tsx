import { PsOutcomeStatement } from '@/components/cases/ps-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type HardcopyRecord, type PsOutcome, type StaffCaseAbilities } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent, type ReactNode, useState } from 'react';

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
 * NEXT-STEP-1: Date Received and Save, shown inline in the Next Step card's
 * checklist (it replaced the separate Hardcopy block). Only rendered when the
 * server sends the form bounds; the server checks every date again.
 */
export function HardcopyRecordForm({ caseId, dates }: { caseId: string; dates: { today: string; earliest: string | null } }) {
    const mark = useForm({ received_on: dates.today });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        mark.post(route('cases.hardcopy.store', caseId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3" noValidate>
            <div className="grid gap-1.5">
                <Label htmlFor="hardcopy-received-on">Date Received</Label>
                <Input
                    id="hardcopy-received-on"
                    aria-required="true"
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
            <Button type="submit" variant="outline" disabled={mark.processing}>
                {mark.processing ? 'Saving…' : 'Save'}
            </Button>
            <div className="w-full">
                <InputError id="hardcopy-received-on-error" message={mark.errors.received_on ?? (mark.errors as Record<string, string>).hardcopy} />
            </div>
        </form>
    );
}

function Group({ id, title, description, children }: { id: string; title: string; description: string; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="space-y-2 border-t pt-4 first:border-t-0 first:pt-0">
            <h3 id={id} className="font-medium">
                {title}
            </h3>
            <p className="text-muted-foreground text-sm">{description}</p>
            {children}
        </section>
    );
}

interface Props {
    caseId: string;
    hardcopy: HardcopyRecord | null;
    can: StaffCaseAbilities;
    psOutcome: PsOutcome | null;
}

/**
 * The Actions tab (NEXT-STEP-1): the less common actions only. The step that
 * moves the file on is in the Next Step card at the top of the page, so it is
 * not repeated here. Buttons follow the server's can flags; the server
 * authorises every request again.
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

    return (
        <section aria-labelledby="actions-heading" className="bg-card space-y-4 rounded-lg border p-4">
            <h2 id="actions-heading" className="text-lg font-semibold">
                Other Actions
            </h2>

            {!canCorrectHardcopy && !showPsOutcome && (
                <p className="text-muted-foreground text-sm">
                    There are no other actions on this case. The step that moves the file on is in the Next Step card at the top of the page.
                </p>
            )}

            {canCorrectHardcopy && hardcopy && (
                <Group
                    id="hardcopy-correct-heading"
                    title="Correct the Hardcopy Record"
                    description="If the hardcopy was recorded by mistake, mark it as not received. The file does not move; the reason is kept in the audit log."
                >
                    <p className="text-sm">{hardcopyText(hardcopy)}</p>
                    <Button type="button" variant="outline" size="sm" onClick={() => setConfirmReverse(true)}>
                        Mark Not Received
                    </Button>
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
                                <div className="grid gap-1.5">
                                    <Label htmlFor="hardcopy-reason">Reason</Label>
                                    <textarea
                                        id="hardcopy-reason"
                                        aria-required="true"
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
                </Group>
            )}

            {/* A record, not an action: the decision already taken, which cannot be changed here. */}
            {showPsOutcome && psOutcome && <PsOutcomeStatement outcome={psOutcome} />}
        </section>
    );
}
