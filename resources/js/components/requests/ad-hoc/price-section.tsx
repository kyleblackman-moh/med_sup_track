import { CheckboxField, FieldGrid, Figure, GridRow, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

/**
 * Section 5, Price comparison (optional). Kyle, 2026-11-24: always shown (no
 * "Add Price Comparison" button, no intro line). 2026-11-25: no Quoted Price figure (the
 * quotation's totals show it). The change against each entered price is computed and formatted by the server (V2-3: the "%" too).
 * 2026-11-23: the tender and previous price (and their changes) show only
 * where the server's section map names them (Tender Excess); hidden values
 * stay in the draft and the server clears them at submit.
 */
export function PriceSection({ api }: { api: FormApi }) {
    const { state, config } = api;
    const showPrices = api.optional('quotation_comparison', 'tender_price') !== undefined;

    return (
        <FieldGrid>
            {showPrices && (
                <>
                    <TextField
                        width="xs"
                        id={fieldId.requisition('tender_price')}
                        label="Tender Price"
                        optional
                        inputMode="decimal"
                        value={text(state.requisition.tender_price)}
                        onChange={(value) => api.setField('tender_price', value)}
                        error={api.fieldError('tender_price')}
                        hint="Unit price on the tender."
                    />
                    <TextField
                        width="xs"
                        id={fieldId.requisition('previous_price')}
                        label="Previous Price"
                        optional
                        inputMode="decimal"
                        value={text(state.requisition.previous_price)}
                        onChange={(value) => api.setField('previous_price', value)}
                        error={api.fieldError('previous_price')}
                        hint="Unit price paid last time."
                    />
                </>
            )}

            {showPrices && (
                <GridRow>
                    <FieldGrid as="dl">
                        <Figure width="md" label="Change Against Tender Price" value={api.form.display.price_change_vs_tender} />
                        <Figure width="md" label="Change Against Previous Price" value={api.form.display.price_change_vs_previous} />
                    </FieldGrid>
                </GridRow>
            )}

            <CheckboxField
                width="full"
                id={fieldId.requisition('quoted_price_acceptable')}
                label="Quoted Price Is Acceptable"
                checked={state.requisition.quoted_price_acceptable === true}
                onChange={(checked) => api.setField('quoted_price_acceptable', checked)}
            />

            <TextAreaField
                width="full"
                id={fieldId.requisition('price_note')}
                label="Note on the Price"
                optional
                maxLength={config.max_lengths.price_note}
                value={text(state.requisition.price_note)}
                onChange={(value) => api.setField('price_note', value)}
                error={api.fieldError('price_note')}
                hint={`Up to ${config.max_lengths.price_note} characters.`}
            />
        </FieldGrid>
    );
}
