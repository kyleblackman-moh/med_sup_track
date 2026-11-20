import { Panel } from '@/components/ui/panel';
import { Link } from '@inertiajs/react';
import { Circle, CircleCheck } from 'lucide-react';

/** OVERNIGHT-2 (C1): one stage of the Pipeline, counted and worded by the server. */
export interface PipelineStage {
    status: string;
    label: string;
    count: number;
    href: string;
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

const linkClass = 'focus-visible:ring-ring rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-offset-2';

/** A plain count per stage, each linking to the case list for that stage. No time colouring. */
export function PipelinePanel({ stages }: { stages: PipelineStage[] }) {
    return (
        <Panel title="Pipeline">
            <ol className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                {stages.map((stage) => (
                    <li key={stage.status}>
                        <Link
                            href={stage.href}
                            className="hover:border-input focus-visible:ring-ring flex h-full flex-col rounded-lg border px-3 py-2 outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                        >
                            <span className="text-muted-foreground text-helper">{stage.label}</span>
                            <span className="text-lg font-semibold tabular-nums">{stage.count}</span>
                        </Link>
                    </li>
                ))}
            </ol>
        </Panel>
    );
}

/** In Review cases with the three gate items: an icon plus text for each, and who holds an open one. */
export function GateTrackerPanel({ tracker }: { tracker: GateTrackerData }) {
    return (
        <Panel title="Gate Tracker" bodyClassName="space-y-3">
            {tracker.rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">No cases are in review.</p>
            ) : (
                <ul className="divide-y">
                    {tracker.rows.map((row) => (
                        <li key={row.id} className="flex flex-col gap-2 py-3 md:flex-row md:items-start md:gap-4">
                            <div className="min-w-0 md:w-56 md:shrink-0">
                                <Link href={row.href} className={`font-mono text-sm font-medium ${linkClass}`}>
                                    {row.reference}
                                </Link>
                                <p className="text-muted-foreground text-helper truncate">{row.vendor}</p>
                            </div>
                            <ul className="grid flex-1 gap-2 sm:grid-cols-3">
                                {row.items.map((item) => {
                                    const Icon = item.done ? CircleCheck : Circle;
                                    return (
                                        <li key={item.key} className="flex gap-2 text-sm">
                                            <Icon
                                                aria-hidden="true"
                                                className={
                                                    item.done ? 'text-primary mt-0.5 size-4 shrink-0' : 'text-muted-foreground mt-0.5 size-4 shrink-0'
                                                }
                                            />
                                            <span className="min-w-0">
                                                <span className="sr-only">{item.done ? 'Done: ' : 'Not done: '}</span>
                                                <span className="font-medium">{item.label}</span>
                                                <span className="text-muted-foreground text-helper block">{item.state}</span>
                                                {item.held_by && (
                                                    <span className="text-muted-foreground text-helper block">Held by {item.held_by}</span>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
            {tracker.more_href && (
                <p className="text-sm">
                    <Link href={tracker.more_href} className={`text-primary ${linkClass}`}>
                        See all {tracker.total} cases in review
                    </Link>
                </p>
            )}
        </Panel>
    );
}
