import { TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

/** Text limits of the variation fields (RequisitionRules::variationFields). */
const MAX = { variation_type: 100, existing_detail: 500, proposed_detail: 500 };

/**
 * V2-2 section "Variation details", shown only when the server marks it
 * visible (Tender Variation). One row: the type of variation, the existing and
 * the proposed detail, and the date it takes effect.
 */
export function VariationSection({ api }: { api: FormApi }) {
    const field = (name: string) => ({
        id: fieldId.variation(name),
        value: text(api.state.variation[name]),
        onChange: (next: string) => api.setVariation(name, next),
        error: api.tableError(`variation.${name}`),
        optional: api.optional('variation_details', `variation.${name}`),
    });

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,2fr)_minmax(0,1fr)]">
            <TextField {...field('variation_type')} label="Variation type" maxLength={MAX.variation_type} hint="For example: price, quantity." />
            <TextAreaField
                {...field('existing_detail')}
                label="Existing approved detail"
                rows={2}
                maxLength={MAX.existing_detail}
                hint={`Up to ${MAX.existing_detail} characters.`}
            />
            <TextAreaField
                {...field('proposed_detail')}
                label="Proposed revised detail"
                rows={2}
                maxLength={MAX.proposed_detail}
                hint={`Up to ${MAX.proposed_detail} characters.`}
            />
            <TextField {...field('effective_date')} label="Effective date" type="date" />
        </div>
    );
}
