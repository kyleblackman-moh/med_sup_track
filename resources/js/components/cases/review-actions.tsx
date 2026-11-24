import { ActionFacts, ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { RadioGroup } from '@/components/ui/radio-group';
import { type ReviewForm } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { CircleCheck, ClipboardCheck } from 'lucide-react';
import { FormEvent, useState } from 'react';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/**
 * One reviewer's outcome form for their own track (slice 10b). Shown only for
 * the server's review_forms; the server checks permission, assignment and
 * status again on every save.
 *
 * Flow: choose an outcome; "Record Outcome" opens a summary to confirm; once
 * recorded the form becomes a summary with "Change Outcome". There is no draft:
 * recording is the save, and it can be changed while the case is in review.
 *
 * CAB funding (Kyle, 2026-11-24): the three statuses come from the server
 * (Availability of Funds, Verified by Accounts, Virement Required). The Reason
 * field is shown, required and sent only for the negative one (Virement
 * Required); the positive ones have no reason or note.
 */
export function ReviewOutcomeForm({ caseId, form: def }: { caseId: string; form: ReviewForm }) {
    const form = useForm({ outcome: def.current?.outcome ?? '', reason: def.current?.reason ?? '' });
    const errors = form.errors as Record<string, string>;
    const [editing, setEditing] = useState(def.current === null);
    const [confirming, setConfirming] = useState(false);
    const chosen = def.outcomes.find((o) => o.value === form.data.outcome);
    const negative = chosen?.positive === false;
    const funding = def.track === 'funding';
    // Funding: a reason only for Virement Required. Technical keeps its optional note.
    const showReason = !funding || negative;
    const choiceLabel = funding ? 'Status' : 'Outcome';
    const id = `review-${def.track}`;
    const reason = form.data.reason.trim();

    const review = (e: FormEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    const record = () => {
        form.transform((data) => ({ ...data, reason: showReason ? data.reason : '' }));
        form.post(route(def.track === 'technical' ? 'cases.technical' : 'cases.funding', caseId), {
            preserveScroll: true,
            preserveState: 'errors',
            onSuccess: () => setEditing(false),
            onFinish: () => setConfirming(false),
        });
    };

    const keepCurrent = () => {
        form.setData({ outcome: def.current?.outcome ?? '', reason: def.current?.reason ?? '' });
        form.clearErrors();
        setEditing(false);
    };

    if (!editing && def.current) {
        return (
            <ActionPanel
                title={`Your ${def.label.toLowerCase()} is recorded`}
                icon={CircleCheck}
                description="Nothing else is needed from you on this case for now. You can change the outcome until the case is marked ready for file."
                actions={
                    <Button type="button" variant="outline" size="sm" className="active:scale-[0.97]" onClick={() => setEditing(true)}>
                        {funding ? 'Change Status' : 'Change Outcome'}
                    </Button>
                }
            >
                <ActionFacts
                    items={[
                        [choiceLabel, def.current.label],
                        ...(def.current.reason ? ([['Reason or Note', def.current.reason]] as [string, string][]) : []),
                        ['Recorded', formatDateTime(def.current.recorded_at)],
                    ]}
                />
            </ActionPanel>
        );
    }

    return (
        <ActionPanel
            title={`Your ${def.label.toLowerCase()}`}
            icon={ClipboardCheck}
            description={def.current ? undefined : funding ? 'You have not recorded a funding status yet.' : 'You have not recorded an outcome yet.'}
            onSubmit={review}
            actions={
                <>
                    {def.current && (
                        <Button type="button" variant="outline" size="sm" className="active:scale-[0.97]" onClick={keepCurrent}>
                            {funding ? 'Keep Current Status' : 'Keep Current Outcome'}
                        </Button>
                    )}
                    <Button type="submit" size="sm" className="active:scale-[0.97]" disabled={form.processing || form.data.outcome === ''}>
                        {funding ? 'Record Status' : 'Record Outcome'}
                    </Button>
                </>
            }
        >
            <RadioGroup
                name={`${id}-outcome`}
                legend={funding ? 'CAB Status' : 'Outcome'}
                required={funding}
                options={def.outcomes}
                value={form.data.outcome}
                onChange={(value) => form.setData('outcome', value)}
                optionId={(o) => `${id}-${o.value}`}
                describedBy={`${id}-outcome-error`}
            >
                <InputError id={`${id}-outcome-error`} message={errors.outcome} />
            </RadioGroup>

            {showReason && (
                <fieldset className="fieldset py-0">
                    <label className="label text-base-content text-sm font-medium capitalize" htmlFor={`${id}-reason`}>
                        {negative ? 'Reason (required)' : 'Note'}
                    </label>
                    <textarea
                        id={`${id}-reason`}
                        aria-label={negative ? 'Reason' : 'Note'}
                        required={negative}
                        aria-required={negative ? true : undefined}
                        rows={3}
                        maxLength={500}
                        className={actionTextareaClass}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        aria-invalid={errors.reason ? true : undefined}
                        aria-describedby={`${id}-reason-hint ${id}-reason-error`}
                    />
                    <p id={`${id}-reason-hint`} className="label whitespace-normal">
                        {negative ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters.'}
                    </p>
                    <InputError id={`${id}-reason-error`} message={errors.reason ?? errors.review} />
                </fieldset>
            )}
            {!showReason && <InputError id={`${id}-review-error`} message={errors.review} />}

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{funding ? 'Record this funding status?' : 'Record this outcome?'}</DialogTitle>
                        <DialogDescription>Check the summary, then record it.</DialogDescription>
                    </DialogHeader>
                    <ActionFacts
                        items={[
                            ['Review', def.label],
                            [choiceLabel, chosen?.label ?? ''],
                            ...(showReason ? ([[negative ? 'Reason' : 'Note', reason === '' ? 'None' : reason]] as [string, string][]) : []),
                        ]}
                    />
                    <p className="text-base-content/70 text-sm">You can change it until the case is marked ready for file.</p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Go Back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Recording…' : funding ? 'Record Status' : 'Record Outcome'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ActionPanel>
    );
}
