import { ActionFacts, ActionPanel } from '@/components/cases/action-panel';
import { formatDay } from '@/components/cases/case-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { type ActionConfirm } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { FormEvent, useState } from 'react';

type LetterData = { letter_date: string; letter_reference: string; emailed_on: string; hardcopy_sent_on: string };

/** Field label inside an action panel (daisyUI fieldset label). */
const LABEL = 'label text-base-content text-sm font-medium capitalize';

/**
 * T13 for CMO staff (slice 10f): record that the signed letter was issued. The
 * letter is sent outside the system. A summary is confirmed before it is
 * recorded; the server checks every date again.
 */
export function IssueLetterForm({ caseId, today, confirm }: { caseId: string; today: string; confirm: ActionConfirm }) {
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
        <fieldset className="fieldset py-0">
            <label className={LABEL} htmlFor={id}>
                {label}
            </label>
            <Input
                id={id}
                aria-label={label}
                aria-required={required ? true : undefined}
                type="date"
                className="input-sm"
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
        ['Letter Date', d.letter_date ? formatDay(d.letter_date) : '—'],
        ['Reference', d.letter_reference.trim() || 'None'],
        ['Emailed On', d.emailed_on ? formatDay(d.emailed_on) : 'Not recorded'],
        ['Hardcopy Sent On', d.hardcopy_sent_on ? formatDay(d.hardcopy_sent_on) : 'Not recorded'],
    ];

    return (
        <ActionPanel
            title="Record the Issued Letter"
            icon={Mail}
            description={confirm.body}
            onSubmit={review}
            actions={
                <Button type="submit" size="sm" className="active:scale-[0.97]" disabled={!ready || form.processing}>
                    Review
                </Button>
            }
        >
            <div className="grid gap-x-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                {dateField('letter_date', 'letter-date', 'Letter Date', 'The date on the signed letter.', true)}
                <fieldset className="fieldset py-0">
                    <label className={LABEL} htmlFor="letter-reference">
                        Reference
                    </label>
                    <Input
                        id="letter-reference"
                        className="input-sm font-mono"
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
                {dateField('emailed_on', 'letter-emailed-on', 'Emailed On')}
                {dateField('hardcopy_sent_on', 'letter-hardcopy-sent-on', 'Hardcopy Sent On')}
            </div>
            <p className="text-base-content/70 text-xs">Record at least one: the email date, the hardcopy date, or both.</p>
            <InputError message={errors.issue_letter} />

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm.title}</DialogTitle>
                        <DialogDescription>The vendor will see that non-objection is granted, with the letter date and reference.</DialogDescription>
                    </DialogHeader>
                    <ActionFacts items={summary} />
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Saving…' : confirm.button}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ActionPanel>
    );
}
