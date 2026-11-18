import { formatDay } from '@/components/cases/case-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type LetterData = { letter_date: string; letter_reference: string; emailed_on: string; hardcopy_sent_on: string };

/**
 * T13 for the CMO (slice 10f): record that the signed letter was issued. The
 * letter is sent outside the system. A summary is confirmed before it is
 * recorded; the server checks every date again.
 */
export function IssueLetterForm({ caseId, today }: { caseId: string; today: string }) {
    const form = useForm<LetterData>({ letter_date: '', letter_reference: '', emailed_on: '', hardcopy_sent_on: '' });
    const errors = form.errors as Record<string, string>;
    const [confirming, setConfirming] = useState(false);
    const d = form.data;
    const ready = d.letter_date !== '' && (d.emailed_on !== '' || d.hardcopy_sent_on !== '');

    const review = (e: FormEvent) => {
        e.preventDefault();
        if (ready) {
            setConfirming(true);
        }
    };

    const record = () =>
        form.post(route('cases.letter.issue', caseId), {
            preserveScroll: true,
            preserveState: 'errors',
            onFinish: () => setConfirming(false),
        });

    const dateField = (field: keyof LetterData, id: string, label: string, hint?: string, required = false) => (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                aria-required={required ? true : undefined}
                type="date"
                max={today}
                value={d[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                aria-invalid={errors[field] ? true : undefined}
                aria-describedby={`${id}-hint ${id}-error`}
            />
            {hint && (
                <p id={`${id}-hint`} className="text-muted-foreground text-xs">
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} message={errors[field]} />
        </div>
    );

    const summary: [string, string][] = [
        ['Letter date', d.letter_date ? formatDay(d.letter_date) : '—'],
        ['Reference', d.letter_reference.trim() || 'None'],
        ['Emailed on', d.emailed_on ? formatDay(d.emailed_on) : 'Not recorded'],
        ['Hardcopy sent on', d.hardcopy_sent_on ? formatDay(d.hardcopy_sent_on) : 'Not recorded'],
    ];

    return (
        <form onSubmit={review} className="space-y-3 border-t pt-4" noValidate>
            <h3 className="font-medium">Issue the letter</h3>
            <p className="text-muted-foreground text-sm">Send the letter by email and hardcopy yourself, then record it here.</p>

            <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                {dateField('letter_date', 'letter-date', 'Letter date', 'The date on the signed letter.', true)}
                <div className="grid gap-1.5">
                    <Label htmlFor="letter-reference">Reference</Label>
                    <Input
                        id="letter-reference"
                        className="font-mono"
                        maxLength={40}
                        value={d.letter_reference}
                        onChange={(e) => form.setData('letter_reference', e.target.value)}
                        aria-invalid={errors.letter_reference ? true : undefined}
                        aria-describedby="letter-reference-hint letter-reference-error"
                    />
                    <p id="letter-reference-hint" className="text-muted-foreground text-xs">
                        As written on the letter. Up to 40 characters.
                    </p>
                    <InputError id="letter-reference-error" message={errors.letter_reference} />
                </div>
                {dateField('emailed_on', 'letter-emailed-on', 'Emailed on')}
                {dateField('hardcopy_sent_on', 'letter-hardcopy-sent-on', 'Hardcopy sent on')}
            </div>
            <p className="text-muted-foreground text-xs">Record at least one: the email date, the hardcopy date, or both.</p>
            <InputError message={errors.issue_letter} />

            <Button type="submit" disabled={!ready || form.processing}>
                Review
            </Button>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record the letter as issued?</DialogTitle>
                        <DialogDescription>The vendor will see that non-objection is granted, with the letter date and reference.</DialogDescription>
                    </DialogHeader>
                    <dl className="grid gap-2 text-sm">
                        {summary.map(([label, value]) => (
                            <div key={label} className="grid grid-cols-[10rem_1fr] gap-2">
                                <dt className="text-muted-foreground">{label}</dt>
                                <dd className="font-medium break-all">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Record letter issued'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}

/** T14 for the CMO (slice 10f): close the case, after a confirm step. */
export function CloseCaseButton({ caseId, reference }: { caseId: string; reference: string | null }) {
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const [confirming, setConfirming] = useState(false);

    const close = () =>
        form.post(route('cases.close', caseId), {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
        });

    return (
        <div className="space-y-2 border-t pt-4">
            <h3 className="font-medium">Close the case</h3>
            <Button type="button" onClick={() => setConfirming(true)} disabled={form.processing}>
                Close case
            </Button>
            <InputError message={errors.close_case} />

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close {reference ?? 'this case'}?</DialogTitle>
                        <DialogDescription>A closed case is read-only. It cannot be reopened.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={close} disabled={form.processing}>
                            {form.processing ? 'Closing…' : 'Close case'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
