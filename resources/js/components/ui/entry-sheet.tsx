import { type ReactNode, useState } from 'react';

/** One field of a sheet line: its column header and its control (or a worked-out figure, with no htmlFor). */
export interface SheetField {
    key: string;
    label: string;
    /** The control's id; omitted for a read-only figure. */
    htmlFor?: string;
    content: ReactNode;
    /** Its share of the line in tracks (of TRACKS); lines without spans share the tracks equally. */
    span?: number;
}

/** The table's tracks: 12 equal columns (table-fixed); a field spans its `span`, else an equal share of its line. */
const TRACKS = 12;

interface EntrySheetProps {
    /** The entry's name for assistive technology ("Quotation Row 1"). */
    label: string;
    /** The collapsed bar: what the entry is at a glance (tag, supplier, status, total). The whole bar opens it. */
    summary: ReactNode;
    /** The header's main field (the Supplier label and input). */
    title: ReactNode;
    /** Small controls after it (Considered, Recommended). */
    controls?: ReactNode;
    /** The remove icon button, at the end of the header line. */
    remove?: ReactNode;
    /** The table lines (3 or 5 fields each): a row of column headers over a row of cells. */
    lines: SheetField[][];
    /**
     * Kyle, 2026-11-25: a field in its own column at the right, as tall as every line (Remarks).
     * It takes whatever width the fixed tracks leave.
     */
    aside?: SheetField;
    /** With an aside: the fixed width of one track ("2.5rem"), so the lines stay short. */
    trackWidth?: string;
    /** Under the table, full width (a reason, the entry's error list); nothing is drawn when empty. */
    below?: ReactNode;
    /** Open on first render (default true). */
    defaultOpen?: boolean;
}

/**
 * Kyle, 2026-11-24: one entry as a collapsible block (Blueprint collapse,
 * details/summary) holding a small bordered table. The bar shows the entry at
 * a glance and opens or closes it; inside, the header line holds the title
 * field, the controls and remove, then each table line is a row of column
 * headers over a row of cells. On a narrow screen the table scrolls inside its
 * block, never the page. A jump to a field inside a closed block opens it
 * (focusTarget). Presentational only: no data.
 */
export function EntrySheet({ label, summary, title, controls, remove, lines, aside, trackWidth, below, defaultOpen = true }: EntrySheetProps) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <details
            open={open}
            onToggle={(e) => setOpen(e.currentTarget.open)}
            aria-label={label}
            className="collapse-arrow bg-base-100 border-base-300 rounded-box collapse min-w-0 border"
        >
            <summary className="collapse-title hover:bg-base-200 min-h-0 py-3 ps-4 pe-10 motion-safe:transition-colors">{summary}</summary>

            <div className="collapse-content min-w-0 space-y-3 px-4">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div className="flex min-w-0 flex-1 basis-64 items-center gap-2">{title}</div>
                    {/* The choices and remove stay together on one line, at the end. */}
                    <div className="ms-auto flex items-center gap-x-4">
                        {controls}
                        {remove}
                    </div>
                </div>

                <div className="border-base-300 rounded-box max-w-full overflow-x-auto border">
                    <table
                        className="table-sm table min-w-[40rem] table-fixed"
                        // The fixed tracks plus room for the aside (at least 12rem) before the block scrolls.
                        style={trackWidth ? { minWidth: `calc(${TRACKS} * ${trackWidth} + 12rem)` } : undefined}
                    >
                        <caption className="sr-only">{label}</caption>
                        {trackWidth && (
                            <colgroup>
                                {Array.from({ length: TRACKS }, (_, track) => (
                                    <col key={track} style={{ width: trackWidth }} />
                                ))}
                                {aside && <col />}
                            </colgroup>
                        )}
                        <tbody>
                            {lines.map((line, lineIndex) => {
                                const share = TRACKS / line.length;
                                const first = lineIndex === 0 && aside;

                                return [
                                    <tr key={`${line[0]?.key}-head`} className="bg-base-200">
                                        {line.map((field) => (
                                            <th
                                                key={field.key}
                                                scope="col"
                                                colSpan={field.span ?? share}
                                                className="border-base-300 text-base-content/70 border text-xs font-medium capitalize"
                                            >
                                                {field.htmlFor ? <label htmlFor={field.htmlFor}>{field.label}</label> : field.label}
                                            </th>
                                        ))}
                                        {first && (
                                            <th scope="col" className="border-base-300 text-base-content/70 border text-xs font-medium capitalize">
                                                {aside.htmlFor ? <label htmlFor={aside.htmlFor}>{aside.label}</label> : aside.label}
                                            </th>
                                        )}
                                    </tr>,
                                    <tr key={`${line[0]?.key}-cells`}>
                                        {line.map((field) => (
                                            <td key={field.key} colSpan={field.span ?? share} className="border-base-300 border p-1.5">
                                                {field.content}
                                            </td>
                                        ))}
                                        {first && (
                                            // Down to the last cell row: past this line's cells, every later header and cell row.
                                            <td rowSpan={lines.length * 2 - 1} className="border-base-300 border p-1.5 align-top">
                                                {aside.content}
                                            </td>
                                        )}
                                    </tr>,
                                ];
                            })}
                        </tbody>
                    </table>
                </div>

                {below && <div className="grid gap-3">{below}</div>}
            </div>
        </details>
    );
}
