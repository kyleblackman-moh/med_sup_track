import { ActionFacts, ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { type ActionConfirm, type LegalForm, type ReviewerOption } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FolderPen, Gavel } from 'lucide-react';
import { FormEvent, useState } from 'react';

/** Field label inside an action panel (daisyUI fieldset label). */
const LABEL = 'label text-base-content text-sm font-medium capitalize';

/** First error for a field or any of its items (legal_officers, legal_officers.0, ...). */
function fieldError(errors: Record<string, string>, field: string): string | undefined {
    return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1];
}

/**
 * T5 for CMO staff (slice 10c). The input starts with the server's
 * file_no_default (config); CMO staff may add to it or change it.
 */
export function PrepareFileForm({ caseId, defaultFileNo, submitLabel }: { caseId: string; defaultFileNo: string; submitLabel: string }) {
    const form = useForm({ file_no: defaultFileNo });
    const errors = form.errors as Record<string, string>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('cases.prepare', caseId), { preserveScroll: true, preserveState: 'errors' });
    };

    return (
        <ActionPanel
            title="Prepare the File"
            icon={FolderPen}
            description="Give the case its file number."
            onSubmit={submit}
            actions={
                <Button type="submit" size="sm" className="active:scale-[0.97]" disabled={form.processing || form.data.file_no.trim() === ''}>
                    {form.processing ? 'Saving…' : submitLabel}
                </Button>
            }
        >
            <fieldset className="fieldset py-0">
                <label className={LABEL} htmlFor="file-no">
                    File Number
                </label>
                <Input
                    id="file-no"
                    aria-required="true"
                    className="input-sm max-w-sm font-mono"
                    maxLength={30}
                    value={form.data.file_no}
                    onChange={(e) => form.setData('file_no', e.target.value)}
                    aria-invalid={errors.file_no ? true : undefined}
                    aria-describedby="file-no-hint file-no-error"
                />
                <p id="file-no-hint" className="label whitespace-normal">
                    Starts with the file series. Add the rest of the number, or edit it. Up to 30 characters. Staff only.
                </p>
                <InputError id="file-no-error" message={errors.file_no ?? errors.prepare} />
            </fieldset>
        </ActionPanel>
    );
}

/**
 * T6 for CMO staff (slice 10c): choose 1 or 2 Legal officers, opened from the
 * Next Step card. The dialog is the confirmation, in the server's words. The
 * server checks each officer again.
 */
export function SendToLegal({
    caseId,
    officers,
    open,
    onOpenChange: setOpen,
    confirm,
}: {
    caseId: string;
    officers: ReviewerOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    confirm: ActionConfirm;
}) {
    const form = useForm<{ legal_officers: number[]; instruction: string }>({ legal_officers: [], instruction: '' });
    const errors = form.errors as Record<string, string>;

    const toggle = (id: number, on: boolean) =>
        form.setData(
            'legal_officers',
            on ? [...form.data.legal_officers.filter((x) => x !== id), id] : form.data.legal_officers.filter((x) => x !== id),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('cases.legal.send', caseId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !form.processing && setOpen(next)}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>{confirm.title}</DialogTitle>
                        <DialogDescription>{confirm.body}</DialogDescription>
                    </DialogHeader>

                    <fieldset className="fieldset gap-2 py-0" aria-describedby="legal-officers-hint legal-officers-error">
                        <legend className="fieldset-legend pt-0 capitalize">Legal Officers</legend>
                        <p id="legal-officers-hint" className="label">
                            Choose 1 or 2.
                        </p>
                        {officers.length === 0 ? (
                            <p className="text-base-content/70 text-sm">No eligible officers are available.</p>
                        ) : (
                            <ul className="border-base-300 rounded-box grid max-h-48 gap-2 overflow-y-auto border p-3 sm:grid-cols-2">
                                {officers.map((o) => {
                                    const id = `legal-officer-${o.id}`;
                                    return (
                                        <li key={o.id}>
                                            <label htmlFor={id} className="label text-base-content cursor-pointer gap-2 text-sm">
                                                <Checkbox
                                                    id={id}
                                                    checked={form.data.legal_officers.includes(o.id)}
                                                    onCheckedChange={(v) => toggle(o.id, v === true)}
                                                />
                                                {o.name}
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <InputError id="legal-officers-error" message={fieldError(errors, 'legal_officers')} />
                    </fieldset>

                    <fieldset className="fieldset py-0">
                        <label className={LABEL} htmlFor="legal-instruction">
                            Instruction
                        </label>
                        <textarea
                            id="legal-instruction"
                            rows={3}
                            maxLength={500}
                            className={actionTextareaClass}
                            value={form.data.instruction}
                            onChange={(e) => form.setData('instruction', e.target.value)}
                            aria-invalid={errors.instruction ? true : undefined}
                            aria-describedby="legal-instruction-hint legal-instruction-error"
                        />
                        <p id="legal-instruction-hint" className="label whitespace-normal">
                            Up to 500 characters. Shown to staff in the case timeline.
                        </p>
                        <InputError id="legal-instruction-error" message={errors.instruction} />
                    </fieldset>

                    <InputError message={errors.send_to_legal} />

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Sending…' : confirm.button}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The Legal decision (slice 10c), for an assigned Legal officer at With Legal.
 * Two outcomes; a return needs a reason. A summary is confirmed before it is
 * recorded, because the first decision is final.
 */
export function LegalDecisionForm({ caseId, form: def }: { caseId: string; form: LegalForm }) {
    const form = useForm<{ decision: string; reason: string }>({ decision: '', reason: '' });
    const errors = form.errors as Record<string, string>;
    const [confirming, setConfirming] = useState(false);
    const chosen = def.decisions.find((d) => d.value === form.data.decision);
    const needsReason = chosen?.needs_reason === true;
    const reason = form.data.reason.trim();

    const review = (e: FormEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    const record = () =>
        form.post(route('cases.legal.decide', caseId), {
            preserveScroll: true,
            preserveState: 'errors',
            onFinish: () => setConfirming(false),
        });

    return (
        <ActionPanel
            title="Legal Decision"
            icon={Gavel}
            description="Choose the decision. A return needs a reason."
            onSubmit={review}
            actions={
                <Button type="submit" size="sm" className="active:scale-[0.97]" disabled={form.processing || form.data.decision === ''}>
                    Record Decision
                </Button>
            }
        >
            <fieldset className="fieldset gap-2 py-0" aria-describedby="legal-decision-error">
                <legend className="fieldset-legend pt-0 capitalize">Decision</legend>
                {def.decisions.map((d) => (
                    <label key={d.value} htmlFor={`legal-${d.value}`} className="label text-base-content cursor-pointer gap-2 text-sm">
                        <input
                            type="radio"
                            id={`legal-${d.value}`}
                            name="legal-decision"
                            value={d.value}
                            checked={form.data.decision === d.value}
                            onChange={() => form.setData('decision', d.value)}
                            className="radio radio-sm"
                        />
                        {d.label}
                    </label>
                ))}
                <InputError id="legal-decision-error" message={errors.decision ?? errors.legal_decision} />
            </fieldset>

            <fieldset className="fieldset py-0">
                <label className={LABEL} htmlFor="legal-reason">
                    {needsReason ? 'Reason for the Query (required)' : 'Note'}
                </label>
                <textarea
                    id="legal-reason"
                    aria-required={needsReason ? true : undefined}
                    rows={3}
                    maxLength={500}
                    className={actionTextareaClass}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby="legal-reason-hint legal-reason-error"
                />
                <p id="legal-reason-hint" className="label whitespace-normal">
                    {needsReason ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters. Staff only.'}
                </p>
                <InputError id="legal-reason-error" message={errors.reason} />
            </fieldset>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record this Legal decision?</DialogTitle>
                        <DialogDescription>Check the summary. Once recorded, the decision cannot be changed here.</DialogDescription>
                    </DialogHeader>
                    <ActionFacts
                        items={[
                            ['Decision', chosen?.label ?? ''],
                            [needsReason ? 'Reason' : 'Note', reason === '' ? 'None' : reason],
                            ['Next', chosen?.next ?? ''],
                        ]}
                    />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Go Back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Recording…' : 'Record Decision'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ActionPanel>
    );
}
