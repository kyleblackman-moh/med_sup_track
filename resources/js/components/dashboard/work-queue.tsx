import { Input } from '@/components/ui/input';
import { StatusPill, TONE_CLASSES } from '@/components/ui/status-pill';
import { useLiveSearch } from '@/hooks/use-live-search';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type StatusTone } from '@/types/cases';
import { Link } from '@inertiajs/react';
import { FileClock, type LucideIcon, Search, Siren, Undo2 } from 'lucide-react';

/** One WorkQueue item (WorkQueue::for), as the "workQueue" prop sends it. */
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

/** DASH-1: a factual text tag on a row, with an icon (never colour alone). */
interface QueueTag {
    key: string;
    label: string;
    tone: StatusTone;
    icon: string;
}

/** DASH-1: a queue row as the table shows it (DashboardView::rows). */
export interface QueueRow extends WorkQueueItem {
    href: string;
    type_label: string | null;
    /** Elapsed time as plain text from the server ("Today", "3 days"); never coloured. */
    waiting: string;
    tags: QueueTag[];
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
    items: QueueRow[];
    total: number;
    /** The empty state to show, with its server wording; null when there are rows. */
    empty: { message: string; clear: boolean } | null;
    clear_href: string;
    truncated: boolean;
}

/** The props a chip, the search or the clear link reloads. */
const QUEUE_PROPS = ['queue'];

/** The longest search the server accepts (DashboardView::SEARCH_MAX). */
const SEARCH_MAX = 100;

/** Display only: the icon of each server tag. */
const TAG_ICONS: Record<string, LucideIcon> = {
    undo: Undo2,
    'file-clock': FileClock,
    siren: Siren,
};

const HEADERS = ['Reference', 'Request', 'Status', 'Waiting', 'Action'] as const;

function Tag({ tag }: { tag: QueueTag }) {
    const Icon = TAG_ICONS[tag.icon];

    return (
        <span
            className={cn('inline-flex h-5 items-center gap-1 rounded-full px-2 text-[11px] font-medium whitespace-nowrap', TONE_CLASSES[tag.tone])}
        >
            {Icon && <Icon className="size-3 shrink-0" aria-hidden="true" />}
            {tag.label}
        </span>
    );
}

interface WorkQueueProps {
    queue: DashboardQueue;
    /** The empty state's next action (the case list), when the user has one. */
    casesHref?: string | null;
}

/**
 * "Needs My Action" (DASH-1): one L3 card. The header holds the title, the
 * server-side search and the filter chips (real links, partial reloads of
 * "queue"). The table: Reference (the row's real link, stretched over the row
 * with rowLink), Request, Status, Waiting and Action (a real link to the same
 * case page). Rows, order, labels, tags and the waiting text all come from the
 * server (WorkQueue, DashboardView::queue). Below lg the table scrolls inside
 * the card. No SLA wording, targets or colouring of time.
 */
export function WorkQueue({ queue, casesHref = null }: WorkQueueProps) {
    const search = useLiveSearch({
        url: route('dashboard'),
        applied: queue.search,
        appliedGroup: queue.filter === 'all' ? '' : queue.filter,
        groupParam: 'filter',
        only: QUEUE_PROPS,
        maxLength: SEARCH_MAX,
    });
    const cell = 'border-divider h-14 border-b px-4 py-2 align-middle';

    return (
        <section aria-labelledby="work-queue-heading" className="bg-card overflow-hidden rounded-xl border shadow-xs">
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

            {queue.empty ? (
                <div className="flex flex-col items-center gap-2 px-6 py-12 text-center" role="status">
                    <p className="text-muted-foreground text-sm">{queue.empty.message}</p>
                    {queue.empty.clear ? (
                        <Link
                            href={queue.clear_href}
                            only={QUEUE_PROPS}
                            preserveState
                            preserveScroll
                            className="text-primary text-sm font-medium underline-offset-2 hover:underline"
                        >
                            Clear Search and Filter
                        </Link>
                    ) : (
                        casesHref && (
                            <Link href={casesHref} className="text-primary text-sm font-medium underline-offset-2 hover:underline">
                                Open File Tracking
                            </Link>
                        )
                    )}
                </div>
            ) : (
                <div className={cn('overflow-x-auto', search.searching && 'opacity-70')}>
                    <table className="w-full min-w-[52rem] border-separate border-spacing-0 text-sm lg:min-w-0">
                        <caption className="sr-only">Cases waiting on you, oldest first</caption>
                        <thead>
                            <tr className="text-muted-foreground text-left text-xs">
                                {HEADERS.map((h) => (
                                    <th
                                        key={h}
                                        scope="col"
                                        className={cn(
                                            'bg-subtle border-divider h-10 border-b px-4 font-medium whitespace-nowrap',
                                            h === 'Request' && 'w-full',
                                            h === 'Waiting' && 'text-right',
                                            h === 'Action' && 'text-right',
                                        )}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {queue.items.map((item) => (
                                // The reference link covers the row (rowLink); no onClick on the row.
                                <tr key={item.id} className="hover:bg-selected focus-within:bg-selected relative">
                                    <td className={cn(cell, 'whitespace-nowrap')}>
                                        <Link href={item.href} className={cn('font-mono text-xs font-medium', rowLink)}>
                                            {item.reference_no ?? '—'}
                                            <span className="sr-only">: {item.action}</span>
                                        </Link>
                                    </td>
                                    <td className={cn(cell, 'max-w-0')}>
                                        <span className="block truncate font-semibold" title={item.item_description ?? undefined}>
                                            {item.item_description ?? '—'}
                                        </span>
                                        <span className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-[13px]">
                                            {item.tags.map((tag) => (
                                                <Tag key={tag.key} tag={tag} />
                                            ))}
                                            <span className="truncate">
                                                {[item.type_label, item.organisation].filter(Boolean).join(' · ') || '—'}
                                            </span>
                                        </span>
                                    </td>
                                    <td className={cn(cell, 'whitespace-nowrap')}>
                                        <StatusPill label={item.status_label} tone={item.status_tone} inColumn />
                                    </td>
                                    <td className={cn(cell, 'text-right whitespace-nowrap tabular-nums')}>{item.waiting}</td>
                                    <td className={cn(cell, 'text-right whitespace-nowrap')}>
                                        {/* A real link to the same case page, above the row overlay. */}
                                        <Link
                                            href={item.href}
                                            tabIndex={-1}
                                            className="bg-primary text-primary-foreground hover:bg-primary-strong focus-visible:ring-ring relative z-10 inline-flex h-8 items-center rounded-md px-3 text-[13px] font-semibold outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                        >
                                            {item.action}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
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
