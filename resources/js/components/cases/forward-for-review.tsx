import { actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type ActionConfirm, type EligibleReviewers, type ReviewerOption } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

/** Field label inside an action panel (daisyUI fieldset label). */
const LABEL = 'label text-base-content text-sm font-medium capitalize';

interface Props {
    caseId: string;
    reviewers: EligibleReviewers;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** NEXT-STEP-1: the server's title, what happens and the confirm button (naming the destination). */
    confirm: ActionConfirm;
}

type Field = 'technocrats' | 'cab_officers';

/** First error for a field or any of its items (technocrats, technocrats.0, ...). */
function fieldError(errors: Record<string, string>, field: string): string | undefined {
    return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1];
}

function ReviewerList({
    field,
    legend,
    options,
    selected,
    onToggle,
    error,
}: {
    field: Field;
    legend: string;
    options: ReviewerOption[];
    selected: number[];
    onToggle: (id: number, on: boolean) => void;
    error?: string;
}) {
    return (
        <fieldset className="fieldset gap-2 py-0" aria-describedby={`${field}-hint ${field}-error`}>
            <legend className="fieldset-legend pt-0 capitalize">{legend}</legend>
            <p id={`${field}-hint`} className="label">
                Choose 1 to 3.
            </p>
            {options.length === 0 ? (
                <p className="text-base-content/70 text-sm">No eligible officers are available.</p>
            ) : (
                <ul className="border-base-300 rounded-box grid max-h-48 gap-2 overflow-y-auto border p-3 sm:grid-cols-2">
                    {options.map((o) => {
                        const id = `${field}-${o.id}`;
                        return (
                            <li key={o.id}>
                                <label htmlFor={id} className="label text-base-content cursor-pointer gap-2 text-sm">
                                    <Checkbox id={id} checked={selected.includes(o.id)} onCheckedChange={(v) => onToggle(o.id, v === true)} />
                                    {o.name}
                                </label>
                            </li>
                        );
                    })}
                </ul>
            )}
            <InputError id={`${field}-error`} message={error} />
        </fieldset>
    );
}

/**
 * Send for review (slice 10a, T3), opened from the Next Step card. The dialog
 * is the confirmation: it names the destination and what happens, in the
 * server's words. The server checks every chosen reviewer again.
 */
export function ForwardForReview({ caseId, reviewers, open, onOpenChange: setOpen, confirm }: Props) {
    const form = useForm<{ technocrats: number[]; cab_officers: number[]; instruction: string }>({
        technocrats: [],
        cab_officers: [],
        instruction: '',
    });
    const errors = form.errors as Record<string, string>;

    const toggle = (field: Field) => (id: number, on: boolean) =>
        form.setData(field, on ? [...form.data[field].filter((x) => x !== id), id] : form.data[field].filter((x) => x !== id));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('cases.forward', caseId), {
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

                    <ReviewerList
                        field="technocrats"
                        legend="Technocrats"
                        options={reviewers.technocrats}
                        selected={form.data.technocrats}
                        onToggle={toggle('technocrats')}
                        error={fieldError(errors, 'technocrats')}
                    />
                    <ReviewerList
                        field="cab_officers"
                        legend="CAB Officers"
                        options={reviewers.cab_officers}
                        selected={form.data.cab_officers}
                        onToggle={toggle('cab_officers')}
                        error={fieldError(errors, 'cab_officers')}
                    />

                    <fieldset className="fieldset py-0">
                        <label className={LABEL} htmlFor="forward-instruction">
                            Instruction
                        </label>
                        <textarea
                            id="forward-instruction"
                            rows={3}
                            maxLength={500}
                            className={actionTextareaClass}
                            value={form.data.instruction}
                            onChange={(e) => form.setData('instruction', e.target.value)}
                            aria-invalid={errors.instruction ? true : undefined}
                            aria-describedby="forward-instruction-hint forward-instruction-error"
                        />
                        <p id="forward-instruction-hint" className="label whitespace-normal">
                            Up to 500 characters. Shown to staff in the case timeline.
                        </p>
                        <InputError id="forward-instruction-error" message={errors.instruction} />
                    </fieldset>

                    <InputError message={errors.forward} />

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
