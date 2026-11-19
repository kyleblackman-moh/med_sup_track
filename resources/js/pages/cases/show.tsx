import { CaseActions, formatDay, hardcopyText } from '@/components/cases/case-actions';
import { type CaseTab, CaseTabs } from '@/components/cases/case-tabs';
import { CaseTimeline } from '@/components/cases/case-timeline';
import { GateTracker } from '@/components/cases/gate-tracker';
import { NextStepCard } from '@/components/cases/next-step-card';
import { SendOnwardPanel } from '@/components/cases/send-onward';
import { PageHeader } from '@/components/page-header';
import { type TabId as RequisitionTab } from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { RequestSummary } from '@/components/requests/request-summary';
import { RequisitionView } from '@/components/requests/requisition-view';
import { Badge } from '@/components/ui/badge';
import { StatusPill } from '@/components/ui/status-pill';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AssignmentEntry,
    type EligibleReviewers,
    type GateState,
    type GrantedLine,
    type HardcopyRecord,
    type LegalForm,
    type LetterRecord,
    type NextStepView,
    type PsForm,
    type PsOutcome,
    type ReturnedBanner,
    type ReviewerOption,
    type ReviewForm,
    type RouteDestination,
    type RoutePanel,
    type StaffCaseAbilities,
    type StaffCaseDetail,
    type StaffDocument,
    type TimelineEntry,
} from '@/types/cases';
import { type RequestSummaryView } from '@/types/summary';
import { Head } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

interface Props {
    case: StaffCaseDetail;
    /** NEXT-STEP-1: the Next Step card (staff only). */
    next_step: NextStepView;
    hardcopy: HardcopyRecord | null;
    hardcopy_dates: { today: string; earliest: string | null };
    documents: StaffDocument[];
    timeline: TimelineEntry[];
    assignments: AssignmentEntry[];
    gate: GateState | null;
    review_forms: ReviewForm[];
    reviewers: EligibleReviewers | null;
    file_no_default: string | null;
    legal_officers: ReviewerOption[] | null;
    legal_form: LegalForm | null;
    returned: ReturnedBanner | null;
    ps_form: PsForm | null;
    ps_outcome: PsOutcome | null;
    granted: GrantedLine | null;
    route_panel: RoutePanel | null;
    letter: LetterRecord | null;
    letter_dates: { today: string } | null;
    /** V2-3: the one RequestSummary for a request-form case; null for the old Pharm and Non-Pharm types. */
    summary: RequestSummaryView | null;
    can: StaffCaseAbilities;
}

type TabId = 'overview' | RequisitionTab | 'history' | 'actions';

const TZ = 'America/Port_of_Spain';

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-TT', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function Card({ title, id, children }: { title: string; id: string; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="bg-card space-y-3 rounded-lg border p-4">
            <h2 id={id} className="text-lg font-semibold">
                {title}
            </h2>
            {children}
        </section>
    );
}

/**
 * Tabs for this case. V2-3: a request-form case shows its request in one
 * "Request Details" tab (the RequestSummary); the old types keep their tabs,
 * with Awards & orders only when the case has any. Actions is always last.
 */
function buildTabs(detail: StaffCaseDetail, hasSummary: boolean): CaseTab<TabId>[] {
    if (hasSummary) {
        return [
            { id: 'overview', label: 'Overview' },
            { id: 'details', label: 'Request Details' },
            { id: 'documents', label: 'Documents' },
            { id: 'history', label: 'History' },
            { id: 'actions', label: 'Actions' },
        ];
    }

    const hasOrders = detail.tender_awards.length > 0 || detail.purchase_orders.length > 0;

    return [
        { id: 'overview', label: 'Overview' },
        { id: 'details', label: 'Request Details' },
        { id: 'stock', label: 'Stock' },
        { id: 'tender', label: 'Tender & RFQ' },
        ...(hasOrders ? [{ id: 'orders' as const, label: 'Awards & Orders' }] : []),
        { id: 'quotations', label: 'Quotations' },
        { id: 'documents', label: 'Documents' },
        { id: 'history', label: 'History' },
        { id: 'actions', label: 'Actions' },
    ];
}

function tabFromUrl(ids: TabId[]): TabId {
    const wanted = new URLSearchParams(window.location.search).get('tab');

    return ids.find((id) => id === wanted) ?? 'overview';
}

/**
 * Staff view of one case (slice 9a), split into tabs so nobody scrolls far.
 * The open tab is kept in ?tab=, so an action's redirect back lands on the
 * same tab. NEXT-STEP-1: the Next Step card sits above the tabs on every tab;
 * the Actions tab keeps the less common actions. No numbered tabs and no
 * Back / Next footer (design.md does not define them).
 */
export default function CaseShow({
    case: detail,
    next_step,
    hardcopy,
    documents,
    timeline,
    assignments,
    gate,
    review_forms,
    reviewers,
    file_no_default,
    legal_officers,
    legal_form,
    returned,
    ps_form,
    ps_outcome,
    granted,
    route_panel,
    letter,
    letter_dates,
    summary,
    can,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Cases', href: route('cases.index') },
        { title: detail.reference_no ?? 'Case', href: route('cases.show', detail.id) },
    ];

    const tabs = buildTabs(detail, summary !== null);
    const [active, setActive] = useState<TabId>(() => tabFromUrl(tabs.map((t) => t.id)));
    const isPharm = detail.category === 'PHARM';
    const [routeOpen, setRouteOpen] = useState(false);
    const [routeTo, setRouteTo] = useState<RouteDestination['value'] | null>(null);

    const openRoute = (destination: RouteDestination['value']) => {
        setRouteTo(destination);
        setRouteOpen(true);
    };

    const show = (next: TabId) => {
        setActive(next);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', url);
        window.scrollTo({ top: 0 });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Case'} />

            <div className="mx-auto w-full max-w-6xl space-y-4 p-4">
                {/* V2-3: PageHeader. The reference is the page title here, in mono. */}
                <PageHeader
                    title={detail.reference_no ?? 'Case'}
                    titleClassName="font-mono"
                    subtitle={detail.item_description ?? 'No item description'}
                    actions={<StatusPill label={detail.status_label} tone={detail.status_tone} />}
                />

                <FlashMessages />

                <NextStepCard
                    caseId={detail.id}
                    next={next_step}
                    reviewers={reviewers}
                    fileNoDefault={file_no_default}
                    legalOfficers={legal_officers}
                    legalForm={legal_form}
                    psForm={ps_form}
                    reviewForms={review_forms}
                    letterToday={letter_dates?.today ?? null}
                    onRoute={can.route_onward && route_panel ? openRoute : null}
                />

                <CaseTabs tabs={tabs} active={active} onSelect={show} />

                <div role="tabpanel" id={`panel-${active}`} aria-labelledby={`tab-${active}`} className="space-y-6 pb-4">
                    {active === 'overview' && (
                        <>
                            {returned && (
                                // "Returned to CMO by <source> (<kind>): <reason>, <name>, <date>". The server
                                // gives the source (CaseStatus::returnedBySource) and, for a PS return, the kind.
                                <section role="status" className="bg-muted rounded-lg border p-4 text-sm">
                                    <span className="font-semibold">
                                        Returned to CMO{returned.source ? ` by ${returned.source}` : ''}
                                        {returned.kind ? ` (${returned.kind})` : ''}:
                                    </span>{' '}
                                    {[returned.reason, returned.by, returned.at ? formatDateTime(returned.at) : null]
                                        .filter((part) => part)
                                        .join(', ')}
                                </section>
                            )}

                            {granted && (
                                // Slice 10d: staff only. "Non-objection granted by <name>, <date>", plus the note.
                                <section role="status" className="bg-muted space-y-1 rounded-lg border p-4 text-sm">
                                    <p>
                                        <span className="font-semibold">Non-objection granted</span>
                                        {granted.by && ` by ${granted.by}`}
                                        {granted.at && `, ${formatDateTime(granted.at)}`}
                                    </p>
                                    {granted.note && <p className="whitespace-pre-line">Note: {granted.note}</p>}
                                </section>
                            )}

                            {letter && (
                                // Slice 10f: staff only. Every part comes from the server.
                                <section role="status" className="bg-muted rounded-lg border p-4 text-sm">
                                    <span className="font-semibold">Letter issued:</span>{' '}
                                    {[
                                        `dated ${formatDay(letter.date)}`,
                                        letter.reference ? `reference ${letter.reference}` : null,
                                        letter.emailed_on ? `emailed on ${formatDay(letter.emailed_on)}` : null,
                                        letter.hardcopy_sent_on ? `hardcopy sent on ${formatDay(letter.hardcopy_sent_on)}` : null,
                                        letter.recorded_by ? `recorded by ${letter.recorded_by}` : null,
                                        letter.recorded_at ? `on ${formatDateTime(letter.recorded_at)}` : null,
                                    ]
                                        .filter((part) => part)
                                        .join(', ')}
                                </section>
                            )}

                            <Card title="Case Summary" id="summary-heading">
                                <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    {[
                                        ['Organisation', detail.organisation ?? '—'],
                                        ['Procurement Type', detail.procurement_type ?? '—'],
                                        ['Status', detail.status_label],
                                        ['Current Holder', detail.holder_label ?? '—'],
                                        ['Submitted', formatDate(detail.submitted_at)],
                                        ['Hardcopy', hardcopyText(hardcopy)],
                                        ...(detail.file_no ? [['File Number', detail.file_no]] : []),
                                    ].map(([label, value]) => (
                                        <div key={label}>
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd className={label === 'File Number' ? 'font-mono font-medium' : 'font-medium'}>{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </Card>

                            <Card title="Gate" id="gate-heading">
                                <GateTracker gate={gate} />
                            </Card>
                        </>
                    )}

                    {summary
                        ? active === 'details' && <RequestSummary summary={summary} idPrefix="case" />
                        : (active === 'details' || active === 'stock' || active === 'tender' || active === 'orders' || active === 'quotations') && (
                              <RequisitionView content={detail} isPharm={isPharm} tab={active} />
                          )}

                    {active === 'documents' && (
                        <>
                            {!summary && <RequisitionView content={detail} isPharm={isPharm} tab="documents" />}

                            <Card title="Documents" id="documents-heading">
                                {documents.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">No documents available.</p>
                                ) : (
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-muted/50">
                                                <tr>
                                                    {['Document', 'Type', 'Version', 'Uploaded', 'Uploaded by', 'Visibility'].map((h) => (
                                                        <th key={h} scope="col" className="px-3 py-2 font-medium whitespace-nowrap">
                                                            {h}
                                                        </th>
                                                    ))}
                                                    <th scope="col" className="px-3 py-2">
                                                        <span className="sr-only">Download</span>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {documents.map((doc) => (
                                                    <tr key={doc.id} className="border-t">
                                                        <td className="px-3 py-2 break-all">{doc.display_name}</td>
                                                        <td className="px-3 py-2">{doc.purpose_label}</td>
                                                        <td className="px-3 py-2 tabular-nums">{doc.version}</td>
                                                        <td className="px-3 py-2 whitespace-nowrap">{formatDateTime(doc.uploaded_at)}</td>
                                                        <td className="px-3 py-2">{doc.uploaded_by ?? '—'}</td>
                                                        <td className="px-3 py-2">
                                                            <Badge variant={doc.visibility === 'Internal' ? 'secondary' : 'outline'}>
                                                                {doc.visibility}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                                            {can.download_documents && doc.can_download && (
                                                                <a
                                                                    href={route('cases.documents.download', [detail.id, doc.id])}
                                                                    className="underline underline-offset-2"
                                                                >
                                                                    Download<span className="sr-only"> {doc.display_name}</span>
                                                                </a>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </Card>
                        </>
                    )}

                    {active === 'history' && (
                        <div className="grid gap-6 lg:grid-cols-2">
                            <Card title="Timeline" id="timeline-heading">
                                <CaseTimeline entries={timeline} />
                            </Card>

                            <Card title="Assignments" id="assignments-heading">
                                {assignments.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">No assignments.</p>
                                ) : (
                                    <ul className="divide-y text-sm">
                                        {assignments.map((a) => (
                                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                                <div>
                                                    <p className="font-medium">{a.user ?? `Any ${a.role_label} officer`}</p>
                                                    <p className="text-muted-foreground">
                                                        {a.role_label} · assigned {formatDateTime(a.assigned_at)}
                                                        {a.completed_at && ` · completed ${formatDateTime(a.completed_at)}`}
                                                    </p>
                                                </div>
                                                <Badge variant={a.state === 'Active' ? 'default' : 'outline'}>{a.state}</Badge>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        </div>
                    )}

                    {active === 'actions' && <CaseActions caseId={detail.id} hardcopy={hardcopy} can={can} psOutcome={ps_outcome} />}
                </div>

                {can.route_onward && route_panel && (
                    <SendOnwardPanel
                        open={routeOpen}
                        onOpenChange={setRouteOpen}
                        caseId={detail.id}
                        reference={detail.reference_no}
                        procurementType={detail.procurement_type}
                        itemDescription={detail.item_description}
                        statusLabel={detail.status_label}
                        returned={returned}
                        gate={gate}
                        panel={route_panel}
                        initialDestination={routeTo}
                        key={routeTo ?? 'suggested'}
                    />
                )}
            </div>
        </AppLayout>
    );
}
