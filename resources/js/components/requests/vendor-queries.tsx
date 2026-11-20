import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type VendorQuery } from '@/types/requests';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

const BODY_MAX = 2000;

function ReplyForm({ caseId, queryId }: { caseId: string; queryId: string }) {
    const form = useForm({ body: '' });
    const errors = form.errors as Record<string, string>;
    const id = `vendor-query-${queryId}-reply`;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('requests.queries.reply', [caseId, queryId]), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="space-y-3 border-t pt-3" noValidate>
            <div className="grid gap-1.5">
                <Label htmlFor={id}>Your Reply</Label>
                <textarea
                    id={id}
                    aria-required="true"
                    rows={4}
                    maxLength={BODY_MAX}
                    className="border-input placeholder:text-muted-foreground focus-visible:ring-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-3"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    aria-invalid={errors.body ? true : undefined}
                    aria-describedby={`${id}-hint ${id}-error`}
                />
                <p id={`${id}-hint`} className="text-muted-foreground text-xs">
                    Text only, up to {BODY_MAX} characters. To send a document, add it in Documents.
                </p>
                <InputError id={`${id}-error`} message={errors.body ?? errors.reply} />
            </div>
            <Button type="submit" disabled={form.processing || form.data.body.trim() === ''}>
                {form.processing ? 'Sending…' : 'Send Reply'}
            </Button>
        </form>
    );
}

/**
 * OVERNIGHT-2 (D3): "Queries From the Ministry" on the vendor's request page.
 * Only what the server sends: external messages already delivered.
 */
export function VendorQueries({ caseId, queries }: { caseId: string; queries: VendorQuery[] }) {
    if (queries.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="vendor-queries-heading" className="bg-card space-y-4 rounded-lg border p-4">
            <h2 id="vendor-queries-heading" className="text-lg font-semibold">
                Queries From the Ministry
            </h2>
            {queries.map((q) => (
                <article key={q.id} aria-labelledby={`vendor-query-${q.id}`} className="space-y-3 rounded-md border p-3">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 id={`vendor-query-${q.id}`} className="font-medium">
                            {q.title}
                        </h3>
                        <p className="text-muted-foreground text-helper">
                            {q.status_label}
                            {q.raised_on && ` · ${q.raised_on}`}
                        </p>
                    </header>
                    <ol className="space-y-2">
                        {q.messages.map((m) => (
                            <li key={m.id} className="space-y-1">
                                <p className="text-helper">
                                    <span className="font-medium">{m.from_label}</span>
                                    {m.sent_on && <span className="text-muted-foreground"> · {m.sent_on}</span>}
                                </p>
                                <p className="text-sm whitespace-pre-line">{m.body}</p>
                            </li>
                        ))}
                    </ol>
                    {q.can_reply && <ReplyForm caseId={caseId} queryId={q.id} />}
                </article>
            ))}
        </section>
    );
}
