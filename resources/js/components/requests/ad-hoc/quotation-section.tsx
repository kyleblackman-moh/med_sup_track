import { EntryRow, EntryTable } from '@/components/ui/entry-table';
import { columnLabel, EntryCheck, EntryInput } from './entry-fields';
import { Figure, RadioGroupField, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { PriceSection } from './price-section';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * V2-2 section "Quotation comparison": who received the RFQ, one entry per
 * quotation (Considered and Recommended first; a reason under the row only
 * when the quotation was not considered), the quantity to purchase and the
 * server's total cost. Eleven inputs a row, so the one-line layout starts at
 * xl (1280px); below that each entry wraps. The optional price comparison
 * (fields of this section on the server) follows.
 */
export function QuotationSection({ api }: { api: FormApi }) {
    const { state, config, form } = api;
    const unit = text(state.requisition.unit_of_measure).trim();
    const rows = state.rows.quotations;
    const optional = (name: string) => api.optional('quotation_comparison', name);
    const cap = config.row_caps.quotations;
    const tableError = [api.tableError('quotations'), api.tableError('quotations.recommended')].filter(Boolean).join(' ');

    const columns = [
        { key: 'considered', label: 'Considered', className: 'xl:w-20 xl:flex-none' },
        { key: 'is_recommended', label: 'Recommended', className: 'xl:w-24 xl:flex-none' },
        { key: 'supplier_name', label: columnLabel('Supplier', optional('quotations.supplier_name')) },
        { key: 'quantity_offered', label: columnLabel('Quantity', optional('quotations.quantity_offered')) },
        { key: 'unit_price', label: columnLabel('Unit price', optional('quotations.unit_price')) },
        { key: 'ex_stock', label: 'Ex stock', className: 'xl:w-16 xl:flex-none' },
        { key: 'lead_time_days', label: columnLabel('Lead time (days)', optional('quotations.lead_time_days')) },
        { key: 'brand', label: columnLabel('Brand', optional('quotations.brand')) },
        { key: 'manufacturer', label: columnLabel('Manufacturer', optional('quotations.manufacturer')) },
        { key: 'country_of_origin', label: columnLabel('Country of origin', optional('quotations.country_of_origin')) },
        { key: 'remarks', label: columnLabel('Remarks', optional('quotations.remarks')), className: 'xl:flex-[2]' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;

    return (
        <div className="grid grid-cols-1 gap-6">
            <RadioGroupField
                id={fieldId.requisition('rfq_audience')}
                legend={columnLabel('RFQ sent to', optional('rfq_audience'))}
                options={config.options.rfq_audience}
                value={text(state.requisition.rfq_audience)}
                onChange={(value) => api.setField('rfq_audience', value)}
                error={api.fieldError('rfq_audience')}
            />

            <div id={fieldId.table('quotations')} tabIndex={-1} className="grid gap-2 outline-none">
                <p className="text-muted-foreground text-xs">Choose one recommended quotation. Give a reason for any quotation not considered.</p>
                {tableError && <p className="text-destructive text-sm">{tableError}</p>}
                <EntryTable
                    caption="Quotations received"
                    columns={columns}
                    breakpoint="xl"
                    narrowRowLabel
                    empty={rows.length === 0 && <RowsNote>No quotations yet.</RowsNote>}
                    addAction={
                        <AddRowButton label="Add quotation" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('quotations')} />
                    }
                >
                    {rows.map((row, index) => {
                        const rowLabel = `Quotation row ${index + 1}`;
                        const id = (name: string) => fieldId.cell('quotations', row.key, name);
                        const input = (name: string, kind: 'text' | 'decimal' | 'integer' = 'text', maxLength?: number, className?: string) => (
                            <EntryInput
                                id={id(name)}
                                label={label(name)}
                                rowLabel={rowLabel}
                                kind={kind}
                                maxLength={maxLength}
                                className={className}
                                value={text(row.values[name])}
                                onChange={(value) => api.setCell('quotations', row.key, name, value)}
                                error={api.cellError('quotations', row, index, name)}
                            />
                        );
                        const considered = row.values.considered === true;

                        return (
                            <EntryRow
                                key={row.key}
                                label={rowLabel}
                                short={String(index + 1)}
                                remove={
                                    <RemoveRowButton
                                        label={`Remove quotation row ${index + 1}`}
                                        onClick={() => api.removeRow('quotations', row.key)}
                                    />
                                }
                                below={
                                    !considered && (
                                        <TextAreaField
                                            id={id('reason_accepted_excluded')}
                                            label={`Reason quotation ${index + 1} was not considered`}
                                            rows={2}
                                            maxLength={config.max_lengths.reason_accepted_excluded}
                                            value={text(row.values.reason_accepted_excluded)}
                                            onChange={(value) => api.setCell('quotations', row.key, 'reason_accepted_excluded', value)}
                                            error={api.cellError('quotations', row, index, 'reason_accepted_excluded')}
                                            hint={`Up to ${config.max_lengths.reason_accepted_excluded} characters.`}
                                        />
                                    )
                                }
                            >
                                <EntryCheck
                                    id={id('considered')}
                                    label="Considered"
                                    rowLabel={rowLabel}
                                    type="checkbox"
                                    checked={considered}
                                    onChange={(checked) => api.setCell('quotations', row.key, 'considered', checked)}
                                    className="xl:w-20 xl:flex-none"
                                />
                                <EntryCheck
                                    id={id('is_recommended')}
                                    label="Recommended"
                                    rowLabel={rowLabel}
                                    type="radio"
                                    name="recommended-quotation"
                                    checked={row.values.is_recommended === true}
                                    onChange={() => api.chooseRecommended(row.key)}
                                    className="xl:w-24 xl:flex-none"
                                />
                                {input('supplier_name', 'text', 200)}
                                {input('quantity_offered', 'decimal')}
                                {input('unit_price', 'decimal')}
                                <EntryCheck
                                    id={id('ex_stock')}
                                    label="Ex stock"
                                    rowLabel={rowLabel}
                                    type="checkbox"
                                    checked={row.values.ex_stock === true}
                                    onChange={(checked) => api.setCell('quotations', row.key, 'ex_stock', checked)}
                                    className="xl:w-16 xl:flex-none"
                                />
                                {input('lead_time_days', 'integer')}
                                {input('brand', 'text', 200)}
                                {input('manufacturer', 'text', 200)}
                                {input('country_of_origin', 'text', 100)}
                                {input('remarks', 'text', 255, 'xl:flex-[2]')}
                            </EntryRow>
                        );
                    })}
                </EntryTable>
            </div>

            <div className="grid items-start gap-4 md:grid-cols-2">
                <TextField
                    id={fieldId.requisition('quantity_to_purchase')}
                    label="Quantity to purchase"
                    optional={optional('quantity_to_purchase')}
                    inputMode="decimal"
                    value={text(state.requisition.quantity_to_purchase)}
                    onChange={(value) => api.setField('quantity_to_purchase', value)}
                    error={api.fieldError('quantity_to_purchase')}
                    suffix={unit || undefined}
                />
                <dl>
                    <Figure label="Total cost (recommended quotation)" value={form.display.total_cost} />
                </dl>
            </div>
            <p className="text-muted-foreground -mt-4 text-xs">The total is worked out by the system and updates after each save.</p>

            <PriceSection api={api} />
        </div>
    );
}
