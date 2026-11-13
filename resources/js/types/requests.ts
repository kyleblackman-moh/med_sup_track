// Shapes of the vendor request props (app/Http/Resources/Requests). Display only:
// the server decides what is allowed and validates every save.

export type Section = 'quotations' | 'tender_awards' | 'purchase_orders' | 'stock_expiries';

export type OptionList = { value: string; label: string }[];

export interface ProcurementTypeSummary {
    code: string;
    name: string;
    category: 'PHARM' | 'NON_PHARM';
}

export interface RequestSummary {
    id: string;
    reference_no: string | null;
    status_label: string;
    status_group: string;
    procurement_type: ProcurementTypeSummary;
    item_description: string | null;
    vendor_reference: string | null;
    updated_at: string | null;
    can: { edit: boolean };
}

export type FieldValue = string | number | boolean | null;

export type Row = Record<string, FieldValue> & { id?: number; derived?: Record<string, string | null> };

export interface RequestDetail extends RequestSummary {
    requisition: Record<string, FieldValue> & { derived: { stock_on_hand: string | null; coverage_months: string | null } };
    quotations: Row[];
    tender_awards: Row[];
    purchase_orders: Row[];
    stock_expiries: Row[];
    totals: Record<Section, Record<string, string | null>>;
    /** Calendar date (Y-m-d) the Ministry received the hardcopy, or null. */
    hardcopy_received_on: string | null;
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
    is_required: boolean;
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
