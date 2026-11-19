import { cn } from '@/lib/utils';
import { CircleAlert } from 'lucide-react';
import { createContext, type ReactNode, useContext } from 'react';

/**
 * FORM-ERR-1 (B): the CARD width (a container query, not the viewport) at and
 * above which each entry is one line of inputs. Each table picks the size at
 * which all its columns fit at their minimum widths (ENTRY_WIDTH), plus the row
 * label, the remove button, the 12px gaps and the 16px row padding. Below it
 * the entry wraps into labelled fields. So a row never overlaps, whatever the
 * viewport or the sidebar state.
 */
export type EntryBreakpoint = '32rem' | '45rem' | '66rem' | '88rem';

/**
 * A column's width (header and cells use the same classes, so they line up).
 * Fixed widths are a flex basis and grow widths a minimum: both apply only
 * in the one-line flex layout, except the minimum, which is always below the
 * 10rem columns of the wrapped grid, so it never forces an overflow there.
 */
export type EntryWidth = 'check' | 'check-wide' | 'value' | 'number' | 'text' | 'select' | 'date' | 'long';

export const ENTRY_WIDTH: Record<EntryWidth, string> = {
    'check': 'flex-none basis-20 min-w-20', // 80px: a checkbox or radio
    'check-wide': 'flex-none basis-24 min-w-24', // 96px: "Recommended"
    'value': 'flex-none basis-24 min-w-24', // 96px: a server figure (a dash until saved)
    'number': 'flex-1 min-w-24', // 96px minimum: quantities, prices, days
    'text': 'flex-1 min-w-28', // 112px minimum
    'select': 'flex-1 min-w-32', // 128px minimum
    'date': 'flex-1 min-w-36', // 144px minimum: a date input with its picker icon
    'long': 'flex-[2] min-w-40', // 160px minimum, twice the share of the rest
};

// Static class strings per breakpoint, so Tailwind can see every class. The
// table's root is the @container. Below the breakpoint an entry wraps into a
// compact grid of labelled fields (as many ~160px columns as fit; never a
// horizontal scroll), with the row label on its own line and the remove
// button at the row's top right.
const LAYOUT: Record<
    EntryBreakpoint,
    {
        header: string;
        row: string;
        cellLabel: string;
        rowLabel: string;
        rowLabelNarrow: string;
        fullOnly: string;
        shortOnly: string;
        remove: string;
    }
> = {
    '32rem': {
        header: 'hidden @min-[32rem]:flex',
        row: 'grid grid-cols-[repeat(auto-fill,minmax(min(100%,10rem),1fr))] gap-3 @min-[32rem]:flex @min-[32rem]:flex-row @min-[32rem]:items-start',
        cellLabel: '@min-[32rem]:sr-only',
        rowLabel: 'col-span-full pr-12 @min-[32rem]:w-32 @min-[32rem]:shrink-0 @min-[32rem]:pt-2 @min-[32rem]:pr-0',
        rowLabelNarrow: 'col-span-full pr-12 @min-[32rem]:w-8 @min-[32rem]:shrink-0 @min-[32rem]:pt-2 @min-[32rem]:pr-0',
        fullOnly: '@min-[32rem]:sr-only',
        shortOnly: 'hidden tabular-nums @min-[32rem]:inline',
        remove: 'absolute top-2 right-3 @min-[32rem]:static',
    },
    '45rem': {
        header: 'hidden @min-[45rem]:flex',
        row: 'grid grid-cols-[repeat(auto-fill,minmax(min(100%,10rem),1fr))] gap-3 @min-[45rem]:flex @min-[45rem]:flex-row @min-[45rem]:items-start',
        cellLabel: '@min-[45rem]:sr-only',
        rowLabel: 'col-span-full pr-12 @min-[45rem]:w-32 @min-[45rem]:shrink-0 @min-[45rem]:pt-2 @min-[45rem]:pr-0',
        rowLabelNarrow: 'col-span-full pr-12 @min-[45rem]:w-8 @min-[45rem]:shrink-0 @min-[45rem]:pt-2 @min-[45rem]:pr-0',
        fullOnly: '@min-[45rem]:sr-only',
        shortOnly: 'hidden tabular-nums @min-[45rem]:inline',
        remove: 'absolute top-2 right-3 @min-[45rem]:static',
    },
    '66rem': {
        header: 'hidden @min-[66rem]:flex',
        row: 'grid grid-cols-[repeat(auto-fill,minmax(min(100%,10rem),1fr))] gap-3 @min-[66rem]:flex @min-[66rem]:flex-row @min-[66rem]:items-start',
        cellLabel: '@min-[66rem]:sr-only',
        rowLabel: 'col-span-full pr-12 @min-[66rem]:w-32 @min-[66rem]:shrink-0 @min-[66rem]:pt-2 @min-[66rem]:pr-0',
        rowLabelNarrow: 'col-span-full pr-12 @min-[66rem]:w-8 @min-[66rem]:shrink-0 @min-[66rem]:pt-2 @min-[66rem]:pr-0',
        fullOnly: '@min-[66rem]:sr-only',
        shortOnly: 'hidden tabular-nums @min-[66rem]:inline',
        remove: 'absolute top-2 right-3 @min-[66rem]:static',
    },
    '88rem': {
        header: 'hidden @min-[88rem]:flex',
        row: 'grid grid-cols-[repeat(auto-fill,minmax(min(100%,10rem),1fr))] gap-3 @min-[88rem]:flex @min-[88rem]:flex-row @min-[88rem]:items-start',
        cellLabel: '@min-[88rem]:sr-only',
        rowLabel: 'col-span-full pr-12 @min-[88rem]:w-32 @min-[88rem]:shrink-0 @min-[88rem]:pt-2 @min-[88rem]:pr-0',
        rowLabelNarrow: 'col-span-full pr-12 @min-[88rem]:w-8 @min-[88rem]:shrink-0 @min-[88rem]:pt-2 @min-[88rem]:pr-0',
        fullOnly: '@min-[88rem]:sr-only',
        shortOnly: 'hidden tabular-nums @min-[88rem]:inline',
        remove: 'absolute top-2 right-3 @min-[88rem]:static',
    },
};

const TableContext = createContext<{ breakpoint: EntryBreakpoint; narrowRowLabel: boolean }>({ breakpoint: '66rem', narrowRowLabel: false });

export interface EntryColumn {
    key: string;
    label: string;
    /** The column's width (ENTRY_WIDTH); the row's cell passes the same one. Default "text". */
    width?: EntryWidth;
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
    /** The card width for the one-line layout; it must fit every column at its minimum (see EntryBreakpoint). */
    breakpoint: EntryBreakpoint;
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
export function EntryTable({ caption, columns, children, addAction, empty, foot, breakpoint, narrowRowLabel = false, className }: EntryTableProps) {
    const layout = LAYOUT[breakpoint];

    return (
        <TableContext.Provider value={{ breakpoint, narrowRowLabel }}>
            <div role="group" aria-label={caption} className={cn('bg-card @container overflow-hidden rounded-xl border shadow-xs', className)}>
                <div aria-hidden="true" className={cn('bg-subtle text-muted-foreground gap-3 border-b px-4 py-2 text-[13px] font-medium', layout.header)}>
                    <span className={cn('shrink-0', narrowRowLabel ? 'w-8' : 'w-32')}>{narrowRowLabel ? '#' : 'Row'}</span>
                    {columns.map((column) => (
                        <span key={column.key} className={cn('break-words', ENTRY_WIDTH[column.width ?? 'text'])}>
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

/** FORM-ERR-1: one problem in a row; the message names the row and the column ("Quotation Row 1: Quantity must be ..."). */
export interface RowError {
    field: string;
    message: string;
}

/**
 * FORM-ERR-1 (A): the ONE place a table row's errors are shown: a full-width
 * list directly under the row, spanning every column, one message per line.
 * Never inside a narrow cell. Long words wrap; nothing overflows or is
 * clipped. The failing controls point at this list with aria-describedby.
 */
export function RowErrorList({ id, errors }: { id: string; errors: RowError[] }) {
    if (errors.length === 0) {
        return null;
    }

    return (
        <ul id={id} className="text-destructive-text grid min-w-0 gap-1 text-sm">
            {errors.map((error) => (
                <li key={error.field} className="flex min-w-0 items-start gap-1.5">
                    <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                    <span className="min-w-0 break-words">{error.message}</span>
                </li>
            ))}
        </ul>
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
    /** FORM-ERR-1: the row's errors, shown in one full-width list under the row (RowErrorList). */
    errors?: RowError[];
    /** The id of that list; each failing control's aria-describedby points at it. */
    errorsId?: string;
    /** The cells (EntryCell). */
    children: ReactNode;
}

export function EntryRow({ label, short, remove, below, errors = [], errorsId, children }: EntryRowProps) {
    const { breakpoint, narrowRowLabel } = useContext(TableContext);
    const layout = LAYOUT[breakpoint];
    const narrow = narrowRowLabel && short !== undefined;

    return (
        <div className="relative space-y-3 px-4 py-3">
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
                <div className={cn('flex shrink-0 justify-end', layout.remove)}>{remove ?? <span className="w-9" />}</div>
            </div>
            {errorsId && <RowErrorList id={errorsId} errors={errors} />}
            {below}
        </div>
    );
}

interface EntryCellProps {
    /** The column label: visible above the field below the breakpoint, hidden (screen-reader only) at it. */
    label: string;
    /** The id of the input it labels. */
    htmlFor?: string;
    /** The same width as the column (ENTRY_WIDTH). Default "text". */
    width?: EntryWidth;
    className?: string;
    children: ReactNode;
}

export function EntryCell({ label, htmlFor, width = 'text', className, children }: EntryCellProps) {
    const layout = LAYOUT[useContext(TableContext).breakpoint];

    return (
        // FORM-ERR-1 (B): one track that can shrink to the cell (minmax(0,1fr)), so nothing
        // inside (a long word, an input's intrinsic size) can widen the cell over its neighbour.
        <div className={cn('grid grid-cols-[minmax(0,1fr)] gap-2', ENTRY_WIDTH[width], className)}>
            <label htmlFor={htmlFor} className={cn('text-sm font-medium', layout.cellLabel)}>
                {label}
            </label>
            {children}
        </div>
    );
}
