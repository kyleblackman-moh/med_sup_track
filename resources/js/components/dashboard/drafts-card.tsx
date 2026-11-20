import { NewRequestButton } from '@/components/requests/new-request-button';
import { Link } from '@inertiajs/react';
import { FilePen, FileText } from 'lucide-react';

/** One of the user's own drafts (DashboardDrafts), worded and dated by the server. */
export interface DraftItem {
    id: string;
    reference: string | null;
    title: string;
    type: string | null;
    updated: string;
    /** Null when the draft cannot be edited now; the row then shows no Continue button. */
    edit_href: string | null;
}

export interface DashboardDrafts {
    items: DraftItem[];
    total: number;
    requests_href: string;
}

/**
 * "Your Drafts": the vendor dashboard's only card (no stats, charts or stages).
 * Each row ends with Continue (the draft's edit page); the footer links to My
 * Requests and ends with New Request when the shared `can.requests` allows it.
 */
export function DraftsCard({ drafts, canCreate }: { drafts: DashboardDrafts; canCreate: boolean }) {
    return (
        <section
            aria-labelledby="drafts-heading"
            className="card bg-base-100 border-base-300 min-w-0 border shadow-xs transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body min-w-0 gap-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id="drafts-heading" className="text-lg font-semibold capitalize">
                            Your Drafts
                        </h2>
                        <p className="text-base-content/60 text-sm">Requests you have started but not yet submitted</p>
                    </div>
                    <span className="badge badge-ghost badge-sm tabular-nums">
                        {drafts.total}
                        <span className="sr-only"> {drafts.total === 1 ? 'draft' : 'drafts'}</span>
                    </span>
                </div>

                {drafts.items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-10 text-center">
                        <FileText className="text-base-content/40 size-8" aria-hidden="true" />
                        <p className="font-medium capitalize">No Drafts</p>
                        <p className="text-base-content/60 text-sm">Start a new request when you are ready. Drafts are saved as you go.</p>
                    </div>
                ) : (
                    <ul className="list border-base-300 rounded-box border" aria-label="Your drafts">
                        {drafts.items.map((draft) => (
                            <li key={draft.id} className="list-row hover:bg-base-200 items-center transition-colors">
                                <FilePen className="text-base-content/50 size-5" aria-hidden="true" />
                                <div className="list-col-grow min-w-0">
                                    <p className="truncate text-sm font-medium" title={draft.title}>
                                        {draft.title}
                                    </p>
                                    <p className="text-base-content/60 truncate text-xs">
                                        {[draft.reference, draft.type].filter(Boolean).join(' · ') || 'Draft'} · Last saved {draft.updated}
                                    </p>
                                </div>
                                {draft.edit_href ? (
                                    <Link
                                        href={draft.edit_href}
                                        className="btn btn-sm whitespace-nowrap capitalize transition-transform active:scale-[0.97]"
                                    >
                                        Continue
                                        <span className="sr-only"> {draft.title}</span>
                                    </Link>
                                ) : (
                                    <span className="text-base-content/60 text-xs whitespace-nowrap">Not editable now</span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="border-base-300 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                    <p className="text-base-content/60 text-sm">
                        Submitted requests are in{' '}
                        <Link href={drafts.requests_href} className="link">
                            My Requests
                        </Link>
                        .
                    </p>
                    {canCreate && <NewRequestButton />}
                </div>
            </div>
        </section>
    );
}
