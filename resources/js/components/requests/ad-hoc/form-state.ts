// Local state of the AD_HOC form (slice F2). The page's local state is the
// source of truth while editing; a save sends all of it, and only the ids the
// server returns (by client key) are merged back, so edits made during a save
// are never overwritten. No maths and no formatting happen here.

import { type AdHocDetail, type ChildSection, type SavedFlash } from '@/types/ad-hoc';
import { type FieldValue, type Row } from '@/types/requests';

/** A form value: text, a checkbox, or null for "never touched" (an optional yes/no). */
export type Value = string | boolean | null;

export type Values = Record<string, Value>;

export interface LocalRow {
    /** Stable local key, also sent as client_key so a new row's id can be attached. */
    key: string;
    id: number | null;
    values: Values;
}

export interface FormState {
    requisition: Values;
    rows: Record<ChildSection, LocalRow[]>;
    /** V2-1: the Tender Variation entry (sent on every save; kept while hidden, until submit). */
    variation: Values;
}

export const CHILD_SECTIONS: ChildSection[] = ['tender_awards', 'purchase_orders', 'stock_expiries', 'quotations'];

/** Request fields, in form order. Booleans stay booleans; the rest are text. */
export const REQUISITION_FIELDS = [
    'item_description',
    'strength',
    'dosage_form',
    'din',
    'quantity_requested',
    'unit_of_measure',
    'tender_reference',
    'tender_cycle_start',
    'tender_cycle_end',
    'clinical_note',
    // V2-2: the tender outcome is not on the form any more (not sent, so a saved value is kept).
    'tender_issue_explanation',
    'quantity_on_order',
    'stock_location',
    'average_monthly_consumption',
    'no_stock_on_hand',
    'rfq_audience',
    'quantity_to_purchase',
    'tender_price',
    'previous_price',
    'quoted_price_acceptable',
    'price_note',
] as const;

/** Row columns per section, in table order, with the value a new row starts with. */
export const ROW_COLUMNS: Record<ChildSection, Record<string, Value>> = {
    tender_awards: { supplier_name: '', manufacturer: '', brand: '', country_of_origin: '', quantity_awarded: '', unit_cost: '' },
    purchase_orders: {
        award_id: '',
        po_number: '',
        quantity_ordered: '',
        ordered_on: '',
        quantity_delivered: '',
        delivery_eta: '',
        delivery_status: '',
    },
    stock_expiries: { quantity: '', expiry_date: '' },
    quotations: {
        considered: true,
        is_recommended: false,
        supplier_name: '',
        quantity_offered: '',
        unit_price: '',
        ex_stock: false,
        lead_time_days: '',
        brand: '',
        manufacturer: '',
        country_of_origin: '',
        remarks: '',
        reason_accepted_excluded: '',
    },
};

/** V2-1: the Tender Variation fields, in form order. */
export const VARIATION_FIELDS = ['variation_type', 'existing_detail', 'proposed_detail', 'effective_date'] as const;

let counter = 0;

/** A random local key. Not crypto: the page may be served without a secure context. */
export function newKey(): string {
    counter += 1;

    return `r${Date.now().toString(36)}${counter.toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}

export function newRow(section: ChildSection): LocalRow {
    return { key: newKey(), id: null, values: { ...ROW_COLUMNS[section] } };
}

function toValue(value: FieldValue | undefined, template: Value): Value {
    if (typeof template === 'boolean') {
        return typeof value === 'boolean' ? value : template;
    }
    if (value === null || value === undefined) {
        return template === null ? null : '';
    }

    return String(value);
}

/** The form's starting state from the saved request. Each table starts with one row. */
export function initialState(detail: AdHocDetail): FormState {
    const requisition: Values = {};
    for (const field of REQUISITION_FIELDS) {
        const value = detail.requisition[field];
        if (field === 'no_stock_on_hand') {
            requisition[field] = value === true;
        } else if (field === 'quoted_price_acceptable') {
            requisition[field] = typeof value === 'boolean' ? value : null;
        } else {
            requisition[field] = value === null || value === undefined ? '' : String(value);
        }
    }

    const rows = {} as Record<ChildSection, LocalRow[]>;
    for (const section of CHILD_SECTIONS) {
        const template = ROW_COLUMNS[section];
        const saved = (detail[section] as Row[]).map((row) => ({
            key: newKey(),
            id: typeof row.id === 'number' ? row.id : null,
            values: Object.fromEntries(Object.entries(template).map(([name, start]) => [name, toValue(row[name], start)])),
        }));
        rows[section] = saved.length > 0 ? saved : [newRow(section)];
    }

    const variation: Values = {};
    for (const field of VARIATION_FIELDS) {
        const value = detail.variation?.[field];
        variation[field] = value === null || value === undefined ? '' : String(value);
    }

    return { requisition, rows, variation };
}

const blankToNull = (value: Value) => (typeof value === 'string' && value.trim() === '' ? null : value);

/** The save payload: every field and every row (rows missing from it are deleted by the server). */
export function toPayload(state: FormState): Record<string, unknown> {
    const requisition = Object.fromEntries(Object.entries(state.requisition).map(([name, value]) => [name, blankToNull(value)]));
    // The column is NOT NULL with a default; never send null for it.
    requisition.no_stock_on_hand = state.requisition.no_stock_on_hand === true;

    const variation = Object.fromEntries(Object.entries(state.variation).map(([name, value]) => [name, blankToNull(value)]));
    const payload: Record<string, unknown> = { requisition, variation };
    for (const section of CHILD_SECTIONS) {
        payload[section] = state.rows[section].map((row) => {
            const values: Record<string, unknown> = Object.fromEntries(Object.entries(row.values).map(([name, value]) => [name, blankToNull(value)]));
            if ('award_id' in values) {
                values.award_id = values.award_id === null ? null : Number(values.award_id);
            }

            return { ...(row.id !== null ? { id: row.id } : {}), client_key: row.key, ...values };
        });
    }

    return payload;
}

/**
 * Merges a save's result into the current local state: attaches new ids by
 * client key, and clears the id of a row the server no longer has (a blank
 * row it dropped). Values are never touched.
 */
export function mergeSaved(state: FormState, saved: SavedFlash): FormState {
    const rows = { ...state.rows };
    for (const section of CHILD_SECTIONS) {
        const kept = saved.row_ids[section];
        rows[section] = state.rows[section].map((row) => {
            let id: number | null = row.id ?? saved.id_map[row.key] ?? null;
            if (id !== null && kept !== undefined && !kept.includes(id)) {
                id = null; // dropped as blank (or removed): the next save inserts it again if it gets values
            }

            return id === row.id ? row : { ...row, id };
        });
    }

    return { ...state, rows };
}

/** The server's copy of a saved row (for its computed values), or undefined for an unsaved row. */
export function serverRow(detail: AdHocDetail, section: ChildSection, id: number | null): Row | undefined {
    return id === null ? undefined : (detail[section] as Row[]).find((row) => row.id === id);
}

/** DOM id of a field: request fields, row cells and table-level targets. */
export const fieldId = {
    requisition: (name: string) => `f-requisition-${name}`,
    variation: (name: string) => `f-variation-${name}`,
    cell: (section: ChildSection, key: string, name: string) => `f-${section}-${key}-${name}`,
    table: (section: ChildSection | 'documents') => `f-${section}`,
};
