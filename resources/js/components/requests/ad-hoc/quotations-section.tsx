import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { controlHeight, Figure, RadioGroupField, TextField } from './fields';
import { text, type FormApi } from './form-api';
import { fieldId } from './form-state';
import { RowTable, type CellContext, type Column } from './row-table';

const boxClass = 'focus-visible:ring-ring size-4 focus-visible:ring-2 focus-visible:ring-offset-2';

/**
 * Section 4, Quotations: who received the RFQ, one row per quotation (a
 * Considered checkbox, one Recommended radio across the rows, and a reason
 * only when not considered), then the quantity to purchase and the server's figures.
 */
export function QuotationsSection({ api }: { api: FormApi }) {
    const { state, detail, config } = api;
    const unit = text(state.requisition.unit_of_measure).trim();
    const figures = detail.figures;

    const consideredCell = ({ row, id, label }: CellContext) => (
        <div className="flex min-h-11 items-center md:min-h-9">
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <input
                id={id}
                type="checkbox"
                className={boxClass}
                checked={row.values.considered === true}
                onChange={(e) => api.setCell('quotations', row.key, 'considered', e.target.checked)}
            />
        </div>
    );

    const recommendedCell = ({ row, id, label }: CellContext) => (
        <div className="flex min-h-11 items-center md:min-h-9">
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <input
                id={id}
                type="radio"
                name="recommended-quotation"
                className={boxClass}
                checked={row.values.is_recommended === true}
                onChange={() => api.chooseRecommended(row.key)}
            />
        </div>
    );

    const reasonCell = ({ row, id, label, error, errorId }: CellContext) =>
        row.values.considered === true ? (
            <span className="text-muted-foreground block py-2" aria-hidden>
                —
            </span>
        ) : (
            <div className="grid min-w-52 gap-1">
                <label htmlFor={id} className="sr-only">
                    {label}
                </label>
                <Input
                    id={id}
                    maxLength={config.max_lengths.reason_accepted_excluded}
                    value={text(row.values.reason_accepted_excluded)}
                    className={cn(controlHeight)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : undefined}
                    onChange={(e) => api.setCell('quotations', row.key, 'reason_accepted_excluded', e.target.value)}
                />
                {error && (
                    <p id={errorId} className="text-destructive text-xs">
                        {error}
                    </p>
                )}
            </div>
        );

    const columns: Column[] = [
        { name: 'supplier_name', label: 'Supplier', kind: 'text', width: 'min-w-44' },
        { name: 'manufacturer', label: 'Manufacturer', kind: 'text', optional: true, width: 'min-w-40' },
        { name: 'brand', label: 'Brand', kind: 'text', optional: true, width: 'min-w-36' },
        { name: 'unit_price', label: 'Unit price', kind: 'decimal', width: 'min-w-28' },
        { name: 'quantity_offered', label: 'Quantity offered', kind: 'decimal', width: 'min-w-32' },
        { name: 'lead_time_days', label: 'Lead time (days)', kind: 'integer', optional: true, width: 'min-w-28' },
        { name: 'considered', label: 'Considered', kind: 'custom', render: consideredCell },
        { name: 'is_recommended', label: 'Recommended', kind: 'custom', render: recommendedCell },
        { name: 'reason_accepted_excluded', label: 'Reason not considered', kind: 'custom', render: reasonCell },
    ];

    const recommendedError = api.tableError('quotations.recommended');

    return (
        <div className="grid gap-6">
            <RadioGroupField
                id={fieldId.requisition('rfq_audience')}
                legend="RFQ sent to"
                options={config.options.rfq_audience}
                value={text(state.requisition.rfq_audience)}
                onChange={(value) => api.setField('rfq_audience', value)}
                error={api.fieldError('rfq_audience')}
            />

            <RowTable
                section="quotations"
                title="Quotations received"
                rowLabel="Quotation"
                addLabel="Add quotation"
                rows={state.rows.quotations}
                columns={columns}
                cap={config.row_caps.quotations}
                onChange={(key, name, value) => api.setCell('quotations', key, name, value)}
                onAdd={() => api.addRow('quotations')}
                onRemove={(key) => api.removeRow('quotations', key)}
                cellError={(row, index, name) => api.cellError('quotations', row, index, name)}
                tableError={[api.tableError('quotations'), recommendedError].filter(Boolean).join(' ') || undefined}
                above={
                    <p className="text-muted-foreground text-xs">Choose one recommended quotation. Give a reason for any quotation not considered.</p>
                }
            />

            <TextField
                id={fieldId.requisition('quantity_to_purchase')}
                label="Quantity to purchase"
                inputMode="decimal"
                value={text(state.requisition.quantity_to_purchase)}
                onChange={(value) => api.setField('quantity_to_purchase', value)}
                error={api.fieldError('quantity_to_purchase')}
                suffix={unit || undefined}
                className="max-w-sm"
            />

            <dl className="grid gap-4 sm:grid-cols-4">
                <Figure label="Quotations received" value={figures?.quotations_received ?? 0} />
                <Figure label="Quotations considered" value={figures?.quotations_considered ?? 0} />
                <Figure label="Recommended unit price" value={figures?.recommended_unit_price} />
                <Figure label="Total cost (recommended)" value={figures?.total_cost} />
            </dl>
            <p className="text-muted-foreground text-xs">Figures are worked out by the system and update after each save.</p>
        </div>
    );
}
