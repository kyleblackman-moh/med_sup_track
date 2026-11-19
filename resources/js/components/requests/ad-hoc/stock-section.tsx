import { EntryRow, EntryTable, type EntryColumn } from '@/components/ui/entry-table';
import { type FormExpiry } from '@/types/ad-hoc';
import { TriangleAlert } from 'lucide-react';
import { collectRowErrors, EntryInput } from './entry-fields';
import { CheckboxField, Figure, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

/**
 * V2-2 section "Stock". The unit of measure from section 1 is shown beside the
 * quantities. "No Stock on Hand" hides the batches (the server then ignores
 * them). The stock total, days on hand and the expiry warning are the
 * server's; the warning never blocks a submit.
 */
export function StockSection({ api }: { api: FormApi }) {
    const { state, config, form } = api;
    const unit = text(state.requisition.unit_of_measure).trim();
    const noStock = state.requisition.no_stock_on_hand === true;
    const rows = state.rows.stock_expiries;
    const optional = (name: string) => api.optional('stock', name);
    const cap = config.row_caps.stock_expiries;
    const tableError = api.tableError('stock_expiries');

    const field = (name: string) => ({
        id: fieldId.requisition(name),
        value: text(state.requisition[name]),
        onChange: (value: string) => api.setField(name, value),
        error: api.fieldError(name),
        optional: optional(name),
    });

    const columns: EntryColumn[] = [
        { key: 'quantity', label: unit ? `Quantity (${unit})` : 'Quantity', width: 'number' },
        { key: 'expiry_date', label: 'Expiry Date', width: 'date' },
    ];

    return (
        <div className="grid grid-cols-1 gap-5">
            <TextField {...field('stock_location')} label="Location" maxLength={200} hint="Where the stock is held, for example Central stores." />

            <div className="grid gap-5 md:grid-cols-2">
                <TextField
                    {...field('average_monthly_consumption')}
                    label="Average Monthly Consumption"
                    inputMode="decimal"
                    hint="Enter 0 if the item has not been used."
                    suffix={unit || undefined}
                />
                <TextField {...field('quantity_on_order')} label="Quantity on Order" inputMode="decimal" suffix={unit || undefined} />
            </div>

            <CheckboxField
                id={fieldId.requisition('no_stock_on_hand')}
                label="No Stock on Hand"
                checked={noStock}
                onChange={(checked) => api.setField('no_stock_on_hand', checked)}
                hint="Tick this instead of listing stock batches."
            />

            {!noStock && (
                <div id={fieldId.table('stock_expiries')} tabIndex={-1} className="grid gap-2 outline-none">
                    {tableError && <p className="text-destructive text-sm">{tableError}</p>}
                    <EntryTable
                        caption="Stock Batches"
                        breakpoint="32rem"
                        columns={columns}
                        empty={rows.length === 0 && <RowsNote>No batches yet.</RowsNote>}
                        addAction={
                            <AddRowButton label="Add Batch" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('stock_expiries')} />
                        }
                    >
                        {rows.map((row, index) => {
                            const rowLabel = `Batch ${index + 1}`;
                            // FORM-ERR-1 (A): every message of the row in one list under it, in column order.
                            const errorsId = fieldId.rowErrors('stock_expiries', row.key);
                            const errors = collectRowErrors(['id', ...columns.map((c) => c.key)], (name) =>
                                api.cellError('stock_expiries', row, index, name),
                            );

                            return (
                                <EntryRow
                                    key={row.key}
                                    label={rowLabel}
                                    errors={errors}
                                    errorsId={errorsId}
                                    remove={
                                        <RemoveRowButton
                                            label={`Remove batch ${index + 1}`}
                                            onClick={() => api.removeRow('stock_expiries', row.key)}
                                        />
                                    }
                                >
                                    {columns.map((column) => (
                                        <EntryInput
                                            key={column.key}
                                            id={fieldId.cell('stock_expiries', row.key, column.key)}
                                            label={column.label}
                                            rowLabel={rowLabel}
                                            kind={column.key === 'expiry_date' ? 'date' : 'decimal'}
                                            width={column.width}
                                            value={text(row.values[column.key])}
                                            onChange={(value) => api.setCell('stock_expiries', row.key, column.key, value)}
                                            error={api.cellError('stock_expiries', row, index, column.key)}
                                            errorListId={errorsId}
                                            required={optional(`stock_expiries.${column.key}`) === false}
                                        />
                                    ))}
                                </EntryRow>
                            );
                        })}
                    </EntryTable>
                </div>
            )}

            <dl className="grid gap-5 sm:grid-cols-2">
                <Figure label="Stock Total" value={form.display.stock_total} suffix={unit || undefined} />
                <Figure label="Days on Hand" value={form.expiry.days_on_hand} />
            </dl>

            <ExpiryBanner expiry={form.expiry} />
        </div>
    );
}

/** The expiry warning (warning tone, not blocking). Every word of it comes from the server's items. */
function ExpiryBanner({ expiry }: { expiry: FormExpiry }) {
    if (expiry.at_risk.length === 0 && expiry.expired.length === 0) {
        return null;
    }

    return (
        <div
            role="status"
            className="bg-status-warning text-status-warning-foreground border-status-warning-foreground/30 flex gap-3 rounded-lg border p-4 text-sm"
        >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div className="space-y-1">
                <p className="font-semibold">Some stock may expire before it is used</p>
                <ul className="space-y-0.5">
                    {expiry.at_risk.map((item) => (
                        <li key={`risk-${item.batch}`}>
                            Batch expiring {item.expiry_date}: about {item.cover_days} days of cover, expires in {item.days_to_expiry} days
                        </li>
                    ))}
                    {expiry.expired.map((item) => (
                        <li key={`expired-${item.batch}`}>Batch expiring {item.expiry_date} has expired</li>
                    ))}
                </ul>
                <p>You can still submit this request.</p>
            </div>
        </div>
    );
}
