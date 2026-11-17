import { DeleteDraftDialog } from '@/components/requests/delete-draft-dialog';
import { FlashMessages } from '@/components/requests/flash-messages';
import { StatusBadge } from '@/components/requests/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLiveSearch } from '@/hooks/use-live-search';
import AppLayout from '@/layouts/app-layout';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type RequestSummary } from '@/types/requests';
import { Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    /** "{organisation label} - requests", built on the server (RequestController::LIST_TITLE). */
    title: string;
    filters: { q: string };
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

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain', dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The vendor's Requests list (UI-3): Reference, Type, Item, Status, newest
 * updated first (server order). Search filters through the server as you
 * type; results are always the user's own organisation's requests.
 */
export default function Index({ title, filters, requests, can }: Props) {
    const [deleting, setDeleting] = useState<string | null>(null);
    const search = useLiveSearch({ url: route('requests.index'), applied: filters.q, only: ['requests', 'filters'], maxLength: SEARCH_MAX });

    const count = requests.total === 1 ? '1 request' : `${requests.total} requests`;
    const resultText = filters.q === '' ? count : requests.total === 0 ? `No requests match “${filters.q}”.` : `${count} match “${filters.q}”.`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    {can.create && (
                        <Button asChild>
                            <Link href={route('requests.create')}>New request</Link>
                        </Button>
                    )}
                </header>

                <FlashMessages />

                <div className="grid max-w-md gap-1.5">
                    <Label htmlFor="requests-search">Search requests</Label>
                    <Input
                        id="requests-search"
                        type="search"
                        maxLength={SEARCH_MAX}
                        autoComplete="off"
                        value={search.value}
                        aria-describedby="requests-search-hint"
                        onChange={(e) => search.setValue(e.target.value)}
                    />
                    <p id="requests-search-hint" className="text-muted-foreground text-xs">
                        Reference, item, strength, DIN or type.
                    </p>
                </div>

                <p aria-live="polite" className="text-muted-foreground text-sm">
                    {resultText}
                </p>

                {requests.data.length === 0 ? (
                    filters.q === '' && (
                        <div className="rounded-lg border border-dashed p-8 text-center">
                            <p className="font-medium">No requests yet</p>
                            <p className="text-muted-foreground mt-1 text-sm">Start a new request to ask the Ministry for a non-objection.</p>
                        </div>
                    )
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">{title}</caption>
                            <thead className="bg-muted/50">
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
                            <tbody>
                                {requests.data.map((request) => (
                                    <tr key={request.id} className="hover:bg-muted/60 focus-within:bg-muted/60 relative border-t">
                                        <td className="px-4 py-2 font-mono text-xs whitespace-nowrap">
                                            {request.reference_no ?? <span className="text-muted-foreground font-sans">Not assigned</span>}
                                        </td>
                                        <td className={request.procurement_type.selected ? 'px-4 py-2' : 'text-muted-foreground px-4 py-2'}>
                                            {request.procurement_type.name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {request.item_description ?? <span className="text-muted-foreground">Not described yet</span>}
                                        </td>
                                        <td className="px-4 py-2">
                                            <StatusBadge label={request.status_label} group={request.status_group} />
                                        </td>
                                        <td className="px-4 py-2 text-right whitespace-nowrap">
                                            {request.can.edit ? (
                                                <Link href={route('requests.edit', request.id)} className={cn('underline', rowLink)}>
                                                    Edit<span className="sr-only"> {request.item_description ?? 'draft'}</span>
                                                </Link>
                                            ) : (
                                                <Link href={route('requests.show', request.id)} className={cn('underline', rowLink)}>
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

                {requests.last_page > 1 && (
                    <nav aria-label="Pagination" className="flex items-center justify-between text-sm">
                        {requests.prev_page_url ? (
                            <Link href={requests.prev_page_url} className="underline" preserveScroll>
                                Previous
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span>
                            Page {requests.current_page} of {requests.last_page}
                        </span>
                        {requests.next_page_url ? (
                            <Link href={requests.next_page_url} className="underline" preserveScroll>
                                Next
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </div>

            <DeleteDraftDialog caseId={deleting} onClose={() => setDeleting(null)} />
        </AppLayout>
    );
}
