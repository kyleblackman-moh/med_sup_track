import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type ActionConfirm, type LegalForm, type ReviewerOption } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const TEXTAREA =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/** First error for a field or any of its items (legal_officers, legal_officers.0, ...). */
function fieldError(errors: Record<string, string>, field: string): string | undefined {
    return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1];
}

/**
 * T5 for the CMO (slice 10c). The input starts with the server's
 * file_no_default (config); the CMO may add to it or change it.
 */
export function PrepareFileForm({ caseId, defaultFileNo, submitLabel }: { caseId: string; defaultFileNo: string; submitLabel: string }) {
    const form = useForm({ file_no: defaultFileNo });
    const errors = form.errors as Record<string, string>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('cases.prepare', caseId), { preserveScroll: true, preserveState: 'errors' });
    };

    return (
        <form onSubmit={submit} className="space-y-3" noValidate>
            <div className="grid max-w-sm gap-1.5">
                <Label htmlFor="file-no">File Number</Label>
                <Input
                    id="file-no"
                    aria-required="true"
                    className="font-mono"
                    maxLength={30}
                    value={form.data.file_no}
                    onChange={(e) => form.setData('file_no', e.target.value)}
                    aria-invalid={errors.file_no ? true : undefined}
                    aria-describedby="file-no-hint file-no-error"
                />
                <p id="file-no-hint" className="text-muted-foreground text-xs">
                    Starts with the file series. Add the rest of the number, or edit it. Up to 30 characters. Staff only.
                </p>
                <InputError id="file-no-error" message={errors.file_no ?? errors.prepare} />
            </div>
            <Button type="submit" disabled={form.processing || form.data.file_no.trim() === ''}>
                {form.processing ? 'Saving…' : submitLabel}
            </Button>
        </form>
    );
}

/**
 * T6 for the CMO (slice 10c): choose 1 or 2 Legal officers, opened from the
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

                    <fieldset className="grid gap-2" aria-describedby="legal-officers-hint legal-officers-error">
                        <legend className="text-sm font-medium">Legal Officers</legend>
                        <p id="legal-officers-hint" className="text-muted-foreground text-xs">
                            Choose 1 or 2.
                        </p>
                        {officers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No eligible officers are available.</p>
                        ) : (
                            <ul className="grid max-h-48 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2">
                                {officers.map((o) => {
                                    const id = `legal-officer-${o.id}`;
                                    return (
                                        <li key={o.id} className="flex items-center gap-2">
                                            <Checkbox
                                                id={id}
                                                checked={form.data.legal_officers.includes(o.id)}
                                                onCheckedChange={(v) => toggle(o.id, v === true)}
                                            />
                                            <Label htmlFor={id} className="font-normal">
                                                {o.name}
                                            </Label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <InputError id="legal-officers-error" message={fieldError(errors, 'legal_officers')} />
                    </fieldset>

                    <div className="grid gap-1.5">
                        <Label htmlFor="legal-instruction">Instruction</Label>
                        <textarea
                            id="legal-instruction"
                            rows={3}
                            maxLength={500}
                            className={TEXTAREA}
                            value={form.data.instruction}
                            onChange={(e) => form.setData('instruction', e.target.value)}
                            aria-invalid={errors.instruction ? true : undefined}
                            aria-describedby="legal-instruction-hint legal-instruction-error"
                        />
                        <p id="legal-instruction-hint" className="text-muted-foreground text-xs">
                            Up to 500 characters. Shown to staff in the case timeline.
                        </p>
                        <InputError id="legal-instruction-error" message={errors.instruction} />
                    </div>

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
        <form onSubmit={review} className="space-y-3 border-t pt-4" noValidate>
            <h3 className="font-medium">Legal Decision</h3>

            <fieldset className="grid gap-2" aria-describedby="legal-decision-error">
                <legend className="text-sm font-medium">Decision</legend>
                {def.decisions.map((d) => (
                    <div key={d.value} className="flex items-center gap-2">
                        <input
                            type="radio"
                            id={`legal-${d.value}`}
                            name="legal-decision"
                            value={d.value}
                            checked={form.data.decision === d.value}
                            onChange={() => form.setData('decision', d.value)}
                            className="accent-primary focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <Label htmlFor={`legal-${d.value}`} className="font-normal">
                            {d.label}
                        </Label>
                    </div>
                ))}
                <InputError id="legal-decision-error" message={errors.decision ?? errors.legal_decision} />
            </fieldset>

            <div className="grid gap-1.5">
                <Label htmlFor="legal-reason">{needsReason ? 'Reason for the Query (required)' : 'Note'}</Label>
                <textarea
                    id="legal-reason"
                    aria-required={needsReason ? true : undefined}
                    rows={3}
                    maxLength={500}
                    className={TEXTAREA}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby="legal-reason-hint legal-reason-error"
                />
                <p id="legal-reason-hint" className="text-muted-foreground text-xs">
                    {needsReason ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters. Staff only.'}
                </p>
                <InputError id="legal-reason-error" message={errors.reason} />
            </div>

            <Button type="submit" disabled={form.processing || form.data.decision === ''}>
                Record Decision
            </Button>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record this Legal decision?</DialogTitle>
                        <DialogDescription>Check the summary. Once recorded, the decision cannot be changed here.</DialogDescription>
                    </DialogHeader>
                    <dl className="grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt className="text-muted-foreground">Decision</dt>
                        <dd className="font-medium">{chosen?.label}</dd>
                        <dt className="text-muted-foreground">{needsReason ? 'Reason' : 'Note'}</dt>
                        <dd className="whitespace-pre-line">{reason === '' ? 'None' : reason}</dd>
                        <dt className="text-muted-foreground">Next</dt>
                        <dd>{chosen?.next}</dd>
                    </dl>
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
        </form>
    );
}
