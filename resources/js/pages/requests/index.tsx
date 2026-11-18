import { PageContent, PageHeader } from '@/components/page-header';
import { DeleteDraftDialog } from '@/components/requests/delete-draft-dialog';
import { FlashMessages } from '@/components/requests/flash-messages';
import { NewRequestButton } from '@/components/requests/new-request-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatTile } from '@/components/ui/stat-tile';
import { StatusPill } from '@/components/ui/status-pill';
import { useLiveSearch } from '@/hooks/use-live-search';
import AppLayout from '@/layouts/app-layout';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type RequestSummary } from '@/types/requests';
import { Head, Link } from '@inertiajs/react';
import { BadgeCheck, FilePen, Hourglass, type LucideIcon, Mailbox, Trash2 } from 'lucide-react';
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

/**
 * The vendor's Requests list (UI-3, restyled in UI-4): four count cards that
 * filter the list, a live search, and Reference, Type, Item, Status, newest
 * updated first (server order). Results are always the user's own
 * organisation's requests. While a search runs the previous rows stay, faded.
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

            <PageContent>
                <PageHeader title={title} actions={can.create && <NewRequestButton />} />

                <FlashMessages />

                <div role="group" aria-label="Filter by group" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {groups.map((group) => (
                        <StatTile
                            key={group.key}
                            label={group.label}
                            value={group.count}
                            icon={GROUP_ICONS[group.key]}
                            active={group.key === filters.group}
                            onClick={() => search.toggleGroup(group.key)}
                        />
                    ))}
                </div>

                <div className="bg-card overflow-hidden rounded-xl border shadow-xs">
                    <div className="flex flex-wrap items-end justify-between gap-3 border-b px-4 py-3">
                        <div className="grid w-full max-w-md gap-1.5">
                            <Label htmlFor="requests-search">Search requests</Label>
                            <div className="flex items-center gap-3">
                                <Input
                                    id="requests-search"
                                    type="search"
                                    maxLength={SEARCH_MAX}
                                    autoComplete="off"
                                    value={search.value}
                                    aria-describedby="requests-search-hint"
                                    onChange={(e) => search.setValue(e.target.value)}
                                />
                                {/* Reserved width, so nothing shifts when the text appears. */}
                                <span className="text-muted-foreground w-20 shrink-0 text-xs" aria-hidden="true">
                                    {search.searching ? 'Searching...' : ''}
                                </span>
                            </div>
                            <p id="requests-search-hint" className="text-muted-foreground text-xs">
                                Reference, item, strength, DIN or type.
                            </p>
                        </div>
                        <p aria-live="polite" className="text-muted-foreground text-[13px] tabular-nums">
                            {resultText}
                        </p>
                    </div>

                    <div
                        className={cn('transition-opacity duration-150 motion-reduce:transition-none', search.searching && 'opacity-60')}
                        aria-busy={search.searching}
                    >
                        {requests.data.length === 0 ? (
                            filters.q === '' &&
                            filters.group === '' && (
                                <div className="p-8 text-center">
                                    <p className="text-sm font-medium">No requests yet</p>
                                    <p className="text-muted-foreground mt-1 text-sm">Start a new request to ask the Ministry for a non-objection.</p>
                                </div>
                            )
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">{title}</caption>
                                    <thead className="bg-subtle text-muted-foreground text-xs">
                                        <tr>
                                            <th scope="col" className="px-4 py-2 font-medium">
                                                Reference
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium">
                                                Type
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium">
                                                Item
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium">
                                                Status
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-divider divide-y">
                                        {requests.data.map((request) => (
                                            <tr
                                                key={request.id}
                                                className="hover:bg-selected focus-within:bg-selected relative transition-colors duration-150 motion-reduce:transition-none"
                                            >
                                                <td className="px-4 py-2.5 font-mono text-xs font-medium whitespace-nowrap">
                                                    {request.reference_no ?? <span className="text-muted-foreground font-sans">Not assigned</span>}
                                                </td>
                                                <td
                                                    className={
                                                        request.procurement_type.selected ? 'px-4 py-2.5' : 'text-muted-foreground px-4 py-2.5'
                                                    }
                                                >
                                                    {request.procurement_type.name}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {request.item_description ?? <span className="text-muted-foreground">Not described yet</span>}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <StatusPill label={request.status_label} tone={request.status_tone} inColumn />
                                                </td>
                                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                    {request.can.edit ? (
                                                        <Link
                                                            href={route('requests.edit', request.id)}
                                                            className={cn('text-primary underline', rowLink)}
                                                        >
                                                            Edit<span className="sr-only"> {request.item_description ?? 'draft'}</span>
                                                        </Link>
                                                    ) : (
                                                        <Link
                                                            href={route('requests.show', request.id)}
                                                            className={cn('text-primary underline', rowLink)}
                                                        >
                                                            View<span className="sr-only"> {request.item_description ?? 'request'}</span>
                                                        </Link>
                                                    )}
                                                    {request.can.delete && (
                                                        // relative z-10: above the row link's overlay, with its own focus and click.
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="relative z-10 ml-2 size-11 align-middle md:size-9"
                                                            aria-label={`Delete draft: ${request.item_description ?? 'Not described yet'}, updated ${formatDate(request.updated_at)}`}
                                                            onClick={() => setDeleting(request.id)}
                                                        >
                                                            <Trash2 className="size-4" aria-hidden />
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>

                {requests.last_page > 1 && (
                    <nav aria-label="Pagination" className="flex items-center justify-between text-sm">
                        {requests.prev_page_url ? (
                            <Link href={requests.prev_page_url} className="text-primary underline" preserveScroll>
                                Previous
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span className="tabular-nums">
                            Page {requests.current_page} of {requests.last_page}
                        </span>
                        {requests.next_page_url ? (
                            <Link href={requests.next_page_url} className="text-primary underline" preserveScroll>
                                Next
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </PageContent>

            <DeleteDraftDialog caseId={deleting} onClose={() => setDeleting(null)} />
        </AppLayout>
    );
}
