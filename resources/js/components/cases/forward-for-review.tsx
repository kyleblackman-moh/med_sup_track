import { actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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

/** First error for a field or any of its items (technocrats, technocrats.0, ...). */
function fieldError(errors: Record<string, string>, field: string): string | undefined {
    return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1];
}

/** Kyle, 2026-11-26: who receives it, read-only (no choosing: it goes to both desks). */
function Recipients({ legend, options, error }: { legend: string; options: ReviewerOption[]; error?: string }) {
    return (
        <div className="grid gap-1 text-sm">
            <p className="font-medium capitalize">{legend}</p>
            {options.length === 0 ? (
                <p className="text-warning">No one can take this yet. Ask ICT to give an officer the role.</p>
            ) : (
                <p className="text-base-content/70">{options.map((o) => o.name).join(', ')}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * Send for review (slice 10a, T3), opened from the Next Step card. The dialog
 * is the confirmation: it names the case (drug, DIN and reference), the
 * destination and what happens, in the server's words. Kyle, 2026-11-26: no
 * reviewer choice; the server assigns every eligible Technocrat and CAB officer.
 */
export function ForwardForReview({ caseId, reviewers, open, onOpenChange: setOpen, confirm }: Props) {
    const form = useForm<{ instruction: string }>({ instruction: '' });
    const errors = form.errors as Record<string, string>;

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

                    <div className="border-base-300 rounded-box grid gap-3 border p-3 sm:grid-cols-2">
                        <Recipients legend="Technocrats" options={reviewers.technocrats} error={fieldError(errors, 'technocrats')} />
                        <Recipients legend="CAB Officers" options={reviewers.cab_officers} error={fieldError(errors, 'cab_officers')} />
                    </div>

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
