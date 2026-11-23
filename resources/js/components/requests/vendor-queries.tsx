import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { type VendorQuery } from '@/types/requests';
import { useForm } from '@inertiajs/react';
import { MessageSquareText, Reply, Send } from 'lucide-react';
import { FormEvent, useState } from 'react';

const BODY_MAX = 2000;

/**
 * The reply box, behind a Reply button until the vendor wants it. Cancel and
 * Send Reply sit at the end (secondary first, primary last).
 */
function ReplyForm({ caseId, queryId }: { caseId: string; queryId: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ body: '' });
    const errors = form.errors as Record<string, string>;
    const id = `vendor-query-${queryId}-reply`;
    const error = errors.body ?? errors.reply;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('requests.queries.reply', [caseId, queryId]), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    if (!open && !error) {
        return (
            <div className="flex justify-end">
                <button type="button" className="btn btn-sm capitalize max-md:h-11" onClick={() => setOpen(true)}>
                    <Reply className="size-4" aria-hidden="true" />
                    Reply
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="border-base-300 space-y-2 border-t pt-3" noValidate>
            <label htmlFor={id} className="grid gap-1.5">
                <span className="text-sm font-medium capitalize">Your Reply</span>
                <textarea
                    id={id}
                    aria-required="true"
                    autoFocus
                    rows={3}
                    maxLength={BODY_MAX}
                    className={cn('textarea w-full', error && 'textarea-error')}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={`${id}-hint ${id}-error`}
                />
            </label>
            <p id={`${id}-hint`} className="text-base-content/70 text-xs">
                Text only, up to {BODY_MAX} characters. To send a document, add it in Documents.
            </p>
            <InputError id={`${id}-error`} message={error} />
            <div className="flex justify-end gap-2">
                <button
                    type="button"
                    className="btn btn-ghost btn-sm capitalize max-md:h-11"
                    disabled={form.processing}
                    onClick={() => {
                        form.reset();
                        form.clearErrors();
                        setOpen(false);
                    }}
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    className="btn btn-primary btn-sm capitalize max-md:h-11"
                    disabled={form.processing || form.data.body.trim() === ''}
                    aria-busy={form.processing || undefined}
                >
                    {form.processing ? (
                        <span className="loading loading-spinner loading-xs" aria-hidden="true" />
                    ) : (
                        <Send className="size-4" aria-hidden="true" />
                    )}
                    {form.processing ? 'Sending…' : 'Send Reply'}
                </button>
            </div>
        </form>
    );
}

/**
 * OVERNIGHT-2 (D3): "Queries From the Ministry" on the vendor's request page.
 * Only what the server sends: external messages already delivered. Each query
 * is one bar (Blueprint collapse, details/summary): the whole bar opens it,
 * and it shows the title, status, date, message count and whether a reply is
 * waiting. Inside: the messages in order, then Reply.
 */
export function VendorQueries({ caseId, queries }: { caseId: string; queries: VendorQuery[] }) {
    if (queries.length === 0) {
        return null;
    }

    const waiting = queries.filter((q) => q.can_reply).length;

    return (
        <section aria-labelledby="vendor-queries-heading" className="card bg-base-100 border-base-300 min-w-0 border">
            <div className="card-body min-w-0 gap-3 p-4">
                <h2 id="vendor-queries-heading" className="card-title flex-wrap text-lg capitalize">
                    <MessageSquareText className="text-primary size-5 shrink-0" aria-hidden="true" />
                    Queries From the Ministry
                    {waiting > 0 && <span className="badge badge-soft badge-warning badge-sm capitalize">{waiting} awaiting your reply</span>}
                </h2>
                <div className="grid gap-2">
                    {queries.map((q) => (
                        <details key={q.id} className="collapse-arrow border-base-300 rounded-box collapse min-w-0 border">
                            <summary className="collapse-title hover:bg-base-200 min-h-0 py-3 ps-4 pe-10 motion-safe:transition-colors">
                                <span className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                    <span className="min-w-0 flex-1 truncate font-medium">{q.title}</span>
                                    <span className="text-base-content/70 flex flex-wrap items-center gap-2 text-sm">
                                        {q.can_reply && <span className="badge badge-soft badge-warning badge-sm capitalize">Reply needed</span>}
                                        <span className="badge badge-ghost badge-sm capitalize">{q.status_label}</span>
                                        <span className="tabular-nums">
                                            {q.messages.length} {q.messages.length === 1 ? 'message' : 'messages'}
                                        </span>
                                        {q.raised_on && <span className="tabular-nums">· {q.raised_on}</span>}
                                    </span>
                                </span>
                            </summary>
                            <div className="collapse-content min-w-0 space-y-3 px-4">
                                <ol className="space-y-3">
                                    {q.messages.map((m) => (
                                        <li key={m.id} className="border-base-300 space-y-1 border-l-2 pl-3">
                                            <p className="text-sm">
                                                <span className="font-medium">{m.from_label}</span>
                                                {m.sent_on && <span className="text-base-content/70 tabular-nums"> · {m.sent_on}</span>}
                                            </p>
                                            <p className="text-sm whitespace-pre-line">{m.body}</p>
                                        </li>
                                    ))}
                                </ol>
                                {q.can_reply && <ReplyForm caseId={caseId} queryId={q.id} />}
                            </div>
                        </details>
                    ))}
                </div>
            </div>
        </section>
    );
}
