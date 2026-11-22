import { PageContent, PageHeader } from '@/components/page-header';
import { DeleteDraftDialog } from '@/components/requests/delete-draft-dialog';
import { FlashMessages } from '@/components/requests/flash-messages';
import { NewRequestButton } from '@/components/requests/new-request-button';
import { StatusPill } from '@/components/ui/status-pill';
import { useLiveSearch } from '@/hooks/use-live-search';
import AppLayout from '@/layouts/app-layout';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type RequestSummary } from '@/types/requests';
import { Head, Link } from '@inertiajs/react';
import { BadgeCheck, ChevronLeft, ChevronRight, FilePen, FileText, Hourglass, type LucideIcon, Mailbox, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface RequestGroup {
    key: string;
    label: string;
    count: number;
}

interface Props {
    /** "{organisation label} - requests", built on the server (RequestController::LIST_TITLE). */
    title: string;
    filters: { q: string; group: string };
    /** UI-4: the count cards, counted on the server for the user's own organisation (RequestGroups). */
    groups: RequestGroup[];
    requests: {
        data: RequestSummary[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    can: { create: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Requests', href: '/requests' }];

/** The longest search the server accepts (RequestController::SEARCH_MAX). */
const SEARCH_MAX = 100;

/** Card icons by the server's group key; an unknown key gets none. */
const GROUP_ICONS: Record<string, LucideIcon> = {
    drafts: FilePen,
    in_progress: Hourglass,
    hardcopy_due: Mailbox,
    granted: BadgeCheck,
};

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain', dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/** One count card (Blueprint stat): a real toggle button (aria-pressed) that filters the list by the server's group. */
function GroupTile({ group, active, onToggle }: { group: RequestGroup; active: boolean; onToggle: () => void }) {
    const Icon = GROUP_ICONS[group.key];

    return (
        <div className="stats bg-base-100 border-base-300 has-[[aria-pressed=true]]:border-primary min-w-0 border">
            <button
                type="button"
                aria-pressed={active}
                onClick={onToggle}
                // Static classes: the applied state is styled from aria-pressed itself.
                className="stat hover:bg-base-200 aria-pressed:bg-primary/5 min-w-0 px-4 py-3 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 motion-safe:transition-colors"
            >
                {Icon && (
                    <span className={cn('stat-figure', active ? 'text-primary' : 'text-base-content/40')}>
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                )}
                <span className="stat-title text-sm capitalize">{group.label}</span>
                <span className="stat-value text-3xl font-semibold tabular-nums">{group.count}</span>
                {/* Not colour alone: the applied filter also says so in text. */}
                <span className={cn('stat-desc', active ? 'text-primary font-medium' : 'invisible')} aria-hidden={!active}>
                    Filter applied
                </span>
            </button>
        </div>
    );
}

/**
 * The vendor's Requests list (UI-3, restyled in UI-4; Blueprint order history):
 * four count cards that filter the list, a live search, and Reference, Type,
 * Item, Status, newest updated first (server order). Results are always the
 * user's own organisation's requests. While a search runs the previous rows
 * stay, faded.
 */
export default function Index({ title, filters, groups, requests, can }: Props) {
    const [deleting, setDeleting] = useState<string | null>(null);
    const search = useLiveSearch({
        url: route('requests.index'),
        applied: filters.q,
        appliedGroup: filters.group,
        only: ['requests', 'filters'],
        maxLength: SEARCH_MAX,
    });

    const activeGroup = groups.find((group) => group.key === filters.group);
    const count = requests.total === 1 ? '1 request' : `${requests.total} requests`;
    const scope = activeGroup ? ` in ${activeGroup.label}` : '';
    const resultText =
        filters.q === ''
            ? `${count}${scope}`
            : requests.total === 0
              ? `No requests match “${filters.q}”${scope}.`
              : `${count} match “${filters.q}”${scope}.`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            {/* Full width beside the drawer: px-4 py-4, lg:px-6 (PageContent's md:p-6 reset to p-4 first). */}
            <PageContent className="space-y-4 px-4 py-4 md:p-4 lg:px-6">
                <PageHeader title={title} titleClassName="capitalize" size="large" actions={can.create && <NewRequestButton />} />

                <FlashMessages />

                <div role="group" aria-label="Filter by group" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {groups.map((group) => (
                        <GroupTile
                            key={group.key}
                            group={group}
                            active={group.key === filters.group}
                            onToggle={() => search.toggleGroup(group.key)}
                        />
                    ))}
                </div>

                <section aria-label="Requests" className="card bg-base-100 border-base-300 min-w-0 border">
                    <div className="border-base-300 flex flex-wrap items-end justify-between gap-3 border-b px-4 py-3">
                        <div className="grid w-full max-w-md gap-1.5">
                            <label htmlFor="requests-search" className="text-sm font-medium capitalize">
                                Search Requests
                            </label>
                            <div className="flex items-center gap-3">
                                <label className="input input-sm w-full">
                                    <Search className="text-base-content/50 size-4 shrink-0" aria-hidden="true" />
                                    <input
                                        id="requests-search"
                                        type="search"
                                        className="grow"
                                        maxLength={SEARCH_MAX}
                                        autoComplete="off"
                                        value={search.value}
                                        aria-describedby="requests-search-hint"
                                        onChange={(e) => search.setValue(e.target.value)}
                                    />
                                </label>
                                {/* Reserved width, so nothing shifts when the text appears. */}
                                <span className="text-base-content/70 flex w-24 shrink-0 items-center gap-1.5 text-xs" aria-hidden="true">
                                    {search.searching && (
                                        <>
                                            <span className="loading loading-spinner loading-xs" />
                                            Searching...
                                        </>
                                    )}
                                </span>
                            </div>
                            <p id="requests-search-hint" className="text-base-content/70 text-xs">
                                Reference, item, strength, DIN or type.
                            </p>
                        </div>
                        <p aria-live="polite" className="text-base-content/70 text-sm tabular-nums">
                            {resultText}
                        </p>
                    </div>

                    <div
                        className={cn('motion-safe:transition-opacity motion-safe:duration-150', search.searching && 'opacity-60')}
                        aria-busy={search.searching}
                    >
                        {requests.data.length === 0 ? (
                            filters.q === '' &&
                            filters.group === '' && (
                                <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
                                    <FileText className="text-base-content/40 size-6" aria-hidden="true" />
                                    <p className="text-sm font-medium">No requests yet</p>
                                    <p className="text-base-content/70 text-sm">Start a new request to ask the Ministry for a non-objection.</p>
                                </div>
                            )
                        ) : (
                            <div className="max-w-full overflow-x-auto">
                                <table className="table-sm table">
                                    <caption className="sr-only">{title}</caption>
                                    <thead>
                                        <tr className="capitalize">
                                            <th scope="col">Reference</th>
                                            <th scope="col">Type</th>
                                            <th scope="col">Item</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {requests.data.map((request) => (
                                            <tr
                                                key={request.id}
                                                // The Edit or View link covers the row (rowLink); no onClick on the row.
                                                className="hover:bg-base-200 focus-within:bg-base-200 relative motion-safe:transition-colors"
                                            >
                                                <td className="font-mono text-xs font-medium whitespace-nowrap">
                                                    {request.reference_no ?? <span className="text-base-content/70 font-sans">Not assigned</span>}
                                                </td>
                                                <td className={request.procurement_type.selected ? undefined : 'text-base-content/70'}>
                                                    {request.procurement_type.name}
                                                </td>
                                                <td>{request.item_description ?? <span className="text-base-content/70">Not described yet</span>}</td>
                                                <td>
                                                    <StatusPill label={request.status_label} tone={request.status_tone} inColumn />
                                                </td>
                                                <td className="text-right whitespace-nowrap">
                                                    {request.can.edit ? (
                                                        <Link href={route('requests.edit', request.id)} className={cn('link link-primary', rowLink)}>
                                                            Edit<span className="sr-only"> {request.item_description ?? 'draft'}</span>
                                                        </Link>
                                                    ) : (
                                                        <Link href={route('requests.show', request.id)} className={cn('link link-primary', rowLink)}>
                                                            View<span className="sr-only"> {request.item_description ?? 'request'}</span>
                                                        </Link>
                                                    )}
                                                    {request.can.delete && (
                                                        // relative z-10: above the row link's overlay, with its own focus and click.
                                                        <button
                                                            type="button"
                                                            className="btn btn-ghost btn-square btn-sm relative z-10 ml-2 size-11 align-middle md:size-8"
                                                            aria-label={`Delete draft: ${request.item_description ?? 'Not described yet'}, updated ${formatDate(request.updated_at)}`}
                                                            onClick={() => setDeleting(request.id)}
                                                        >
                                                            <Trash2 className="size-4" aria-hidden="true" />
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    {requests.last_page > 1 && (
                        <nav aria-label="Pagination" className="border-base-300 flex justify-end border-t px-3 py-2">
                            <div className="join">
                                {requests.prev_page_url ? (
                                    <Link href={requests.prev_page_url} preserveScroll className="btn btn-sm join-item capitalize">
                                        <ChevronLeft className="size-4" aria-hidden="true" />
                                        Previous
                                    </Link>
                                ) : (
                                    <button type="button" className="btn btn-sm join-item capitalize" disabled>
                                        <ChevronLeft className="size-4" aria-hidden="true" />
                                        Previous
                                    </button>
                                )}
                                <span className="join-item border-base-300 bg-base-100 flex items-center border px-3 text-sm tabular-nums">
                                    Page {requests.current_page} of {requests.last_page}
                                </span>
                                {requests.next_page_url ? (
                                    <Link href={requests.next_page_url} preserveScroll className="btn btn-sm join-item capitalize">
                                        Next
                                        <ChevronRight className="size-4" aria-hidden="true" />
                                    </Link>
                                ) : (
                                    <button type="button" className="btn btn-sm join-item capitalize" disabled>
                                        Next
                                        <ChevronRight className="size-4" aria-hidden="true" />
                                    </button>
                                )}
                            </div>
                        </nav>
                    )}
                </section>
            </PageContent>

            <DeleteDraftDialog caseId={deleting} onClose={() => setDeleting(null)} />
        </AppLayout>
    );
}
