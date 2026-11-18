import { cn } from '@/lib/utils';
import { createContext, type ReactNode, useContext } from 'react';

/** The width at and above which each entry is one line of inputs (design.md section 7). */
export type EntryBreakpoint = 'lg' | 'xl';

// Static class strings per breakpoint, so Tailwind can see every class.
const LAYOUT: Record<
    EntryBreakpoint,
    { header: string; row: string; cellLabel: string; cell: string; rowLabel: string; rowLabelNarrow: string; fullOnly: string; shortOnly: string }
> = {
    lg: {
        header: 'hidden lg:flex',
        row: 'flex flex-col gap-3 lg:flex-row lg:items-start lg:gap-3',
        cellLabel: 'lg:sr-only',
        cell: 'lg:flex-1 lg:min-w-0',
        rowLabel: 'lg:w-32 lg:shrink-0 lg:pt-2',
        rowLabelNarrow: 'lg:w-8 lg:shrink-0 lg:pt-2',
        fullOnly: 'lg:sr-only',
        shortOnly: 'hidden tabular-nums lg:inline',
    },
    xl: {
        header: 'hidden xl:flex',
        row: 'flex flex-col gap-3 xl:flex-row xl:items-start xl:gap-3',
        cellLabel: 'xl:sr-only',
        cell: 'xl:flex-1 xl:min-w-0',
        rowLabel: 'xl:w-32 xl:shrink-0 xl:pt-2',
        rowLabelNarrow: 'xl:w-8 xl:shrink-0 xl:pt-2',
        fullOnly: 'xl:sr-only',
        shortOnly: 'hidden tabular-nums xl:inline',
    },
};

const TableContext = createContext<{ breakpoint: EntryBreakpoint; narrowRowLabel: boolean }>({ breakpoint: 'lg', narrowRowLabel: false });

export interface EntryColumn {
    key: string;
    label: string;
    /** Width classes for the column at the one-line width (for example "lg:w-24 lg:flex-none"). */
    className?: string;
}

interface EntryTableProps {
    /** Accessible name of the table, for example "Awarded suppliers". */
    caption: string;
    columns: EntryColumn[];
    /** The rows (EntryRow). */
    children: ReactNode;
    /** The "Add ..." button, shown at the BOTTOM of the card. */
    addAction?: ReactNode;
    /** Shown instead of rows when there are none. */
    empty?: ReactNode;
    /** Shown under the rows, above the add button (for example a total). */
    foot?: ReactNode;
    breakpoint?: EntryBreakpoint;
    /**
     * V2-2: at the one-line width each row shows only its number ("1") in a
     * narrow first column, leaving the width to the inputs; the full row label
     * ("Supplier row 1") is shown below the breakpoint and kept for screen readers.
     */
    narrowRowLabel?: boolean;
    className?: string;
}

/**
 * UI-4: the entry-rows shell (design.md section 7). At the breakpoint and
 * above: column headers once on --subtle and one line of inputs per entry,
 * with a row label at the start and a remove button at the end. Below it each
 * entry wraps to labelled fields; there is no horizontal scroll. The add
 * button sits in a dashed strip at the bottom. Presentational only: no data.
 */
export function EntryTable({
    caption,
    columns,
    children,
    addAction,
    empty,
    foot,
    breakpoint = 'lg',
    narrowRowLabel = false,
    className,
}: EntryTableProps) {
    const layout = LAYOUT[breakpoint];

    return (
        <TableContext.Provider value={{ breakpoint, narrowRowLabel }}>
            <div role="group" aria-label={caption} className={cn('bg-card overflow-hidden rounded-xl border shadow-xs', className)}>
                <div aria-hidden="true" className={cn('bg-subtle text-muted-foreground gap-3 border-b px-4 py-2 text-xs font-medium', layout.header)}>
                    <span className={cn('shrink-0', narrowRowLabel ? 'w-8' : 'w-32')}>{narrowRowLabel ? '#' : 'Row'}</span>
                    {columns.map((column) => (
                        <span key={column.key} className={cn('min-w-0 flex-1', column.className)}>
                            {column.label}
                        </span>
                    ))}
                    <span className="w-9 shrink-0" />
                </div>

                <div className="divide-divider divide-y">{children}</div>
                {empty}
                {foot}

                {addAction && <div className="bg-subtle border-input border-t border-dashed px-4 py-2">{addAction}</div>}
            </div>
        </TableContext.Provider>
    );
}

interface EntryRowProps {
    /** For example "Supplier row 1"; also part of each input's accessible name. */
    label: string;
    /** V2-2: the row number shown at the one-line width when the table has narrowRowLabel. */
    short?: string;
    /** The remove icon button (with an aria-label naming the row). */
    remove?: ReactNode;
    /** V2-2: content under the row's inputs, full width (for example a reason asked only for this row). */
    below?: ReactNode;
    /** The cells (EntryCell). */
    children: ReactNode;
}

export function EntryRow({ label, short, remove, below, children }: EntryRowProps) {
    const { breakpoint, narrowRowLabel } = useContext(TableContext);
    const layout = LAYOUT[breakpoint];
    const narrow = narrowRowLabel && short !== undefined;

    return (
        <div className="space-y-3 px-4 py-3">
            <div className={layout.row}>
                <div className={cn('flex items-center justify-between gap-2 text-sm font-medium', narrow ? layout.rowLabelNarrow : layout.rowLabel)}>
                    {narrow ? (
                        <>
                            <span className={layout.fullOnly}>{label}</span>
                            <span className={layout.shortOnly} aria-hidden="true">
                                {short}
                            </span>
                        </>
                    ) : (
                        <span>{label}</span>
                    )}
                </div>
                {children}
                <div className="flex shrink-0 justify-end">{remove ?? <span className="w-9" />}</div>
            </div>
            {below}
        </div>
    );
}

interface EntryCellProps {
    /** The column label: visible above the field below the breakpoint, hidden (screen-reader only) at it. */
    label: string;
    /** The id of the input it labels. */
    htmlFor?: string;
    /** The same width classes as the column. */
    className?: string;
    children: ReactNode;
}

export function EntryCell({ label, htmlFor, className, children }: EntryCellProps) {
    const layout = LAYOUT[useContext(TableContext).breakpoint];

    return (
        <div className={cn('grid min-w-0 gap-1', layout.cell, className)}>
            <label htmlFor={htmlFor} className={cn('text-muted-foreground text-xs font-medium', layout.cellLabel)}>
                {label}
            </label>
            {children}
        </div>
    );
}
