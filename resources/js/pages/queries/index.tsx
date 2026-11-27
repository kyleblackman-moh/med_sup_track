import { MessageField } from '@/components/cases/queries-panel';
import InputError from '@/components/input-error';
import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import AppLayout from '@/layouts/app-layout';
import { fullTime } from '@/lib/notifications';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, FilterX, MessageSquareText } from 'lucide-react';
import { FormEvent } from 'react';

type QueryFilter = 'all' | 'answer' | 'forward';

interface QueryInboxRow {
    id: string;
    case_id: string;
    step: 'query_forward' | 'query_answer';
    action: string;
    title: string;
    reference_no: string | null;
    item_description: string | null;
    organisation: string | null;
    waiting_since: string | null;
    days_waiting: number | null;
    url: string;
    /** Kyle, 2026-11-27: staff answer or forward here (QueryView::inbox); null for vendors, who use the link. */
    respond: QueryRespond | null;
}

interface QueryRespond {
    messages: { id: string; from_label: string; author: string | null; to_label: string; body: string; sent_on: string | null; held: boolean }[];
    answer: { url: string; label: string } | null;
    forward: { url: string; label: string; to: string } | null;
}

interface Props {
    queries: QueryInboxRow[];
    filter: QueryFilter;
    counts: Record<QueryFilter, number>;
    forward_desk: boolean;
}

const FILTERS: { value: QueryFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'answer', label: 'To Answer' },
    { value: 'forward', label: 'To Forward' },
];

const EMPTY: Record<QueryFilter, string> = {
    all: 'No queries are waiting on you.',
    answer: 'No queries are waiting for your answer.',
    forward: 'No queries are waiting to be forwarded.',
};

/**
 * Kyle, 2026-11-27: answer or forward a query without leaving the page. The
 * latest messages, then the answer box or the forward confirmation, buttons
 * at the end. The server checks everything again and brings the user back
 * here (from: 'queries').
 */
function RespondHere({ row, respond }: { row: QueryInboxRow; respond: QueryRespond }) {
    const answer = useForm({ body: '', from: 'queries' });
    const forward = useForm({ from: 'queries' });
    const errors = Object.values({ ...(answer.errors as Record<string, string>), ...(forward.errors as Record<string, string>) }).filter(
        (m) => m !== answer.errors.body,
    );
    const fieldId = `inbox-answer-${row.id}`;
    const label = respond.forward?.label ?? respond.answer?.label ?? row.action;

    const submitAnswer = (e: FormEvent) => {
        e.preventDefault();
        if (respond.answer) answer.post(respond.answer.url, { preserveScroll: true });
    };

    return (
        <details
            className="collapse-arrow border-base-300 bg-base-100 rounded-box collapse min-w-0 border"
            open={Boolean(answer.errors.body) || undefined}
        >
            <summary className="collapse-title hover:bg-base-200 min-h-0 py-2.5 ps-4 pe-10 text-sm font-medium capitalize motion-safe:transition-colors">
                {respond.answer ? 'Answer Here' : 'Forward Here'}
            </summary>
            <form onSubmit={submitAnswer} noValidate className="collapse-content min-w-0 space-y-3 px-4">
                <ol className="list bg-base-200 rounded-box" aria-label={`Latest messages, ${row.reference_no ?? row.title}`}>
                    {respond.messages.map((m) => (
                        <li key={m.id} className="list-row block space-y-1 py-2.5">
                            <p className="text-xs">
                                <span className="font-medium">{m.from_label}</span>
                                {m.author && <span className="text-base-content/70"> ({m.author})</span>}
                                <span className="text-base-content/70"> to {m.to_label}</span>
                                {m.sent_on && <span className="text-base-content/70 tabular-nums"> · {m.sent_on}</span>}
                            </p>
                            <p className="text-sm whitespace-pre-line">{m.body}</p>
                        </li>
                    ))}
                </ol>

                {respond.answer && (
                    <MessageField id={fieldId} value={answer.data.body} onChange={(v) => answer.setData('body', v)} error={answer.errors.body} />
                )}
                {respond.forward && (
                    <p className="text-sm">The message goes to {respond.forward.to} as written. You are recorded as the person who forwarded it.</p>
                )}
                {errors.map((m) => (
                    <InputError key={m} message={m} />
                ))}

                <div className="border-base-300 flex flex-wrap justify-end gap-2 border-t pt-3">
                    {respond.answer ? (
                        <button
                            type="submit"
                            className="btn btn-sm capitalize active:scale-[0.97]"
                            disabled={answer.processing || answer.data.body.trim() === ''}
                        >
                            {answer.processing ? 'Sending…' : label}
                        </button>
                    ) : (
                        respond.forward && (
                            <button
                                type="button"
                                className="btn btn-sm capitalize active:scale-[0.97]"
                                disabled={forward.processing}
                                onClick={() => respond.forward && forward.post(respond.forward.url, { preserveScroll: true })}
                            >
                                {forward.processing ? 'Forwarding…' : label}
                            </button>
                        )
                    )}
                </div>
            </form>
        </details>
    );
}

function waitingText(days: number | null): string {
    if (days === null) return 'Waiting';
    if (days === 0) return 'Today';
    return days === 1 ? '1 day' : `${days} days`;
}

/**
 * QUERIES-1: the sidebar's Queries page (Blueprint: support ticket queue,
 * trimmed to what this inbox holds). Every query waiting on the signed-in
 * user, oldest first: the wait leads each row, then the query and its case,
 * then the one action, which opens the case's Queries tab (or the vendor's
 * request page) where the query is answered or forwarded. The filter (All /
 * To Answer / To Forward) shows only on the CMO's desk, the one desk that
 * forwards; it is kept in ?filter=.
 */
export default function QueriesIndex({ queries, filter, counts, forward_desk }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Queries', href: route('queries.index') }];
    const filters = forward_desk ? FILTERS : [];
    const active = FILTERS.find((f) => f.value === filter) ?? FILTERS[0];

    const choose = (next: QueryFilter) =>
        router.get(route('queries.index'), next === 'all' ? {} : { filter: next }, { preserveScroll: true, preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Queries" />

            <PageContent>
                <PageHeader title="Queries" subtitle={counts.all === 0 ? 'Nothing waiting on you.' : `${counts.all} waiting on you, oldest first.`} />

                <FlashMessages />

                <section aria-labelledby="queries-list-heading" className="card bg-base-100 border-base-300 min-w-0 border">
                    <div className="border-base-300 flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h2 id="queries-list-heading" className="text-base font-semibold capitalize">
                            {filter === 'all' ? 'Waiting on you' : active.label}
                            <span className="text-base-content/60 ms-2 font-normal tabular-nums">{queries.length}</span>
                        </h2>
                        {filters.length > 0 && (
                            // A segmented toggle (aria-pressed), not radios: RadioGuardTest keeps radios to the shared RadioGroup.
                            <div role="group" aria-label="Show queries" className="join">
                                {filters.map((f) => (
                                    <button
                                        key={f.value}
                                        type="button"
                                        className={cn('join-item btn btn-sm capitalize', filter === f.value && 'btn-active')}
                                        aria-pressed={filter === f.value}
                                        onClick={() => filter !== f.value && choose(f.value)}
                                    >
                                        {f.label}
                                        <span className="badge badge-ghost badge-sm tabular-nums">{counts[f.value]}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {queries.length === 0 ? (
                        <div className="text-base-content/70 flex flex-col items-center gap-3 px-4 py-14 text-center text-sm">
                            <MessageSquareText className="text-base-content/40 size-6" aria-hidden="true" />
                            <p>{EMPTY[filter]}</p>
                            {filter !== 'all' && (
                                <button type="button" className="btn btn-ghost btn-sm capitalize" onClick={() => choose('all')}>
                                    <FilterX className="size-4" aria-hidden="true" />
                                    Show all queries
                                </button>
                            )}
                        </div>
                    ) : (
                        <ul className="list" aria-label="Queries waiting on you">
                            {queries.map((row) => (
                                <li key={row.id} className="list-row border-base-300 items-center rounded-none border-b last:border-b-0">
                                    <div className="w-16 shrink-0 text-center">
                                        <div className="text-base font-semibold tabular-nums">{waitingText(row.days_waiting)}</div>
                                        {row.waiting_since && (
                                            <time
                                                dateTime={row.waiting_since}
                                                title={fullTime(row.waiting_since)}
                                                className="text-base-content/60 text-helper"
                                            >
                                                waiting
                                            </time>
                                        )}
                                    </div>
                                    <div className="grid min-w-0 gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">{row.title}</span>
                                            {forward_desk && (
                                                <span className="badge badge-soft badge-sm capitalize">
                                                    {row.step === 'query_forward' ? 'To Forward' : 'To Answer'}
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-base-content/70 text-helper flex flex-wrap items-center gap-x-3 gap-y-1">
                                            {row.reference_no && <span className="text-base-content font-mono font-medium">{row.reference_no}</span>}
                                            {row.item_description && <span className="min-w-0 truncate">{row.item_description}</span>}
                                            {row.organisation && <span>{row.organisation}</span>}
                                        </div>
                                    </div>
                                    <Link
                                        href={row.url}
                                        className={cn('btn btn-sm capitalize', row.respond && 'btn-ghost')}
                                        aria-label={`${row.respond ? 'Open Case' : row.action}: ${row.reference_no ?? row.title}`}
                                    >
                                        {row.respond ? 'Open Case' : row.action}
                                        <ArrowRight className="size-4" aria-hidden="true" />
                                    </Link>
                                    {row.respond && (
                                        <div className="list-col-wrap min-w-0">
                                            <RespondHere row={row} respond={row.respond} />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </PageContent>
        </AppLayout>
    );
}
