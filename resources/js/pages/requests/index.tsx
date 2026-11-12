import { FlashMessages } from '@/components/requests/flash-messages';
import { StatusBadge } from '@/components/requests/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type RequestSummary } from '@/types/requests';
import { Head, Link } from '@inertiajs/react';

interface Props {
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

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: 'America/Port_of_Spain', dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

export default function Index({ requests, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Requests" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Non-objection requests</h1>
                    {can.create && (
                        <Button asChild>
                            <Link href={route('requests.create')}>New request</Link>
                        </Button>
                    )}
                </header>

                <FlashMessages />

                {requests.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <p className="font-medium">No requests yet</p>
                        <p className="text-muted-foreground mt-1 text-sm">Start a new request to ask the Ministry for a non-objection.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Your organisation's requests</caption>
                            <thead className="bg-muted/50">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Type
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Item
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Reference
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        Updated
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.data.map((request) => (
                                    <tr key={request.id} className="border-t">
                                        <td className="px-4 py-2">
                                            <StatusBadge label={request.status_label} group={request.status_group} />
                                        </td>
                                        <td className="px-4 py-2">{request.procurement_type.name}</td>
                                        <td className="px-4 py-2">
                                            {request.item_description ?? <span className="text-muted-foreground">Not described yet</span>}
                                        </td>
                                        <td className="px-4 py-2">{request.reference_no ?? ''}</td>
                                        <td className="px-4 py-2">{formatDate(request.updated_at)}</td>
                                        <td className="px-4 py-2 text-right">
                                            {request.can.edit ? (
                                                <Link href={route('requests.edit', request.id)} className="underline">
                                                    Edit<span className="sr-only"> {request.item_description ?? 'draft'}</span>
                                                </Link>
                                            ) : (
                                                <Link href={route('requests.show', request.id)} className="underline">
                                                    View<span className="sr-only"> {request.item_description ?? 'request'}</span>
                                                </Link>
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
        </AppLayout>
    );
}
