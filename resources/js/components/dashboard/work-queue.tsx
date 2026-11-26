import { ExpiryFlag } from '@/components/cases/expiry-flag';
import { DataTable, DataTableCell, DataTableHeaderCell, DataTableRow, dataTableRefClass } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { type FilterChip } from '@/components/ui/filter-chips';
import { StatusPill, TONE_CLASSES } from '@/components/ui/status-pill';
import { useLiveSearch } from '@/hooks/use-live-search';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type ExpiryFlag as ExpiryFlagData, type StatusTone } from '@/types/cases';
import { Link } from '@inertiajs/react';
import { ArrowRight, FileClock, Search, Siren, Undo2, type LucideIcon } from 'lucide-react';

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
    /** Kyle, 2026-11-24: stock may expire before use; null when there is nothing to flag. */
    expiry_flag?: ExpiryFlagData | null;
    /** Kyle, 2026-11-26: who acts ("cmo", "technocrats", …) and its To Do heading ("CMO to Action"), from the server. */
    desk?: string;
    desk_label?: string;
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

/** DASH-1: one filter chip, built by the server (DashboardView::queue); the shared FilterChip shape. */
export type QueueFilter = FilterChip;

/** DASH-1: the queue card after the server applied the filter and the search. */
export interface DashboardQueue {
    filter: string;
    search: string;
    filters: QueueFilter[];
    items: QueueRow[];
    /** Kyle, 2026-11-26: the rows under their desk headings, in the server's order; empty desks left out. */
    sections: { key: string; label: string; items: QueueRow[] }[];
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

/** The row's action: a real link to the case page (the same place the row opens). */
const ACTION_BTN = 'btn btn-sm whitespace-nowrap capitalize transition-transform active:scale-[0.97]';

function Tag({ tag }: { tag: QueueTag }) {
    const Icon = TAG_ICONS[tag.icon];

    return (
        <span className={cn('badge badge-sm shrink-0 gap-1 whitespace-nowrap capitalize', TONE_CLASSES[tag.tone])}>
            {Icon && <Icon className="size-3 shrink-0" aria-hidden="true" />}
            {tag.label}
        </span>
    );
}

/** The second line of a row: the tags, then the request type and organisation. */
function RowMeta({ item }: { item: QueueRow }) {
    return (
        <span className="text-base-content/60 flex min-w-0 items-center gap-1.5 text-xs">
            {item.tags.map((tag) => (
                <Tag key={tag.key} tag={tag} />
            ))}
            <ExpiryFlag flag={item.expiry_flag} />
            <span className="truncate">{[item.type_label, item.organisation].filter(Boolean).join(' · ') || '—'}</span>
        </span>
    );
}

interface WorkQueueProps {
    queue: DashboardQueue;
    /** The empty state's next action (the Application Requests list), when the user has one. */
    casesHref?: string | null;
}

/** One desk's tasks: the table (md and up) or a list (phones). */
function QueueSection({ section }: { section: DashboardQueue['sections'][number] }) {
    // OVERNIGHT-3 (B2): the shared DataTable; queue rows are 56px (two lines).
    const cell = 'h-14 px-4';
    const headingId = `todo-${section.key}-heading`;

    return (
        <section aria-labelledby={headingId} className="border-base-300 border-t first:border-t-0">
            <h2
                id={headingId}
                className="bg-primary/8 border-b-primary/40 flex items-center gap-2 border-b px-4 py-2 text-sm font-semibold capitalize sm:px-6"
            >
                {section.label}
                <span className="badge badge-ghost badge-sm tabular-nums">
                    {section.items.length}
                    <span className="sr-only"> {section.items.length === 1 ? 'task' : 'tasks'}</span>
                </span>
            </h2>
            <DataTable
                caption={`${section.label}, most recent first`}
                className="table-sm table min-w-[52rem] lg:min-w-0"
                wrapperClassName="max-md:hidden"
            >
                <thead>
                    <tr className="capitalize">
                        {HEADERS.map((h) => (
                            <DataTableHeaderCell
                                key={h}
                                className={cn(
                                    'bg-base-200 text-base-content/70 border-base-300 px-4',
                                    h === 'Request' && 'w-full',
                                    (h === 'Waiting' || h === 'Action') && 'text-right',
                                )}
                            >
                                {h}
                            </DataTableHeaderCell>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {section.items.map((item) => (
                        // The reference link covers the row (rowLink); no onClick on the row.
                        <DataTableRow key={item.id} linked>
                            <DataTableCell className={cn(cell, 'border-base-300 whitespace-nowrap')}>
                                <Link href={item.href} className={cn(dataTableRefClass, rowLink)}>
                                    {item.reference_no ?? '—'}
                                    <span className="sr-only">: {item.action}</span>
                                </Link>
                            </DataTableCell>
                            <DataTableCell className={cn(cell, 'border-base-300 max-w-0')}>
                                <span className="block truncate font-semibold" title={item.item_description ?? undefined}>
                                    {item.item_description ?? '—'}
                                </span>
                                <RowMeta item={item} />
                            </DataTableCell>
                            <DataTableCell className={cn(cell, 'border-base-300 whitespace-nowrap')}>
                                <StatusPill label={item.status_label} tone={item.status_tone} inColumn className="capitalize" />
                            </DataTableCell>
                            <DataTableCell className={cn(cell, 'border-base-300 text-right whitespace-nowrap tabular-nums')}>
                                {item.waiting}
                            </DataTableCell>
                            <DataTableCell className={cn(cell, 'border-base-300 text-right whitespace-nowrap')}>
                                {/* A real link to the same case page, above the row overlay. */}
                                <Link href={item.href} tabIndex={-1} className={cn(ACTION_BTN, 'relative z-10')}>
                                    {item.action}
                                </Link>
                            </DataTableCell>
                        </DataTableRow>
                    ))}
                </tbody>
            </DataTable>

            {/* Phones: the same rows as a list; the reference link covers the row, the action sits at the end. */}
            <ul className="list md:hidden" aria-label={`${section.label}, most recent first`}>
                {section.items.map((item) => (
                    <li key={item.id} className="list-row hover:bg-base-200 focus-within:bg-base-200 relative items-center transition-colors">
                        <div className="list-col-grow min-w-0">
                            <Link href={item.href} className={cn(dataTableRefClass, rowLink)}>
                                {item.reference_no ?? '—'}
                                <span className="sr-only">: {item.action}</span>
                            </Link>
                            <div className="truncate text-sm font-semibold">{item.item_description ?? '—'}</div>
                            <RowMeta item={item} />
                            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                                <StatusPill label={item.status_label} tone={item.status_tone} className="capitalize" />
                                <span className="text-base-content/70 tabular-nums">{item.waiting}</span>
                            </div>
                        </div>
                        <Link href={item.href} tabIndex={-1} className={cn(ACTION_BTN, 'relative z-10')}>
                            {item.action}
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Kyle, 2026-11-26: "To Do" (was "Needs My Action", DASH-1). Only the tasks
 * waiting on the user, under who acts on them (CMO to Action, Technocrats to
 * Action, CAB to Action, Legal to Action, PS to Action / Non-Objection), most
 * recent first; "No task to complete." when there are none. The search stays
 * (server-side, a partial reload of "queue"); the filter chips left (the
 * headings group the tasks). Rows, order, headings, tags and the waiting text
 * all come from the server (WorkQueue, DashboardView::queue). No SLA wording,
 * targets or colouring of time.
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

    return (
        <div className="card bg-base-100 border-base-300 min-w-0 overflow-hidden border shadow-xs transition-shadow duration-200 hover:shadow-md">
            {queue.total > 0 && (
                <div className="border-base-300 flex flex-wrap items-center justify-between gap-3 border-b p-4 sm:px-6">
                    <p className="text-base-content/70 text-sm">Most recent first</p>
                    <label className={search.searching ? 'input input-sm w-full opacity-70 sm:w-72' : 'input input-sm w-full sm:w-72'}>
                        <Search className="text-base-content/60 size-4 shrink-0" aria-hidden="true" />
                        <input
                            type="search"
                            className="grow"
                            value={search.value}
                            onChange={(e) => search.setValue(e.target.value)}
                            maxLength={SEARCH_MAX}
                            placeholder="Reference, item or organisation"
                            aria-label="Search your tasks"
                        />
                    </label>
                </div>
            )}

            {queue.empty ? (
                <EmptyState
                    live
                    message={queue.empty.message}
                    action={
                        queue.empty.clear ? (
                            <Link
                                href={queue.clear_href}
                                only={QUEUE_PROPS}
                                preserveState
                                preserveScroll
                                className="btn btn-sm capitalize transition-transform active:scale-[0.97]"
                            >
                                Clear Search
                            </Link>
                        ) : (
                            casesHref && (
                                <Link href={casesHref} className="btn btn-sm capitalize transition-transform active:scale-[0.97]">
                                    Open Application Requests
                                </Link>
                            )
                        )
                    }
                />
            ) : (
                <div className={search.searching ? 'opacity-70 transition-opacity' : 'transition-opacity'}>
                    {queue.sections.map((section) => (
                        <QueueSection key={section.key} section={section} />
                    ))}
                </div>
            )}
            {queue.truncated && (
                <div className="border-base-300 text-base-content/70 flex flex-wrap items-center justify-between gap-2 border-t px-4 py-2.5 text-sm">
                    <p>Showing some tasks only. Use Application Requests for the full list.</p>
                    <Link href={route('cases.index')} className="btn btn-ghost btn-sm capitalize transition-transform active:scale-[0.97]">
                        Application Requests
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </div>
            )}
        </div>
    );
}
