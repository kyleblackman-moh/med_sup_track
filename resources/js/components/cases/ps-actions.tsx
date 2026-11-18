import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type PsForm, type PsOutcome } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

const TEXTAREA =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

/**
 * The PS decision (slice 10d), for a user the server allows at With PS. Grant,
 * or return to the CMO with a query type and a reason. A summary is confirmed
 * before it is recorded, because the first decision is final.
 */
export function PsDecisionForm({ caseId, form: def }: { caseId: string; form: PsForm }) {
    const form = useForm<{ decision: string; return_kind: string; reason: string }>({ decision: '', return_kind: '', reason: '' });
    const errors = form.errors as Record<string, string>;
    const [confirming, setConfirming] = useState(false);
    const chosen = def.decisions.find((d) => d.value === form.data.decision);
    const isReturn = chosen?.needs_reason === true;
    const kind = def.kinds.find((k) => k.value === form.data.return_kind);
    const reason = form.data.reason.trim();

    const choose = (value: string) => form.setData((data) => ({ ...data, decision: value, return_kind: value === 'grant' ? '' : data.return_kind }));

    const review = (e: FormEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    const record = () =>
        form.post(route('cases.ps.decide', caseId), {
            preserveScroll: true,
            preserveState: 'errors',
            onFinish: () => setConfirming(false),
        });

    return (
        <form onSubmit={review} className="space-y-3 border-t pt-4" noValidate>
            <h3 className="font-medium">PS decision</h3>

            <fieldset className="grid gap-2" aria-describedby="ps-decision-error">
                <legend className="text-sm font-medium">Decision</legend>
                {def.decisions.map((d) => (
                    <div key={d.value} className="flex items-center gap-2">
                        <input
                            type="radio"
                            id={`ps-${d.value}`}
                            name="ps-decision"
                            value={d.value}
                            checked={form.data.decision === d.value}
                            onChange={() => choose(d.value)}
                            className="accent-primary focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <Label htmlFor={`ps-${d.value}`} className="font-normal">
                            {d.label}
                        </Label>
                    </div>
                ))}
                <InputError id="ps-decision-error" message={errors.decision ?? errors.ps_decision} />
            </fieldset>

            {isReturn && (
                <div className="grid max-w-sm gap-1.5">
                    <Label htmlFor="ps-return-kind">Query type (required)</Label>
                    <Select value={form.data.return_kind} onValueChange={(v) => form.setData('return_kind', v)}>
                        <SelectTrigger
                            id="ps-return-kind"
                            aria-required="true"
                            aria-invalid={errors.return_kind ? true : undefined}
                            aria-describedby="ps-return-kind-error"
                        >
                            <SelectValue placeholder="Choose a query type" />
                        </SelectTrigger>
                        <SelectContent>
                            {def.kinds.map((k) => (
                                <SelectItem key={k.value} value={k.value}>
                                    {k.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError id="ps-return-kind-error" message={errors.return_kind} />
                </div>
            )}

            <div className="grid gap-1.5">
                <Label htmlFor="ps-reason">{isReturn ? 'Reason for the query (required)' : 'Note'}</Label>
                <textarea
                    id="ps-reason"
                    aria-required={isReturn ? true : undefined}
                    rows={3}
                    maxLength={500}
                    className={TEXTAREA}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                    aria-describedby="ps-reason-hint ps-reason-error"
                />
                <p id="ps-reason-hint" className="text-muted-foreground text-xs">
                    {isReturn ? '10 to 500 characters. Staff can see it; the vendor cannot.' : 'Up to 500 characters. Staff only.'}
                </p>
                <InputError id="ps-reason-error" message={errors.reason} />
            </div>

            <Button type="submit" disabled={form.processing || form.data.decision === '' || (isReturn && form.data.return_kind === '')}>
                Record decision
            </Button>

            <Dialog open={confirming} onOpenChange={(open) => !form.processing && setConfirming(open)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record this PS decision?</DialogTitle>
                        <DialogDescription>Check the summary. Once recorded, the decision cannot be changed here.</DialogDescription>
                    </DialogHeader>
                    <dl className="grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt className="text-muted-foreground">Decision</dt>
                        <dd className="font-medium">{chosen?.label}</dd>
                        {isReturn && (
                            <>
                                <dt className="text-muted-foreground">Query type</dt>
                                <dd>{kind?.label ?? 'Not chosen'}</dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">{isReturn ? 'Reason' : 'Note'}</dt>
                        <dd className="whitespace-pre-line">{reason === '' ? 'None' : reason}</dd>
                        <dt className="text-muted-foreground">Next</dt>
                        <dd>{isReturn ? 'The case returns to the CMO.' : 'The case returns to the CMO to issue the letter.'}</dd>
                    </dl>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={form.processing}>
                            Go back
                        </Button>
                        <Button type="button" onClick={record} disabled={form.processing}>
                            {form.processing ? 'Recording…' : 'Record decision'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}

/** The recorded PS decision, in place of the form (slice 10d). Staff only. */
export function PsOutcomeStatement({ outcome }: { outcome: PsOutcome }) {
    return (
        <div className="space-y-1 border-t pt-4 text-sm">
            <h3 className="font-medium">PS decision</h3>
            <p>
                {outcome.outcome}
                {outcome.by && ` by ${outcome.by}`}
                {outcome.at && `, ${formatDateTime(outcome.at)}`}.
            </p>
        </div>
    );
}
