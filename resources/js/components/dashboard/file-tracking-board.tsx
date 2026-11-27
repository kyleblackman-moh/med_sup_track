import { DataTable, DataTableCell, DataTableHeaderCell, DataTableRow } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { caseTitle } from '@/lib/case-title';
import { rowLink } from '@/lib/row-link';
import { cn } from '@/lib/utils';
import { type TrackingBoard, type TrackingRow, type TrackingUnit } from '@/types/tracking';
import { Link } from '@inertiajs/react';
import { ArrowRight, MapPin, Route } from 'lucide-react';

/** The unit the file is at now: a pinging dot and its name (never colour alone). */
function WhereNow({ row }: { row: TrackingRow }) {
    if (!row.current) {
        return <span className="text-base-content/60">—</span>;
    }

    return (
        <span className="grid min-w-0 gap-0.5">
            <span className="flex items-center gap-2 font-semibold">
                <span className="inline-grid shrink-0 *:[grid-area:1/1]" aria-hidden="true">
                    <span className="status status-primary motion-safe:animate-ping" />
                    <span className="status status-primary" />
                </span>
                <span className="truncate">{row.current.label}</span>
            </span>
            <span className="text-base-content/60 truncate text-xs" title={row.holder_label ?? undefined}>
                {row.stage}
                {row.holder_label && ` · ${row.holder_label}`}
            </span>
        </span>
    );
}

/** One unit's days: the current unit is marked "Now" in words as well as tinted. */
function UnitDays({ unit }: { unit: TrackingUnit }) {
    return (
        <span className={cn('flex items-center justify-end gap-1.5 tabular-nums', !unit.visited && 'text-base-content/40')}>
            {unit.current && <span className="badge badge-primary badge-soft badge-xs capitalize">Now</span>}
            <span className={unit.current ? 'font-semibold' : undefined}>{unit.days}</span>
        </span>
    );
}

/** A thin bar of the four units, each as long as its share of the file's time (phones). */
function UnitStrip({ units }: { units: TrackingUnit[] }) {
    const total = units.reduce((sum, u) => sum + u.seconds, 0);
    if (total === 0) {
        return null;
    }

    return (
        <div className="bg-base-200 rounded-box flex h-1.5 w-full overflow-hidden" aria-hidden="true">
            {units
                .filter((u) => u.seconds > 0)
                .map((u) => (
                    <span
                        key={u.key}
                        className={cn('border-base-100 h-full basis-0 border-e last:border-e-0', u.current ? 'bg-primary' : 'bg-base-content/25')}
                        style={{ flexGrow: u.seconds }}
                    />
                ))}
        </div>
    );
}

/**
 * Kyle, 2026-11-24: the dashboard's File Tracking board. Every open file the
 * user can see: what it is (drug and DIN, then the reference), where it is
 * now, when it last arrived there, and its days at each unit (CMO's Office,
 * Technocrats, Legal Unit, PS Office). Longest at its current unit first.
 * Everything is worked out on the server (FileTracking::board); the row opens
 * the case. Phones get the same rows as a list with a time strip.
 */
export function FileTrackingBoard({ board }: { board: TrackingBoard }) {
    const unitKeys = Object.keys(board.units);
    const cell = 'border-base-300 h-14 px-4';

    return (
        <section
            id="file-tracking"
            aria-labelledby="file-tracking-heading"
            className="card bg-base-100 border-base-300 min-w-0 overflow-hidden border shadow-xs transition-shadow duration-200 hover:shadow-md"
        >
            <div className="border-base-300 flex flex-wrap items-center justify-between gap-3 border-b p-4 sm:px-6">
                <div className="min-w-0">
                    <h2 id="file-tracking-heading" className="flex items-center gap-2 text-lg font-semibold capitalize">
                        <Route className="text-base-content/50 size-5" aria-hidden="true" />
                        File Tracking
                        <span className="badge badge-ghost badge-sm tabular-nums">
                            {board.total}
                            <span className="sr-only"> open {board.total === 1 ? 'file' : 'files'}</span>
                        </span>
                    </h2>
                    <p className="text-base-content/60 text-sm">Where each open file is, when it arrived and its days at every unit</p>
                </div>
            </div>

            {board.rows.length === 0 ? (
                <EmptyState message="No open files to track." />
            ) : (
                <>
                    <DataTable
                        caption="Open files: where each one is and its days at every unit"
                        className="table-sm table min-w-[60rem]"
                        wrapperClassName="max-md:hidden"
                    >
                        <thead>
                            <tr className="capitalize">
                                <DataTableHeaderCell className="bg-base-200 text-base-content/70 border-base-300 w-full px-4">
                                    File
                                </DataTableHeaderCell>
                                <DataTableHeaderCell className="bg-base-200 text-base-content/70 border-base-300 px-4">
                                    Where It Is
                                </DataTableHeaderCell>
                                <DataTableHeaderCell className="bg-base-200 text-base-content/70 border-base-300 px-4">
                                    Last Received
                                </DataTableHeaderCell>
                                {unitKeys.map((key) => (
                                    <DataTableHeaderCell key={key} className="bg-base-200 text-base-content/70 border-base-300 px-4 text-right">
                                        {board.units[key]}
                                    </DataTableHeaderCell>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {board.rows.map((row) => (
                                <DataTableRow key={row.id} linked>
                                    <DataTableCell className={cn(cell, 'max-w-0')}>
                                        <Link href={row.href} className={cn('block truncate font-mono text-sm font-semibold', rowLink)}>
                                            {caseTitle(row.item, row.din, row.reference)}
                                        </Link>
                                        <span className="text-base-content/60 block truncate text-xs">{row.reference}</span>
                                    </DataTableCell>
                                    <DataTableCell className={cn(cell, 'max-w-56')}>
                                        <WhereNow row={row} />
                                    </DataTableCell>
                                    <DataTableCell className={cn(cell, 'whitespace-nowrap')}>
                                        {row.current ? (
                                            <span className="grid">
                                                <time dateTime={row.current.received_at_iso ?? undefined} className="tabular-nums">
                                                    {row.current.received_at}
                                                </time>
                                                <span className="text-base-content/60 text-xs">{row.current.days} there</span>
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </DataTableCell>
                                    {row.units.map((unit) => (
                                        <DataTableCell
                                            key={unit.key}
                                            className={cn(cell, 'text-right whitespace-nowrap', unit.current && 'bg-primary/5')}
                                        >
                                            <UnitDays unit={unit} />
                                        </DataTableCell>
                                    ))}
                                </DataTableRow>
                            ))}
                        </tbody>
                    </DataTable>

                    <ul className="list md:hidden" aria-label="Open files: where each one is">
                        {board.rows.map((row) => (
                            <li key={row.id} className="list-row hover:bg-base-200 focus-within:bg-base-200 relative transition-colors">
                                <div className="list-col-grow grid min-w-0 gap-1.5">
                                    <Link href={row.href} className={cn('truncate font-mono text-sm font-semibold', rowLink)}>
                                        {caseTitle(row.item, row.din, row.reference)}
                                    </Link>
                                    <span className="text-base-content/60 -mt-1 truncate text-xs">{row.reference}</span>
                                    <span className="flex items-center gap-1.5 text-sm">
                                        <MapPin className="text-primary size-4 shrink-0" aria-hidden="true" />
                                        <span className="font-semibold">{row.current?.label ?? '—'}</span>
                                        {row.current && (
                                            <span className="text-base-content/60 text-xs">
                                                since {row.current.received_at} · {row.current.days}
                                            </span>
                                        )}
                                    </span>
                                    <UnitStrip units={row.units} />
                                    <dl className="text-base-content/70 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                                        {row.units.map((unit) => (
                                            <div key={unit.key} className="flex justify-between gap-2">
                                                <dt>{unit.label}</dt>
                                                <dd className={cn('tabular-nums', unit.current && 'text-base-content font-semibold')}>{unit.days}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </div>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {board.more_href && (
                <div className="border-base-300 text-base-content/70 flex flex-wrap items-center justify-between gap-2 border-t px-4 py-2.5 text-sm">
                    <p>Showing the {board.rows.length} longest-held files. The case list has them all.</p>
                    <Link href={board.more_href} className="btn btn-ghost btn-sm capitalize transition-transform active:scale-[0.97]">
                        All Cases
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </div>
            )}
        </section>
    );
}
