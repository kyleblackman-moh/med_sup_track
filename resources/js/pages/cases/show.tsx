import { CaseActions, formatDay, hardcopyText } from '@/components/cases/case-actions';
import { CaseTimeline } from '@/components/cases/case-timeline';
import { CaseEndedAlert, EndCasePanel } from '@/components/cases/end-case';
import { ExpiryFlag } from '@/components/cases/expiry-flag';
import { FileJourney } from '@/components/cases/file-journey';
import { FundingFigures, type FundingSummary } from '@/components/cases/funding-figures';
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
import { caseTitle } from '@/lib/case-title';
import { type BreadcrumbItem } from '@/types';
import {
    type AssignmentEntry,
    type CaseEnded,
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
import { type CaseTracking } from '@/types/tracking';
import { Head, Link } from '@inertiajs/react';
import { CircleCheck, FileText, History, Info, Mail, Printer, Route, Signpost, Undo2, Users, X } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

interface Props {
    /**
     * WORKFLOW-2 (R3): an officer named on a forward who is not on the case.
     * The server sends the header and their queries only (every other prop is
     * empty); the page shows just the header and the Queries tab.
     */
    query_only: boolean;
    case: StaffCaseDetail;
    /** NEXT-STEP-1: the Next Step card (staff only). */
    next_step: NextStepView;
    hardcopy: HardcopyRecord | null;
    /** Null in query-only mode. */
    hardcopy_dates: { today: string; earliest: string | null } | null;
    documents: StaffDocument[];
    timeline: TimelineEntry[];
    /** Kyle, 2026-11-24: the File Tracking tab (where the file is, days per unit, every stage); null in query-only mode. */
    tracking: CaseTracking | null;
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
    /** TERMINAL-1: how a withdrawn or rejected case ended; null otherwise. */
    ended: CaseEnded | null;
    /** V2-3: the one RequestSummary for a request-form case; null for the old Pharm and Non-Pharm types. */
    summary: RequestSummaryView | null;
    /** Kyle, 2026-11-26: the funding figures, for a funding reviewer only (null otherwise). */
    funding_summary?: FundingSummary | null;
    /** OVERNIGHT-2 (D2): the queries this user may see, and the routes they may raise one on. */
    queries: StaffQueries;
    can: StaffCaseAbilities;
}

type TabId = 'tracking' | RequisitionTab | 'queries' | 'history';

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
 * Tabs for this case. Kyle, 2026-11-26: no Overview tab (its notices sit
 * above the tabs, the Gate under Where Is The File); File Tracking is now
 * "Where Is The File", after Queries. V2-3: a request-form case shows its request in one
 * "Request Details" tab (the RequestSummary); the old types keep their tabs,
 * with Awards & orders only when the case has any. The actions are not a tab:
 * they sit in the action column beside the tabs.
 */
function buildTabs(detail: StaffCaseDetail, hasSummary: boolean, queryOnly: boolean): TabItem<TabId>[] {
    if (queryOnly) {
        return [{ id: 'queries', label: 'Queries' }];
    }

    if (hasSummary) {
        return [
            { id: 'details', label: 'Request Details' },
            { id: 'documents', label: 'Documents' },
            { id: 'queries', label: 'Queries' },
            { id: 'tracking', label: 'Where Is The File' },
            { id: 'history', label: 'History' },
        ];
    }

    const hasOrders = detail.tender_awards.length > 0 || detail.purchase_orders.length > 0;

    return [
        { id: 'details', label: 'Request Details' },
        { id: 'stock', label: 'Stock' },
        { id: 'tender', label: 'Tender & RFQ' },
        ...(hasOrders ? [{ id: 'orders' as const, label: 'Awards & Orders' }] : []),
        { id: 'quotations', label: 'Quotations' },
        { id: 'documents', label: 'Documents' },
        { id: 'queries', label: 'Queries' },
        { id: 'tracking', label: 'Where Is The File' },
        { id: 'history', label: 'History' },
    ];
}

/** The Next Step drawer's checkbox and panel ids. */
const NEXT_STEP_DRAWER = 'next-step-drawer';
const NEXT_STEP_PANEL = 'next-step-panel';

/** Older links into the action column (?tab=actions, #case-actions) and #next-step open the Next Step panel. */
function wantsNextStep(): boolean {
    const hash = window.location.hash;

    const params = new URLSearchParams(window.location.search);

    // Kyle, 2026-11-26: ?next=send_to_legal (after Prepare File) opens the panel on that step.
    return params.get('tab') === 'actions' || params.has('next') || hash === '#next-step' || hash === '#case-actions';
}

function tabFromUrl(ids: TabId[]): TabId {
    // ?tab=actions (older links) opens the first tab, with the Next Step panel open; ?tab=overview (gone) too.
    const wanted = new URLSearchParams(window.location.search).get('tab');

    return ids.find((id) => id === wanted) ?? ids[0] ?? 'details';
}

/**
 * Staff view of one case (slice 9a): a compact facts header, then the record
 * in tabs across the full width. The open tab is kept in ?tab=, so an
 * action's redirect back lands on the same tab.
 * NEXT-DRAWER-1: the officer's next step lives in a right-side panel (daisyUI
 * drawer drawer-end) opened from the "Next Step" button in the header, which
 * wears the page's one aura; once the header scrolls away the button floats at
 * the bottom right. The panel holds the Next Step card, then the less common
 * actions. No numbered tabs and no Back / Next footer (design.md does not
 * define them).
 */
export default function CaseShow({
    query_only: queryOnly = false,
    case: detail,
    next_step,
    hardcopy,
    documents,
    timeline,
    tracking,
    assignments,
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
    ended,
    summary,
    funding_summary: fundingSummary = null,
    queries,
    can,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Application Requests', href: route('cases.index') },
        { title: detail.reference_no ?? 'Case', href: route('cases.show', detail.id) },
    ];

    const din = typeof detail.requisition?.din === 'string' ? detail.requisition.din : null;
    const itemName = typeof detail.requisition?.item_description === 'string' ? detail.requisition.item_description : detail.item_description;
    const title = queryOnly ? (detail.reference_no ?? 'Case') : caseTitle(itemName, din, detail.reference_no);
    const tabs = buildTabs(detail, summary !== null, queryOnly);
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

    // NEXT-DRAWER-1: the Next Step panel. An older ?tab=actions or #case-actions link, or #next-step, opens it.
    const [panelOpen, setPanelOpen] = useState(false);
    const [floating, setFloating] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const closeRef = useRef<HTMLButtonElement>(null);
    const anchorRef = useRef<HTMLDivElement>(null);
    const wasOpen = useRef(false);
    const hasNextStep = Boolean(
        !queryOnly && next_step && (next_step.stage || next_step.instruction || next_step.actions.length > 0 || next_step.checklist.length > 0),
    );

    useEffect(() => {
        if (hasNextStep && wantsNextStep()) {
            setPanelOpen(true);
        }
    }, [hasNextStep]);

    // Focus the close button on open (after the drawer's short reveal delay) and give focus back to the button on close.
    useEffect(() => {
        if (panelOpen) {
            wasOpen.current = true;
            const timer = window.setTimeout(() => closeRef.current?.focus(), 150);

            return () => window.clearTimeout(timer);
        }
        if (wasOpen.current) {
            wasOpen.current = false;
            triggerRef.current?.focus();
        }
    }, [panelOpen]);

    // Escape closes the panel, unless a dialog opened from it is on top (the dialog handles its own Escape).
    useEffect(() => {
        if (!panelOpen) {
            return;
        }
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !document.querySelector('dialog[open]')) {
                setPanelOpen(false);
            }
        };
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [panelOpen]);

    // The button floats at the bottom right once its place in the header has scrolled out of view.
    useEffect(() => {
        const anchor = anchorRef.current;
        if (!anchor || typeof IntersectionObserver === 'undefined') {
            return;
        }
        const observer = new IntersectionObserver(([entry]) => setFloating(!entry.isIntersecting));
        observer.observe(anchor);

        return () => observer.disconnect();
    }, [hasNextStep]);

    // Query-only: the header facts the server sends (no hardcopy, which it does not).
    const facts: [string, string, boolean?][] = [
        ['Organisation', detail.organisation ?? '—'],
        ['Procurement Type', detail.procurement_type ?? '—'],
        ['Current Holder', detail.holder_label ?? '—'],
        ['Submitted', formatDate(detail.submitted_at)],
        ...(queryOnly ? [] : ([['Hardcopy', hardcopyText(hardcopy)]] as [string, string][])),
        ...(detail.file_no ? ([['File Number', detail.file_no, true]] as [string, string, boolean][]) : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="drawer drawer-end min-w-0 flex-1">
                <input
                    id={NEXT_STEP_DRAWER}
                    type="checkbox"
                    className="drawer-toggle"
                    aria-label="Show your next step"
                    tabIndex={-1}
                    checked={panelOpen}
                    onChange={(e) => setPanelOpen(e.target.checked)}
                />

                <div className="drawer-content flex min-w-0 flex-col" inert={panelOpen}>
                    <header className="bg-base-100 border-base-300 border-b px-4 py-4 lg:px-6">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                            {/* Kyle, 2026-11-24: the drug and DIN are the page title, in the reference's mono; the reference sits under it. */}
                            <h1 className="font-mono text-xl font-semibold">{title}</h1>
                            <StatusPill label={detail.status_label} tone={detail.status_tone} className="capitalize" />
                            {hasNextStep && (
                                // The anchor keeps the button's place; the button itself floats once the anchor scrolls away.
                                <div ref={anchorRef} className="ms-auto flex min-h-9 items-center">
                                    <div
                                        className={
                                            floating
                                                ? 'aura aura-glow text-primary fixed end-6 bottom-6 z-30 motion-reduce:before:animate-none motion-reduce:after:animate-none print:hidden'
                                                : 'aura aura-glow text-primary motion-reduce:before:animate-none motion-reduce:after:animate-none print:hidden'
                                        }
                                    >
                                        <button
                                            ref={triggerRef}
                                            type="button"
                                            className="btn btn-primary btn-sm capitalize active:scale-[0.97]"
                                            aria-haspopup="dialog"
                                            aria-expanded={panelOpen}
                                            aria-controls={NEXT_STEP_PANEL}
                                            onClick={() => setPanelOpen(true)}
                                        >
                                            <Signpost className="size-4" aria-hidden="true" />
                                            Next Step
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                        {title !== detail.reference_no && <p className="text-base-content/70 mt-1 text-sm">{detail.reference_no}</p>}
                        <ExpiryFlag flag={detail.expiry_flag} variant="alert" className="mt-3" />
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

                    <div className="min-w-0 flex-1 px-4 py-4 lg:px-6">
                        <div className="flex min-w-0 flex-col gap-4">
                            {/* The printable request form: request-form types only (summary is null otherwise, as is the print route). */}
                            {summary && (
                                <div className="flex flex-wrap justify-end gap-2 print:hidden">
                                    <Link href={route('cases.print', detail.id)} className="btn btn-sm capitalize">
                                        <Printer className="size-4" aria-hidden="true" />
                                        Print
                                    </Link>
                                </div>
                            )}

                            <FlashMessages />

                            {queryOnly && (
                                <div role="status" className="alert alert-soft alert-info">
                                    <Info className="size-5" aria-hidden="true" />
                                    <span className="text-sm">You have access to this case's queries only.</span>
                                </div>
                            )}

                            {/* Kyle, 2026-11-26: the Overview tab left; its notices sit above the tabs. */}
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

                            {ended && <CaseEndedAlert ended={ended} />}

                            <Tabs tabs={tabs} active={active} onSelect={show} label="Case sections" className="border-base-300 capitalize" />

                            <div
                                role="tabpanel"
                                id={`panel-${active}`}
                                aria-labelledby={`tab-${active}`}
                                key={active}
                                className="flex min-w-0 flex-col gap-4 motion-safe:transition-opacity motion-safe:duration-300 motion-safe:starting:opacity-0"
                            >
                                {active === 'details' && fundingSummary && <FundingFigures summary={fundingSummary} />}

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
                                                                    <td className="whitespace-nowrap tabular-nums">
                                                                        {formatDateTime(doc.uploaded_at)}
                                                                    </td>
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

                                {/* Kyle, 2026-11-26: no Gate card; the Next Step checklist says what is outstanding. */}
                                {active === 'tracking' && tracking && (
                                    <Card title="Where Is The File" id="tracking-heading" icon={Route}>
                                        <FileJourney tracking={tracking} />
                                    </Card>
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
                    </div>
                </div>

                {!queryOnly && (
                    <div className="drawer-side z-50 print:hidden">
                        <label htmlFor={NEXT_STEP_DRAWER} aria-label="Close your next step" className="drawer-overlay" />
                        <section
                            id={NEXT_STEP_PANEL}
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="next-step-panel-title"
                            className="bg-base-100 text-base-content flex min-h-full w-full flex-col sm:w-md xl:w-lg"
                        >
                            <header className="bg-base-100 border-base-300 sticky top-0 z-10 flex items-center justify-between gap-2 border-b px-4 py-3">
                                <h2 id="next-step-panel-title" className="flex items-center gap-2 text-base font-semibold capitalize">
                                    <Signpost className="size-4 shrink-0" aria-hidden="true" />
                                    Your Next Step
                                </h2>
                                <button
                                    ref={closeRef}
                                    type="button"
                                    className="btn btn-ghost btn-sm btn-square"
                                    aria-label="Close your next step"
                                    onClick={() => setPanelOpen(false)}
                                >
                                    <X className="size-4" aria-hidden="true" />
                                </button>
                            </header>

                            <div id="case-actions" className="flex min-w-0 flex-col gap-4 p-4">
                                <NextStepCard
                                    caseId={detail.id}
                                    reference={detail.reference_no}
                                    organisation={detail.organisation}
                                    next={next_step}
                                    reviewers={reviewers}
                                    fileNoDefault={file_no_default}
                                    legalOfficers={legal_officers}
                                    legalForm={legal_form}
                                    psForm={ps_form}
                                    reviewForms={review_forms}
                                    letterToday={letter_dates?.today ?? null}
                                    onRoute={can.route_onward && route_panel ? openRoute : null}
                                    onOpenQueries={() => {
                                        setPanelOpen(false);
                                        show('queries');
                                    }}
                                />

                                <CaseActions caseId={detail.id} hardcopy={hardcopy} can={can} psOutcome={ps_outcome} />

                                {/* Kyle, 2026-11-26: no withdraw for staff; a final rejection on the PS's instruction stays. */}
                                <EndCasePanel
                                    caseId={detail.id}
                                    reference={detail.reference_no}
                                    organisation={detail.organisation}
                                    canReject={can.reject_final}
                                />
                            </div>
                        </section>
                    </div>
                )}
            </div>

            {!queryOnly && can.route_onward && route_panel && (
                <SendOnwardPanel
                    open={routeOpen}
                    onOpenChange={setRouteOpen}
                    caseId={detail.id}
                    reference={detail.reference_no}
                    procurementType={detail.procurement_type}
                    itemDescription={detail.item_description}
                    statusLabel={detail.status_label}
                    returned={returned}
                    panel={route_panel}
                    initialDestination={routeTo}
                    key={routeTo ?? 'suggested'}
                />
            )}
        </AppLayout>
    );
}
