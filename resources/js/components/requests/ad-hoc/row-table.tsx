import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { type ChildSection } from '@/types/ad-hoc';
import { Plus, Trash2 } from 'lucide-react';
import { type ReactNode } from 'react';
import { controlHeight } from './fields';
import { fieldId, type LocalRow, type Value } from './form-state';

// An inline-editable table for one AD_HOC child section (slice F2). Rows live in
// the page's local state; the page autosaves them. Computed columns show the
// server's values for saved rows. Used instead of row-list, which saves its
// own section and replaces rows (row-list stays for the old form).

export interface CellContext {
    row: LocalRow;
    index: number;
    /** DOM id for this cell's control (the jump-link target). */
    id: string;
    /** "<column label>, <row label> <n>": the cell control's accessible name. */
    label: string;
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
    render?: (cell: CellContext) => ReactNode;
    /** Minimum width class for the column. */
    width?: string;
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
    /** A problem with the table as a whole (for example "Add at least one award row."). */
    tableError?: string;
    /** Text above the table, for example a computed figure. */
    above?: ReactNode;
    /** Text below the table, for example totals. */
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
        <div className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 id={headingId} className="text-base font-semibold">
                    {title}
                </h3>
                <Button type="button" variant="outline" size="sm" className={controlHeight} onClick={onAdd} disabled={atCap}>
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
                className="focus-visible:ring-ring overflow-x-auto rounded-lg border focus-visible:ring-2 focus-visible:outline-hidden"
            >
                <table className="w-full border-collapse text-sm">
                    <thead className="bg-muted">
                        <tr>
                            <th scope="col" className="w-10 px-2 py-2 text-left text-xs font-medium">
                                <span className="sr-only">{rowLabel}</span>#
                            </th>
                            {columns.map((column) => (
                                <th key={column.name} scope="col" className={cn('px-2 py-2 text-left text-xs font-medium', column.width)}>
                                    {column.label}
                                    {column.optional && <span className="text-muted-foreground font-normal"> (optional)</span>}
                                </th>
                            ))}
                            <th scope="col" className="w-12 px-2 py-2">
                                <span className="sr-only">Remove</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + 2} className="text-muted-foreground px-3 py-4 text-center">
                                    No rows yet. Use “{addLabel}”.
                                </td>
                            </tr>
                        )}
                        {rows.map((row, index) => (
                            <tr key={row.key} className="border-t align-top">
                                <th scope="row" className="px-2 py-2 text-left text-xs font-medium tabular-nums">
                                    {index + 1}
                                </th>
                                {columns.map((column) => (
                                    <td key={column.name} className={cn('px-2 py-1.5', column.width)}>
                                        {renderCell(section, rowLabel, column, row, index, onChange, cellError(row, index, column.name))}
                                    </td>
                                ))}
                                <td className="px-2 py-1.5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-11 md:size-9"
                                        aria-label={`Remove ${rowLabel.toLowerCase()} ${index + 1}`}
                                        onClick={() => onRemove(row.key)}
                                    >
                                        <Trash2 className="size-4" aria-hidden />
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {atCap && <p className="text-muted-foreground text-xs">This table is full ({cap} rows).</p>}
            {below}
        </div>
    );
}

function renderCell(
    section: ChildSection,
    rowLabel: string,
    column: Column,
    row: LocalRow,
    index: number,
    onChange: RowTableProps['onChange'],
    error: string | undefined,
) {
    const id = fieldId.cell(section, row.key, column.name);
    const label = `${column.label}, ${rowLabel.toLowerCase()} ${index + 1}`;
    const errorId = `${id}-error`;

    if (column.kind === 'computed') {
        return <span className="text-muted-foreground block py-2 tabular-nums">{column.computed?.(row) ?? '—'}</span>;
    }
    if (column.kind === 'custom') {
        return column.render?.({ row, index, id, label, error, errorId });
    }

    const value = row.values[column.name];

    return (
        <div className="grid gap-1">
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <Input
                id={id}
                type={column.kind === 'date' ? 'date' : 'text'}
                inputMode={column.kind === 'decimal' ? 'decimal' : column.kind === 'integer' ? 'numeric' : undefined}
                value={typeof value === 'string' ? value : ''}
                className={cn(controlHeight, 'min-w-24')}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                onChange={(e) => onChange(row.key, column.name, e.target.value)}
            />
            {error && (
                <p id={errorId} className="text-destructive text-xs">
                    {error}
                </p>
            )}
        </div>
    );
}
