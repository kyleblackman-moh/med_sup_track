// Field layout for the request form and read-only view. Labels and input kinds
// only; lengths, formats and required rules are enforced by the server.

import { type FormConfig, type Section } from '@/types/requests';

export type FieldKind = 'text' | 'textarea' | 'decimal' | 'integer' | 'date' | 'boolean' | 'select';

export interface FieldDef {
    name: string;
    label: string;
    kind: FieldKind;
    options?: keyof FormConfig['options'];
    pharmOnly?: boolean;
    hint?: string;
}

/** Tabs of the draft form, in order. */
export type TabId = 'details' | 'stock' | 'tender' | 'orders' | 'quotations' | 'documents';

export interface FieldGroup {
    title: string;
    tab: TabId;
    fields: FieldDef[];
}

export const REQUISITION_GROUPS: FieldGroup[] = [
    {
        title: 'Request details',
        tab: 'details',
        fields: [
            { name: 'vendor_reference', label: 'Your reference', kind: 'text' },
            { name: 'request_date', label: 'Request date', kind: 'date' },
            { name: 'need_classification', label: 'Need classification', kind: 'select', options: 'need_classification' },
            { name: 'item_description', label: 'Item description', kind: 'text' },
            { name: 'din', label: 'DIN', kind: 'text', pharmOnly: true },
            { name: 'item_code', label: 'Item code', kind: 'text' },
            { name: 'unit_of_measure', label: 'Unit of measure', kind: 'text' },
            { name: 'purpose', label: 'Purpose', kind: 'textarea' },
            { name: 'quantity_requested', label: 'Quantity requested', kind: 'decimal' },
        ],
    },
    {
        title: 'Stock position',
        tab: 'stock',
        fields: [
            { name: 'average_monthly_consumption', label: 'Average monthly consumption', kind: 'decimal' },
            { name: 'central_stores_stock', label: 'Central stores stock', kind: 'decimal', hint: 'Enter 0 if none.' },
            { name: 'rha_stock', label: 'RHA stock', kind: 'decimal', hint: 'Enter 0 if none.' },
            { name: 'stock_verified_on', label: 'Stock verified on', kind: 'date' },
            { name: 'stockout_date', label: 'Stock-out date', kind: 'date' },
            { name: 'urgency_explanation', label: 'Urgency explanation', kind: 'textarea' },
        ],
    },
    {
        title: 'Tender background',
        tab: 'tender',
        fields: [
            { name: 'tender_reference', label: 'Tender reference', kind: 'text' },
            { name: 'tender_outcome', label: 'Tender outcome', kind: 'select', options: 'tender_outcome' },
            { name: 'tender_issue_explanation', label: 'Tender issue explanation', kind: 'textarea' },
        ],
    },
    {
        title: 'Request for quotation (RFQ)',
        tab: 'tender',
        fields: [
            { name: 'rfq_date', label: 'RFQ date', kind: 'date' },
            { name: 'rfq_audience', label: 'RFQ sent to', kind: 'select', options: 'rfq_audience' },
            { name: 'sample_evaluation_summary', label: 'Sample evaluation summary', kind: 'textarea' },
        ],
    },
];

export const COMMENTS_FIELD: FieldDef = { name: 'applicant_comments', label: 'Comments', kind: 'textarea' };
export const COMMENTS_TAB: TabId = 'documents';

export const REQUISITION_FIELDS: FieldDef[] = [...REQUISITION_GROUPS.flatMap((g) => g.fields), COMMENTS_FIELD];

/** The tab each child section lives on. */
export const SECTION_TAB: Record<Section, TabId> = {
    stock_expiries: 'stock',
    tender_awards: 'orders',
    purchase_orders: 'orders',
    quotations: 'quotations',
};

/** A column of the compact row list: a field, or a server-computed value when derived is set. */
export interface SummaryColumn {
    key: string;
    label: string;
    derived?: boolean;
}

export interface SectionDef {
    title: string;
    rowLabel: string;
    columns: FieldDef[];
    /** Columns shown in the compact list; the dialog edits every column. */
    summary: SummaryColumn[];
    /** Values for a new row; fields the server defaults must not be sent empty. */
    newRow: Record<string, string | boolean>;
    /** Server-computed values shown per row: [derived key, label]. */
    derived: [string, string][];
    /** Server-computed section totals: [totals key, label]. */
    totals: [string, string][];
}

export const SECTIONS: Record<Section, SectionDef> = {
    quotations: {
        title: 'Supplier quotations',
        rowLabel: 'Quotation',
        columns: [
            { name: 'supplier_name', label: 'Supplier', kind: 'text' },
            { name: 'manufacturer', label: 'Manufacturer', kind: 'text' },
            { name: 'brand', label: 'Brand', kind: 'text' },
            { name: 'unit_price', label: 'Unit price', kind: 'decimal' },
            { name: 'vat_rate', label: 'VAT rate (%)', kind: 'decimal' },
            { name: 'quantity_offered', label: 'Quantity offered', kind: 'decimal' },
            { name: 'lead_time_days', label: 'Lead time (days)', kind: 'integer' },
            { name: 'minimum_order_quantity', label: 'Minimum order quantity', kind: 'decimal' },
            { name: 'price_valid_until', label: 'Price valid until', kind: 'date' },
            { name: 'quantity_available_now', label: 'Quantity available now', kind: 'decimal' },
            { name: 'previous_price_maintained', label: 'Previous price maintained', kind: 'select', options: 'previous_price_maintained' },
            { name: 'reason_accepted_excluded', label: 'Reason accepted or excluded', kind: 'text' },
            { name: 'is_recommended', label: 'Recommended', kind: 'boolean' },
            { name: 'recommended_quantity', label: 'Recommended quantity', kind: 'decimal' },
        ],
        summary: [
            { key: 'supplier_name', label: 'Supplier' },
            { key: 'unit_price', label: 'Unit price' },
            { key: 'quantity_offered', label: 'Qty offered' },
            { key: 'offered_total', label: 'Offered total', derived: true },
            { key: 'is_recommended', label: 'Recommended' },
            { key: 'recommended_quantity', label: 'Recommended qty' },
        ],
        newRow: { previous_price_maintained: 'UNKNOWN', is_recommended: false },
        derived: [
            ['offered_total', 'Offered total'],
            ['recommended_total', 'Recommended total'],
            ['recommended_vat_amount', 'VAT on recommended'],
            ['recommended_total_including_vat', 'Recommended total incl. VAT'],
        ],
        totals: [
            ['recommended_quantity', 'Total recommended quantity'],
            ['recommended_total', 'Total recommended'],
            ['recommended_total_including_vat', 'Total recommended incl. VAT'],
        ],
    },
    tender_awards: {
        title: 'Tender awards',
        rowLabel: 'Award',
        columns: [
            { name: 'supplier_name', label: 'Supplier', kind: 'text' },
            { name: 'manufacturer', label: 'Manufacturer', kind: 'text' },
            { name: 'brand', label: 'Brand', kind: 'text' },
            { name: 'quantity_awarded', label: 'Quantity awarded', kind: 'decimal' },
            { name: 'unit_cost', label: 'Unit cost', kind: 'decimal' },
        ],
        summary: [
            { key: 'supplier_name', label: 'Supplier' },
            { key: 'quantity_awarded', label: 'Qty awarded' },
            { key: 'unit_cost', label: 'Unit cost' },
            { key: 'total', label: 'Total', derived: true },
        ],
        newRow: {},
        derived: [
            ['total', 'Total'],
            ['percentage', 'Share of award (%)'],
        ],
        totals: [
            ['quantity_awarded', 'Total quantity awarded'],
            ['total', 'Total value'],
        ],
    },
    purchase_orders: {
        title: 'Purchase orders',
        rowLabel: 'Purchase order',
        columns: [
            { name: 'supplier_name', label: 'Supplier', kind: 'text' },
            { name: 'po_number', label: 'PO number', kind: 'text' },
            { name: 'quantity_ordered', label: 'Quantity ordered', kind: 'decimal' },
            { name: 'ordered_on', label: 'Ordered on', kind: 'date' },
            { name: 'quantity_delivered', label: 'Quantity delivered', kind: 'decimal' },
            { name: 'delivery_eta', label: 'Delivery ETA', kind: 'date' },
            { name: 'delivery_status', label: 'Delivery status', kind: 'text' },
        ],
        summary: [
            { key: 'po_number', label: 'PO number' },
            { key: 'supplier_name', label: 'Supplier' },
            { key: 'quantity_ordered', label: 'Ordered' },
            { key: 'quantity_delivered', label: 'Delivered' },
            { key: 'delivery_eta', label: 'Delivery ETA' },
        ],
        newRow: { quantity_delivered: '0' },
        derived: [['quantity_outstanding', 'Outstanding']],
        totals: [
            ['quantity_ordered', 'Total ordered'],
            ['quantity_delivered', 'Total delivered'],
            ['quantity_outstanding', 'Total outstanding'],
        ],
    },
    stock_expiries: {
        title: 'Stock expiries',
        rowLabel: 'Expiry',
        columns: [
            { name: 'quantity', label: 'Quantity', kind: 'decimal' },
            { name: 'expiry_date', label: 'Expiry date', kind: 'date' },
        ],
        summary: [
            { key: 'quantity', label: 'Quantity' },
            { key: 'expiry_date', label: 'Expiry date' },
        ],
        newRow: {},
        derived: [],
        totals: [['quantity', 'Total quantity']],
    },
};

/** Display text for a stored value. */
export function displayValue(field: FieldDef, value: unknown, config?: FormConfig): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (field.kind === 'boolean') {
        return value ? 'Yes' : 'No';
    }
    if (field.kind === 'select') {
        const label = field.options && config ? config.options[field.options].find((o) => o.value === value)?.label : undefined;
        if (label) {
            return label;
        }
        const words = String(value).replace(/_/g, ' ').toLowerCase();

        return words.charAt(0).toUpperCase() + words.slice(1);
    }

    return String(value);
}
