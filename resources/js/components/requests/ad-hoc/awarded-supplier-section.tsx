import { EntryRow, EntryTable, type EntryColumn } from '@/components/ui/entry-table';
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
 * on its Quantity Awarded cell. Pack Size is optional free text (2026-11-25). One line per entry once the card is 88rem wide (FORM-ERR-1); narrower, each entry wraps.
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
        { key: 'quantity_awarded', label: 'Quantity Awarded', width: 'xs' },
        { key: 'unit_cost', label: 'Unit Cost', width: 'xs' },
        { key: 'percentage_awarded', label: '% Awarded', width: 'xs' },
        { key: 'total_cost', label: 'Total Cost', width: 'value' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;

    return (
        <div id={fieldId.table('tender_awards')} tabIndex={-1} className="grid gap-2 outline-none">
            <TableError message={tableError || undefined} />
            <EntryTable
                caption="Awarded Suppliers"
                columns={columns}
                breakpoint="88rem"
                narrowRowLabel
                empty={rows.length === 0 && <RowsNote>No awarded supplier yet.</RowsNote>}
                foot={
                    rows.length > 0 && (
                        <p className="border-base-300 flex justify-end gap-2 border-t px-4 py-2 text-sm">
                            <span className="text-base-content/70 capitalize">Total</span>
                            <span className="font-semibold tabular-nums">{api.form.display.awards_total}</span>
                        </p>
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
                            width={kind === 'decimal' ? 'xs' : 'md'}
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
                            {input('quantity_awarded', 'decimal')}
                            {input('unit_cost', 'decimal')}
                            {input('percentage_awarded', 'decimal')}
                            <EntryValue label="Total Cost" rowLabel={rowLabel} value={shown?.total_cost ?? '—'} />
                        </EntryRow>
                    );
                })}
            </EntryTable>
        </div>
    );
}
