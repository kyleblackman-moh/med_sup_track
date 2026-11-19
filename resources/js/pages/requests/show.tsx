import { PageContent } from '@/components/page-header';
import { CaseHero } from '@/components/requests/case-hero';
import { FlashMessages } from '@/components/requests/flash-messages';
import { ProgressTimeline } from '@/components/requests/progress-timeline';
import { RequestSummary } from '@/components/requests/request-summary';
import { RequisitionView } from '@/components/requests/requisition-view';
import { SubmitPanel } from '@/components/requests/submit-panel';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { VendorQueries } from '@/components/requests/vendor-queries';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type ProgressMilestone, type Readiness, type RequestDetail, type VendorQuery } from '@/types/requests';
import { type RequestSummaryView } from '@/types/summary';
import { Head, Link } from '@inertiajs/react';

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

            <PageContent>
                {/* V2-4 Stage B: the case hero (design.md section 7), from the server's hero prop. V2-4b: it
                    holds the page's one h1 (the item description, else "New Request"). */}
                <CaseHero hero={detail.hero} />

                {/* The page's links (as they were), in a row under the hero. */}
                {(detail.can.edit || detail.reference_no) && (
                    <div className="flex flex-wrap justify-end gap-2">
                        {detail.can.edit && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('requests.edit', detail.id)}>Edit Draft</Link>
                            </Button>
                        )}
                        {detail.reference_no && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('requests.receipt', detail.id)}>Receipt</Link>
                            </Button>
                        )}
                    </div>
                )}

                <FlashMessages />

                {detail.reference_no && (
                    <p className="bg-card rounded-md border px-4 py-2 text-sm">
                        {detail.hardcopy_received_on ? `Hardcopy received on ${formatDay(detail.hardcopy_received_on)}` : 'Hardcopy not yet received'}
                    </p>
                )}

                {detail.letter && (
                    // Slice 10f: the outcome, with the letter date and reference only.
                    <p role="status" className="bg-card rounded-md border px-4 py-2 text-sm">
                        <span className="font-medium">Non-objection granted.</span> Letter dated {formatDay(detail.letter.date)}
                        {detail.letter.reference && (
                            <>
                                , reference <span className="font-mono">{detail.letter.reference}</span>
                            </>
                        )}
                        .
                    </p>
                )}

                <ProgressTimeline milestones={progress} />

                <VendorQueries caseId={detail.id} queries={queries} />

                {readiness && <SubmitPanel caseId={detail.id} readiness={readiness} canSubmit={can.submit} />}

                {summary ? (
                    <section aria-labelledby="request-summary-heading" className="space-y-4">
                        <h2 id="request-summary-heading" className="text-lg font-semibold">
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
