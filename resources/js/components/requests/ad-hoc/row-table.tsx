import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ChildSection } from '@/types/ad-hoc';
import { Plus, Trash2 } from 'lucide-react';
import { type ReactNode } from 'react';
import { controlHeight, Optional } from './fields';
import { fieldId, type LocalRow, type Value } from './form-state';

// The editable rows of one AD_HOC child section (slice F2). Each row is a
// bordered block: a row number, a remove button at the top right, then the
// fields in a wrapping grid, so nothing ever scrolls sideways. Every field keeps
// its own visible label; DOM order = tab order = visual order = column order.
// Rows live in the page's local state; the page autosaves them. Computed values
// show the server's figures for saved rows. Used instead of row-list, which
// saves its own section and replaces rows (row-list stays for the old form).

/** Fields wrap: as many ~160px columns as fit, never wider than the row. */
export const rowGrid = 'grid gap-4 grid-cols-[repeat(auto-fill,minmax(min(100%,10rem),1fr))]';

export interface CellContext {
    row: LocalRow;
    index: number;
    /** DOM id for this cell's control (the jump-link target). */
    id: string;
    /** "<column label>, <row label> <n>": the full accessible name. */
    label: string;
    /** ", <row label> <n>": appended (screen readers only) to a visible label. */
    rowText: string;
    error?: string;
    errorId: string;
}

export interface Column {
    name: string;
    label: string;
    optional?: boolean;
    /** Input kind; "computed" shows `computed(row)` read-only; "custom" uses `render`. */
    kind: 'text' | 'decimal' | 'integer' | 'date' | 'computed' | 'custom';
    computed?: (row: LocalRow) => ReactNode;
    /** Returns one or more grid items (a fragment is fine), each with its own visible label. */
    render?: (cell: CellContext) => ReactNode;
}

/** A field's visible label; the row number is added for screen readers. */
export function CellLabel({ htmlFor, text, rowText, optional }: { htmlFor: string; text: string; rowText: string; optional?: boolean }) {
    return (
        <Label htmlFor={htmlFor}>
            {text}
            {optional && <Optional />}
            <span className="sr-only">{rowText}</span>
        </Label>
    );
}

export function CellError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} className="text-destructive text-xs">
            {message}
        </p>
    ) : null;
}

interface RowTableProps {
    section: ChildSection;
    title: string;
    /** "Award row", "Order", "Batch", "Quotation": used in labels and remove buttons. */
    rowLabel: string;
    addLabel: string;
    rows: LocalRow[];
    columns: Column[];
    cap: number;
    onChange: (rowKey: string, name: string, value: Value) => void;
    onAdd: () => void;
    onRemove: (rowKey: string) => void;
    /** A problem with one cell, or undefined. */
    cellError: (row: LocalRow, index: number, name: string) => string | undefined;
    /** A problem with the rows as a whole (for example "Add at least one award row."). */
    tableError?: string;
    /** Text above the rows, for example a computed figure. */
    above?: ReactNode;
    /** Text below the rows, for example totals. */
    below?: ReactNode;
}

export function RowTable({
    section,
    title,
    rowLabel,
    addLabel,
    rows,
    columns,
    cap,
    onChange,
    onAdd,
    onRemove,
    cellError,
    tableError,
    above,
    below,
}: RowTableProps) {
    const headingId = `${section}-heading`;
    const tableErrorId = `${fieldId.table(section)}-error`;
    const atCap = rows.length >= cap;

    return (
        <div className="grid min-w-0 grid-cols-1 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 id={headingId} className="text-base font-semibold">
                    {title}
                </h3>
                <Button type="button" variant="outline" size="sm" className={cn(controlHeight, 'ml-auto')} onClick={onAdd} disabled={atCap}>
                    <Plus className="size-4" aria-hidden />
                    {addLabel}
                </Button>
            </div>
            {above}
            {tableError && (
                <p id={tableErrorId} className="text-destructive text-sm">
                    {tableError}
                </p>
            )}
            <div
                id={fieldId.table(section)}
                tabIndex={-1}
                role="region"
                aria-labelledby={headingId}
                aria-describedby={tableError ? tableErrorId : undefined}
                className="focus-visible:ring-ring min-w-0 rounded-md focus-visible:ring-2 focus-visible:outline-hidden"
            >
                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border px-3 py-4 text-center text-sm">No rows yet. Use “{addLabel}”.</p>
                ) : (
                    <ol className="grid grid-cols-1 gap-3">
                        {rows.map((row, index) => {
                            const rowHeadingId = `${fieldId.table(section)}-${row.key}-heading`;
                            const rowName = `${rowLabel} ${index + 1}`;

                            return (
                                <li key={row.key} aria-labelledby={rowHeadingId} className="min-w-0 rounded-md border p-4">
                                    <div className="flex items-center justify-between gap-2">
                                        <h4 id={rowHeadingId} className="text-sm font-medium tabular-nums">
                                            {rowName}
                                        </h4>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="-my-1 size-11 md:size-9"
                                            aria-label={`Remove ${rowName.toLowerCase()}`}
                                            onClick={() => onRemove(row.key)}
                                        >
                                            <Trash2 className="size-4" aria-hidden />
                                        </Button>
                                    </div>
                                    <div className={cn(rowGrid, 'mt-2')}>
                                        {columns.map((column) => (
                                            <Cell
                                                key={column.name}
                                                section={section}
                                                rowLabel={rowLabel}
                                                column={column}
                                                row={row}
                                                index={index}
                                                onChange={onChange}
                                                error={cellError(row, index, column.name)}
                                            />
                                        ))}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>
            {atCap && <p className="text-muted-foreground text-xs">This list is full ({cap} rows).</p>}
            {below}
        </div>
    );
}

interface CellProps {
    section: ChildSection;
    rowLabel: string;
    column: Column;
    row: LocalRow;
    index: number;
    onChange: RowTableProps['onChange'];
    error: string | undefined;
}

function Cell({ section, rowLabel, column, row, index, onChange, error }: CellProps) {
    const id = fieldId.cell(section, row.key, column.name);
    const rowText = `, ${rowLabel.toLowerCase()} ${index + 1}`;
    const errorId = `${id}-error`;

    if (column.kind === 'computed') {
        return (
            <div className="grid min-w-0 content-start gap-1.5">
                <span className="text-sm font-medium">
                    {column.label}
                    <span className="sr-only">{rowText}</span>
                </span>
                <span className="text-muted-foreground flex min-h-11 items-center text-sm tabular-nums md:min-h-9">
                    {column.computed?.(row) ?? '—'}
                </span>
            </div>
        );
    }
    if (column.kind === 'custom') {
        return <>{column.render?.({ row, index, id, label: `${column.label}${rowText}`, rowText, error, errorId })}</>;
    }

    const value = row.values[column.name];

    return (
        <div className="grid min-w-0 content-start gap-1.5">
            <CellLabel htmlFor={id} text={column.label} rowText={rowText} optional={column.optional} />
            <Input
                id={id}
                type={column.kind === 'date' ? 'date' : 'text'}
                inputMode={column.kind === 'decimal' ? 'decimal' : column.kind === 'integer' ? 'numeric' : undefined}
                value={typeof value === 'string' ? value : ''}
                className={cn(controlHeight, 'w-full min-w-0')}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                onChange={(e) => onChange(row.key, column.name, e.target.value)}
            />
            <CellError id={errorId} message={error} />
        </div>
    );
}
