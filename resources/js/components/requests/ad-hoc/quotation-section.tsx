import { EntryCardRow, EntryTable, type EntryColumn } from '@/components/ui/entry-table';
import { collectRowErrors, EntryCheckGroup, EntryInlineCheck, EntryInput } from './entry-fields';
import { FieldGrid, Figure, GridRow, RadioGroupField, TextAreaField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { PriceSection } from './price-section';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * FORM-COMPACT-1 (2): each quotation is a small card in two lines (the
 * fit test reads `line1` and `line2`): Supplier, Quantity, Unit Price, Lead
 * Time (Days) and the three checks; then Brand, Manufacturer, Country of
 * Origin and Remarks. Each line is one row once the card is 54rem wide (every
 * field at its minimum, EntryTableFitTest); narrower, its fields wrap.
 */
const line1: EntryColumn[] = [
    { key: 'supplier_name', label: 'Supplier', width: 'md' },
    { key: 'quantity_offered', label: 'Quantity', width: 'xs' },
    { key: 'unit_price', label: 'Unit Price', width: 'xs' },
    { key: 'lead_time_days', label: 'Lead Time (Days)', width: 'xs' },
    { key: 'checks', label: 'Considered, Recommended and Ex Stock', width: 'checks' },
];

const line2: EntryColumn[] = [
    { key: 'brand', label: 'Brand', width: 'md' },
    { key: 'manufacturer', label: 'Manufacturer', width: 'md' },
    { key: 'country_of_origin', label: 'Country of Origin', width: 'md' },
    { key: 'remarks', label: 'Remarks', width: 'lg' },
];

/** Every message of an entry in one list under it, in the order the card shows the fields. */
const ERROR_ORDER = [
    'id',
    'supplier_name',
    'quantity_offered',
    'unit_price',
    'lead_time_days',
    'considered',
    'is_recommended',
    'ex_stock',
    'brand',
    'manufacturer',
    'country_of_origin',
    'remarks',
    'reason_accepted_excluded',
];

/**
 * V2-2 section "Quotation comparison": who received the RFQ, one card per
 * quotation (a reason under the card's fields only when the quotation was
 * not considered), the quantity to purchase and the server's total cost.
 * The optional price comparison (fields of this section on the server)
 * follows. Presentation only: the server still requires the reason on a
 * row that is not considered, and one recommended quotation.
 */
export function QuotationSection({ api }: { api: FormApi }) {
    const { state, config, form } = api;
    const unit = text(state.requisition.unit_of_measure).trim();
    const rows = state.rows.quotations;
    const optional = (name: string) => api.optional('quotation_comparison', name);
    const cap = config.row_caps.quotations;
    const tableError = [api.tableError('quotations'), api.tableError('quotations.recommended')].filter(Boolean).join(' ');

    const columns = [...line1, ...line2];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;
    const width = (key: string) => columns.find((c) => c.key === key)?.width;

    return (
        <FieldGrid>
            <RadioGroupField
                width="md"
                id={fieldId.requisition('rfq_audience')}
                legend={'RFQ Sent To'}
                options={config.options.rfq_audience}
                value={text(state.requisition.rfq_audience)}
                onChange={(value) => api.setField('rfq_audience', value)}
                error={api.fieldError('rfq_audience')}
            />

            <GridRow>
                <div id={fieldId.table('quotations')} tabIndex={-1} className="grid gap-2 outline-none">
                    <p className="text-muted-foreground text-[13px]">
                        Choose one recommended quotation. Give a reason for any quotation not considered.
                    </p>
                    {tableError && <p className="text-destructive text-sm">{tableError}</p>}
                    <EntryTable
                        caption="Quotations Received"
                        columns={columns}
                        breakpoint="54rem"
                        variant="cards"
                        empty={rows.length === 0 && <RowsNote>No quotations yet.</RowsNote>}
                        addAction={
                            <AddRowButton label="Add Quotation" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('quotations')} />
                        }
                    >
                        {rows.map((row, index) => {
                            const rowLabel = `Quotation Row ${index + 1}`;
                            const id = (name: string) => fieldId.cell('quotations', row.key, name);
                            // FORM-ERR-1 (A): every message of the entry in one list, at the bottom of its card.
                            const errorsId = fieldId.rowErrors('quotations', row.key);
                            const errors = collectRowErrors(ERROR_ORDER, (name) => api.cellError('quotations', row, index, name));
                            const input = (name: string, kind: 'text' | 'decimal' | 'integer' = 'text', maxLength?: number) => (
                                <EntryInput
                                    id={id(name)}
                                    label={label(name)}
                                    rowLabel={rowLabel}
                                    kind={kind}
                                    maxLength={maxLength}
                                    width={width(name)}
                                    value={text(row.values[name])}
                                    onChange={(value) => api.setCell('quotations', row.key, name, value)}
                                    error={api.cellError('quotations', row, index, name)}
                                    errorListId={errorsId}
                                    required={optional(`quotations.${name}`) === false}
                                />
                            );
                            const considered = row.values.considered === true;

                            return (
                                <EntryCardRow
                                    key={row.key}
                                    label={rowLabel}
                                    errors={errors}
                                    errorsId={errorsId}
                                    remove={
                                        <RemoveRowButton
                                            label={`Remove Quotation Row ${index + 1}`}
                                            onClick={() => api.removeRow('quotations', row.key)}
                                        />
                                    }
                                    lines={[
                                        <>
                                            {input('supplier_name', 'text', 200)}
                                            {input('quantity_offered', 'decimal')}
                                            {input('unit_price', 'decimal')}
                                            {input('lead_time_days', 'integer')}
                                            <EntryCheckGroup label={`Choices, ${rowLabel}`}>
                                                <EntryInlineCheck
                                                    id={id('considered')}
                                                    label="Considered"
                                                    rowLabel={rowLabel}
                                                    type="checkbox"
                                                    checked={considered}
                                                    onChange={(checked) => api.setCell('quotations', row.key, 'considered', checked)}
                                                />
                                                <EntryInlineCheck
                                                    id={id('is_recommended')}
                                                    label="Recommended"
                                                    rowLabel={rowLabel}
                                                    type="radio"
                                                    name="recommended-quotation"
                                                    checked={row.values.is_recommended === true}
                                                    onChange={() => api.chooseRecommended(row.key)}
                                                />
                                                <EntryInlineCheck
                                                    id={id('ex_stock')}
                                                    label="Ex Stock"
                                                    rowLabel={rowLabel}
                                                    type="checkbox"
                                                    checked={row.values.ex_stock === true}
                                                    onChange={(checked) => api.setCell('quotations', row.key, 'ex_stock', checked)}
                                                />
                                            </EntryCheckGroup>
                                        </>,
                                        <>
                                            {input('brand', 'text', 200)}
                                            {input('manufacturer', 'text', 200)}
                                            {input('country_of_origin', 'text', 100)}
                                            {input('remarks', 'text', 255)}
                                        </>,
                                    ]}
                                    below={
                                        // Only for a quotation that was not considered; no space otherwise.
                                        !considered && (
                                            <TextAreaField
                                                width="full"
                                                id={id('reason_accepted_excluded')}
                                                label="Reason Quotation Wasn't Considered"
                                                srLabelSuffix={`, ${rowLabel}`}
                                                rows={2}
                                                maxLength={config.max_lengths.reason_accepted_excluded}
                                                value={text(row.values.reason_accepted_excluded)}
                                                onChange={(value) => api.setCell('quotations', row.key, 'reason_accepted_excluded', value)}
                                                error={api.cellError('quotations', row, index, 'reason_accepted_excluded')}
                                                errorListId={errorsId}
                                                optional={optional('quotations.reason_accepted_excluded')}
                                                hint={`Up to ${config.max_lengths.reason_accepted_excluded} characters.`}
                                            />
                                        )
                                    }
                                />
                            );
                        })}
                    </EntryTable>
                </div>
            </GridRow>

            <TextField
                width="xs"
                id={fieldId.requisition('quantity_to_purchase')}
                label="Quantity to Purchase"
                optional={optional('quantity_to_purchase')}
                inputMode="decimal"
                value={text(state.requisition.quantity_to_purchase)}
                onChange={(value) => api.setField('quantity_to_purchase', value)}
                error={api.fieldError('quantity_to_purchase')}
                suffix={unit || undefined}
            />
            <GridRow width="md">
                <dl>
                    <Figure width="full" label="Total Cost (Recommended Quotation)" value={form.display.total_cost} />
                </dl>
                <p className="text-muted-foreground mt-1 text-[13px]">The total is worked out by the system and updates after each save.</p>
            </GridRow>

            <GridRow>
                <PriceSection api={api} />
            </GridRow>
        </FieldGrid>
    );
}
