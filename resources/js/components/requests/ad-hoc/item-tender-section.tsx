import { FieldGrid, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

/**
 * V2-2 section "Item and tender details". Field order (edit here if the order
 * changes) is the visual, DOM and tab order. aria-required only where the
 * server says the field may stay empty for this type.
 */
export function ItemTenderSection({ api }: { api: FormApi }) {
    const field = (name: string) => ({
        id: fieldId.requisition(name),
        value: text(api.state.requisition[name]),
        onChange: (next: string) => api.setField(name, next),
        error: api.fieldError(name),
        optional: api.optional('item_tender', name),
    });

    // V2-2b order: DIN, Item description, Strength / Dosage form, Quantity requested,
    // Unit of measure, Contract reference / Clinical importance. FORM-COMPACT-1: one
    // grid on the width scale; from a 56rem card that is two rows of fields, then the text area.
    return (
        <FieldGrid>
            <TextField {...field('din')} width="sm" label="DIN" maxLength={8} inputMode="numeric" hint="Exactly 8 digits, for example 02345678." />
            <TextField {...field('item_description')} width="lg" label="Item Name" maxLength={500} />
            <TextField {...field('strength')} width="sm" label="Strength" maxLength={100} />
            <TextField {...field('dosage_form')} width="sm" label="Dosage Form" maxLength={100} />
            <TextField {...field('quantity_requested')} width="xs" label="Qty Requested" inputMode="decimal" hint="In figures, for example 2800." />
            <TextField
                {...field('unit_of_measure')}
                width="sm"
                label="Unit of Measure"
                maxLength={32}
                hint="The unit the quantity is counted in, for example: each, box of 100, vial."
            />
            {/* V2-5: the tender cycle moved to section 1, Request Type. */}
            <TextField {...field('tender_reference')} width="sm" label="Contract Reference" maxLength={120} hint="For example TENDER-2026-14." />

            <TextAreaField
                {...field('clinical_note')}
                width="full"
                label="Clinical Importance (Purpose of Drugs)"
                maxLength={api.config.max_lengths.clinical_note}
                hint={`Why the item is needed and what it is used for. Up to ${api.config.max_lengths.clinical_note} characters.`}
            />
        </FieldGrid>
    );
}
