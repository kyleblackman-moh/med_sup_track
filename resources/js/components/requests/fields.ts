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
        title: 'Request Details',
        tab: 'details',
        fields: [
            { name: 'vendor_reference', label: 'Your Reference', kind: 'text' },
            { name: 'request_date', label: 'Request Date', kind: 'date' },
            { name: 'need_classification', label: 'Need Classification', kind: 'select', options: 'need_classification' },
            { name: 'item_description', label: 'Item Description', kind: 'text' },
            { name: 'din', label: 'DIN', kind: 'text', pharmOnly: true },
            { name: 'item_code', label: 'Item Code', kind: 'text' },
            { name: 'unit_of_measure', label: 'Unit of Measure', kind: 'text' },
            { name: 'purpose', label: 'Purpose', kind: 'textarea' },
            { name: 'quantity_requested', label: 'Quantity Requested', kind: 'decimal' },
        ],
    },
    {
        title: 'Stock Position',
        tab: 'stock',
        fields: [
            { name: 'average_monthly_consumption', label: 'Average Monthly Consumption', kind: 'decimal' },
            { name: 'central_stores_stock', label: 'Central Stores Stock', kind: 'decimal', hint: 'Enter 0 if none.' },
            { name: 'rha_stock', label: 'RHA Stock', kind: 'decimal', hint: 'Enter 0 if none.' },
            { name: 'stock_verified_on', label: 'Stock Verified On', kind: 'date' },
            { name: 'stockout_date', label: 'Stock-Out Date', kind: 'date' },
            { name: 'urgency_explanation', label: 'Urgency Explanation', kind: 'textarea' },
        ],
    },
    {
        title: 'Tender Background',
        tab: 'tender',
        fields: [
            { name: 'tender_reference', label: 'Tender Reference', kind: 'text' },
            { name: 'tender_outcome', label: 'Tender Outcome', kind: 'select', options: 'tender_outcome' },
            { name: 'tender_issue_explanation', label: 'Tender Issue Explanation', kind: 'textarea' },
        ],
    },
    {
        title: 'Request for Quotation (RFQ)',
        tab: 'tender',
        fields: [
            { name: 'rfq_date', label: 'RFQ Date', kind: 'date' },
            { name: 'rfq_audience', label: 'RFQ Sent To', kind: 'select', options: 'rfq_audience' },
            { name: 'sample_evaluation_summary', label: 'Sample Evaluation Summary', kind: 'textarea' },
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
        title: 'Supplier Quotations',
        rowLabel: 'Quotation',
        columns: [
            { name: 'supplier_name', label: 'Supplier', kind: 'text' },
            { name: 'manufacturer', label: 'Manufacturer', kind: 'text' },
            { name: 'brand', label: 'Brand', kind: 'text' },
            { name: 'unit_price', label: 'Unit Price', kind: 'decimal' },
            { name: 'vat_rate', label: 'VAT Rate (%)', kind: 'decimal' },
            { name: 'quantity_offered', label: 'Quantity Offered', kind: 'decimal' },
            { name: 'lead_time_days', label: 'Lead Time (Days)', kind: 'integer' },
            { name: 'minimum_order_quantity', label: 'Minimum Order Quantity', kind: 'decimal' },
            { name: 'price_valid_until', label: 'Price Valid Until', kind: 'date' },
            { name: 'quantity_available_now', label: 'Quantity Available Now', kind: 'decimal' },
            { name: 'previous_price_maintained', label: 'Previous Price Maintained', kind: 'select', options: 'previous_price_maintained' },
            { name: 'reason_accepted_excluded', label: 'Reason Accepted or Excluded', kind: 'text' },
            { name: 'is_recommended', label: 'Recommended', kind: 'boolean' },
            { name: 'recommended_quantity', label: 'Recommended Quantity', kind: 'decimal' },
        ],
        summary: [
            { key: 'supplier_name', label: 'Supplier' },
            { key: 'unit_price', label: 'Unit Price' },
            { key: 'quantity_offered', label: 'Qty Offered' },
            { key: 'offered_total', label: 'Offered Total', derived: true },
            { key: 'is_recommended', label: 'Recommended' },
            { key: 'recommended_quantity', label: 'Recommended Qty' },
        ],
        newRow: { previous_price_maintained: 'UNKNOWN', is_recommended: false },
        derived: [
            ['offered_total', 'Offered Total'],
            ['recommended_total', 'Recommended Total'],
            ['recommended_vat_amount', 'VAT on Recommended'],
            ['recommended_total_including_vat', 'Recommended Total Incl. VAT'],
        ],
        totals: [
            ['recommended_quantity', 'Total Recommended Quantity'],
            ['recommended_total', 'Total Recommended'],
            ['recommended_total_including_vat', 'Total Recommended Incl. VAT'],
        ],
    },
    tender_awards: {
        title: 'Tender Awards',
        rowLabel: 'Award',
        columns: [
            { name: 'supplier_name', label: 'Supplier', kind: 'text' },
            { name: 'manufacturer', label: 'Manufacturer', kind: 'text' },
            { name: 'brand', label: 'Brand', kind: 'text' },
            { name: 'quantity_awarded', label: 'Quantity Awarded', kind: 'decimal' },
            { name: 'unit_cost', label: 'Unit Cost', kind: 'decimal' },
        ],
        summary: [
            { key: 'supplier_name', label: 'Supplier' },
            { key: 'quantity_awarded', label: 'Qty Awarded' },
            { key: 'unit_cost', label: 'Unit Cost' },
            { key: 'total', label: 'Total', derived: true },
        ],
        newRow: {},
        derived: [
            ['total', 'Total'],
            ['percentage', 'Share of Award (%)'],
        ],
        totals: [
            ['quantity_awarded', 'Total Quantity Awarded'],
            ['total', 'Total Value'],
        ],
    },
    purchase_orders: {
        title: 'Purchase Orders',
        rowLabel: 'Purchase Order',
        columns: [
            { name: 'supplier_name', label: 'Supplier', kind: 'text' },
            { name: 'po_number', label: 'PO Number', kind: 'text' },
            { name: 'quantity_ordered', label: 'Quantity Ordered', kind: 'decimal' },
            { name: 'ordered_on', label: 'Ordered On', kind: 'date' },
            { name: 'quantity_delivered', label: 'Quantity Delivered', kind: 'decimal' },
            { name: 'delivery_eta', label: 'Delivery ETA', kind: 'date' },
            { name: 'delivery_status', label: 'Delivery Status', kind: 'text' },
        ],
        summary: [
            { key: 'po_number', label: 'PO Number' },
            { key: 'supplier_name', label: 'Supplier' },
            { key: 'quantity_ordered', label: 'Ordered' },
            { key: 'quantity_delivered', label: 'Delivered' },
            { key: 'delivery_eta', label: 'Delivery ETA' },
        ],
        newRow: { quantity_delivered: '0' },
        derived: [['quantity_outstanding', 'Outstanding']],
        totals: [
            ['quantity_ordered', 'Total Ordered'],
            ['quantity_delivered', 'Total Delivered'],
            ['quantity_outstanding', 'Total Outstanding'],
        ],
    },
    stock_expiries: {
        title: 'Stock Expiries',
        rowLabel: 'Expiry',
        columns: [
            { name: 'quantity', label: 'Quantity', kind: 'decimal' },
            { name: 'expiry_date', label: 'Expiry Date', kind: 'date' },
        ],
        summary: [
            { key: 'quantity', label: 'Quantity' },
            { key: 'expiry_date', label: 'Expiry Date' },
        ],
        newRow: {},
        derived: [],
        totals: [['quantity', 'Total Quantity']],
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
