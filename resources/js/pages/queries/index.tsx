import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import AppLayout from '@/layouts/app-layout';
import { fullTime } from '@/lib/notifications';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, FilterX, MessageSquareText } from 'lucide-react';

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
                                        className="btn btn-sm capitalize"
                                        aria-label={`${row.action}: ${row.reference_no ?? row.title}`}
                                    >
                                        {row.action}
                                        <ArrowRight className="size-4" aria-hidden="true" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </PageContent>
        </AppLayout>
    );
}
