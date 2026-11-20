import { ActionFacts, ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type GateState, type ReviewForm } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { CircleCheck, ClipboardCheck, FolderCheck } from 'lucide-react';
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
 * Flow: choose an outcome; "Record outcome" opens a summary to confirm; once
 * recorded the form becomes a summary with "Change outcome". There is no draft:
 * recording is the save, and it can be changed while the case is in review.
 */
export function ReviewOutcomeForm({ caseId, form: def }: { caseId: string; form: ReviewForm }) {
    const form = useForm({ outcome: def.current?.outcome ?? '', reason: def.current?.reason ?? '' });
    const errors = form.errors as Record<string, string>;
    const [editing, setEditing] = useState(def.current === null);
    const [confirming, setConfirming] = useState(false);
    const chosen = def.outcomes.find((o) => o.value === form.data.outcome);
    const negative = chosen?.positive === false;
    const id = `review-${def.track}`;
    const reason = form.data.reason.trim();

    const review = (e: FormEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    const record = () => {
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
                description="Nothing else is needed from you on this case for now. You can change the outcome until the CMO marks the case ready for file."
                actions={
                    <Button type="button" variant="outline" size="sm" onClick={() => setEditing(true)}>
                        Change outcome
                    </Button>
                }
            >
                <ActionFacts
                    items={[
                        ['Outcome', def.current.label],
                        ...(def.current.reason ? ([['Reason or note', def.current.reason]] as [string, string][]) : []),
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
            description={def.current ? undefined : 'You have not recorded an outcome yet.'}
            onSubmit={review}
            actions={
                <>
                    {def.current && (
                        <Button type="button" variant="ghost" size="sm" onClick={keepCurrent}>
                            Keep current outcome
                        </Button>
                    )}
                    <Button type="submit" size="sm" disabled={form.processing || form.data.outcome === ''}>
                        Record outcome
                    </Button>
                </>
            }
        >
            <fieldset className="fieldset gap-2 py-0" aria-describedby={`${id}-outcome-error`}>
                <legend className="fieldset-legend pt-0">Outcome</legend>
                {def.outcomes.map((o) => (
                    <label key={o.value} htmlFor={`${id}-${o.value}`} className="label text-base-content cursor-pointer gap-2 text-sm">
                        <input
                            type="radio"
                            id={`${id}-${o.value}`}
                            name={`${id}-outcome`}
                            value={o.value}
                            checked={form.data.outcome === o.value}
                            onChange={() => form.setData('outcome', o.value)}
                            className="radio radio-sm"
                        />
                        {o.label}
                    </label>
                ))}
                <InputError id={`${id}-outcome-error`} message={errors.outcome} />
            </fieldset>

            <fieldset className="fieldset py-0">
                <label className="label text-base-content text-sm font-medium" htmlFor={`${id}-reason`}>
                    {negative ? 'Reason (required)' : 'Note (optional)'}
                </label>
                <textarea
                    id={`${id}-reason`}
                    aria-label={negative ? 'Reason' : 'Note'}
                    rows={3}
                    maxLength={500}
                    className={actionTextareaClass}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby={`${id}-reason-hint ${id}-reason-error`}
                />
                <p id={`${id}-reason-hint`} className="label">
                    {negative ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters.'}
                </p>
                <InputError id={`${id}-reason-error`} message={errors.reason ?? errors.review} />
            </fieldset>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record this outcome?</DialogTitle>
                        <DialogDescription>Check the summary, then record it.</DialogDescription>
                    </DialogHeader>
                    <ActionFacts
                        items={[
                            ['Review', def.label],
                            ['Outcome', chosen?.label ?? ''],
                            [negative ? 'Reason' : 'Note', reason === '' ? 'None' : reason],
                        ]}
                    />
                    <p className="text-base-content/70 text-sm">You can change it until the CMO marks the case ready for file.</p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Go back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Recording…' : 'Record outcome'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ActionPanel>
    );
}

/**
 * T4 for the CMO (slice 10b). Enabled only when the server says the gate is
 * open; otherwise the server's missing list is shown as text.
 */
export function MarkReadyForFile({ caseId, gate }: { caseId: string; gate: GateState | null }) {
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const open = gate?.open === true;

    return (
        <ActionPanel
            title="Ready for file"
            icon={FolderCheck}
            description={open ? 'Technical, funding and hardcopy are complete.' : 'The gate is not complete yet.'}
            actions={
                <Button
                    type="button"
                    size="sm"
                    onClick={() => form.post(route('cases.ready', caseId), { preserveScroll: true })}
                    disabled={!open || form.processing}
                    aria-describedby={!open ? 'ready-missing' : undefined}
                >
                    {form.processing ? 'Saving…' : 'Mark ready for file'}
                </Button>
            }
        >
            {!open && gate && gate.missing.length > 0 && (
                <ul className="text-base-content/70 [list-style-type:disc] pl-5 text-sm" id="ready-missing">
                    {gate.missing.map((m) => (
                        <li key={m}>{m}</li>
                    ))}
                </ul>
            )}
            <InputError message={errors.gate} />
        </ActionPanel>
    );
}
