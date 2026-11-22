import { PageContent } from '@/components/page-header';
import { CaseHero } from '@/components/requests/case-hero';
import { FlashMessages } from '@/components/requests/flash-messages';
import { ProgressTimeline } from '@/components/requests/progress-timeline';
import { RequestSummary } from '@/components/requests/request-summary';
import { RequisitionView } from '@/components/requests/requisition-view';
import { SubmitPanel } from '@/components/requests/submit-panel';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { VendorQueries } from '@/components/requests/vendor-queries';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type ProgressMilestone, type Readiness, type RequestDetail, type VendorQuery } from '@/types/requests';
import { type RequestSummaryView } from '@/types/summary';
import { Head, Link } from '@inertiajs/react';
import { BadgeCheck, FilePen, Mailbox, PackageCheck, ReceiptText } from 'lucide-react';

interface Props {
    case: RequestDetail;
    documents: DocumentGroup[];
    readiness: Readiness | null;
    can: { submit: boolean };
    /** V2-3: the one RequestSummary for a request-form case; null for the old Pharm and Non-Pharm types. */
    summary: RequestSummaryView | null;
    /** OVERNIGHT-2 (B1): dated vendor-facing milestones; empty for a draft. */
    progress: ProgressMilestone[];
    /** OVERNIGHT-2 (D3): queries from the Ministry addressed to this organisation; empty for a draft. */
    queries: VendorQuery[];
}

/** A calendar date (Y-m-d), shown as-is: no time zone shift. */
function formatDay(day: string): string {
    return new Date(`${day}T12:00:00Z`).toLocaleDateString('en-TT', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });
}

export default function Show({ case: detail, documents, readiness, can, summary, progress, queries }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        // V2-4 (design.md section 6): the last crumb is the reference, or "New Request" before there is one.
        { title: detail.reference_no ?? 'New Request', href: route('requests.show', detail.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Request'} />

            {/* Full width beside the drawer: px-4 py-4, lg:px-6 (PageContent's md:p-6 reset to p-4 first). */}
            <PageContent className="space-y-4 px-4 py-4 md:p-4 lg:px-6">
                {/* V2-4 Stage B: the case hero (design.md section 7), from the server's hero prop. V2-4b: it
                    holds the page's one h1 (the item description, else "New Request"). */}
                <CaseHero hero={detail.hero} />

                {/* The page's links (as they were), in a row under the hero. */}
                {(detail.can.edit || detail.reference_no) && (
                    <div className="flex flex-wrap justify-end gap-2">
                        {detail.can.edit && (
                            <Link href={route('requests.edit', detail.id)} className="btn btn-sm capitalize">
                                <FilePen className="size-4" aria-hidden="true" />
                                Edit Draft
                            </Link>
                        )}
                        {detail.reference_no && (
                            <Link href={route('requests.receipt', detail.id)} className="btn btn-sm capitalize">
                                <ReceiptText className="size-4" aria-hidden="true" />
                                Receipt
                            </Link>
                        )}
                    </div>
                )}

                <FlashMessages />

                {detail.reference_no && (
                    // The physical file the vendor delivers to the CMO's Office: received or not, in words and icon.
                    <div className="alert bg-base-100 border-base-300 text-sm">
                        {detail.hardcopy_received_on ? (
                            <PackageCheck className="text-primary size-5" aria-hidden="true" />
                        ) : (
                            <Mailbox className="text-base-content/60 size-5" aria-hidden="true" />
                        )}
                        <span>
                            {detail.hardcopy_received_on
                                ? `Hardcopy received on ${formatDay(detail.hardcopy_received_on)}`
                                : 'Hardcopy not yet received'}
                        </span>
                    </div>
                )}

                {detail.letter && (
                    // Slice 10f: the outcome, with the letter date and reference only.
                    <div role="status" className="alert alert-success alert-soft text-sm">
                        <BadgeCheck className="size-5" aria-hidden="true" />
                        <p>
                            <span className="font-medium">Non-objection granted.</span> Letter dated {formatDay(detail.letter.date)}
                            {detail.letter.reference && (
                                <>
                                    , reference <span className="font-mono">{detail.letter.reference}</span>
                                </>
                            )}
                            .
                        </p>
                    </div>
                )}

                <ProgressTimeline milestones={progress} />

                <VendorQueries caseId={detail.id} queries={queries} />

                {readiness && <SubmitPanel caseId={detail.id} readiness={readiness} canSubmit={can.submit} />}

                {summary ? (
                    <section aria-labelledby="request-summary-heading" className="space-y-4">
                        <h2 id="request-summary-heading" className="text-lg font-semibold capitalize">
                            Your Request
                        </h2>
                        <RequestSummary summary={summary} />
                    </section>
                ) : (
                    <RequisitionView content={detail} isPharm={detail.procurement_type.category === 'PHARM'} />
                )}

                <SupportingDocuments caseId={detail.id} groups={documents} readOnly />
            </PageContent>
        </AppLayout>
    );
}
