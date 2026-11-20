import { CaseActions, formatDay, hardcopyText } from '@/components/cases/case-actions';
import { CaseTimeline } from '@/components/cases/case-timeline';
import { GateTracker } from '@/components/cases/gate-tracker';
import { NextStepCard } from '@/components/cases/next-step-card';
import { QueriesPanel } from '@/components/cases/queries-panel';
import { SendOnwardPanel } from '@/components/cases/send-onward';
import { type TabId as RequisitionTab } from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { RequestSummary } from '@/components/requests/request-summary';
import { RequisitionView } from '@/components/requests/requisition-view';
import { StatusPill } from '@/components/ui/status-pill';
import { type TabItem, Tabs } from '@/components/ui/tabs';
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
    type StaffQueries,
    type TimelineEntry,
} from '@/types/cases';
import { type RequestSummaryView } from '@/types/summary';
import { Head } from '@inertiajs/react';
import { CircleCheck, FileText, History, Mail, ShieldCheck, Undo2, Users } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

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
    /** OVERNIGHT-2 (D2): the queries this user may see, and the routes they may raise one on. */
    queries: StaffQueries;
    can: StaffCaseAbilities;
}

type TabId = 'overview' | RequisitionTab | 'queries' | 'history';

const TZ = 'America/Port_of_Spain';

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-TT', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function Card({ title, id, icon: Icon, children }: { title: string; id: string; icon?: typeof FileText; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="card bg-base-100 border-base-300 min-w-0 border transition-shadow duration-200 hover:shadow-sm">
            <div className="card-body min-w-0 gap-3 p-4">
                <h2 id={id} className="card-title text-base capitalize">
                    {Icon && <Icon className="text-primary size-4 shrink-0" aria-hidden="true" />}
                    {title}
                </h2>
                {children}
            </div>
        </section>
    );
}

/**
 * Tabs for this case. V2-3: a request-form case shows its request in one
 * "Request Details" tab (the RequestSummary); the old types keep their tabs,
 * with Awards & orders only when the case has any. The actions are not a tab:
 * they sit in the action column beside the tabs.
 */
function buildTabs(detail: StaffCaseDetail, hasSummary: boolean): TabItem<TabId>[] {
    if (hasSummary) {
        return [
            { id: 'overview', label: 'Overview' },
            { id: 'details', label: 'Request Details' },
            { id: 'documents', label: 'Documents' },
            { id: 'queries', label: 'Queries' },
            { id: 'history', label: 'History' },
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
        { id: 'queries', label: 'Queries' },
        { id: 'history', label: 'History' },
    ];
}

function tabFromUrl(ids: TabId[]): TabId {
    // ?tab=actions (older links) opens the overview; the action column is always shown.
    const wanted = new URLSearchParams(window.location.search).get('tab');

    return ids.find((id) => id === wanted) ?? 'overview';
}

/**
 * Staff view of one case (slice 9a): a compact facts header, the record in
 * tabs on the left, and the officer's next step in the action column on the
 * right (sticky from xl; at the end of the page below xl). The open tab is
 * kept in ?tab=, so an action's redirect back lands on the same tab.
 * NEXT-STEP-1: the Next Step card leads the action column on every tab; the
 * less common actions follow it. No numbered tabs and no Back / Next footer
 * (design.md does not define them).
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
    queries,
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

    // An older ?tab=actions link: bring the action column into view.
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('tab') === 'actions') {
            document.getElementById('case-actions')?.scrollIntoView({ block: 'start' });
        }
    }, []);

    const facts: [string, string, boolean?][] = [
        ['Organisation', detail.organisation ?? '—'],
        ['Procurement Type', detail.procurement_type ?? '—'],
        ['Current Holder', detail.holder_label ?? '—'],
        ['Submitted', formatDate(detail.submitted_at)],
        ['Hardcopy', hardcopyText(hardcopy)],
        ...(detail.file_no ? ([['File Number', detail.file_no, true]] as [string, string, boolean][]) : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Case'} />

            <header className="bg-base-100 border-base-300 border-b px-4 py-4 lg:px-6">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    {/* V2-3: the reference is the page title here, in mono. */}
                    <h1 className="font-mono text-xl font-semibold">{detail.reference_no ?? 'Case'}</h1>
                    <StatusPill label={detail.status_label} tone={detail.status_tone} className="capitalize" />
                </div>
                <p className="text-base-content/70 mt-1 text-sm">{detail.item_description ?? 'No item description'}</p>
                <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3 xl:grid-cols-6">
                    {facts.map(([label, value, mono]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-base-content/60 text-xs capitalize">{label}</dt>
                            <dd className={mono ? 'truncate font-mono font-medium' : 'truncate font-medium'} title={value}>
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </header>

            <div className="grid min-w-0 flex-1 items-start gap-4 px-4 py-4 lg:px-6 xl:grid-cols-[minmax(0,1fr)_24rem] 2xl:grid-cols-[minmax(0,1fr)_28rem]">
                <div className="flex min-w-0 flex-col gap-4">
                    <FlashMessages />

                    <Tabs tabs={tabs} active={active} onSelect={show} label="Case sections" className="border-base-300 capitalize" />

                    <div
                        role="tabpanel"
                        id={`panel-${active}`}
                        aria-labelledby={`tab-${active}`}
                        key={active}
                        className="flex min-w-0 flex-col gap-4 motion-safe:transition-opacity motion-safe:duration-300 motion-safe:starting:opacity-0"
                    >
                        {active === 'overview' && (
                            <>
                                {returned && (
                                    // "Returned to CMO by <source> (<kind>): <reason>, <name>, <date>". The server
                                    // gives the source (CaseStatus::returnedBySource) and, for a PS return, the kind.
                                    <div role="status" className="alert alert-soft alert-warning items-start">
                                        <Undo2 className="size-5" aria-hidden="true" />
                                        <div className="text-sm">
                                            <h2 className="font-semibold capitalize">
                                                Returned to CMO{returned.source ? ` by ${returned.source}` : ''}
                                                {returned.kind ? ` (${returned.kind})` : ''}
                                            </h2>
                                            <p className="whitespace-pre-line">
                                                {[returned.reason, returned.by, returned.at ? formatDateTime(returned.at) : null]
                                                    .filter((part) => part)
                                                    .join(', ')}
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {granted && (
                                    // Slice 10d: staff only. "Non-objection granted by <name>, <date>", plus the note.
                                    <div role="status" className="alert alert-soft alert-success items-start">
                                        <CircleCheck className="size-5" aria-hidden="true" />
                                        <div className="text-sm">
                                            <h2 className="font-semibold capitalize">Non-objection granted</h2>
                                            <p>
                                                {granted.by && `By ${granted.by}`}
                                                {granted.by && granted.at && ', '}
                                                {granted.at && formatDateTime(granted.at)}
                                            </p>
                                            {granted.note && <p className="whitespace-pre-line">Note: {granted.note}</p>}
                                        </div>
                                    </div>
                                )}

                                {letter && (
                                    // Slice 10f: staff only. Every part comes from the server.
                                    <div role="status" className="alert alert-soft alert-info items-start">
                                        <Mail className="size-5" aria-hidden="true" />
                                        <div className="text-sm">
                                            <h2 className="font-semibold capitalize">Letter issued</h2>
                                            <p>
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
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <Card title="Gate" id="gate-heading" icon={ShieldCheck}>
                                    <GateTracker gate={gate} />
                                </Card>
                            </>
                        )}

                        {summary
                            ? active === 'details' && <RequestSummary summary={summary} idPrefix="case" />
                            : (active === 'details' ||
                                  active === 'stock' ||
                                  active === 'tender' ||
                                  active === 'orders' ||
                                  active === 'quotations') && <RequisitionView content={detail} isPharm={isPharm} tab={active} />}

                        {active === 'queries' && <QueriesPanel caseId={detail.id} data={queries} />}

                        {active === 'documents' && (
                            <>
                                {!summary && <RequisitionView content={detail} isPharm={isPharm} tab="documents" />}

                                <Card title="Documents" id="documents-heading" icon={FileText}>
                                    {documents.length === 0 ? (
                                        <p className="text-base-content/70 text-sm">No documents available.</p>
                                    ) : (
                                        <div className="-mx-4 max-w-none overflow-x-auto">
                                            <table className="table-sm table">
                                                <caption className="sr-only">Documents on this case</caption>
                                                <thead>
                                                    <tr className="capitalize">
                                                        {['Document', 'Type', 'Version', 'Uploaded', 'Uploaded by', 'Visibility'].map((h) => (
                                                            <th key={h} scope="col" className="whitespace-nowrap">
                                                                {h}
                                                            </th>
                                                        ))}
                                                        <th scope="col">
                                                            <span className="sr-only">Download</span>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {documents.map((doc) => (
                                                        <tr key={doc.id} className="hover:bg-base-200 transition-colors">
                                                            <td className="break-all">{doc.display_name}</td>
                                                            <td>{doc.purpose_label}</td>
                                                            <td className="tabular-nums">{doc.version}</td>
                                                            <td className="whitespace-nowrap tabular-nums">{formatDateTime(doc.uploaded_at)}</td>
                                                            <td>{doc.uploaded_by ?? '—'}</td>
                                                            <td>
                                                                <span
                                                                    className={
                                                                        doc.visibility === 'Internal'
                                                                            ? 'badge badge-soft badge-neutral badge-sm capitalize'
                                                                            : 'badge badge-outline badge-sm capitalize'
                                                                    }
                                                                >
                                                                    {doc.visibility}
                                                                </span>
                                                            </td>
                                                            <td className="text-right whitespace-nowrap">
                                                                {can.download_documents && doc.can_download && (
                                                                    <a
                                                                        href={route('cases.documents.download', [detail.id, doc.id])}
                                                                        className="btn btn-ghost btn-xs capitalize active:scale-[0.97]"
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
                            <div className="grid gap-4 2xl:grid-cols-2">
                                <Card title="Timeline" id="timeline-heading" icon={History}>
                                    <CaseTimeline entries={timeline} />
                                </Card>

                                <Card title="Assignments" id="assignments-heading" icon={Users}>
                                    {assignments.length === 0 ? (
                                        <p className="text-base-content/70 text-sm">No assignments.</p>
                                    ) : (
                                        <ul className="list -mx-4">
                                            {assignments.map((a) => (
                                                <li key={a.id} className="list-row items-center py-2 transition-colors">
                                                    <div className="list-col-grow min-w-0 text-sm">
                                                        <p className="font-medium">{a.user ?? `Any ${a.role_label} officer`}</p>
                                                        <p className="text-base-content/70">
                                                            {a.role_label} · assigned {formatDateTime(a.assigned_at)}
                                                            {a.completed_at && ` · completed ${formatDateTime(a.completed_at)}`}
                                                        </p>
                                                    </div>
                                                    <span
                                                        className={
                                                            a.state === 'Active'
                                                                ? 'badge badge-soft badge-info badge-sm capitalize'
                                                                : 'badge badge-outline badge-sm capitalize'
                                                        }
                                                    >
                                                        {a.state}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                            </div>
                        )}
                    </div>
                </div>

                <aside
                    id="case-actions"
                    aria-labelledby="case-actions-heading"
                    className="flex min-w-0 scroll-mt-4 flex-col gap-4 xl:sticky xl:top-4 xl:max-h-[calc(100dvh-2rem)] xl:overflow-y-auto xl:overscroll-contain xl:pb-1"
                >
                    <h2 id="case-actions-heading" className="sr-only">
                        Your Next Step and Other Actions
                    </h2>

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
                        onOpenQueries={() => show('queries')}
                    />

                    <CaseActions caseId={detail.id} hardcopy={hardcopy} can={can} psOutcome={ps_outcome} />
                </aside>
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
        </AppLayout>
    );
}
