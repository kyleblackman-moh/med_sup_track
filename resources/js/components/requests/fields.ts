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

export interface FieldGroup {
    title: string;
    fields: FieldDef[];
}

export const REQUISITION_GROUPS: FieldGroup[] = [
    {
        title: 'Request details',
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
        fields: [
            { name: 'tender_reference', label: 'Tender reference', kind: 'text' },
            { name: 'tender_outcome', label: 'Tender outcome', kind: 'select', options: 'tender_outcome' },
            { name: 'tender_issue_explanation', label: 'Tender issue explanation', kind: 'textarea' },
        ],
    },
    {
        title: 'Request for quotation (RFQ)',
        fields: [
            { name: 'rfq_date', label: 'RFQ date', kind: 'date' },
            { name: 'rfq_audience', label: 'RFQ sent to', kind: 'select', options: 'rfq_audience' },
            { name: 'sample_evaluation_summary', label: 'Sample evaluation summary', kind: 'textarea' },
        ],
    },
];

export const COMMENTS_FIELD: FieldDef = { name: 'applicant_comments', label: 'Comments', kind: 'textarea' };

export const REQUISITION_FIELDS: FieldDef[] = [...REQUISITION_GROUPS.flatMap((g) => g.fields), COMMENTS_FIELD];

export interface SectionDef {
    title: string;
    rowLabel: string;
    columns: FieldDef[];
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
