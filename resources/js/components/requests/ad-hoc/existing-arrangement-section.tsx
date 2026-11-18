import { EntryCell, EntryRow, EntryTable } from '@/components/ui/entry-table';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { CellError, columnLabel, EntryInput, EntryValue } from './entry-fields';
import { controlHeight, TextAreaField } from './fields';
import { rowDisplay, text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { AddRowButton, RemoveRowButton, RowsNote } from './row-controls';

const selectClass =
    'border-input bg-background focus-visible:ring-ring ring-offset-background w-full min-w-0 rounded-md border px-3 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm';

/**
 * V2-2 section "Existing approved arrangement": the orders placed against the
 * awarded suppliers (each picks its supplier from the SAVED award rows, which
 * have an id), the server's quantity outstanding per order, and one text box
 * for issues affecting the tender quantity.
 */
export function ExistingArrangementSection({ api }: { api: FormApi }) {
    const rows = api.state.rows.purchase_orders;
    const awards = api.state.rows.tender_awards;
    const choosable = awards.filter((row) => row.id !== null && text(row.values.supplier_name).trim() !== '');
    const awaitingSave = awards.some((row) => row.id === null && text(row.values.supplier_name).trim() !== '');
    const optional = (name: string) => api.optional('existing_arrangement', name);
    const cap = api.config.row_caps.purchase_orders;
    const tableError = api.tableError('purchase_orders');

    const columns = [
        { key: 'award_id', label: columnLabel('Supplier', optional('purchase_orders.award_id')) },
        { key: 'po_number', label: columnLabel('PO number', optional('purchase_orders.po_number')) },
        { key: 'quantity_ordered', label: columnLabel('Quantity ordered', optional('purchase_orders.quantity_ordered')) },
        { key: 'ordered_on', label: columnLabel('Date ordered', optional('purchase_orders.ordered_on')) },
        { key: 'quantity_delivered', label: columnLabel('Quantity delivered', optional('purchase_orders.quantity_delivered')) },
        { key: 'delivery', label: columnLabel('Delivery date / status', optional('purchase_orders.delivery_status')) },
        { key: 'quantity_outstanding', label: 'Quantity outstanding', className: 'lg:w-24 lg:flex-none' },
    ];
    const label = (key: string) => columns.find((c) => c.key === key)?.label ?? key;

    return (
        <div className="grid grid-cols-1 gap-5">
            <div id={fieldId.table('purchase_orders')} tabIndex={-1} className="grid gap-2 outline-none">
                <p className="text-muted-foreground text-[13px]">One supplier can have several orders.</p>
                {tableError && <p className="text-destructive text-sm">{tableError}</p>}
                <EntryTable
                    caption="Orders"
                    columns={columns}
                    narrowRowLabel
                    empty={rows.length === 0 && <RowsNote>No orders yet.</RowsNote>}
                    addAction={
                        <AddRowButton label="Add order" disabled={rows.length >= cap} cap={cap} onClick={() => api.addRow('purchase_orders')} />
                    }
                >
                    {rows.map((row, index) => {
                        const rowLabel = `Order row ${index + 1}`;
                        const input = (name: string, kind: 'text' | 'decimal' | 'date' = 'text', maxLength?: number) => (
                            <EntryInput
                                id={fieldId.cell('purchase_orders', row.key, name)}
                                label={label(name)}
                                rowLabel={rowLabel}
                                kind={kind}
                                maxLength={maxLength}
                                value={text(row.values[name])}
                                onChange={(value) => api.setCell('purchase_orders', row.key, name, value)}
                                error={api.cellError('purchase_orders', row, index, name)}
                            />
                        );

                        const supplierId = fieldId.cell('purchase_orders', row.key, 'award_id');
                        const supplierError = api.cellError('purchase_orders', row, index, 'award_id');
                        const current = text(row.values.award_id);
                        const valid = choosable.some((award) => String(award.id) === current);
                        const deliveryId = fieldId.cell('purchase_orders', row.key, 'delivery_status');

                        return (
                            <EntryRow
                                key={row.key}
                                label={rowLabel}
                                short={String(index + 1)}
                                remove={
                                    <RemoveRowButton
                                        label={`Remove order row ${index + 1}`}
                                        onClick={() => api.removeRow('purchase_orders', row.key)}
                                    />
                                }
                            >
                                <EntryCell label={label('award_id')} htmlFor={supplierId}>
                                    <select
                                        id={supplierId}
                                        className={cn(selectClass, controlHeight)}
                                        value={valid ? current : ''}
                                        disabled={choosable.length === 0}
                                        aria-label={`${label('award_id')}, ${rowLabel}`}
                                        aria-invalid={supplierError ? true : undefined}
                                        aria-describedby={
                                            [choosable.length === 0 ? `${supplierId}-hint` : null, supplierError ? `${supplierId}-error` : null]
                                                .filter(Boolean)
                                                .join(' ') || undefined
                                        }
                                        onChange={(e) => api.setCell('purchase_orders', row.key, 'award_id', e.target.value)}
                                    >
                                        <option value="">Choose…</option>
                                        {choosable.map((award) => (
                                            <option key={award.key} value={String(award.id)}>
                                                {text(award.values.supplier_name)}
                                            </option>
                                        ))}
                                    </select>
                                    {choosable.length === 0 && (
                                        <p id={`${supplierId}-hint`} className="text-muted-foreground text-[13px]">
                                            {awaitingSave ? 'Saving...' : 'Add an awarded supplier first.'}
                                        </p>
                                    )}
                                    <CellError id={`${supplierId}-error`} message={supplierError} />
                                </EntryCell>
                                {input('po_number', 'text', 64)}
                                {input('quantity_ordered', 'decimal')}
                                {input('ordered_on', 'date')}
                                {input('quantity_delivered', 'decimal')}
                                <EntryCell label={label('delivery')} htmlFor={`${deliveryId}-date`}>
                                    <div className="grid gap-1">
                                        <Input
                                            id={`${deliveryId}-date`}
                                            type="date"
                                            value={text(row.values.delivery_eta)}
                                            className={cn(controlHeight, 'w-full min-w-0')}
                                            aria-label={`Delivery date, ${rowLabel}`}
                                            onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_eta', e.target.value)}
                                        />
                                        <Input
                                            id={deliveryId}
                                            type="text"
                                            maxLength={120}
                                            value={text(row.values.delivery_status)}
                                            placeholder="Status"
                                            className={cn(controlHeight, 'w-full min-w-0')}
                                            aria-label={`Delivery status, ${rowLabel}`}
                                            onChange={(e) => api.setCell('purchase_orders', row.key, 'delivery_status', e.target.value)}
                                        />
                                    </div>
                                </EntryCell>
                                <EntryValue
                                    label="Quantity outstanding"
                                    rowLabel={rowLabel}
                                    value={rowDisplay(api.form.display.rows.purchase_orders, row.id)?.quantity_outstanding ?? '—'}
                                    className="lg:w-24 lg:flex-none"
                                />
                            </EntryRow>
                        );
                    })}
                </EntryTable>
            </div>

            <TextAreaField
                id={fieldId.requisition('tender_issue_explanation')}
                label="Issues affecting the tender quantity"
                optional={optional('tender_issue_explanation')}
                value={text(api.state.requisition.tender_issue_explanation)}
                onChange={(value) => api.setField('tender_issue_explanation', value)}
                error={api.fieldError('tender_issue_explanation')}
            />
        </div>
    );
}
