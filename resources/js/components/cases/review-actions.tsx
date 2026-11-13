import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type GateState, type ReviewForm } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/**
 * One reviewer's outcome form for their own track (slice 10b). Shown only for
 * the server's review_forms; the server checks permission, assignment and
 * status again on every save.
 */
export function ReviewOutcomeForm({ caseId, form: def }: { caseId: string; form: ReviewForm }) {
    const form = useForm({ outcome: def.current?.outcome ?? '', reason: '' });
    const errors = form.errors as Record<string, string>;
    const negative = def.outcomes.find((o) => o.value === form.data.outcome)?.positive === false;
    const id = `review-${def.track}`;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route(def.track === 'technical' ? 'cases.technical' : 'cases.funding', caseId), {
            preserveScroll: true,
            onSuccess: () => form.setData('reason', ''),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3 border-t pt-4" noValidate>
            <h3 className="font-medium">Your {def.label.toLowerCase()}</h3>
            <p className="text-sm">
                {def.current ? (
                    <>
                        Current outcome: <span className="font-medium">{def.current.label}</span>
                        <span className="text-muted-foreground"> · recorded {formatDateTime(def.current.recorded_at)}</span>
                    </>
                ) : (
                    <span className="text-muted-foreground">You have not recorded an outcome yet.</span>
                )}
            </p>

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
                <Label htmlFor={`${id}-reason`}>{negative ? 'Reason (required)' : 'Note (optional)'}</Label>
                <textarea
                    id={`${id}-reason`}
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

            <Button type="submit" disabled={form.processing || form.data.outcome === ''}>
                {form.processing ? 'Saving…' : 'Save'}
            </Button>
        </form>
    );
}

/**
 * T4 for the CMO (slice 10b). Enabled only when the server says the gate is
 * open; otherwise the server's missing list is shown as text.
 */
export function MarkReadyForFile({ caseId, gate }: { caseId: string; gate: GateState | null }) {
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const open = gate?.open === true;

    return (
        <div className="space-y-2 border-t pt-4">
            <h3 className="font-medium">Ready for file</h3>
            {!open && gate && gate.missing.length > 0 && (
                <ul className="text-muted-foreground list-disc pl-5 text-sm" id="ready-missing">
                    {gate.missing.map((m) => (
                        <li key={m}>{m}</li>
                    ))}
                </ul>
            )}
            <Button
                type="button"
                onClick={() => form.post(route('cases.ready', caseId), { preserveScroll: true })}
                disabled={!open || form.processing}
                aria-describedby={!open ? 'ready-missing' : undefined}
            >
                {form.processing ? 'Saving…' : 'Mark ready for file'}
            </Button>
            <InputError message={errors.gate} />
        </div>
    );
}
