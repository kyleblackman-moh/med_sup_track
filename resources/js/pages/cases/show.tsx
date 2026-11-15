import { CaseActions, hardcopyText } from '@/components/cases/case-actions';
import { type CaseTab, CaseTabNav, CaseTabs } from '@/components/cases/case-tabs';
import { CaseTimeline } from '@/components/cases/case-timeline';
import { GateTracker } from '@/components/cases/gate-tracker';
import { SendOnwardPanel } from '@/components/cases/send-onward';
import { type TabId as RequisitionTab } from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { RequisitionView } from '@/components/requests/requisition-view';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AssignmentEntry,
    type EligibleReviewers,
    type GateState,
    type GrantedLine,
    type HardcopyRecord,
    type LegalForm,
    type PsForm,
    type PsOutcome,
    type ReturnedBanner,
    type ReviewerOption,
    type ReviewForm,
    type RoutePanel,
    type StaffCaseAbilities,
    type StaffCaseDetail,
    type StaffDocument,
    type TimelineEntry,
} from '@/types/cases';
import { Head } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

interface Props {
    case: StaffCaseDetail;
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

/** Tabs for this case. Awards & orders only when the case has any; Actions is always last. */
function buildTabs(detail: StaffCaseDetail): CaseTab<TabId>[] {
    const hasOrders = detail.tender_awards.length > 0 || detail.purchase_orders.length > 0;

    return [
        { id: 'overview', label: 'Overview' },
        { id: 'details', label: 'Request details' },
        { id: 'stock', label: 'Stock' },
        { id: 'tender', label: 'Tender & RFQ' },
        ...(hasOrders ? [{ id: 'orders' as const, label: 'Awards & orders' }] : []),
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
 * same tab. Actions (intake, hardcopy, send for review) are the last tab.
 */
export default function CaseShow({
    case: detail,
    hardcopy,
    hardcopy_dates,
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
    can,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Cases', href: route('cases.index') },
        { title: detail.reference_no ?? 'Case', href: route('cases.show', detail.id) },
    ];

    const tabs = buildTabs(detail);
    const [active, setActive] = useState<TabId>(() => tabFromUrl(tabs.map((t) => t.id)));
    const isPharm = detail.category === 'PHARM';
    const [routeOpen, setRouteOpen] = useState(false);

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
                <header className="space-y-1">
                    <div className="flex flex-wrap items-center gap-3">
                        {/* The reference is the page title here: mono, but kept at heading size. */}
                        <h1 className="font-mono text-2xl font-semibold">{detail.reference_no ?? 'Case'}</h1>
                        <Badge variant="outline">{detail.status_label}</Badge>
                    </div>
                    <p className="text-muted-foreground">{detail.item_description ?? 'No item description'}</p>
                </header>

                <FlashMessages />

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
                                    {can.route_onward && route_panel && (
                                        <div className="mt-3">
                                            <Button type="button" size="sm" onClick={() => setRouteOpen(true)}>
                                                Send onward
                                            </Button>
                                        </div>
                                    )}
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

                            <Card title="Case summary" id="summary-heading">
                                <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    {[
                                        ['Organisation', detail.organisation ?? '—'],
                                        ['Procurement type', detail.procurement_type ?? '—'],
                                        ['Status', detail.status_label],
                                        ['Current holder', detail.holder_label ?? '—'],
                                        ['Submitted', formatDate(detail.submitted_at)],
                                        ['Hardcopy', hardcopyText(hardcopy)],
                                        ...(detail.file_no ? [['File number', detail.file_no]] : []),
                                    ].map(([label, value]) => (
                                        <div key={label}>
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd className={label === 'File number' ? 'font-mono font-medium' : 'font-medium'}>{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </Card>

                            <Card title="Gate" id="gate-heading">
                                <GateTracker gate={gate} />
                            </Card>
                        </>
                    )}

                    {(active === 'details' || active === 'stock' || active === 'tender' || active === 'orders' || active === 'quotations') && (
                        <RequisitionView content={detail} isPharm={isPharm} tab={active} />
                    )}

                    {active === 'documents' && (
                        <>
                            <RequisitionView content={detail} isPharm={isPharm} tab="documents" />

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

                    {active === 'actions' && (
                        <CaseActions
                            caseId={detail.id}
                            reference={detail.reference_no}
                            hardcopy={hardcopy}
                            dates={hardcopy_dates}
                            can={can}
                            reviewers={reviewers}
                            gate={gate}
                            reviewForms={review_forms}
                            fileNoDefault={file_no_default}
                            legalOfficers={legal_officers}
                            legalForm={legal_form}
                            psForm={ps_form}
                            psOutcome={ps_outcome}
                            onSendOnward={route_panel ? () => setRouteOpen(true) : null}
                        />
                    )}
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
                    />
                )}

                <CaseTabNav tabs={tabs} active={active} onSelect={show} finishHref={route('cases.index')} />
            </div>
        </AppLayout>
    );
}
