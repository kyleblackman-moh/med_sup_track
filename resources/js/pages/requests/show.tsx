import { PageContent } from '@/components/page-header';
import { CaseHero } from '@/components/requests/case-hero';
import { FlashMessages } from '@/components/requests/flash-messages';
import { ProgressTimeline } from '@/components/requests/progress-timeline';
import { RequestSummary } from '@/components/requests/request-summary';
import { RequisitionView } from '@/components/requests/requisition-view';
import { SubmitPanel } from '@/components/requests/submit-panel';
import { SupportingDocuments } from '@/components/requests/supporting-documents';
import { VendorQueries } from '@/components/requests/vendor-queries';
import { WithdrawRequest } from '@/components/requests/withdraw-request';
import { type TabItem, Tabs } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DocumentGroup, type ProgressMilestone, type Readiness, type RequestDetail, type VendorQuery } from '@/types/requests';
import { type RequestSummaryView } from '@/types/summary';
import { Head, Link } from '@inertiajs/react';
import { BadgeCheck, Ban, FileDown, FilePen, Mailbox, PackageCheck, Printer, ReceiptText } from 'lucide-react';
import { useState } from 'react';

interface Props {
    case: RequestDetail;
    documents: DocumentGroup[];
    readiness: Readiness | null;
    can: { submit: boolean; withdraw: boolean };
    /** TERMINAL-1: "Withdrawn" or "Not approved" and the Ministry's message (never the internal reason); null otherwise. */
    ended: { label: string; message: string } | null;
    /** V2-3: the one RequestSummary for a request-form case; null for the old Pharm and Non-Pharm types. */
    summary: RequestSummaryView | null;
    /** OVERNIGHT-2 (B1): dated vendor-facing milestones; empty for a draft. */
    progress: ProgressMilestone[];
    /** OVERNIGHT-2 (D3): queries from the Ministry addressed to this organisation; empty for a draft. */
    queries: VendorQuery[];
}

type RequestTab = 'request' | 'documents';

/** A calendar date (Y-m-d), shown as-is: no time zone shift. */
function formatDay(day: string): string {
    return new Date(`${day}T12:00:00Z`).toLocaleDateString('en-TT', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });
}

export default function Show({ case: detail, documents, readiness, can, ended, summary, progress, queries }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Requests', href: route('requests.index') },
        // V2-4 (design.md section 6): the last crumb is the reference, or "New Request" before there is one.
        { title: detail.reference_no ?? 'New Request', href: route('requests.show', detail.id) },
    ];

    // The long parts of the page, one at a time: the request itself, then its documents.
    const [tab, setTab] = useState<RequestTab>('request');
    const documentCount = documents.reduce((n, group) => n + group.documents.length, 0);
    const tabs: TabItem<RequestTab>[] = [
        { id: 'request', label: 'Your Request' },
        { id: 'documents', label: documentCount > 0 ? `Supporting Documents (${documentCount})` : 'Supporting Documents' },
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
                {(detail.can.edit || detail.reference_no || can.withdraw || summary) && (
                    <div className="flex flex-wrap justify-end gap-2">
                        {can.withdraw && <WithdrawRequest caseId={detail.id} reference={detail.reference_no} />}
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
                        {/* The printable form: request-form types only (summary is null for the old types, as is the print route). */}
                        {summary && (
                            <>
                                <Link href={route('requests.print', detail.id)} className="btn btn-sm capitalize">
                                    <Printer className="size-4" aria-hidden="true" />
                                    Print
                                </Link>
                                {/* Backlog v0.4: the same form as a PDF download. */}
                                <a href={route('requests.letter', detail.id)} className="btn btn-sm capitalize" download>
                                    <FileDown className="size-4" aria-hidden="true" />
                                    Export PDF
                                </a>
                            </>
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

                {ended && (
                    // TERMINAL-1: the outcome and the Ministry's message only.
                    <div role="status" className="alert alert-soft items-start text-sm">
                        <Ban className="size-5" aria-hidden="true" />
                        <div className="grid gap-1">
                            <p className="font-medium">{ended.label}.</p>
                            <p className="whitespace-pre-line">
                                <span className="font-medium">Message from the Ministry of Health:</span> {ended.message}
                            </p>
                        </div>
                    </div>
                )}

                <ProgressTimeline milestones={progress} />

                <VendorQueries caseId={detail.id} queries={queries} />

                {readiness && <SubmitPanel caseId={detail.id} readiness={readiness} canSubmit={can.submit} />}

                <div className="min-w-0">
                    <Tabs tabs={tabs} active={tab} onSelect={setTab} label="Request sections" />
                    <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="pt-4">
                        {tab === 'request' &&
                            (summary ? (
                                <section aria-label="Your Request" className="space-y-4">
                                    <RequestSummary summary={summary} />
                                </section>
                            ) : (
                                <RequisitionView content={detail} isPharm={detail.procurement_type.category === 'PHARM'} />
                            ))}
                        {tab === 'documents' && <SupportingDocuments caseId={detail.id} groups={documents} readOnly embedded />}
                    </div>
                </div>
            </PageContent>
        </AppLayout>
    );
}
