import { TextAreaField, TextField } from './fields';
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

    // V2-2b order: DIN, Item description / Strength, Dosage form, Quantity requested,
    // Unit of measure / Contract reference, Tender cycle start, Tender cycle end /
    // Clinical importance.
    return (
        <div className="grid grid-cols-1 gap-5">
            <div className="grid gap-5 md:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
                <TextField {...field('din')} label="DIN" inputMode="numeric" maxLength={32} hint="Digits only." />
                <TextField {...field('item_description')} label="Item Description" maxLength={500} />
            </div>

            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <TextField {...field('strength')} label="Strength" maxLength={100} />
                <TextField {...field('dosage_form')} label="Dosage Form" maxLength={100} />
                <TextField {...field('quantity_requested')} label="Quantity Requested" inputMode="decimal" hint="In figures, for example 2800." />
                <TextField {...field('unit_of_measure')} label="Unit of Measure" maxLength={32} hint="For example: each, box of 100, vial." />
            </div>

            <div className="grid gap-5 xl:grid-cols-3">
                <TextField {...field('tender_reference')} label="Contract Reference" maxLength={120} hint="For example TENDER-2026-14." />
                <TextField {...field('tender_cycle_start')} label="Tender Cycle Start" type="date" />
                <TextField {...field('tender_cycle_end')} label="Tender Cycle End" type="date" hint="Not before the start." />
            </div>

            <TextAreaField
                {...field('clinical_note')}
                label="Clinical Importance"
                maxLength={api.config.max_lengths.clinical_note}
                hint={`Why the item is needed. Up to ${api.config.max_lengths.clinical_note} characters.`}
            />
        </div>
    );
}
