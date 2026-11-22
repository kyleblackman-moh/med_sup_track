import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { type VendorQuery } from '@/types/requests';
import { useForm } from '@inertiajs/react';
import { MessageSquareText, Send } from 'lucide-react';
import { FormEvent } from 'react';

const BODY_MAX = 2000;

function ReplyForm({ caseId, queryId }: { caseId: string; queryId: string }) {
    const form = useForm({ body: '' });
    const errors = form.errors as Record<string, string>;
    const id = `vendor-query-${queryId}-reply`;
    const error = errors.body ?? errors.reply;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('requests.queries.reply', [caseId, queryId]), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="border-base-300 space-y-3 border-t pt-3" noValidate>
            <div className="grid gap-1.5">
                <label htmlFor={id} className="grid gap-1.5">
                    <span className="text-sm font-medium capitalize">Your Reply</span>
                    <textarea
                        id={id}
                        aria-required="true"
                        rows={4}
                        maxLength={BODY_MAX}
                        className={cn('textarea w-full', error && 'textarea-error')}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        aria-invalid={errors.body ? true : undefined}
                        aria-describedby={`${id}-hint ${id}-error`}
                    />
                </label>
                <p id={`${id}-hint`} className="text-base-content/70 text-xs">
                    Text only, up to {BODY_MAX} characters. To send a document, add it in Documents.
                </p>
                <InputError id={`${id}-error`} message={error} />
            </div>
            <div className="flex justify-end">
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
 * is a bordered thread: title and status, the messages in order, then the
 * reply form with its button at the end.
 */
export function VendorQueries({ caseId, queries }: { caseId: string; queries: VendorQuery[] }) {
    if (queries.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="vendor-queries-heading" className="card bg-base-100 border-base-300 min-w-0 border">
            <div className="card-body min-w-0 gap-4 p-4">
                <h2 id="vendor-queries-heading" className="card-title text-lg capitalize">
                    <MessageSquareText className="text-primary size-5 shrink-0" aria-hidden="true" />
                    Queries From the Ministry
                </h2>
                {queries.map((q) => (
                    <article key={q.id} aria-labelledby={`vendor-query-${q.id}`} className="border-base-300 rounded-box space-y-3 border p-3">
                        <header className="flex flex-wrap items-center justify-between gap-2">
                            <h3 id={`vendor-query-${q.id}`} className="font-medium">
                                {q.title}
                            </h3>
                            <p className="text-base-content/70 flex items-center gap-2 text-sm">
                                <span className="badge badge-ghost badge-sm capitalize">{q.status_label}</span>
                                {q.raised_on && <span className="tabular-nums">{q.raised_on}</span>}
                            </p>
                        </header>
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
                    </article>
                ))}
            </div>
        </section>
    );
}
