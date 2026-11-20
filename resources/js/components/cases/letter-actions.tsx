import { ActionFacts, ActionPanel } from '@/components/cases/action-panel';
import { formatDay } from '@/components/cases/case-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { Lock, MailCheck } from 'lucide-react';
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

    const dateField = (field: keyof LetterData, id: string, label: string, hint?: string) => (
        <fieldset className="fieldset py-0">
            <label className="label text-base-content text-sm font-medium" htmlFor={id}>
                {label}
            </label>
            <input
                id={id}
                type="date"
                aria-label={label}
                className="input input-sm w-full"
                max={today}
                value={d[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                aria-invalid={errors[field] ? true : undefined}
                aria-describedby={`${id}-hint ${id}-error`}
            />
            {hint && (
                <p id={`${id}-hint`} className="label whitespace-normal">
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} message={errors[field]} />
        </fieldset>
    );

    const summary: [string, string][] = [
        ['Letter date', d.letter_date ? formatDay(d.letter_date) : '—'],
        ['Reference', d.letter_reference.trim() || 'None'],
        ['Emailed on', d.emailed_on ? formatDay(d.emailed_on) : 'Not recorded'],
        ['Hardcopy sent on', d.hardcopy_sent_on ? formatDay(d.hardcopy_sent_on) : 'Not recorded'],
    ];

    return (
        <ActionPanel
            title="Issue the letter"
            icon={MailCheck}
            description="Send the letter by email and hardcopy yourself, then record it here."
            onSubmit={review}
            actions={
                <Button type="submit" size="sm" disabled={!ready || form.processing}>
                    Review
                </Button>
            }
        >
            <div className="grid max-w-xl gap-x-4 gap-y-2 sm:grid-cols-2">
                {dateField('letter_date', 'letter-date', 'Letter date', 'The date on the signed letter.')}
                <fieldset className="fieldset py-0">
                    <label className="label text-base-content text-sm font-medium" htmlFor="letter-reference">
                        Reference (optional)
                    </label>
                    <input
                        id="letter-reference"
                        type="text"
                        className="input input-sm w-full font-mono"
                        maxLength={40}
                        value={d.letter_reference}
                        onChange={(e) => form.setData('letter_reference', e.target.value)}
                        aria-invalid={errors.letter_reference ? true : undefined}
                        aria-describedby="letter-reference-hint letter-reference-error"
                    />
                    <p id="letter-reference-hint" className="label whitespace-normal">
                        As written on the letter. Up to 40 characters.
                    </p>
                    <InputError id="letter-reference-error" message={errors.letter_reference} />
                </fieldset>
                {dateField('emailed_on', 'letter-emailed-on', 'Emailed on')}
                {dateField('hardcopy_sent_on', 'letter-hardcopy-sent-on', 'Hardcopy sent on')}
            </div>
            <p className="text-base-content/70 text-xs">Record at least one: the email date, the hardcopy date, or both.</p>
            <InputError message={errors.issue_letter} />

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record the letter as issued?</DialogTitle>
                        <DialogDescription>The vendor will see that non-objection is granted, with the letter date and reference.</DialogDescription>
                    </DialogHeader>
                    <ActionFacts items={summary} />
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
        </ActionPanel>
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
        <ActionPanel
            title="Close the case"
            icon={Lock}
            description="A closed case is read-only and cannot be reopened."
            actions={
                <Button type="button" size="sm" onClick={() => setConfirming(true)} disabled={form.processing}>
                    Close case
                </Button>
            }
        >
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
        </ActionPanel>
    );
}
