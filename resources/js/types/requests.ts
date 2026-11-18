// Shapes of the vendor request props (app/Http/Resources/Requests). Display only:
// the server decides what is allowed and validates every save.

import { type StatusTone } from './cases';

export type Section = 'quotations' | 'tender_awards' | 'purchase_orders' | 'stock_expiries';

export type OptionList = { value: string; label: string }[];

export interface ProcurementTypeSummary {
    code: string;
    name: string;
    category: 'PHARM' | 'NON_PHARM' | 'AD_HOC' | null; // null for the tender types and an unselected draft
    /** V2-1b: false until the vendor chooses a type ("Not selected"). */
    selected: boolean;
}

/** V2-1b: a type a request-form draft may change to (RequestDetailResource type_options). */
export interface RequestTypeOption {
    code: string;
    label: string;
    /** "Ad Hoc" for the three Ad Hoc types, else null. */
    group: string | null;
    /** V2-2c: a lucide icon name for the type card, or null. */
    icon: string | null;
}

export interface RequestSummary {
    id: string;
    reference_no: string | null;
    status_label: string;
    status_group: string;
    /** UI-4: the pill's tone, decided by the server (CaseStatus::vendorTone). */
    status_tone: StatusTone;
    procurement_type: ProcurementTypeSummary;
    item_description: string | null;
    vendor_reference: string | null;
    updated_at: string | null;
    can: { edit: boolean; delete: boolean };
}

export type FieldValue = string | number | boolean | null;

export type Row = Record<string, FieldValue> & { id?: number; derived?: Record<string, string | null> };

/** V2-4 Stage B: the case hero, built by the server (CaseHero::for). Display only. */
export interface CaseHeroView {
    /** The reference number; null on a draft (the hero shows "Draft"). */
    reference: string | null;
    /** The item description, or null. */
    title: string | null;
    /** The type's label and lucide icon name; null while no type is chosen. */
    type: { label: string; icon: string } | null;
    /** Shown in place of the type while it is null ("Not chosen yet"). */
    type_unset_label: string;
    status_label: string;
    status_tone: StatusTone;
    /** "Last saved" on a draft, "Submitted on" once submitted; the value is formatted by the server. */
    date: { label: string; value: string };
}

export interface RequestDetail extends RequestSummary {
    /** V2-4 Stage B: the case hero. */
    hero: CaseHeroView;
    requisition: Record<string, FieldValue> & { derived: { stock_on_hand: string | null; coverage_months: string | null } };
    quotations: Row[];
    tender_awards: Row[];
    purchase_orders: Row[];
    stock_expiries: Row[];
    totals: Record<Section, Record<string, string | null>>;
    /** Calendar date (Y-m-d) the Ministry received the hardcopy, or null. */
    hardcopy_received_on: string | null;
    /** Slice 10f: once the letter is issued, its date (Y-m-d) and reference; null before. */
    letter: { date: string; reference: string | null } | null;
    /** V2-1b: request-form drafts only. The current type code and the types it may change to. */
    request_type?: string;
    type_options?: RequestTypeOption[];
}

export interface ReadinessItem {
    key: string;
    message: string;
}

export interface Readiness {
    errors: ReadinessItem[];
    warnings: ReadinessItem[];
    pending: string[];
    is_ready: boolean;
}

export interface FormConfig {
    procurement_type: ProcurementTypeSummary;
    is_pharm: boolean;
    allowed_sections: Section[];
    required_sections: Section[];
    row_caps: Partial<Record<Section, number>>;
    options: Record<'need_classification' | 'tender_outcome' | 'rfq_audience' | 'previous_price_maintained', OptionList>;
}

export interface RequestDocument {
    id: string;
    display_name: string;
    size_bytes: number;
    uploaded_at: string | null;
    version: number;
    status_label: 'Scanning' | 'Ready' | 'Rejected';
    can_download: boolean;
    can_replace: boolean;
    can_withdraw: boolean;
}

export interface DocumentGroup {
    purpose: string;
    label: string;
    hint?: string | null;
    is_required: boolean;
    /** Documents outside the type's categories ("Earlier uploads"): readable, never editable. */
    read_only?: boolean;
    documents: RequestDocument[];
}

export interface UploadRules {
    extensions: string[];
    max_size_kb: number;
}

export interface Flash {
    success: string | null;
    error: string | null;
    info: string | null;
}
