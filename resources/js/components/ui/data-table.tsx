import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ComponentProps, type ReactNode } from 'react';

/**
 * OVERNIGHT-3 (B1): the ONE read-only data table (design.md section 7, "Data
 * table"). The only place in pages and components that renders a <table>
 * (ThemeGuardTest). Pieces:
 *
 * - DataTableCard: the L3 white card that holds a table, its empty state and
 *   its pagination (1px border, 12px radius, shadow-xs).
 * - DataTable: the table itself, with a caption (visually hidden unless
 *   `showCaption`), inside its own box that scrolls sideways at narrow
 *   widths, so the page never does. Wraps instead in print.
 * - DataTableHeaderCell (<th scope="col">), DataTableRowHeader (<th
 *   scope="row">), DataTableRow and DataTableCell: header on --subtle, 12px/500
 *   muted; rows divided by 1px --divider; a row with a stretched link
 *   (rowLink) is `relative` and shows --selected on hover and focus.
 * - DataTableRef: a reference number in JetBrains Mono 12px/500.
 * - DataTablePagination: "Showing x–y of n" with Previous and Next links.
 *
 * Every value shown comes formatted from the server.
 */

/** The L3 card around a table, its empty state and pagination. */
export function DataTableCard({ children, className, ...props }: ComponentProps<'div'>) {
    return (
        <div className={cn('bg-card min-w-0 overflow-hidden rounded-xl border shadow-xs', className)} {...props}>
            {children}
        </div>
    );
}

interface DataTableProps extends Omit<ComponentProps<'table'>, 'children'> {
    /** Names the table for assistive technology. */
    caption: ReactNode;
    /** Show the caption as a 14px/600 heading above the table instead of hiding it. */
    showCaption?: boolean;
    /** "compact": 13px cells (summaries and receipts); "default": 14px. */
    density?: 'default' | 'compact';
    /** Classes for the scrolling box (for example a min width breakpoint, or `print:hidden`). */
    wrapperClassName?: string;
    /** Draw a 1px border around the box (a table that sits inside another card). */
    bordered?: boolean;
    'aria-busy'?: boolean;
    children: ReactNode;
}

/** The table, in a box that scrolls sideways inside its card (never the page). */
export function DataTable({
    caption,
    showCaption = false,
    density = 'default',
    wrapperClassName,
    bordered = false,
    className,
    children,
    'aria-busy': busy,
    ...props
}: DataTableProps) {
    return (
        <div
            className={cn('min-w-0 overflow-x-auto print:overflow-visible', bordered && 'rounded-lg border', wrapperClassName)}
            aria-busy={busy || undefined}
        >
            <table
                className={cn('w-full border-separate border-spacing-0 text-left', density === 'compact' ? 'text-helper' : 'text-sm', className)}
                {...props}
            >
                <caption className={showCaption ? 'mb-2 text-left text-sm font-semibold' : 'sr-only'}>{caption}</caption>
                {children}
            </table>
        </div>
    );
}

/** A column header: --subtle, 12px/500 muted, 40px high. */
export const dataTableHeaderClass = 'bg-subtle text-muted-foreground border-divider h-10 border-b px-3 text-xs font-medium whitespace-nowrap';

export function DataTableHeaderCell({ className, ...props }: ComponentProps<'th'>) {
    return <th scope="col" className={cn(dataTableHeaderClass, className)} {...props} />;
}

/** A body cell: 48px high, 12px side padding, a 1px --divider under it. */
export const dataTableCellClass = 'border-divider h-12 border-b px-3 py-2 align-middle';

export function DataTableCell({ className, ...props }: ComponentProps<'td'>) {
    return <td className={cn(dataTableCellClass, className)} {...props} />;
}

/** The first cell of a row that names the row (a row label): a <th scope="row">. */
export function DataTableRowHeader({ className, ...props }: ComponentProps<'th'>) {
    return <th scope="row" className={cn(dataTableCellClass, 'text-left font-medium whitespace-nowrap', className)} {...props} />;
}

interface DataTableRowProps extends ComponentProps<'tr'> {
    /** The row holds a stretched link (rowLink): position it and show the hover and focus fill. */
    linked?: boolean;
}

export function DataTableRow({ linked = false, className, ...props }: DataTableRowProps) {
    return <tr className={cn(linked && 'hover:bg-selected focus-within:bg-selected relative', '[&:last-child>*]:border-b-0', className)} {...props} />;
}

/** A reference number in a table: JetBrains Mono 12px/500 (design.md section 4). */
export const dataTableRefClass = 'font-mono text-xs font-medium';

export function DataTableRef({ children, className }: { children: ReactNode; className?: string }) {
    return <span className={cn(dataTableRefClass, className)}>{children}</span>;
}

/** The empty state inside a table card: centred muted text and one next action, when there is one. */
export function DataTableEmpty({ message, action }: { message: ReactNode; action?: ReactNode }) {
    return <EmptyState message={message} action={action} className="py-14" />;
}

interface DataTablePaginationProps {
    /** Names the navigation ("Case list pages"). */
    label: string;
    from: number | null;
    to: number | null;
    total: number;
    prevUrl: string | null;
    nextUrl: string | null;
    /** Extra Link props for the page links (for example `only` for a partial reload). */
    linkProps?: Partial<ComponentProps<typeof Link>>;
}

/** "Showing x–y of n" and Previous / Next (real links; a missing page is a disabled button). */
export function DataTablePagination({ label, from, to, total, prevUrl, nextUrl, linkProps }: DataTablePaginationProps) {
    return (
        <nav aria-label={label} className="flex flex-wrap items-center justify-between gap-3 border-t px-3 py-2.5 text-sm">
            <span className="text-muted-foreground tabular-nums">
                Showing {from ?? 0}–{to ?? 0} of {total}
            </span>
            <span className="flex gap-2">
                <PageLink href={prevUrl} label="Previous" linkProps={linkProps} />
                <PageLink href={nextUrl} label="Next" linkProps={linkProps} />
            </span>
        </nav>
    );
}

function PageLink({ href, label, linkProps }: { href: string | null; label: string; linkProps?: Partial<ComponentProps<typeof Link>> }) {
    return href ? (
        <Button asChild variant="outline" size="sm">
            <Link href={href} preserveScroll preserveState {...linkProps}>
                {label}
            </Link>
        </Button>
    ) : (
        <Button variant="outline" size="sm" disabled>
            {label}
        </Button>
    );
}
