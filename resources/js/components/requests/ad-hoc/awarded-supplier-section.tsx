import { EntryRow, EntryTable, type EntryColumn, type EntryWidth } from '@/components/ui/entry-table';
import { sumPercent } from '@/lib/quotation-price';
import { collectRowErrors, EntryInput, EntryValue } from './entry-fields';
import { TableError } from './fields';
import { rowDisplay, text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * V2-2 section "Awarded supplier": one entry per supplier. Kyle, 2026-11-24:
 * % Awarded is typed (optional, 0 to 100, up to 2 decimals; the server checks
 * it). The line cost is the server's (shown once the row is saved); the total
 * is at the foot. A quantity awarded above the quantity requested is reported
 * on its Qty Awarded cell. Pack Size is optional free text (2026-11-25).
 *
 * Kyle, 2026-11-25: Line Cost is now Total Cost. Qty Awarded and % Awarded are
 * narrow fixed columns, so each supplier is one line from a 72rem card (was
 * 88rem; narrower, each entry wraps). The foot reads "TOTAL:" and
 * "TOTAL AWARD:" in capitals, and asks the vendor to check the % Awarded when
 * the entered percentages do not add up to 100%. A prompt only: the server
 * does not require 100%.
 */
export function AwardedSupplierSection({ api }: { api: FormApi }) {
    const rows = api.state.rows.tender_awards;
    const optional = (name: string) => api.optional('awarded_supplier', `tender_awards.${name}`);
    const cap = api.config.row_caps.tender_awards;
    const tableError = api.tableError('tender_awards');

    const columns: EntryColumn[] = [
        { key: 'supplier_name', label: 'Supplier' },
        { key: 'manufacturer', label: 'Manufacturer' },
        { key: 'brand', label: 'Brand' },
        { key: 'country_of_origin', label: 'Country of Origin' },
        { key: 'pack_size', label: 'Pack Size' },
        { key: 'quantity_awarded', label: 'Qty Awarded', width: 'num' },
        { key: 'unit_cost', label: 'Unit Cost', width: 'xs' },
        { key: 'percentage_awarded', label: '% Awarded', width: 'num' },
        { key: 'line_cost', label: 'Total Cost', width: 'value' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;
    const widthOf = (key: string): EntryWidth => columns.find((c) => c.key === key)?.width ?? 'md';
    // Live, as the vendor types: null until some % Awarded is entered.
    const awarded = sumPercent(rows.map((row) => text(row.values.percentage_awarded)));
    const awardedText = awarded === null ? '—' : `${awarded.replace(/\.00$/, '')}%`;

    return (
        <div id={fieldId.table('tender_awards')} tabIndex={-1} className="grid gap-2 outline-none">
            <TableError message={tableError || undefined} />
            <EntryTable
                caption="Awarded Suppliers"
                columns={columns}
                breakpoint="72rem"
                narrowRowLabel
                empty={rows.length === 0 && <RowsNote>No awarded supplier yet.</RowsNote>}
                foot={
                    rows.length > 0 && (
                        <div className="border-base-300 grid gap-2 border-t px-4 py-2 text-sm">
                            <dl className="flex flex-wrap justify-end gap-x-6 gap-y-1 font-bold uppercase">
                                <div className="flex gap-2">
                                    <dt>Total:</dt>
                                    <dd className="tabular-nums">{api.form.display.awards_total}</dd>
                                </div>
                                <div className="flex gap-2">
                                    <dt>Total Award:</dt>
                                    <dd className="tabular-nums">{awardedText}</dd>
                                </div>
                            </dl>
                            {awarded !== null && awarded !== '100.00' && (
                                <div role="status" className="alert alert-warning alert-soft py-2 text-sm">
                                    <span>The % Awarded adds up to {awardedText}, not 100%. Check the % Awarded for each supplier.</span>
                                </div>
                            )}
                        </div>
                    )
                }
                addAction={<AddRowButton label="Add Supplier" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('tender_awards')} />}
            >
                {rows.map((row, index) => {
                    const rowLabel = `Supplier Row ${index + 1}`;
                    const shown = rowDisplay(api.form.display.rows.tender_awards, row.id);
                    // FORM-ERR-1 (A): every message of the row in one list under it, in column order.
                    const errorsId = fieldId.rowErrors('tender_awards', row.key);
                    const errors = collectRowErrors(['id', ...columns.map((c) => c.key)], (name) => api.cellError('tender_awards', row, index, name));
                    const input = (name: string, kind: 'text' | 'decimal' = 'text', maxLength?: number) => (
                        <EntryInput
                            id={fieldId.cell('tender_awards', row.key, name)}
                            label={label(name)}
                            rowLabel={rowLabel}
                            kind={kind}
                            width={widthOf(name)}
                            maxLength={maxLength}
                            value={text(row.values[name])}
                            onChange={(value) => api.setCell('tender_awards', row.key, name, value)}
                            error={api.cellError('tender_awards', row, index, name)}
                            errorListId={errorsId}
                            required={optional(name) === false}
                        />
                    );

                    return (
                        <EntryRow
                            key={row.key}
                            label={rowLabel}
                            short={String(index + 1)}
                            errors={errors}
                            errorsId={errorsId}
                            remove={
                                <RemoveRowButton label={`Remove Supplier Row ${index + 1}`} onClick={() => api.removeRow('tender_awards', row.key)} />
                            }
                        >
                            {input('supplier_name', 'text', 200)}
                            {input('manufacturer', 'text', 200)}
                            {input('brand', 'text', 200)}
                            {input('country_of_origin', 'text', 100)}
                            {input('pack_size', 'text', 100)}
                            {input('quantity_awarded', 'decimal')}
                            {input('unit_cost', 'decimal')}
                            {input('percentage_awarded', 'decimal')}
                            <EntryValue label="Total Cost" rowLabel={rowLabel} value={shown?.line_cost ?? '—'} />
                        </EntryRow>
                    );
                })}
            </EntryTable>
        </div>
    );
}
