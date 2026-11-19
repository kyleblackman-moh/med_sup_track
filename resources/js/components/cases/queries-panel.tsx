import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { StatusPill } from '@/components/ui/status-pill';
import { type StaffQueries, type StaffQuery, type StaffQueryMessage } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

const TEXTAREA =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]';

const BODY_MAX = 2000;

/** The message box shared by Raise, Answer and Add a Message. */
function MessageField({ id, value, onChange, error }: { id: string; value: string; onChange: (v: string) => void; error?: string }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>Message</Label>
            <textarea
                id={id}
                aria-required="true"
                rows={4}
                maxLength={BODY_MAX}
                className={TEXTAREA}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={`${id}-hint ${id}-error`}
            />
            <p id={`${id}-hint`} className="text-muted-foreground text-xs">
                Up to {BODY_MAX} characters.
            </p>
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/** Raise a query on one of the routes the server allows this user. */
function RaiseQuery({ caseId, routes }: { caseId: string; routes: { id: number; label: string }[] }) {
    const form = useForm<{ route: string; body: string }>({ route: routes.length === 1 ? String(routes[0].id) : '', body: '' });
    const errors = form.errors as Record<string, string>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('cases.queries.store', caseId), { preserveScroll: true, onSuccess: () => form.reset('body') });
    };

    return (
        <form onSubmit={submit} className="bg-card space-y-3 rounded-lg border p-4" noValidate>
            <h3 className="font-medium">Raise a Query</h3>
            <fieldset className="grid gap-2" aria-describedby="query-route-error">
                <legend className="text-sm font-medium">Send To</legend>
                {routes.map((r) => (
                    <div key={r.id} className="flex items-center gap-2">
                        <input
                            type="radio"
                            id={`query-route-${r.id}`}
                            name="query-route"
                            value={r.id}
                            checked={form.data.route === String(r.id)}
                            onChange={() => form.setData('route', String(r.id))}
                            className="accent-primary focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        />
                        <Label htmlFor={`query-route-${r.id}`} className="font-normal">
                            {r.label}
                        </Label>
                    </div>
                ))}
                <InputError id="query-route-error" message={errors.route} />
            </fieldset>
            <MessageField id="query-body" value={form.data.body} onChange={(v) => form.setData('body', v)} error={errors.body} />
            <Button type="submit" disabled={form.processing || form.data.route === '' || form.data.body.trim() === ''}>
                {form.processing ? 'Sending…' : 'Raise Query'}
            </Button>
        </form>
    );
}

/** The CMO's forward of a held message, with a confirmation that names the destination. */
function ForwardMessage({ caseId, queryId, message }: { caseId: string; queryId: string; message: StaffQueryMessage }) {
    const [open, setOpen] = useState(false);
    const form = useForm({});
    const errors = Object.values(form.errors as Record<string, string>);

    if (!message.forward) {
        return null;
    }

    const submit = () =>
        form.post(route('cases.queries.forward', [caseId, queryId, message.id]), { preserveScroll: true, onSuccess: () => setOpen(false) });

    return (
        <>
            <Button type="button" size="sm" onClick={() => setOpen(true)}>
                {message.forward.label}
            </Button>
            <Dialog open={open} onOpenChange={(o) => !form.processing && setOpen(o)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{message.forward.title}</DialogTitle>
                        <DialogDescription>{message.forward.body}</DialogDescription>
                    </DialogHeader>
                    {errors.map((m) => (
                        <InputError key={m} message={m} />
                    ))}
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={submit} disabled={form.processing}>
                            {form.processing ? 'Forwarding…' : message.forward.label}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function QueryCard({ caseId, query }: { caseId: string; query: StaffQuery }) {
    const reply = useForm({ body: '' });
    const close = useForm({});
    const [confirmClose, setConfirmClose] = useState(false);
    const replyId = `query-${query.id}-reply`;

    const submitReply = (e: FormEvent) => {
        e.preventDefault();
        reply.post(route('cases.queries.reply', [caseId, query.id]), { preserveScroll: true, onSuccess: () => reply.reset() });
    };

    return (
        <article aria-labelledby={`query-${query.id}-title`} className="bg-card space-y-3 rounded-lg border p-4">
            <header className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-0.5">
                    <h3 id={`query-${query.id}-title`} className="font-medium">
                        {query.title}
                    </h3>
                    <p className="text-muted-foreground text-[13px]">
                        {query.route_label}
                        {query.raised_by && ` · Raised by ${query.raised_by}`}
                        {query.raised_on && ` on ${query.raised_on}`}
                    </p>
                </div>
                <StatusPill label={query.status_label} tone={query.status_tone} />
            </header>

            {query.waiting_on && <p className="text-sm">{query.waiting_on}</p>}
            {query.read_only && <p className="text-muted-foreground text-sm">You can read this query. You are not one of its sides.</p>}

            <ol className="space-y-3">
                {query.messages.map((m) => (
                    <li key={m.id} className="space-y-1 rounded-md border px-3 py-2">
                        <p className="text-[13px]">
                            <span className="font-medium">{m.from_label}</span>
                            {m.author && <span className="text-muted-foreground"> ({m.author})</span>}
                            <span className="text-muted-foreground"> to {m.to_label}</span>
                            {m.sent_on && <span className="text-muted-foreground"> · {m.sent_on}</span>}
                        </p>
                        <p className="text-sm whitespace-pre-line">{m.body}</p>
                        <p className="text-muted-foreground text-[13px]">{m.state}</p>
                        <ForwardMessage caseId={caseId} queryId={query.id} message={m} />
                    </li>
                ))}
            </ol>

            {query.can_reply && (
                <form onSubmit={submitReply} className="space-y-3 border-t pt-3" noValidate>
                    <MessageField
                        id={replyId}
                        value={reply.data.body}
                        onChange={(v) => reply.setData('body', v)}
                        error={reply.errors.body ?? (reply.errors as Record<string, string>).reply}
                    />
                    <Button type="submit" disabled={reply.processing || reply.data.body.trim() === ''}>
                        {reply.processing ? 'Sending…' : query.reply_label}
                    </Button>
                </form>
            )}

            {query.can_close && (
                <div className="border-t pt-3">
                    <Button type="button" variant="outline" size="sm" onClick={() => setConfirmClose(true)}>
                        Close Query
                    </Button>
                    <Dialog open={confirmClose} onOpenChange={(o) => !close.processing && setConfirmClose(o)}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Close this query?</DialogTitle>
                                <DialogDescription>Nobody can add to it once it is closed. The messages stay on the case.</DialogDescription>
                            </DialogHeader>
                            <InputError message={(close.errors as Record<string, string>).close} />
                            <DialogFooter className="gap-2">
                                <Button type="button" variant="ghost" onClick={() => setConfirmClose(false)} disabled={close.processing}>
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    disabled={close.processing}
                                    onClick={() =>
                                        close.post(route('cases.queries.close', [caseId, query.id]), {
                                            preserveScroll: true,
                                            onSuccess: () => setConfirmClose(false),
                                        })
                                    }
                                >
                                    {close.processing ? 'Closing…' : 'Close Query'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            )}
        </article>
    );
}

/**
 * OVERNIGHT-2 (D2): the staff Queries tab. Every word comes from the server's
 * QueryView DTO; the server authorises every request again.
 */
export function QueriesPanel({ caseId, data }: { caseId: string; data: StaffQueries }) {
    return (
        <section aria-labelledby="queries-heading" className="space-y-4">
            <h2 id="queries-heading" className="text-lg font-semibold">
                Queries
            </h2>
            {data.queries.length === 0 && <p className="text-muted-foreground text-sm">There are no queries on this case.</p>}
            {data.queries.map((q) => (
                <QueryCard key={q.id} caseId={caseId} query={q} />
            ))}
            {data.raise && <RaiseQuery caseId={caseId} routes={data.raise.routes} />}
        </section>
    );
}
