import { money, priceChange } from '@/lib/quotation-price';
import { cn } from '@/lib/utils';
import { CheckboxField, FieldError, FieldGrid, FieldHint, fieldLabelClass, GridRow, inputClass, TextAreaField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';

/**
 * Section 5, Price comparison. Kyle, 2026-11-24: always shown (no "Add Price
 * Comparison" button, no intro line). 2026-11-23: the tender and previous
 * price show only where the server's section map names them (Tender Excess);
 * hidden values stay in the draft and the server clears them at submit.
 *
 * Kyle, 2026-11-25: on a Tender Excess the prices are one small comparison
 * table: the recommended quotation's unit price as the reference line, then
 * Tender Price and Previous Price, each with how the recommended quotation
 * compares to it, worked out live as the vendor types (priceChange, the
 * server's rule in QuotationPrice::changePercent).
 */
export function PriceSection({ api }: { api: FormApi }) {
    const { state, config } = api;
    const showPrices = api.optional('quotation_comparison', 'tender_price') !== undefined;

    return (
        <FieldGrid>
            {showPrices && (
                <GridRow width="lg">
                    <PriceComparison api={api} />
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

/** The Tender Excess price table: the reference line, then one line per entered price with its change. */
function PriceComparison({ api }: { api: FormApi }) {
    const { state } = api;
    const recommended = state.rows.quotations.find((row) => row.values.is_recommended === true);
    const quoted = recommended ? text(recommended.values.unit_price).trim() : '';
    const supplier = recommended ? text(recommended.values.supplier_name).trim() : '';
    const hintId = 'price-comparison-hint';

    const priceLine = (field: 'tender_price' | 'previous_price', label: string, hint: string) => {
        const id = fieldId.requisition(field);
        const value = text(state.requisition[field]);
        const error = api.fieldError(field);
        const errorId = `${id}-error`;

        return (
            <tr>
                <th scope="row" className="border-base-300 border font-normal">
                    <label htmlFor={id} className="font-medium capitalize">
                        {label}
                    </label>
                    <span className="text-base-content/70 block text-xs">{hint}</span>
                </th>
                <td className="border-base-300 border">
                    <input
                        id={id}
                        inputMode="decimal"
                        type="text"
                        value={value}
                        className={inputClass(Boolean(error))}
                        aria-label={label}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? errorId : undefined}
                        onChange={(e) => api.setField(field, e.target.value)}
                    />
                    <FieldError id={errorId} message={error} />
                </td>
                <td className="border-base-300 border">
                    <ChangeBadge change={priceChange(quoted, value.trim())} />
                </td>
            </tr>
        );
    };

    return (
        <fieldset className="fieldset min-w-0 gap-2 py-0" aria-describedby={hintId}>
            <legend className={fieldLabelClass}>Price Comparison</legend>
            <FieldHint hintId={hintId}>Unit prices. The change shows how the recommended quotation compares with each price you enter.</FieldHint>
            <div className="border-base-300 rounded-box overflow-x-auto border">
                <table className="table-sm table">
                    <thead>
                        <tr className="bg-base-200">
                            <th scope="col" className="border-base-300 text-base-content/70 border text-xs font-medium capitalize">
                                Price
                            </th>
                            <th scope="col" className="border-base-300 text-base-content/70 border text-xs font-medium capitalize">
                                Unit Price
                            </th>
                            <th scope="col" className="border-base-300 text-base-content/70 border text-xs font-medium capitalize">
                                Recommended Quotation Is
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr className="bg-base-200/50">
                            <th scope="row" className="border-base-300 border font-normal">
                                <span className="font-medium capitalize">Recommended Quotation</span>
                                <span className="text-base-content/70 block text-xs">{supplier || 'None chosen yet'}</span>
                            </th>
                            <td className="border-base-300 border font-semibold tabular-nums">{quoted ? money(quoted) : '—'}</td>
                            <td className="border-base-300 text-base-content/70 border text-sm">The reference</td>
                        </tr>
                        {priceLine('tender_price', 'Tender Price', 'On the tender')}
                        {priceLine('previous_price', 'Previous Price', 'Paid last time')}
                    </tbody>
                </table>
            </div>
        </fieldset>
    );
}

/** "12.50" as higher (warning), "-3.00" as lower (success), "0.00" as the same; a dash until both prices are in. */
function ChangeBadge({ change }: { change: string | null }) {
    if (change === null) {
        return <span className="text-base-content/70">—</span>;
    }
    const negative = change.startsWith('-');
    const same = /^-?0\.00$/.test(change);
    const size = negative ? change.slice(1) : change;

    return (
        <span className={cn('badge badge-soft badge-sm tabular-nums', same ? 'badge-ghost' : negative ? 'badge-success' : 'badge-warning')}>
            {same ? 'The same' : `${size}% ${negative ? 'lower' : 'higher'}`}
        </span>
    );
}
