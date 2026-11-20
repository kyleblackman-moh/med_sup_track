import { CaseActions, formatDay, hardcopyText } from '@/components/cases/case-actions';
import { type CaseTab, CaseTabNav, CaseTabs } from '@/components/cases/case-tabs';
import { CaseTimeline } from '@/components/cases/case-timeline';
import { GateTracker } from '@/components/cases/gate-tracker';
import { SendOnwardPanel } from '@/components/cases/send-onward';
import { type TabId as RequisitionTab } from '@/components/requests/fields';
import { FlashMessages } from '@/components/requests/flash-messages';
import { RequisitionView } from '@/components/requests/requisition-view';
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
import { CircleCheck, FileText, Mail, ShieldCheck, Undo2, Users } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

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
    letter: LetterRecord | null;
    letter_dates: { today: string } | null;
    can: StaffCaseAbilities;
}

type TabId = 'overview' | RequisitionTab | 'history';

const TZ = 'America/Port_of_Spain';

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-TT', { timeZone: TZ, day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function Card({ title, id, icon: Icon, children }: { title: string; id: string; icon?: typeof FileText; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="card bg-base-100 border-base-300 border">
            <div className="card-body gap-3 p-4">
                <h2 id={id} className="card-title text-base capitalize">
                    {Icon && <Icon className="text-primary size-4" aria-hidden="true" />}
                    {title}
                </h2>
                {children}
            </div>
        </section>
    );
}

/** Tabs for this case. Awards & orders only when the case has any. Actions live beside the tabs, not in one. */
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
    ];
}

function tabFromUrl(ids: TabId[]): TabId {
    // ?tab=actions (older links and redirects) opens the overview; the actions column is always shown.
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
    letter,
    letter_dates,
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

    // An old ?tab=actions link: bring the actions column into view.
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('tab') === 'actions') {
            document.getElementById('case-actions')?.scrollIntoView({ block: 'start' });
        }
    }, []);

    const facts: [string, string, boolean?][] = [
        ['Organisation', detail.organisation ?? '—'],
        ['Procurement type', detail.procurement_type ?? '—'],
        ['Current holder', detail.holder_label ?? '—'],
        ['Submitted', formatDate(detail.submitted_at)],
        ['Hardcopy', hardcopyText(hardcopy)],
        ...(detail.file_no ? ([['File number', detail.file_no, true]] as [string, string, boolean][]) : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={detail.reference_no ?? 'Case'} />

            <header className="bg-base-100 border-base-300 border-b px-4 py-4 lg:px-6">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    {/* The reference is the page title here: mono, but kept at heading size. */}
                    <h1 className="font-mono text-2xl font-semibold">{detail.reference_no ?? 'Case'}</h1>
                    <StatusPill label={detail.status_label} tone={detail.status_tone} />
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

                    <CaseTabs tabs={tabs} active={active} onSelect={show} />

                    <div
                        role="tabpanel"
                        id={`panel-${active}`}
                        aria-labelledby={`tab-${active}`}
                        className="flex min-w-0 flex-col gap-4 motion-safe:transition-opacity motion-safe:duration-300 motion-safe:starting:opacity-0"
                        key={active}
                    >
                        {active === 'overview' && (
                            <>
                                {returned && (
                                    // "Returned to CMO by <source> (<kind>): <reason>, <name>, <date>". The server
                                    // gives the source (CaseStatus::returnedBySource) and, for a PS return, the kind.
                                    <div role="status" className="alert alert-soft alert-warning items-start">
                                        <Undo2 className="size-5" aria-hidden="true" />
                                        <div className="text-sm">
                                            <h3 className="font-semibold capitalize">
                                                Returned to CMO{returned.source ? ` by ${returned.source}` : ''}
                                                {returned.kind ? ` (${returned.kind})` : ''}
                                            </h3>
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
                                            <h3 className="font-semibold capitalize">Non-objection granted</h3>
                                            <p>{[granted.by, granted.at ? formatDateTime(granted.at) : null].filter((part) => part).join(', ')}</p>
                                            {granted.note && <p className="whitespace-pre-line">Note: {granted.note}</p>}
                                        </div>
                                    </div>
                                )}

                                {letter && (
                                    // Slice 10f: staff only. Every part comes from the server.
                                    <div role="status" className="alert alert-soft alert-info items-start">
                                        <Mail className="size-5" aria-hidden="true" />
                                        <div className="text-sm">
                                            <h3 className="font-semibold capitalize">Letter issued</h3>
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

                                <AssignmentsCard assignments={assignments} />
                            </>
                        )}

                        {(active === 'details' || active === 'stock' || active === 'tender' || active === 'orders' || active === 'quotations') && (
                            <RequisitionView content={detail} isPharm={isPharm} tab={active} />
                        )}

                        {active === 'documents' && (
                            <>
                                <RequisitionView content={detail} isPharm={isPharm} tab="documents" />

                                <Card title="Documents" id="documents-heading" icon={FileText}>
                                    {documents.length === 0 ? (
                                        <p className="text-base-content/60 text-sm">No documents available.</p>
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
                                                            <td className="whitespace-nowrap">{formatDateTime(doc.uploaded_at)}</td>
                                                            <td>{doc.uploaded_by ?? '—'}</td>
                                                            <td>
                                                                <span
                                                                    className={
                                                                        doc.visibility === 'Internal'
                                                                            ? 'badge badge-soft badge-neutral badge-sm'
                                                                            : 'badge badge-outline badge-sm'
                                                                    }
                                                                >
                                                                    {doc.visibility}
                                                                </span>
                                                            </td>
                                                            <td className="text-right whitespace-nowrap">
                                                                {can.download_documents && doc.can_download && (
                                                                    <a
                                                                        href={route('cases.documents.download', [detail.id, doc.id])}
                                                                        className="btn btn-ghost btn-xs capitalize"
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
                                <Card title="Timeline" id="timeline-heading">
                                    <CaseTimeline entries={timeline} />
                                </Card>
                                <AssignmentsCard assignments={assignments} />
                            </div>
                        )}
                    </div>

                    <CaseTabNav tabs={tabs} active={active} onSelect={show} finishHref={route('cases.index')} />
                </div>

                <aside id="case-actions" aria-label="Case actions" className="min-w-0 scroll-mt-4 xl:sticky xl:top-4">
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
                        letterToday={letter_dates?.today ?? null}
                    />
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
                />
            )}
        </AppLayout>
    );
}

function AssignmentsCard({ assignments }: { assignments: AssignmentEntry[] }) {
    return (
        <Card title="Assignments" id="assignments-heading" icon={Users}>
            {assignments.length === 0 ? (
                <p className="text-base-content/60 text-sm">No assignments.</p>
            ) : (
                <ul className="list -mx-4">
                    {assignments.map((a) => (
                        <li key={a.id} className="list-row items-center py-2">
                            <div className="list-col-grow min-w-0 text-sm">
                                <p className="font-medium">{a.user ?? `Any ${a.role_label} officer`}</p>
                                <p className="text-base-content/60">
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
    );
}
