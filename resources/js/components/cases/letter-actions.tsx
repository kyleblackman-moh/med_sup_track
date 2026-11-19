import { formatDay } from '@/components/cases/case-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type ActionConfirm } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type LetterData = { letter_date: string; letter_reference: string; emailed_on: string; hardcopy_sent_on: string };

/**
 * T13 for the CMO (slice 10f): record that the signed letter was issued. The
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
        ['Letter Date', d.letter_date ? formatDay(d.letter_date) : '—'],
        ['Reference', d.letter_reference.trim() || 'None'],
        ['Emailed On', d.emailed_on ? formatDay(d.emailed_on) : 'Not recorded'],
        ['Hardcopy Sent On', d.hardcopy_sent_on ? formatDay(d.hardcopy_sent_on) : 'Not recorded'],
    ];

    return (
        <form onSubmit={review} className="space-y-3" noValidate>
            <p className="text-muted-foreground text-sm">{confirm.body}</p>

            <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                {dateField('letter_date', 'letter-date', 'Letter Date', 'The date on the signed letter.', true)}
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
                {dateField('emailed_on', 'letter-emailed-on', 'Emailed On')}
                {dateField('hardcopy_sent_on', 'letter-hardcopy-sent-on', 'Hardcopy Sent On')}
            </div>
            <p className="text-muted-foreground text-xs">Record at least one: the email date, the hardcopy date, or both.</p>
            <InputError message={errors.issue_letter} />

            <Button type="submit" disabled={!ready || form.processing}>
                Review
            </Button>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm.title}</DialogTitle>
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
                            {form.processing ? 'Saving…' : confirm.button}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}
