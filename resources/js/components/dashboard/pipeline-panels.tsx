import { useFill } from '@/components/dashboard/motion';
import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Route, ShieldCheck } from 'lucide-react';

/** OVERNIGHT-2 (C1): one stage of the Pipeline, counted and worded by the server. */
export interface PipelineStage {
    status: string;
    label: string;
    count: number;
    href: string;
    /**
     * A stage on the viewer's own desk. Optional: DashboardView::pipeline does
     * not send it yet, so no stage is marked until the server adds it.
     */
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
                className="hover:bg-base-200 rounded-field -mx-2 grid grid-cols-[minmax(0,1fr)_4rem_2.5rem] items-center gap-3 px-2 py-1.5 text-sm transition-colors focus-visible:outline-2"
            >
                <span className="flex min-w-0 items-center gap-2">
                    <span className={stage.mine ? 'status status-primary shrink-0' : 'status shrink-0'} aria-hidden="true" />
                    <span className={stage.mine ? 'truncate font-semibold capitalize' : 'text-base-content/80 truncate capitalize'}>
                        {stage.label}
                    </span>
                    {stage.mine && <span className="sr-only">(your desk)</span>}
                </span>
                <progress
                    className={stage.mine ? 'progress progress-primary h-1.5 w-full' : 'progress h-1.5 w-full'}
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
 * "Cases by stage": the server's stages in workflow order, each a count and a
 * bar that fills on load, linking to the case list for that stage. Desk stages
 * (when the server marks them) carry a status dot and a bold label. No time colouring.
 */
export function PipelinePanel({ stages }: { stages: PipelineStage[] }) {
    const max = Math.max(1, ...stages.map((s) => s.count));
    const marked = stages.some((s) => s.mine);

    return (
        <section
            aria-labelledby="stage-rail-heading"
            className="card card-sm bg-base-100 border-base-300 min-w-0 border transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-3">
                <h2 id="stage-rail-heading" className="card-title text-base capitalize">
                    <Route className="text-base-content/60 size-4" aria-hidden="true" />
                    Cases by stage
                </h2>
                {marked && (
                    <p className="text-base-content/60 flex items-center gap-1.5 text-xs">
                        <span className="status status-primary" aria-hidden="true" />
                        Bold stages sit on your desk
                    </p>
                )}
                <ol className="flex flex-col">
                    {stages.map((stage) => (
                        <StageRow key={stage.status} stage={stage} max={max} />
                    ))}
                </ol>
            </div>
        </section>
    );
}

/** In Review cases with the three gate items: a badge plus text for each, and who holds an open one. */
export function GateTrackerPanel({ tracker }: { tracker: GateTrackerData }) {
    return (
        <section
            aria-labelledby="gate-tracker-heading"
            className="card card-sm bg-base-100 border-base-300 min-w-0 border transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-3">
                <div className="flex items-baseline justify-between gap-2">
                    <h2 id="gate-tracker-heading" className="card-title text-base capitalize">
                        <ShieldCheck className="text-base-content/60 size-4" aria-hidden="true" />
                        Gate Tracker
                    </h2>
                    <span className="text-base-content/60 text-xs tabular-nums">{tracker.total} in review</span>
                </div>
                {tracker.rows.length === 0 ? (
                    <p className="text-base-content/60 text-sm">No cases are in review.</p>
                ) : (
                    <ul className="divide-base-300 flex flex-col divide-y">
                        {tracker.rows.map((row) => (
                            <li key={row.id} className="flex flex-col gap-2 py-2.5 first:pt-0 last:pb-0">
                                <div className="min-w-0">
                                    <Link href={row.href} className="link link-hover font-mono text-sm font-medium">
                                        {row.reference}
                                    </Link>
                                    <p className="text-base-content/60 truncate text-xs">{row.vendor}</p>
                                </div>
                                <ul className="flex flex-col gap-1.5">
                                    {row.items.map((item) => (
                                        <li key={item.key} className="flex items-start gap-2 text-sm">
                                            <span
                                                className={
                                                    item.done
                                                        ? 'badge badge-soft badge-success badge-sm shrink-0 capitalize'
                                                        : 'badge badge-outline badge-sm text-base-content/70 shrink-0 capitalize'
                                                }
                                            >
                                                {item.done && <Check className="size-3" aria-hidden="true" />}
                                                <span className="sr-only">{item.done ? 'Done: ' : 'Not done: '}</span>
                                                {item.label}
                                            </span>
                                            <span className="min-w-0 text-xs leading-5">
                                                <span className="text-base-content/70 block">{item.state}</span>
                                                {item.held_by && <span className="text-base-content/60 block">Held by {item.held_by}</span>}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}
                {tracker.more_href && (
                    <div className="card-actions justify-end">
                        <Link href={tracker.more_href} className="btn btn-ghost btn-sm transition-transform active:scale-[0.97]">
                            See all {tracker.total} cases in review
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    </div>
                )}
            </div>
        </section>
    );
}
