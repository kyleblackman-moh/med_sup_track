// Props of the AD_HOC single-page form (slice F2). Display only: the server
// validates every save, computes every figure and decides what is complete.

import { type DocumentGroup, type FieldValue, type OptionList, type RequestDetail, type UploadRules } from './requests';
import { type RequestSummaryView } from './summary';

export type ChildSection = 'tender_awards' | 'purchase_orders' | 'stock_expiries' | 'quotations';

export interface SectionIssue {
    /** Readiness key, for example "tender_awards.0.unit_cost". */
    key: string;
    /** The key without the row index, for example "tender_awards.unit_cost". */
    field: string;
    /** The saved row the issue is about, or null. */
    row_id: number | null;
    message: string;
}

/** Server-computed AD_HOC figures (RequestDetailResource "figures"). */
export interface AdHocFigures {
    suppliers_awarded: number;
    quotations_received: number;
    quotations_considered: number;
    recommended_unit_price: string | null;
    total_cost: string | null;
    price_change_vs_tender_percent: string | null;
    price_change_vs_previous_percent: string | null;
}

export type AdHocDetail = Omit<RequestDetail, 'requisition'> & {
    requisition: RequestDetail['requisition'];
    figures: AdHocFigures | null;
    /** V2-5: "Was a request previously made for this drug in this current tender cycle?"; null = not answered. */
    previous_request_in_cycle: boolean | null;
    /** V2-1: the Tender Variation entry; null when none is saved. */
    variation: Record<string, FieldValue> | null;
};

export interface AdHocConfig {
    /** V2-3: no tender outcome; awards and orders never depend on it. */
    options: { rfq_audience: OptionList };
    row_caps: Record<ChildSection, number>;
    max_lengths: Record<string, number>;
    uploads: UploadRules;
}

/** The "saved" flash after an autosave: new row ids by client key, and the saved rows' ids. */
export interface SavedFlash {
    id_map: Record<string, number>;
    row_ids: Partial<Record<ChildSection, number[]>>;
    saved_at: string;
}

/** V2-1 "request_sections": the sections the draft's type shows, in order (RequestSections). */
export interface RequestSectionStatus {
    key: FormSectionKey;
    label: string;
    optional: boolean;
    /** V2-1c: "Choose a request type" while the section waits for the type, else null. */
    note: string | null;
    anchor: string;
    complete: boolean;
    issues: SectionIssue[];
}

export interface AdHocPageProps {
    case: AdHocDetail;
    request_sections: RequestSectionStatus[];
    /** V2-2: numbered sections, display strings and the expiry warning. */
    form: RequestFormView;
    /** V2-3: the read-only summary; only after Review and submit asks for it (an optional prop). */
    summary?: RequestSummaryView | null;
    config: AdHocConfig;
    documents: DocumentGroup[];
    saved: SavedFlash | null;
    hardcopy_notice: string;
    can: { edit: boolean; submit: boolean; requests: boolean };
    errors: Record<string, string>;
}

/** V2-2: the request form's sections, in form order (RequestFormView). */
export type FormSectionKey =
    | 'request_type'
    | 'item_tender'
    | 'variation_details'
    | 'awarded_supplier'
    | 'existing_arrangement'
    | 'stock'
    | 'quotation_comparison'
    | 'documents';

export type FormSectionState = 'complete' | 'incomplete' | 'optional' | 'blocked';

export interface FormSectionView {
    key: FormSectionKey;
    label: string;
    /** Position among the visible sections; null when hidden. */
    number: number | null;
    visible: boolean;
    state: FormSectionState;
    /** Why the section is blocked ("Choose a request type"), else null. */
    note: string | null;
    /** Field key => must be filled in to submit. True: the control carries aria-required (V2-4b: no "(optional)" label). */
    required: Record<string, boolean>;
}

/** V2-2: server-formatted values (RequestFormView "display"). */
export interface FormDisplay {
    stock_total: string;
    days_on_hand: string;
    awards_total: string;
    total_cost: string;
    /** V2-3: "12.50%", or a dash. */
    price_change_vs_tender: string;
    price_change_vs_previous: string;
    rows: {
        tender_awards: { id: number; percentage: string; line_cost: string }[];
        purchase_orders: { id: number; quantity_outstanding: string }[];
        stock_expiries: { id: number; expiry_date: string }[];
    };
}

/** V2-2: the expiry warning (ExpiryRisk), as display strings. */
export interface FormExpiry {
    days_on_hand: string;
    at_risk: { batch: string; quantity: string; expiry_date: string; cover_days: string; days_to_expiry: string }[];
    expired: { batch: string; quantity: string; expiry_date: string }[];
}

export interface RequestFormView {
    sections: FormSectionView[];
    display: FormDisplay;
    expiry: FormExpiry;
}
