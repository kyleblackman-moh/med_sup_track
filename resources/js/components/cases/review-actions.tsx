import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { type ReviewForm } from '@/types/cases';
import { useForm } from '@inertiajs/react';
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
 * Flow: choose an outcome; "Record Outcome" opens a summary to confirm; once
 * recorded the form becomes a summary with "Change Outcome". There is no draft:
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
            <section className="space-y-3 border-t pt-4" aria-labelledby={`${id}-done`}>
                <h3 id={`${id}-done`} className="font-medium">
                    Your {def.label.toLowerCase()} is recorded
                </h3>
                <dl className="grid gap-1 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt className="text-muted-foreground">Outcome</dt>
                    <dd className="font-medium">{def.current.label}</dd>
                    {def.current.reason && (
                        <>
                            <dt className="text-muted-foreground">Reason or Note</dt>
                            <dd className="whitespace-pre-line">{def.current.reason}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Recorded</dt>
                    <dd>{formatDateTime(def.current.recorded_at)}</dd>
                </dl>
                <p className="text-muted-foreground text-sm">
                    Nothing else is needed from you on this case for now. You can change the outcome until the case is marked ready for file.
                </p>
                <Button type="button" variant="outline" onClick={() => setEditing(true)}>
                    Change Outcome
                </Button>
            </section>
        );
    }

    return (
        <form onSubmit={review} className="space-y-3 border-t pt-4" noValidate>
            <h3 className="font-medium">Your {def.label.toLowerCase()}</h3>
            {!def.current && <p className="text-muted-foreground text-sm">You have not recorded an outcome yet.</p>}

            <fieldset className="grid gap-2" aria-describedby={`${id}-outcome-error`}>
                <legend className="text-sm font-medium">Outcome</legend>
                {def.outcomes.map((o) => (
                    <div key={o.value} className="flex items-center gap-2">
                        <input
                            type="radio"
                            id={`${id}-${o.value}`}
                            name={`${id}-outcome`}
                            value={o.value}
                            checked={form.data.outcome === o.value}
                            onChange={() => form.setData('outcome', o.value)}
                            className="accent-primary focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <Label htmlFor={`${id}-${o.value}`} className="font-normal">
                            {o.label}
                        </Label>
                    </div>
                ))}
                <InputError id={`${id}-outcome-error`} message={errors.outcome} />
            </fieldset>

            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-reason`}>{negative ? 'Reason (required)' : 'Note'}</Label>
                <textarea
                    id={`${id}-reason`}
                    aria-required={negative ? true : undefined}
                    rows={3}
                    maxLength={500}
                    className="border-input placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby={`${id}-reason-hint ${id}-reason-error`}
                />
                <p id={`${id}-reason-hint`} className="text-muted-foreground text-xs">
                    {negative ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters.'}
                </p>
                <InputError id={`${id}-reason-error`} message={errors.reason ?? errors.review} />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing || form.data.outcome === ''}>
                    Record Outcome
                </Button>
                {def.current && (
                    <Button type="button" variant="outline" onClick={keepCurrent}>
                        Keep Current Outcome
                    </Button>
                )}
            </div>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record this outcome?</DialogTitle>
                        <DialogDescription>Check the summary, then record it.</DialogDescription>
                    </DialogHeader>
                    <dl className="grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt className="text-muted-foreground">Review</dt>
                        <dd>{def.label}</dd>
                        <dt className="text-muted-foreground">Outcome</dt>
                        <dd className="font-medium">{chosen?.label}</dd>
                        <dt className="text-muted-foreground">{negative ? 'Reason' : 'Note'}</dt>
                        <dd className="whitespace-pre-line">{reason === '' ? 'None' : reason}</dd>
                    </dl>
                    <p className="text-muted-foreground text-sm">You can change it until the case is marked ready for file.</p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Go Back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Recording…' : 'Record Outcome'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}
