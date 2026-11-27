import { actionTextareaClass } from '@/components/cases/action-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { RadioGroup } from '@/components/ui/radio-group';
import { StatusPill } from '@/components/ui/status-pill';
import { cn } from '@/lib/utils';
import { type StaffQueries, type StaffQuery, type StaffQueryMessage } from '@/types/cases';
import { useForm } from '@inertiajs/react';
import { MessageCircleQuestion, MessageSquarePlus, Reply } from 'lucide-react';
import { FormEvent, useState } from 'react';

const BODY_MAX = 2000;

/** The message box shared by Raise, Answer and Add a Message. */
function MessageField({
    id,
    value,
    onChange,
    error,
    autoFocus,
}: {
    id: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    autoFocus?: boolean;
}) {
    return (
        <fieldset className="fieldset py-0">
            <label className="label text-base-content text-sm font-medium capitalize" htmlFor={id}>
                Message
            </label>
            <textarea
                id={id}
                aria-label="Message"
                aria-required="true"
                autoFocus={autoFocus}
                rows={3}
                maxLength={BODY_MAX}
                className={actionTextareaClass}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={`${id}-hint ${id}-error`}
            />
            <p id={`${id}-hint`} className="label">
                Up to {BODY_MAX} characters.
            </p>
            <InputError id={`${id}-error`} message={error} />
        </fieldset>
    );
}

/**
 * Raise a query on one of the routes the server allows this user: one bar
 * (Blueprint collapse) that opens the form, with its button at the end.
 */
function RaiseQuery({ caseId, routes }: { caseId: string; routes: { id: number; label: string }[] }) {
    const form = useForm<{ route: string; body: string }>({ route: routes.length === 1 ? String(routes[0].id) : '', body: '' });
    const errors = form.errors as Record<string, string>;
    const [open, setOpen] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('cases.queries.store', caseId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('body');
                setOpen(false);
            },
        });
    };

    return (
        <details
            className="collapse-plus border-base-300 rounded-box bg-base-100 collapse min-w-0 border"
            open={open || Object.keys(errors).length > 0}
            onToggle={(e) => setOpen(e.currentTarget.open)}
        >
            <summary className="collapse-title hover:bg-base-200 min-h-0 py-3 ps-4 pe-10 font-medium capitalize motion-safe:transition-colors">
                <span className="flex items-center gap-2">
                    <MessageSquarePlus className="text-base-content/70 size-4 shrink-0" aria-hidden="true" />
                    Raise a Query
                </span>
            </summary>
            <form onSubmit={submit} noValidate className="collapse-content min-w-0 space-y-3 px-4">
                <RadioGroup
                    name="query-route"
                    legend="Send To"
                    options={routes.map((r) => ({ value: String(r.id), label: r.label }))}
                    value={form.data.route}
                    onChange={(value) => form.setData('route', value)}
                    optionId={(r) => `query-route-${r.value}`}
                    describedBy="query-route-error"
                >
                    <InputError id="query-route-error" message={errors.route} />
                </RadioGroup>
                <MessageField id="query-body" value={form.data.body} onChange={(v) => form.setData('body', v)} error={errors.body} />
                <div className="border-base-300 flex justify-end gap-2 border-t pt-3">
                    <Button type="button" variant="ghost" size="sm" className="capitalize" disabled={form.processing} onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        size="sm"
                        className="capitalize active:scale-[0.97]"
                        disabled={form.processing || form.data.route === '' || form.data.body.trim() === ''}
                    >
                        {form.processing ? 'Sending…' : 'Raise Query'}
                    </Button>
                </div>
            </form>
        </details>
    );
}

/**
 * The CMO's forward of a held message, with a confirmation that names the
 * destination. The button sits in the query's footer; it names the message
 * it forwards for screen readers.
 */
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
            <Button type="button" size="sm" className="active:scale-[0.97]" onClick={() => setOpen(true)}>
                {message.forward.label}
                {message.sent_on && <span className="sr-only"> (message of {message.sent_on})</span>}
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

/**
 * One query as one bar (Blueprint collapse, details/summary): the whole bar
 * opens it. The bar shows the title, status, route, date, message count and
 * "Action needed" when this user can answer or forward. Inside: the thread,
 * then the reply box behind its button, and the buttons in a footer row.
 */
function QueryCard({ caseId, query }: { caseId: string; query: StaffQuery }) {
    const reply = useForm({ body: '' });
    const close = useForm({});
    const [confirmClose, setConfirmClose] = useState(false);
    // Kyle, 2026-11-27: a query waiting on this user's answer opens with the answer box ready.
    const [replying, setReplying] = useState(query.needs_my_answer);
    const replyId = `query-${query.id}-reply`;
    const forwards = query.messages.filter((m) => m.forward);
    const replyError = reply.errors.body ?? (reply.errors as Record<string, string>).reply;
    const showReply = query.can_reply && (replying || Boolean(replyError));
    const hasActions = query.can_close || query.can_reply || forwards.length > 0;
    // Kyle, 2026-11-27: only what waits on this user (the server's rule), not every query they could add to.
    const actionNeeded = query.needs_my_answer || query.needs_my_forward;

    const submitReply = (e: FormEvent) => {
        e.preventDefault();
        reply.post(route('cases.queries.reply', [caseId, query.id]), {
            preserveScroll: true,
            onSuccess: () => {
                reply.reset();
                setReplying(false);
            },
        });
    };

    const cancelReply = () => {
        reply.reset();
        reply.clearErrors();
        setReplying(false);
    };

    return (
        <details
            className={cn(
                'collapse-arrow rounded-box bg-base-100 collapse min-w-0 border',
                actionNeeded ? 'border-warning border-s-4' : 'border-base-300',
            )}
            open={actionNeeded || undefined}
        >
            <summary className="collapse-title hover:bg-base-200 min-h-0 py-3 ps-4 pe-10 motion-safe:transition-colors">
                <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="flex min-w-0 flex-1 items-center gap-2">
                        <MessageCircleQuestion className="text-base-content/70 size-4 shrink-0" aria-hidden="true" />
                        <span className="truncate font-medium">{query.title}</span>
                    </span>
                    <span className="text-base-content/70 flex flex-wrap items-center gap-2 text-sm">
                        {actionNeeded && <span className="badge badge-soft badge-warning badge-sm capitalize">Action needed</span>}
                        <StatusPill label={query.status_label} tone={query.status_tone} className="capitalize" />
                        <span>{query.route_label}</span>
                        <span className="tabular-nums">
                            {query.messages.length} {query.messages.length === 1 ? 'message' : 'messages'}
                        </span>
                        {query.raised_on && <span className="tabular-nums">· {query.raised_on}</span>}
                    </span>
                </span>
            </summary>

            <form onSubmit={query.can_reply ? submitReply : (e) => e.preventDefault()} noValidate className="collapse-content min-w-0 space-y-3 px-4">
                {query.raised_by && (
                    <p className="text-base-content/70 text-sm">
                        Raised by {query.raised_by}
                        {query.raised_on && ` on ${query.raised_on}`}
                    </p>
                )}
                {query.waiting_on && <p className="text-sm">{query.waiting_on}</p>}
                {query.read_only && <p className="text-base-content/70 text-sm">You can read this query. You are not one of its sides.</p>}

                <ol className="list bg-base-200 rounded-box">
                    {query.messages.map((m) => (
                        <li key={m.id} className="list-row block space-y-1 py-2.5">
                            <p className="text-xs">
                                <span className="font-medium">{m.from_label}</span>
                                {m.author && <span className="text-base-content/70"> ({m.author})</span>}
                                <span className="text-base-content/70"> to {m.to_label}</span>
                                {m.sent_on && <span className="text-base-content/70 tabular-nums"> · {m.sent_on}</span>}
                            </p>
                            <p className="text-sm whitespace-pre-line">{m.body}</p>
                            <p className="text-base-content/70 text-xs">{m.state}</p>
                        </li>
                    ))}
                </ol>

                {showReply && (
                    <MessageField id={replyId} autoFocus value={reply.data.body} onChange={(v) => reply.setData('body', v)} error={replyError} />
                )}

                {hasActions && (
                    <div className="border-base-300 flex flex-wrap items-center justify-end gap-2 border-t pt-3 [&_.btn]:capitalize">
                        {showReply ? (
                            <>
                                <Button type="button" variant="ghost" size="sm" disabled={reply.processing} onClick={cancelReply}>
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="active:scale-[0.97]"
                                    disabled={reply.processing || reply.data.body.trim() === ''}
                                >
                                    {reply.processing ? 'Sending…' : query.reply_label}
                                </Button>
                            </>
                        ) : (
                            <>
                                {query.can_close && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="active:scale-[0.97]"
                                        onClick={() => setConfirmClose(true)}
                                    >
                                        Close Query
                                    </Button>
                                )}
                                {forwards.map((m) => (
                                    <ForwardMessage key={m.id} caseId={caseId} queryId={query.id} message={m} />
                                ))}
                                {query.can_reply && (
                                    <Button type="button" size="sm" className="active:scale-[0.97]" onClick={() => setReplying(true)}>
                                        <Reply className="size-4" aria-hidden="true" />
                                        {query.reply_label}
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                )}
            </form>

            {query.can_close && (
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
            )}
        </details>
    );
}

/**
 * OVERNIGHT-2 (D2): the staff Queries tab. Every word comes from the server's
 * QueryView DTO; the server authorises every request again.
 */
export function QueriesPanel({ caseId, data }: { caseId: string; data: StaffQueries }) {
    const waiting = data.queries.filter((q) => q.needs_my_answer || q.needs_my_forward).length;

    return (
        <section aria-labelledby="queries-heading" className="flex flex-col gap-2">
            <h2 id="queries-heading" className="flex flex-wrap items-center gap-2 text-lg font-semibold capitalize">
                Queries
                {waiting > 0 && <span className="badge badge-soft badge-warning badge-sm capitalize">{waiting} need your action</span>}
            </h2>
            {data.queries.length === 0 && <p className="text-base-content/70 text-sm">There are no queries on this case.</p>}
            {data.queries.map((q) => (
                <QueryCard key={q.id} caseId={caseId} query={q} />
            ))}
            {data.raise && <RaiseQuery caseId={caseId} routes={data.raise.routes} />}
        </section>
    );
}
