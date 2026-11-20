import { ActionFacts, actionTextareaClass } from '@/components/cases/action-panel';
import { CaseTimeline } from '@/components/cases/case-timeline';
import { GateTracker } from '@/components/cases/gate-tracker';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { type GateState, type ReturnedBanner, type RouteDestination, type RoutePanel } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent, type ReactNode, useState } from 'react';

const TZ = 'America/Port_of_Spain';

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/** First error for a field or any of its items (reviewers, reviewers.0, ...). */
function fieldError(errors: Record<string, string>, field: string): string | undefined {
    return errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1];
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2">
            <h3 className="text-base-content text-sm font-semibold">{title}</h3>
            {children}
        </section>
    );
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    caseId: string;
    reference: string | null;
    procurementType: string | null;
    itemDescription: string | null;
    statusLabel: string;
    returned: ReturnedBanner | null;
    gate: GateState | null;
    panel: RoutePanel;
}

type FormData = { destination: string; reviewers: number[]; note: string };

/**
 * Send a returned case onward (slice 10e): a right-hand sheet with why the case
 * came back, recent history, the gate, and the destinations the server allows.
 * The suggestion, the sentences and the reviewer list all come from the server;
 * the server checks everything again. A confirm step restates the choice.
 */
export function SendOnwardPanel({
    open,
    onOpenChange,
    caseId,
    reference,
    procurementType,
    itemDescription,
    statusLabel,
    returned,
    gate,
    panel,
}: Props) {
    const initial: FormData = { destination: panel.suggested ?? '', reviewers: [], note: '' };
    const form = useForm<FormData>(initial);
    const errors = form.errors as Record<string, string>;
    const [confirming, setConfirming] = useState(false);

    const chosen: RouteDestination | undefined = panel.destinations.find((d) => d.value === form.data.destination);
    const toTechnocrats = chosen?.value === 'technocrats';
    const note = form.data.note.trim();
    const reviewerCount = form.data.reviewers.length;
    const ready =
        chosen !== undefined &&
        chosen.available &&
        note.length >= panel.note_min &&
        note.length <= panel.note_max &&
        (!toTechnocrats || (reviewerCount >= panel.reviewers_min && reviewerCount <= panel.reviewers_max));
    const chosenNames = panel.technocrats.filter((t) => form.data.reviewers.includes(t.id)).map((t) => t.name);

    const close = (next: boolean) => {
        if (form.processing) {
            return;
        }
        if (!next) {
            setConfirming(false);
        }
        onOpenChange(next);
    };

    const toggle = (id: number, on: boolean) =>
        form.setData('reviewers', on ? [...form.data.reviewers.filter((x) => x !== id), id] : form.data.reviewers.filter((x) => x !== id));

    const review = (e: FormEvent) => {
        e.preventDefault();
        if (ready) {
            setConfirming(true);
        }
    };

    const send = () => {
        form.transform((data) => ({ ...data, reviewers: data.destination === 'technocrats' ? data.reviewers : [] }));
        form.post(route('cases.route.onward', caseId), {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults(initial);
                form.reset();
                setConfirming(false);
                onOpenChange(false);
            },
            onError: () => setConfirming(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={close}>
            <SheetContent side="right" className="flex w-full flex-col overflow-y-auto sm:max-w-lg">
                <SheetHeader className="pr-8">
                    <SheetTitle>Send onward</SheetTitle>
                    <SheetDescription className="sr-only">Choose where this returned case goes next.</SheetDescription>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono font-semibold">{reference ?? 'Case'}</span>
                        <Badge variant="outline">{statusLabel}</Badge>
                    </div>
                    <p className="text-base-content/70 text-sm">
                        {[procurementType, itemDescription].filter((part) => part).join(' · ') || 'No item description'}
                    </p>
                </SheetHeader>

                <div className="space-y-5">
                    {returned && (
                        <Block title="Why it came back">
                            <div className="bg-base-200 border-base-300 rounded-box space-y-1 border p-3 text-sm">
                                <p className="font-medium">
                                    Returned to CMO{returned.source ? ` by ${returned.source}` : ''}
                                    {returned.kind ? ` (${returned.kind})` : ''}
                                </p>
                                {returned.reason && <p className="whitespace-pre-line">{returned.reason}</p>}
                                <p className="text-base-content/70">
                                    {[returned.by, returned.at ? formatDateTime(returned.at) : null].filter((part) => part).join(', ')}
                                </p>
                            </div>
                        </Block>
                    )}

                    <Block title="Recent history">
                        <CaseTimeline entries={panel.timeline} />
                    </Block>

                    <Block title="Gate">
                        <GateTracker gate={gate} />
                    </Block>

                    <Separator />

                    {confirming && chosen ? (
                        <Block title="Check before sending">
                            <ActionFacts
                                items={[
                                    [
                                        'Send to',
                                        <>
                                            {chosen.label}
                                            <span className="text-base-content/70 block font-normal">{chosen.consequence}</span>
                                        </>,
                                    ],
                                    ...(toTechnocrats ? ([['Technocrats', chosenNames.join(', ')]] as [string, string][]) : []),
                                    ['Note', note],
                                ]}
                            />
                        </Block>
                    ) : (
                        <form id="route-onward-form" onSubmit={review} className="space-y-4" noValidate>
                            <fieldset className="fieldset gap-2 py-0" aria-describedby="route-destination-hint route-destination-error">
                                <legend className="fieldset-legend pt-0">Send to</legend>
                                {panel.suggested_line && (
                                    <p id="route-destination-hint" className="text-base-content/70 text-sm">
                                        {panel.suggested_line}
                                    </p>
                                )}
                                {panel.destinations.map((d) => (
                                    <label
                                        key={d.value}
                                        htmlFor={`route-${d.value}`}
                                        className="label text-base-content cursor-pointer items-start gap-2 text-sm whitespace-normal"
                                    >
                                        <input
                                            type="radio"
                                            id={`route-${d.value}`}
                                            name="route-destination"
                                            value={d.value}
                                            checked={form.data.destination === d.value}
                                            disabled={!d.available}
                                            onChange={() => form.setData('destination', d.value)}
                                            className="radio radio-sm mt-0.5"
                                        />
                                        <span className="grid gap-0.5">
                                            <span className="font-medium">{d.label}</span>
                                            <span className="text-base-content/70">{d.consequence}</span>
                                        </span>
                                    </label>
                                ))}
                                <InputError id="route-destination-error" message={errors.destination ?? errors.route} />
                            </fieldset>

                            {toTechnocrats && (
                                <fieldset className="fieldset gap-2 py-0" aria-describedby="route-reviewers-hint route-reviewers-error">
                                    <legend className="fieldset-legend pt-0">Technocrats</legend>
                                    <p id="route-reviewers-hint" className="label whitespace-normal">
                                        Choose {panel.reviewers_min} to {panel.reviewers_max}.
                                    </p>
                                    {panel.technocrats.length === 0 ? (
                                        <p className="text-base-content/70 text-sm">No eligible Technocrats are available.</p>
                                    ) : (
                                        <ul className="border-base-300 rounded-box grid max-h-48 gap-2 overflow-y-auto border p-3 sm:grid-cols-2">
                                            {panel.technocrats.map((t) => {
                                                const id = `route-reviewer-${t.id}`;
                                                return (
                                                    <li key={t.id}>
                                                        <label htmlFor={id} className="label text-base-content cursor-pointer gap-2 text-sm">
                                                            <input
                                                                type="checkbox"
                                                                id={id}
                                                                className="checkbox checkbox-sm"
                                                                checked={form.data.reviewers.includes(t.id)}
                                                                onChange={(e) => toggle(t.id, e.target.checked)}
                                                            />
                                                            {t.name}
                                                        </label>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                    <InputError id="route-reviewers-error" message={fieldError(errors, 'reviewers')} />
                                </fieldset>
                            )}

                            <fieldset className="fieldset py-0">
                                <label className="label text-base-content text-sm font-medium" htmlFor="route-note">
                                    Note
                                </label>
                                <textarea
                                    id="route-note"
                                    rows={4}
                                    maxLength={panel.note_max}
                                    className={actionTextareaClass}
                                    value={form.data.note}
                                    onChange={(e) => form.setData('note', e.target.value)}
                                    aria-invalid={errors.note ? true : undefined}
                                    aria-describedby="route-note-hint route-note-error"
                                />
                                <p id="route-note-hint" className="label whitespace-normal">
                                    {panel.note_min} to {panel.note_max} characters. Shown to staff in the case history.
                                </p>
                                <InputError id="route-note-error" message={errors.note} />
                            </fieldset>
                        </form>
                    )}
                </div>

                {/* The sheet's buttons, always last: pinned to the bottom edge while the body scrolls. */}
                <SheetFooter className="border-base-300 bg-base-100 sticky bottom-0 -mx-6 mt-auto -mb-6 flex-row flex-wrap justify-end border-t px-6 py-3">
                    {confirming && chosen ? (
                        <>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setConfirming(false)} disabled={form.processing}>
                                Back
                            </Button>
                            <Button type="button" size="sm" onClick={send} disabled={form.processing}>
                                {form.processing ? 'Sending…' : 'Send now'}
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button type="button" variant="ghost" size="sm" onClick={() => close(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" form="route-onward-form" size="sm" disabled={!ready}>
                                Review
                            </Button>
                        </>
                    )}
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
