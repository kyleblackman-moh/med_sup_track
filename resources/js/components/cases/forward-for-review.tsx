import { ActionPanel, actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type EligibleReviewers, type ReviewerOption } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Props {
    caseId: string;
    reference: string | null;
    reviewers: EligibleReviewers;
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
            <legend className="fieldset-legend pt-0">{legend}</legend>
            <p id={`${field}-hint`} className="label whitespace-normal">
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
                                    <input
                                        type="checkbox"
                                        id={id}
                                        className="checkbox checkbox-sm"
                                        checked={selected.includes(o.id)}
                                        onChange={(e) => onToggle(o.id, e.target.checked)}
                                    />
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
 * Send for review (slice 10a, T3). Shown only for the server's
 * can.forward_for_review flag; the server checks every chosen reviewer again.
 */
export function ForwardForReview({ caseId, reference, reviewers }: Props) {
    const [open, setOpen] = useState(false);
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
        <ActionPanel
            title="Send for review"
            icon={Send}
            description="Assign technocrats and CAB officers to review the case."
            actions={
                <Button type="button" size="sm" onClick={() => setOpen(true)} disabled={form.processing}>
                    Send for review
                </Button>
            }
        >
            <InputError message={errors.forward} />

            <Dialog open={open} onOpenChange={(next) => !form.processing && setOpen(next)}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <DialogHeader>
                            <DialogTitle>Send {reference ?? 'this case'} for review</DialogTitle>
                            <DialogDescription>
                                The chosen technocrats and CAB officers are assigned to the case and can see it in their list.
                            </DialogDescription>
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
                            legend="CAB officers"
                            options={reviewers.cab_officers}
                            selected={form.data.cab_officers}
                            onToggle={toggle('cab_officers')}
                            error={fieldError(errors, 'cab_officers')}
                        />

                        <fieldset className="fieldset py-0">
                            <label className="label text-base-content text-sm font-medium" htmlFor="forward-instruction">
                                Instruction (optional)
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

                        <DialogFooter className="gap-2">
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={form.processing}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Sending…' : 'Send'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ActionPanel>
    );
}
