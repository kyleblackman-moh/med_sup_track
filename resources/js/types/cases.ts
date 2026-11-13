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

export type StaffCaseDetail = StaffCaseSummary & RequisitionContent & { category: 'PHARM' | 'NON_PHARM' | null };

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

export interface ReviewProgressRow {
    key: 'technical' | 'funding' | 'hardcopy';
    label: string;
    state: string;
    reviewers: string[];
    received_on: string | null;
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
