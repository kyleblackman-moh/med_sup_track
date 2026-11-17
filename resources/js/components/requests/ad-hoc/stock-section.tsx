import { CheckboxField, Figure, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { RowTable, type Column } from './row-table';

/**
 * Section 3, Stock. The unit of measure from section 1 is shown as static text
 * next to every quantity: one unit for the item. "No stock on hand" hides the
 * batches (the server then ignores them).
 */
export function StockSection({ api }: { api: FormApi }) {
    const { state, detail, config } = api;
    const unit = text(state.requisition.unit_of_measure).trim();
    const noStock = state.requisition.no_stock_on_hand === true;

    const columns: Column[] = [
        { name: 'quantity', label: unit ? `Quantity (${unit})` : 'Quantity', kind: 'decimal', width: 'min-w-32' },
        { name: 'expiry_date', label: 'Expiry date', kind: 'date', width: 'min-w-36' },
    ];

    return (
        <div className="grid gap-6">
            <TextField
                id={fieldId.requisition('stock_location')}
                label="Location"
                optional
                maxLength={200}
                value={text(state.requisition.stock_location)}
                onChange={(value) => api.setField('stock_location', value)}
                error={api.fieldError('stock_location')}
                hint="Where the stock is held, for example Central stores."
            />

            <TextField
                id={fieldId.requisition('average_monthly_consumption')}
                label="Average monthly consumption"
                inputMode="decimal"
                value={text(state.requisition.average_monthly_consumption)}
                onChange={(value) => api.setField('average_monthly_consumption', value)}
                error={api.fieldError('average_monthly_consumption')}
                hint="Enter 0 if the item has not been used."
                suffix={unit || undefined}
                className="max-w-sm"
            />

            <CheckboxField
                id={fieldId.requisition('no_stock_on_hand')}
                label="No stock on hand"
                checked={noStock}
                onChange={(checked) => api.setField('no_stock_on_hand', checked)}
                hint="Tick this instead of listing stock batches."
            />

            {!noStock && (
                <RowTable
                    section="stock_expiries"
                    title="Stock batches"
                    rowLabel="Batch"
                    addLabel="Add batch"
                    rows={state.rows.stock_expiries}
                    columns={columns}
                    cap={config.row_caps.stock_expiries}
                    onChange={(key, name, value) => api.setCell('stock_expiries', key, name, value)}
                    onAdd={() => api.addRow('stock_expiries')}
                    onRemove={(key) => api.removeRow('stock_expiries', key)}
                    cellError={(row, index, name) => api.cellError('stock_expiries', row, index, name)}
                    tableError={api.tableError('stock_expiries')}
                />
            )}

            <dl>
                <Figure label="Stock on hand" value={detail.requisition.derived.stock_on_hand} suffix={unit || undefined} />
            </dl>
        </div>
    );
}
