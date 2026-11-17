// Props of the AD_HOC single-page form (slice F2). Display only: the server
// validates every save, computes every figure and decides what is complete.

import { type DocumentGroup, type OptionList, type RequestDetail, type UploadRules } from './requests';

export type ChildSection = 'tender_awards' | 'purchase_orders' | 'stock_expiries' | 'quotations';

export type SectionKey = 'item' | 'tender' | 'stock' | 'quotations' | 'price' | 'documents';

export interface SectionIssue {
    /** Readiness key, for example "tender_awards.0.unit_cost". */
    key: string;
    /** The key without the row index, for example "tender_awards.unit_cost". */
    field: string;
    /** The saved row the issue is about, or null. */
    row_id: number | null;
    message: string;
}

export interface SectionStatus {
    key: SectionKey;
    label: string;
    optional: boolean;
    complete: boolean;
    summary: string;
    issues: SectionIssue[];
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
};

export interface AdHocConfig {
    options: { tender_outcome: OptionList; rfq_audience: OptionList };
    /** The outcome value that hides the award and order tables. */
    not_awarded: string;
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

export interface AdHocPageProps {
    case: AdHocDetail;
    sections: SectionStatus[];
    config: AdHocConfig;
    documents: DocumentGroup[];
    saved: SavedFlash | null;
    hardcopy_notice: string;
    can: { edit: boolean; submit: boolean; requests: boolean };
    errors: Record<string, string>;
}
