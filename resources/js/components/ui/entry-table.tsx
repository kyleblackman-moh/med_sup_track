import { cn } from '@/lib/utils';
import { createContext, type ReactNode, useContext } from 'react';

/** The width at and above which each entry is one line of inputs (design.md section 7). */
export type EntryBreakpoint = 'lg' | 'xl';

// Static class strings per breakpoint, so Tailwind can see every class.
const LAYOUT: Record<EntryBreakpoint, { header: string; row: string; cellLabel: string; cell: string; rowLabel: string }> = {
    lg: {
        header: 'hidden lg:flex',
        row: 'flex flex-col gap-3 lg:flex-row lg:items-start lg:gap-3',
        cellLabel: 'lg:sr-only',
        cell: 'lg:flex-1 lg:min-w-0',
        rowLabel: 'lg:w-32 lg:shrink-0 lg:pt-2',
    },
    xl: {
        header: 'hidden xl:flex',
        row: 'flex flex-col gap-3 xl:flex-row xl:items-start xl:gap-3',
        cellLabel: 'xl:sr-only',
        cell: 'xl:flex-1 xl:min-w-0',
        rowLabel: 'xl:w-32 xl:shrink-0 xl:pt-2',
    },
};

const BreakpointContext = createContext<EntryBreakpoint>('lg');

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
    breakpoint?: EntryBreakpoint;
    className?: string;
}

/**
 * UI-4: the entry-rows shell (design.md section 7). At the breakpoint and
 * above: column headers once on --subtle and one line of inputs per entry,
 * with a row label at the start and a remove button at the end. Below it each
 * entry wraps to labelled fields; there is no horizontal scroll. The add
 * button sits in a dashed strip at the bottom. Presentational only: no data.
 */
export function EntryTable({ caption, columns, children, addAction, empty, breakpoint = 'lg', className }: EntryTableProps) {
    const layout = LAYOUT[breakpoint];

    return (
        <BreakpointContext.Provider value={breakpoint}>
            <div role="group" aria-label={caption} className={cn('bg-card overflow-hidden rounded-xl border shadow-xs', className)}>
                <div aria-hidden="true" className={cn('bg-subtle text-muted-foreground gap-3 border-b px-4 py-2 text-xs font-medium', layout.header)}>
                    <span className="w-32 shrink-0">Row</span>
                    {columns.map((column) => (
                        <span key={column.key} className={cn('min-w-0 flex-1', column.className)}>
                            {column.label}
                        </span>
                    ))}
                    <span className="w-9 shrink-0" />
                </div>

                <div className="divide-divider divide-y">{children}</div>
                {empty}

                {addAction && <div className="bg-subtle border-input border-t border-dashed px-4 py-2">{addAction}</div>}
            </div>
        </BreakpointContext.Provider>
    );
}

interface EntryRowProps {
    /** For example "Supplier row 1"; also part of each input's accessible name. */
    label: string;
    /** The remove icon button (with an aria-label naming the row). */
    remove?: ReactNode;
    /** The cells (EntryCell). */
    children: ReactNode;
}

export function EntryRow({ label, remove, children }: EntryRowProps) {
    const layout = LAYOUT[useContext(BreakpointContext)];

    return (
        <div className={cn('px-4 py-3', layout.row)}>
            <div className={cn('flex items-center justify-between gap-2 text-sm font-medium', layout.rowLabel)}>
                <span>{label}</span>
            </div>
            {children}
            <div className="flex shrink-0 justify-end">{remove ?? <span className="w-9" />}</div>
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
    const layout = LAYOUT[useContext(BreakpointContext)];

    return (
        <div className={cn('grid min-w-0 gap-1', layout.cell, className)}>
            <label htmlFor={htmlFor} className={cn('text-muted-foreground text-xs font-medium', layout.cellLabel)}>
                {label}
            </label>
            {children}
        </div>
    );
}
