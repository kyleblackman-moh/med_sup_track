import { PageContent, PageHeader } from '@/components/page-header';
import { FlashMessages } from '@/components/requests/flash-messages';
import { RequestSummary } from '@/components/requests/request-summary';
import { RequisitionView } from '@/components/requests/requisition-view';
import { SubmitPanel } from '@/components/requests/submit-panel';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type Readiness, type RequestDetail } from '@/types/requests';
import { type RequestSummaryView } from '@/types/summary';
import { Head, Link } from '@inertiajs/react';

interface Props {
    case: RequestDetail;
    documents: DocumentGroup[];
    readiness: Readiness | null;
    can: { submit: boolean };
    /** V2-3: the one RequestSummary for a request-form case; null for the old Pharm and Non-Pharm types. */
    summary: RequestSummaryView | null;
}

/** A calendar date (Y-m-d), shown as-is: no time zone shift. */
function formatDay(day: string): string {
    return new Date(`${day}T12:00:00Z`).toLocaleDateString('en-TT', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });
}

export default function Show({ case: detail, documents, readiness, can, summary }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        // V2-4 (design.md section 6): the last crumb is the reference, or "New request" before there is one.
        { title: detail.reference_no ?? 'New request', href: route('requests.show', detail.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Request'} />

            <PageContent>
                {/* V2-3: PageHeader with the title, reference and status pill; the page's links stay as they were. */}
                <PageHeader
                    title={detail.item_description ?? 'Request'}
                    subtitle={
                        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span>{detail.procurement_type.name}</span>
                            {detail.reference_no ? (
                                <span className="text-foreground font-mono text-xs">{detail.reference_no}</span>
                            ) : (
                                <span>Draft</span>
                            )}
                        </span>
                    }
                    actions={
                        <>
                            <StatusPill label={detail.status_label} tone={detail.status_tone} />
                            {detail.can.edit && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('requests.edit', detail.id)}>Edit draft</Link>
                                </Button>
                            )}
                            {detail.reference_no && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={route('requests.receipt', detail.id)}>Receipt</Link>
                                </Button>
                            )}
                        </>
                    }
                />

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

                {readiness && <SubmitPanel caseId={detail.id} readiness={readiness} canSubmit={can.submit} />}

                {summary ? (
                    <section aria-labelledby="request-summary-heading" className="space-y-4">
                        <h2 id="request-summary-heading" className="text-lg font-semibold">
                            Your request
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
