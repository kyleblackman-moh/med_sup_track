// Shapes of the staff case props (app/Http/Resources/Cases). Display only: the
// server decides what each user may see.

import { type RequisitionContent } from '@/components/requests/requisition-view';
import { type OptionList } from './requests';

export interface StaffCaseSummary {
    id: string;
    reference_no: string | null;
    organisation: string | null;
    procurement_type: string | null;
    item_description: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    holder_label: string | null;
    submitted_at: string | null;
    days_since_submitted: number | null;
}

/**
 * Badge tone decided by the server (CaseStatus::tone / vendorTone). The colours
 * live in the status palette in resources/css/app.css; TONE_CLASSES maps each one.
 */
export type StatusTone =
    | 'neutral'
    | 'info'
    | 'success'
    | 'warning'
    | 'danger'
    | 'indigo'
    | 'violet'
    | 'teal'
    | 'cyan'
    | 'purple'
    | 'fuchsia'
    | 'lime'
    | 'slate';

/** A row of the staff case list. */
export interface StaffCaseListRow extends StaffCaseSummary {
    /** Not sent yet: queries are not built. */
    has_open_query?: boolean;
}

export interface CaseFilters {
    status: string;
    type: string;
    org: string;
    ref: string;
    /** 'funding': in review, funding not yet confirmed (the CAB view). */
    awaiting: '' | 'funding';
    /** 'days' is days since submission (submitted_at, reversed) on the server. */
    sort: 'submitted_at' | 'reference_no' | 'status' | 'days';
    dir: 'asc' | 'desc';
}

/** UI hints for the case list; the server authorises every request. */
export interface CaseListCan {
    create: boolean;
    see_organisation: boolean;
}

export interface CaseListPage {
    data: StaffCaseListRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface CaseListOptions {
    statuses: OptionList;
    types: OptionList;
    organisations: OptionList;
}

export type StaffCaseDetail = StaffCaseSummary & RequisitionContent & { category: 'PHARM' | 'NON_PHARM' | null; file_no: string | null };

/** Slice 10c: the Legal decision form, present only for an assigned Legal officer at With Legal. */
export interface LegalForm {
    decisions: { value: 'confirmed' | 'return_to_cmo'; label: string; needs_reason: boolean; next: string }[];
}

/** Slice 10c: shown to staff while the case is Returned to CMO. */
export interface ReturnedBanner {
    /** "Legal", "PS office", or null when no source is named. */
    source: string | null;
    /** Slice 10d: "legal-related query" / "technical query" on a PS return; null otherwise. */
    kind: string | null;
    reason: string | null;
    by: string | null;
    at: string | null;
}

/** Slice 10d: the PS decision form, present only for a user who may decide now (With PS). */
export interface PsForm {
    decisions: { value: 'grant' | 'return_to_cmo'; label: string; needs_reason: boolean; next: string }[];
    kinds: { value: 'legal' | 'technical'; label: string }[];
}

/** Slice 10d: the latest PS decision as a plain statement (staff only). */
export interface PsOutcome {
    outcome: string;
    by: string | null;
    at: string | null;
}

/** Slice 10d: "Non-objection granted by <name>, <date>" (staff only). */
export interface GrantedLine {
    by: string | null;
    at: string | null;
    note: string | null;
}

export interface HardcopyRecord {
    received_on: string;
    recorded_by: string | null;
    recorded_at: string | null;
}

export interface StaffCaseAbilities {
    requests: boolean;
    cases: boolean;
    download_documents: boolean;
    intake: boolean;
    record_hardcopy: boolean;
    forward_for_review: boolean;
    record_review: boolean;
    mark_ready_for_file: boolean;
    prepare_file: boolean;
    send_to_legal: boolean;
    record_legal_decision: boolean;
    record_ps_decision: boolean;
    route_onward: boolean;
    issue_letter: boolean;
    close_case: boolean;
    /** FR-09: withdraw (T15) and final rejection on the PS's instruction (T16). */
    withdraw: boolean;
    reject_final: boolean;
    /** FR-03: the printable hardcopy cover sheet. */
    cover_sheet: boolean;
}

/** FR-09 (staff): how a withdrawn or rejected case ended. */
export interface CaseOutcome {
    kind: 'withdrawn' | 'rejected';
    label: string;
    by: string | null;
    by_vendor: boolean;
    at: string | null;
    reason: string | null;
    vendor_message: string | null;
    ps_instructed: boolean;
}

export interface ReviewerOption {
    id: number;
    name: string;
}

/** Present only for a user who may send the case for review. */
export interface EligibleReviewers {
    technocrats: ReviewerOption[];
    cab_officers: ReviewerOption[];
}

export interface GateReviewer {
    name: string | null;
    /** Latest outcome label, or null while pending. */
    outcome: string | null;
    recorded_at: string | null;
    /** Only for a negative outcome (staff only). */
    reason: string | null;
}

export interface GateRow {
    key: 'technical' | 'funding' | 'hardcopy';
    label: string;
    done: boolean;
    state: string;
    reviewers: GateReviewer[];
    received_on: string | null;
    recorded_by: string | null;
    recorded_at: string | null;
}

/** Gate tracker (slice 10b). All states come from the server. */
export interface GateState {
    rows: GateRow[];
    open: boolean;
    missing: string[];
}

export interface ReviewForm {
    track: 'technical' | 'funding';
    label: string;
    outcomes: { value: string; label: string; positive: boolean }[];
    current: { outcome: string; label: string; reason: string | null; recorded_at: string | null } | null;
}

export interface StaffDocument {
    id: string;
    display_name: string;
    purpose_label: string;
    version: number;
    uploaded_at: string | null;
    uploaded_by: string | null;
    visibility: 'Internal' | 'External';
    can_download: boolean;
}

export interface TimelineEntry {
    id: number;
    from_label: string | null;
    to_label: string;
    actor: string | null;
    acting_for: string | null;
    reason: string | null;
    at: string | null;
}

export interface AssignmentEntry {
    id: number;
    role_label: string;
    user: string | null;
    state: 'Active' | 'Completed' | 'Cancelled';
    assigned_at: string | null;
    completed_at: string | null;
}

/** Slice 10e: one destination in the Send onward panel. Everything comes from the server. */
export interface RouteDestination {
    value: 'technocrats' | 'legal' | 'ps';
    label: string;
    consequence: string;
    /** The confirm button, naming the destination. */
    button: string;
    /** False when the destination cannot be used now (for example no Legal officer assigned). */
    available: boolean;
}

/** Slice 10e: the Send onward side panel, present only for a user who may route the case now. */
export interface RoutePanel {
    destinations: RouteDestination[];
    suggested: RouteDestination['value'] | null;
    suggested_line: string | null;
    technocrats: ReviewerOption[];
    reviewers_min: number;
    reviewers_max: number;
    note_min: number;
    note_max: number;
    /** Last 8 history rows, newest first. */
    timeline: TimelineEntry[];
}

/** Slice 10f: the recorded letter (staff only). Dates are Y-m-d; recorded_at is ISO. */
export interface LetterRecord {
    date: string;
    reference: string | null;
    emailed_on: string | null;
    hardcopy_sent_on: string | null;
    recorded_by: string | null;
    recorded_at: string | null;
}

/** NEXT-STEP-1: the server's confirmation text for one action (title, what happens, the confirm button). */
export interface ActionConfirm {
    title: string;
    body: string;
    button: string;
}

/** NEXT-STEP-1: one action this user may take now. Every string comes from the server. */
export interface NextStepAction {
    /** Which form the card opens; never shown. */
    key: string;
    label: string;
    /** Where the file goes; null when it stays with the current desk. */
    destination: string | null;
    enabled: boolean;
    disabled_reason: string | null;
    /** For a returned case: the destination value the Send Onward panel starts with. */
    route: RouteDestination['value'] | null;
    primary: boolean;
    confirm: ActionConfirm;
}

/** NEXT-STEP-1: one row of the blocker checklist. */
export interface NextStepItem {
    key: string;
    label: string;
    done: boolean;
    state: string;
    holder: string;
    people: string[];
    /** The inline Date Received form, only for a user who may record the hardcopy now. */
    hardcopy_form: { today: string; earliest: string | null } | null;
}

/** NEXT-STEP-1: the Next Step card at the top of the staff case page. */
export interface NextStepView {
    stage: string;
    holder: string | null;
    assigned: string[];
    since: string | null;
    since_label: string | null;
    instruction: string;
    checklist: NextStepItem[];
    actions: NextStepAction[];
    /** OVERNIGHT-2 (D4): open queries; action is the button label (forward or answer), else waiting_on says who. */
    queries: { id: string; title: string; status_label: string; waiting_on: string | null; action: string | null }[];
}

/** OVERNIGHT-2 (D2): one query message as staff see it (all text from the server). */
export interface StaffQueryMessage {
    id: string;
    author: string | null;
    from_label: string;
    to_label: string;
    body: string;
    sent_on: string | null;
    held: boolean;
    state: string;
    external: boolean;
    /** WORKFLOW-2 (Step 4): the author's office, Internal or External, and a plain time. */
    author_office: string;
    visibility_label: 'Internal' | 'External';
    sent_at: string | null;
    /** Only for the CMO's desk while the message is held. */
    forward: { label: string; title: string; body: string } | null;
}

export interface StaffQuery {
    id: string;
    title: string;
    route_label: string;
    status_label: string;
    status_tone: StatusTone;
    raised_by: string | null;
    raised_on: string | null;
    waiting_on: string | null;
    read_only: boolean;
    can_reply: boolean;
    reply_label: string;
    can_close: boolean;
    /** WORKFLOW-2 (Step 4): the thread stated plainly, worded on the server. */
    thread: StaffQueryThread;
    messages: StaffQueryMessage[];
}

export interface StaffQueryThread {
    from: string;
    to: string;
    route: string;
    via_cmo: boolean;
    /** Who holds the query now; null once closed. */
    holder: string | null;
    next_action: string;
}

export interface StaffQueries {
    queries: StaffQuery[];
    /** The routes this user may raise a query on; null when none. */
    raise: { routes: { id: number; label: string }[] } | null;
}
