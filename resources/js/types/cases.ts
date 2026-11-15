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
    holder_label: string | null;
    submitted_at: string | null;
    days_since_submitted: number | null;
}

export interface CaseFilters {
    status: string;
    type: string;
    org: string;
    ref: string;
    sort: 'submitted_at' | 'reference_no' | 'status';
    dir: 'asc' | 'desc';
}

export interface CaseListPage {
    data: StaffCaseSummary[];
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
    decisions: { value: 'confirmed' | 'return_to_cmo'; label: string; needs_reason: boolean }[];
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
    decisions: { value: 'grant' | 'return_to_cmo'; label: string; needs_reason: boolean }[];
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
