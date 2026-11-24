import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/** One field of a sheet line: its column header and its control (or a worked-out figure, with no htmlFor). */
export interface SheetField {
    key: string;
    label: string;
    /** The control's id; omitted for a read-only figure. */
    htmlFor?: string;
    content: ReactNode;
}

/**
 * The sheet's table lines: hairline cell borders (1px gaps over base-300). Once
 * the block is 40rem wide a line of five shares 15 tracks 3 each and a line of
 * three 5 each; narrower, two fields a row, and the last field of an odd line
 * spans both (a container query: never a sideways scroll).
 */
const SHEET_LINE = 'bg-base-300 grid grid-cols-2 gap-px @min-[40rem]:grid-cols-15';
const SPAN: Record<number, { cell: string; last: string }> = {
    3: { cell: '@min-[40rem]:col-span-5', last: 'col-span-2 @min-[40rem]:col-span-5' },
    5: { cell: '@min-[40rem]:col-span-3', last: 'col-span-2 @min-[40rem]:col-span-3' },
};

function SheetCell({ field, className }: { field: SheetField; className?: string }) {
    return (
        <div className={cn('bg-base-100 flex min-w-0 flex-col', className)}>
            <div className="bg-base-200 text-base-content/70 px-3 py-1.5 text-xs font-medium capitalize">
                {field.htmlFor ? <label htmlFor={field.htmlFor}>{field.label}</label> : <span>{field.label}</span>}
            </div>
            <div className="flex min-h-12 min-w-0 items-center px-2 py-1.5">{field.content}</div>
        </div>
    );
}

interface EntrySheetProps {
    /** The entry's name for assistive technology ("Quotation Row 1"). */
    label: string;
    /** The small tag at the start of the header ("Quotation 1"). */
    tag: ReactNode;
    /** The header's main field (the Supplier label and input). */
    title: ReactNode;
    /** Small controls after it (Considered, Recommended). */
    controls?: ReactNode;
    /** The remove icon button: top right on a phone, at the end of the header line once wide. */
    remove?: ReactNode;
    /** The table lines (3 or 5 fields each), column headers over their fields. */
    lines: SheetField[][];
    /** Under the table, full width (a reason, the entry's error list); nothing is drawn when empty. */
    below?: ReactNode;
}

/**
 * Kyle, 2026-11-24: one entry as a small table grouped under its header (the
 * quotation's supplier block). The header holds the title field, the controls
 * and remove; under it each line is a row of column headers over a row of
 * fields. Presentational only: no data.
 */
export function EntrySheet({ label, tag, title, controls, remove, lines, below }: EntrySheetProps) {
    return (
        <div role="group" aria-label={label} className="bg-base-100 border-base-300 rounded-box @container overflow-hidden border">
            <div className="bg-base-200 border-base-300 flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-3 py-2">
                <span className="badge badge-ghost badge-sm order-1 shrink-0 capitalize tabular-nums">{tag}</span>
                <div className="order-3 flex min-w-0 flex-1 basis-full items-center gap-2 @min-[40rem]:order-2 @min-[40rem]:basis-64">{title}</div>
                {controls && <div className="order-4 @min-[40rem]:order-3">{controls}</div>}
                {remove && <div className="order-2 ms-auto @min-[40rem]:order-4 @min-[40rem]:ms-0">{remove}</div>}
            </div>

            <div className="grid gap-px">
                {lines.map((line) => (
                    <div key={line[0]?.key} className={SHEET_LINE}>
                        {line.map((field, i) => (
                            <SheetCell
                                key={field.key}
                                field={field}
                                className={i === line.length - 1 && line.length % 2 === 1 ? SPAN[line.length]?.last : SPAN[line.length]?.cell}
                            />
                        ))}
                    </div>
                ))}
            </div>

            {below && <div className="border-base-300 grid gap-3 border-t px-3 py-3">{below}</div>}
        </div>
    );
}
