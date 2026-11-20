import { Button } from '@/components/ui/button';
import { useState } from 'react';
import { CheckboxField, controlHeight, FieldGrid, Figure, GridRow, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

const PRICE_FIELDS = ['tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note'];

/**
 * Section 5, Price comparison (optional). Hidden behind "Add Price Comparison"
 * until something is entered. The quoted price and the change against each
 * entered price are computed and formatted by the server (V2-3: the "%" too).
 */
export function PriceSection({ api }: { api: FormApi }) {
    const { state, detail, config } = api;
    const entered = PRICE_FIELDS.some((name) => {
        const value = state.requisition[name];
        return typeof value === 'boolean' || (typeof value === 'string' && value.trim() !== '');
    });
    const [adding, setAdding] = useState(false);
    const figures = detail.figures;

    if (!entered && !adding) {
        return (
            <div className="grid gap-2">
                <p className="text-muted-foreground text-sm">Compare the quoted price with the tender price or the price paid last time.</p>
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        className={controlHeight}
                        onClick={() => {
                            setAdding(true);
                            window.setTimeout(() => document.getElementById(fieldId.requisition('tender_price'))?.focus(), 0);
                        }}
                    >
                        Add Price Comparison
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <FieldGrid>
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

            <GridRow>
                <FieldGrid as="dl">
                    <Figure width="md" label="Quoted Price (Recommended Quotation)" value={figures?.recommended_unit_price} />
                    <Figure width="md" label="Change Against Tender Price" value={api.form.display.price_change_vs_tender} />
                    <Figure width="md" label="Change Against Previous Price" value={api.form.display.price_change_vs_previous} />
                </FieldGrid>
            </GridRow>

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
