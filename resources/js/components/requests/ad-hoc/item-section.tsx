import { TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

/** Section 1, Item. Field order is the visual, DOM and tab order. */
export function ItemSection({ api }: { api: FormApi }) {
    const value = (name: string) => text(api.state.requisition[name]);
    const field = (name: string) => ({
        id: fieldId.requisition(name),
        value: value(name),
        onChange: (next: string) => api.setField(name, next),
        error: api.fieldError(name),
    });

    return (
        <div className="grid grid-cols-1 gap-4">
            <TextField {...field('item_description')} label="Item description" maxLength={500} />

            <div className="grid gap-4 md:grid-cols-3">
                <TextField {...field('strength')} label="Strength" optional maxLength={100} />
                <TextField {...field('dosage_form')} label="Dosage form" optional maxLength={100} />
                <TextField {...field('din')} label="DIN" inputMode="numeric" maxLength={32} hint="Digits only." />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <TextField {...field('quantity_requested')} label="Quantity requested" inputMode="decimal" hint="In figures, for example 2800." />
                <TextField {...field('unit_of_measure')} label="Unit of measure" maxLength={32} hint="For example: each, box of 100, vial." />
            </div>

            <TextAreaField
                {...field('clinical_note')}
                label="Clinical importance"
                optional
                maxLength={api.config.max_lengths.clinical_note}
                hint={`Why the item is needed. Up to ${api.config.max_lengths.clinical_note} characters.`}
            />
        </div>
    );
}
