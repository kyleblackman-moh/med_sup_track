import { Button } from '@/components/ui/button';
import { useState } from 'react';
import { CheckboxField, controlHeight, Figure, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

const PRICE_FIELDS = ['tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note'];

/** "12.50" -> "12.50%"; the server computes the value, this only adds the sign. */
const percent = (value: string | null | undefined) => (value === null || value === undefined ? null : `${value}%`);

/**
 * Section 5, Price comparison (optional). Hidden behind "Add price comparison"
 * until something is entered. The quoted price and the change against each
 * entered price are computed by the server.
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
                        Add price comparison (optional)
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-6">
            <div className="grid gap-4 md:grid-cols-2">
                <TextField
                    id={fieldId.requisition('tender_price')}
                    label="Tender price"
                    optional
                    inputMode="decimal"
                    value={text(state.requisition.tender_price)}
                    onChange={(value) => api.setField('tender_price', value)}
                    error={api.fieldError('tender_price')}
                    hint="Unit price on the tender."
                />
                <TextField
                    id={fieldId.requisition('previous_price')}
                    label="Previous price"
                    optional
                    inputMode="decimal"
                    value={text(state.requisition.previous_price)}
                    onChange={(value) => api.setField('previous_price', value)}
                    error={api.fieldError('previous_price')}
                    hint="Unit price paid last time."
                />
            </div>

            <dl className="grid gap-4 sm:grid-cols-3">
                <Figure label="Quoted price (recommended quotation)" value={figures?.recommended_unit_price} />
                <Figure label="Change against tender price" value={percent(figures?.price_change_vs_tender_percent)} />
                <Figure label="Change against previous price" value={percent(figures?.price_change_vs_previous_percent)} />
            </dl>

            <CheckboxField
                id={fieldId.requisition('quoted_price_acceptable')}
                label="Quoted price is acceptable"
                checked={state.requisition.quoted_price_acceptable === true}
                onChange={(checked) => api.setField('quoted_price_acceptable', checked)}
            />

            <TextAreaField
                id={fieldId.requisition('price_note')}
                label="Note on the price"
                optional
                maxLength={config.max_lengths.price_note}
                value={text(state.requisition.price_note)}
                onChange={(value) => api.setField('price_note', value)}
                error={api.fieldError('price_note')}
                hint={`Up to ${config.max_lengths.price_note} characters.`}
            />
        </div>
    );
}
