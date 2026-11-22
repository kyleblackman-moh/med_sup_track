import { useFill } from '@/components/dashboard/motion';
import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Route, ShieldCheck } from 'lucide-react';

/** OVERNIGHT-2 (C1): one stage of the Pipeline, counted and worded by the server. */
export interface PipelineStage {
    status: string;
    label: string;
    count: number;
    href: string;
    /** A stage on the viewer's own desk (status dot and bold label). */
    mine?: boolean;
}

/** OVERNIGHT-2 (C2): one gate item on a Gate Tracker row. */
export interface GateItem {
    key: string;
    label: string;
    done: boolean;
    state: string;
    /** Who holds the item while it is open; null once done. */
    held_by: string | null;
}

export interface GateTrackerRow {
    id: string;
    reference: string;
    vendor: string;
    href: string;
    items: GateItem[];
}

export interface GateTrackerData {
    rows: GateTrackerRow[];
    total: number;
    /** The full In Review list, when there are more rows than shown. */
    more_href: string | null;
}

function StageRow({ stage, max }: { stage: PipelineStage; max: number }) {
    const fill = useFill(stage.count);

    return (
        <li>
            <Link
                href={stage.href}
                className="hover:bg-base-200 rounded-field -mx-2 grid grid-cols-[minmax(0,12rem)_minmax(0,1fr)_2.5rem] items-center gap-3 px-2 py-1.5 text-sm transition-colors focus-visible:outline-2"
            >
                <span className="flex min-w-0 items-center gap-2">
                    <span className={stage.mine ? 'status status-primary shrink-0' : 'status shrink-0'} aria-hidden="true" />
                    <span className={stage.mine ? 'truncate font-semibold capitalize' : 'text-base-content/80 truncate capitalize'}>
                        {stage.label}
                    </span>
                    {stage.mine && <span className="sr-only">(your desk)</span>}
                </span>
                <progress
                    className={stage.mine ? 'progress progress-primary h-2.5 w-full' : 'progress h-2.5 w-full'}
                    value={fill}
                    max={max}
                    aria-hidden="true"
                />
                <span className="text-right font-medium tabular-nums">{stage.count}</span>
            </Link>
        </li>
    );
}

/**
 * "Cases By Stage": the server's stages in workflow order, each a count and a
 * bar that fills on load, linking to the case list for that stage. Desk stages
 * (when the server marks them) carry a status dot and a bold label. No time colouring.
 */
export function PipelinePanel({ stages }: { stages: PipelineStage[] }) {
    const max = Math.max(1, ...stages.map((s) => s.count));
    const marked = stages.some((s) => s.mine);
    const total = stages.reduce((sum, s) => sum + s.count, 0);

    return (
        <section
            aria-labelledby="stage-rail-heading"
            className="card bg-base-100 border-base-300 min-w-0 border shadow-xs transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-4 p-4 sm:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id="stage-rail-heading" className="flex items-center gap-2 text-lg font-semibold capitalize">
                            <Route className="text-base-content/50 size-5" aria-hidden="true" />
                            Cases By Stage
                        </h2>
                        {marked && (
                            <p className="text-base-content/60 flex items-center gap-1.5 text-sm">
                                <span className="status status-primary" aria-hidden="true" />
                                Bold stages sit on your desk
                            </p>
                        )}
                    </div>
                    <span className="text-base-content/60 shrink-0 text-sm tabular-nums">{total} open</span>
                </div>
                {stages.length === 0 ? (
                    <p className="text-base-content/60 py-6 text-center text-sm">There are no open cases.</p>
                ) : (
                    <ol className="flex flex-col gap-1">
                        {stages.map((stage) => (
                            <StageRow key={stage.status} stage={stage} max={max} />
                        ))}
                    </ol>
                )}
            </div>
        </section>
    );
}

/** In Review cases with the three gate items: a badge per item (icon plus text, never colour alone), then who holds the open ones. */
export function GateTrackerPanel({ tracker }: { tracker: GateTrackerData }) {
    return (
        <section
            aria-labelledby="gate-tracker-heading"
            className="card bg-base-100 border-base-300 min-w-0 border shadow-xs transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-3 p-4 sm:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id="gate-tracker-heading" className="flex items-center gap-2 text-lg font-semibold capitalize">
                            <ShieldCheck className="text-base-content/50 size-5" aria-hidden="true" />
                            Gate Tracker
                        </h2>
                        <p className="text-base-content/60 text-sm">Technical, funding and hardcopy for each case in review</p>
                    </div>
                    <span className="text-base-content/60 shrink-0 text-sm tabular-nums">{tracker.total} in review</span>
                </div>
                {tracker.rows.length === 0 ? (
                    <p className="text-base-content/60 py-6 text-center text-sm">No cases are in review.</p>
                ) : (
                    <ul className="divide-base-300 flex flex-col divide-y">
                        {tracker.rows.map((row) => {
                            const open = row.items.filter((item) => !item.done);

                            return (
                                <li key={row.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <Link href={row.href} className="link link-hover font-mono text-sm font-medium">
                                                {row.reference}
                                            </Link>
                                            <p className="text-base-content/60 truncate text-xs">{row.vendor}</p>
                                        </div>
                                        <ul className="flex flex-wrap gap-1" aria-label={`Gate items for ${row.reference}`}>
                                            {row.items.map((item) => (
                                                <li
                                                    key={item.key}
                                                    className={
                                                        item.done
                                                            ? 'badge badge-soft badge-success badge-sm capitalize'
                                                            : 'badge badge-outline badge-sm text-base-content/70 capitalize'
                                                    }
                                                >
                                                    {item.done && <Check className="size-3" aria-hidden="true" />}
                                                    <span className="sr-only">{item.done ? 'Done: ' : 'Not done: '}</span>
                                                    {item.label}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                    {open.length === 0 ? (
                                        <p className="text-base-content/60 text-xs">All three gate items are done.</p>
                                    ) : (
                                        <ul className="text-base-content/60 flex flex-col gap-0.5 text-xs">
                                            {open.map((item) => (
                                                <li key={item.key}>
                                                    <span className="text-base-content/80 font-medium capitalize">{item.label}</span>: {item.state}
                                                    {item.held_by && <> · held by {item.held_by}</>}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
                {tracker.more_href && (
                    <div className="border-base-300 flex items-center justify-end border-t pt-3">
                        <Link href={tracker.more_href} className="btn btn-ghost btn-sm capitalize transition-transform active:scale-[0.97]">
                            See All {tracker.total} In Review
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    </div>
                )}
            </div>
        </section>
    );
}
