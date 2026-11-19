import { Input } from '@/components/ui/input';
import { StatusPill } from '@/components/ui/status-pill';
import { useLiveSearch } from '@/hooks/use-live-search';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';
import { Link } from '@inertiajs/react';
import { Search } from 'lucide-react';

export interface WorkQueueItem {
    id: string;
    case_id: string;
    step: string;
    action: string;
    reference_no: string | null;
    item_description: string | null;
    organisation: string | null;
    status_label: string;
    status_tone: StatusTone;
    waiting_since: string | null;
    days_waiting: number | null;
}

export interface WorkQueueData {
    groups: { key: string; label: string; count: number }[];
    items: WorkQueueItem[];
    truncated: boolean;
}

/** DASH-1: one filter chip, built by the server (DashboardView::queue). */
export interface QueueFilter {
    key: string;
    label: string;
    count: number;
    /** A real link: the filter with the current search. */
    href: string;
    active: boolean;
}

/** DASH-1: the queue card after the server applied the filter and the search. */
export interface DashboardQueue {
    filter: string;
    search: string;
    filters: QueueFilter[];
    items: WorkQueueItem[];
    total: number;
    clear_href: string;
    truncated: boolean;
}

/** The props a chip or the search reloads. */
const QUEUE_PROPS = ['queue'];

/** The longest search the server accepts (DashboardView::SEARCH_MAX). */
const SEARCH_MAX = 100;

function waitingLabel(days: number | null): string {
    if (days === null) return '—';
    if (days === 0) return 'Today';
    return days === 1 ? '1 day' : `${days} days`;
}

interface WorkQueueProps {
    queue: DashboardQueue;
    /** Case id => request type name, from the server (DashboardView::requestTypes). */
    requestTypes: Record<string, string>;
    /** The empty state's next action (the case list), when the user has one. */
    casesHref?: string | null;
}

const HEADERS = ['Reference', 'Request Type', 'Action', 'Status', 'Waiting'] as const;

/**
 * "Needs My Action": built on the server (WorkQueue), oldest first. An L3 card
 * (design.md section 7, data table): a header row with the title and a count
 * chip, the step filter chips, then the table with its header on --subtle. The
 * reference is the row's real link, stretched over the row (rowLink); the row
 * opens the case, where the action is taken. Same rows, order and links as
 * before; no gate columns, because the queue does not carry them.
 */
export function WorkQueue({ queue, requestTypes, casesHref = null }: WorkQueueProps) {
    // DASH-1: the search runs on the server (debounced, partial reload of "queue"), with the active filter.
    const search = useLiveSearch({
        url: route('dashboard'),
        applied: queue.search,
        appliedGroup: queue.filter === 'all' ? '' : queue.filter,
        groupParam: 'filter',
        only: QUEUE_PROPS,
        maxLength: SEARCH_MAX,
    });
    const items = queue.items;
    const cell = 'border-divider h-14 border-b px-4 py-2 align-middle';

    return (
        <section aria-labelledby="work-queue-heading" className="bg-card overflow-hidden rounded-xl border shadow-xs">
            {/* DASH-1: the card header: the title, the search box, then the filter chips (real links, partial reloads). */}
            <div className="grid gap-3 border-b px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="work-queue-heading" className="flex items-center gap-2 text-base font-semibold">
                        Needs My Action
                        <span className="bg-primary text-primary-foreground inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold tabular-nums">
                            {queue.total}
                            <span className="sr-only"> {queue.total === 1 ? 'item' : 'items'}</span>
                        </span>
                    </h2>
                    <div className="relative w-full sm:w-72">
                        <Search
                            className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                            aria-hidden="true"
                        />
                        <Input
                            type="search"
                            value={search.value}
                            onChange={(e) => search.setValue(e.target.value)}
                            maxLength={SEARCH_MAX}
                            placeholder="Reference, item or organisation"
                            aria-label="Search the work queue"
                            className={cn('h-10 pl-9', search.searching && 'opacity-70')}
                        />
                    </div>
                </div>

                <nav aria-label="Filter the work queue">
                    <ul className="flex flex-wrap gap-2">
                        {queue.filters.map((filter) => (
                            <li key={filter.key}>
                                <Link
                                    href={filter.href}
                                    only={QUEUE_PROPS}
                                    preserveState
                                    preserveScroll
                                    aria-current={filter.active ? 'true' : undefined}
                                    className={cn(
                                        'focus-visible:ring-ring inline-flex h-[30px] items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                                        filter.active ? 'border-primary bg-primary text-primary-foreground' : 'bg-card border-border hover:bg-muted',
                                    )}
                                >
                                    {filter.label}
                                    <span
                                        className={cn(
                                            'rounded-full px-1.5 text-xs tabular-nums',
                                            filter.active ? 'bg-primary-foreground/20' : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {filter.count}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            </div>

            {items.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                    <p className="text-muted-foreground text-sm">Nothing needs your action right now.</p>
                    {casesHref && (
                        <Link href={casesHref} className="text-primary text-sm font-medium underline-offset-2 hover:underline">
                            Open File Tracking
                        </Link>
                    )}
                </div>
            ) : (
                <>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full border-separate border-spacing-0 text-sm">
                            <caption className="sr-only">Cases waiting on you, oldest first</caption>
                            <thead>
                                <tr className="text-muted-foreground text-left text-xs">
                                    {HEADERS.map((h) => (
                                        <th
                                            key={h}
                                            scope="col"
                                            className={cn(
                                                'bg-subtle border-divider h-10 border-b px-4 font-medium whitespace-nowrap',
                                                h === 'Reference' && 'w-full',
                                                h === 'Request Type' && 'hidden lg:table-cell',
                                                h === 'Waiting' && 'text-right',
                                            )}
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item) => (
                                    <tr
                                        key={item.id}
                                        // The reference link covers the row (rowLink); no onClick on the row.
                                        className="hover:bg-muted/60 focus-within:bg-muted/60 relative"
                                    >
                                        <td className={cn(cell, 'max-w-0')}>
                                            <Link
                                                href={route('cases.show', item.case_id)}
                                                className={cn('block font-mono text-xs font-medium whitespace-nowrap', rowLink)}
                                            >
                                                {item.reference_no ?? '—'}
                                                <span className="sr-only">: {item.action}</span>
                                            </Link>
                                            <span
                                                className="text-muted-foreground block truncate text-[13px]"
                                                title={item.item_description ?? undefined}
                                            >
                                                {item.item_description ?? '—'}
                                                {item.organisation && <span> · {item.organisation}</span>}
                                            </span>
                                        </td>
                                        <td className={cn(cell, 'hidden whitespace-nowrap lg:table-cell')}>{requestTypes[item.case_id] ?? '—'}</td>
                                        <td className={cn(cell, 'text-primary font-medium whitespace-nowrap')}>{item.action}</td>
                                        <td className={cn(cell, 'whitespace-nowrap')}>
                                            <StatusPill label={item.status_label} tone={item.status_tone} inColumn />
                                        </td>
                                        <td className={cn(cell, 'text-right whitespace-nowrap tabular-nums')}>{waitingLabel(item.days_waiting)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="divide-divider divide-y md:hidden">
                        {items.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={route('cases.show', item.case_id)}
                                    className="hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-ring block space-y-1 p-4 outline-none focus-visible:ring-2 focus-visible:ring-inset"
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        <span className="text-primary text-sm font-semibold">{item.action}</span>
                                        <span className="text-xs tabular-nums">{waitingLabel(item.days_waiting)}</span>
                                    </span>
                                    <span className="block font-mono text-xs font-medium">{item.reference_no ?? '—'}</span>
                                    <span className="text-muted-foreground block truncate text-[13px]">{item.item_description ?? '—'}</span>
                                    <StatusPill label={item.status_label} tone={item.status_tone} />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            {queue.truncated && (
                <p className="text-muted-foreground border-t px-4 py-2.5 text-sm">
                    Showing the oldest cases only. Use{' '}
                    <Link href={route('cases.index')} className="text-primary underline-offset-2 hover:underline">
                        File Tracking
                    </Link>{' '}
                    for the full list.
                </p>
            )}
        </section>
    );
}
