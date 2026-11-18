import { EntryRow, EntryTable } from '@/components/ui/entry-table';
import { columnLabel, EntryInput, EntryValue } from './entry-fields';
import { rowDisplay, text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * V2-2 section "Awarded supplier": one entry per supplier. % awarded and the
 * line cost are the server's (shown once the row is saved); the total is at
 * the foot. One line per entry from xl (V2-3); below that each entry wraps.
 */
export function AwardedSupplierSection({ api }: { api: FormApi }) {
    const rows = api.state.rows.tender_awards;
    const optional = (name: string) => api.optional('awarded_supplier', `tender_awards.${name}`);
    const cap = api.config.row_caps.tender_awards;
    const tableError = api.tableError('tender_awards');

    const columns = [
        { key: 'supplier_name', label: columnLabel('Supplier', optional('supplier_name')) },
        { key: 'manufacturer', label: columnLabel('Manufacturer', optional('manufacturer')) },
        { key: 'brand', label: columnLabel('Brand', optional('brand')) },
        { key: 'country_of_origin', label: columnLabel('Country of origin', optional('country_of_origin')) },
        { key: 'quantity_awarded', label: columnLabel('Quantity awarded', optional('quantity_awarded')) },
        { key: 'unit_cost', label: columnLabel('Unit cost', optional('unit_cost')) },
        { key: 'percentage', label: '% awarded', className: 'xl:w-20 xl:flex-none' },
        { key: 'line_cost', label: 'Line cost', className: 'xl:w-24 xl:flex-none' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;

    return (
        <div id={fieldId.table('tender_awards')} tabIndex={-1} className="grid gap-2 outline-none">
            {tableError && <p className="text-destructive text-sm">{tableError}</p>}
            <EntryTable
                caption="Awarded suppliers"
                columns={columns}
                breakpoint="xl"
                narrowRowLabel
                empty={rows.length === 0 && <RowsNote>No awarded supplier yet.</RowsNote>}
                foot={
                    rows.length > 0 && (
                        <p className="flex justify-end gap-2 border-t px-4 py-2 text-sm">
                            <span className="text-muted-foreground">Total</span>
                            <span className="font-medium tabular-nums">{api.form.display.awards_total}</span>
                        </p>
                    )
                }
                addAction={<AddRowButton label="Add supplier" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('tender_awards')} />}
            >
                {rows.map((row, index) => {
                    const rowLabel = `Supplier row ${index + 1}`;
                    const shown = rowDisplay(api.form.display.rows.tender_awards, row.id);
                    const input = (name: string, kind: 'text' | 'decimal' = 'text', maxLength?: number) => (
                        <EntryInput
                            id={fieldId.cell('tender_awards', row.key, name)}
                            label={label(name)}
                            rowLabel={rowLabel}
                            kind={kind}
                            maxLength={maxLength}
                            value={text(row.values[name])}
                            onChange={(value) => api.setCell('tender_awards', row.key, name, value)}
                            error={api.cellError('tender_awards', row, index, name)}
                        />
                    );

                    return (
                        <EntryRow
                            key={row.key}
                            label={rowLabel}
                            short={String(index + 1)}
                            remove={
                                <RemoveRowButton label={`Remove supplier row ${index + 1}`} onClick={() => api.removeRow('tender_awards', row.key)} />
                            }
                        >
                            {input('supplier_name', 'text', 200)}
                            {input('manufacturer', 'text', 200)}
                            {input('brand', 'text', 200)}
                            {input('country_of_origin', 'text', 100)}
                            {input('quantity_awarded', 'decimal')}
                            {input('unit_cost', 'decimal')}
                            <EntryValue label="% awarded" rowLabel={rowLabel} value={shown?.percentage ?? '—'} className="xl:w-20 xl:flex-none" />
                            <EntryValue label="Line cost" rowLabel={rowLabel} value={shown?.line_cost ?? '—'} className="xl:w-24 xl:flex-none" />
                        </EntryRow>
                    );
                })}
            </EntryTable>
        </div>
    );
}
