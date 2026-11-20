import { StatusPill } from '@/components/ui/status-pill';
import { type ActivityRow, type GateRow } from '@/types/dashboard';
import { Link } from '@inertiajs/react';
import { Activity, Check, CircleDot, ShieldCheck } from 'lucide-react';

const TZ = 'America/Port_of_Spain';

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('en-TT', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/** Gate tracker (spec 12.2 C): in-review cases with their three ticks and who the case waits on. */
export function GateList({ gate }: { gate: { total: number; items: GateRow[] } }) {
    const tick = (done: boolean, label: string) =>
        done ? (
            <span className="badge badge-soft badge-success badge-sm">
                <Check className="size-3" aria-hidden="true" />
                {label}
                <span className="sr-only"> done</span>
            </span>
        ) : (
            <span className="badge badge-outline badge-sm text-base-content/70">
                {label}
                <span className="sr-only"> pending</span>
            </span>
        );

    return (
        <section
            aria-labelledby="gate-list-heading"
            className="card bg-base-100 border-base-300 border transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-3 p-4">
                <div className="flex items-baseline justify-between gap-2">
                    <h2 id="gate-list-heading" className="card-title text-base capitalize">
                        <ShieldCheck className="text-primary size-4" aria-hidden="true" />
                        Gate tracker
                    </h2>
                    <span className="text-base-content/60 text-xs tabular-nums">{gate.total} in review</span>
                </div>
                {gate.items.length === 0 ? (
                    <p className="text-base-content/60 text-sm">No cases are in review.</p>
                ) : (
                    <ul className="divide-base-300 flex flex-col divide-y">
                        {gate.items.map((row) => (
                            <li key={row.case_id} className="flex flex-col gap-1.5 py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <Link href={route('cases.show', row.case_id)} className="link link-hover font-mono text-[13px] font-medium">
                                        {row.reference_no ?? 'Case'}
                                    </Link>
                                    <span className="text-base-content/60 text-xs capitalize">
                                        {row.waiting_on.length ? `Waiting on ${row.waiting_on.join(', ')}` : 'Ready for file'}
                                    </span>
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {tick(row.technical, 'Technical')}
                                    {tick(row.funding, 'Funding')}
                                    {tick(row.hardcopy, 'Hardcopy')}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

/** Latest status changes on cases the user can see (labels and names only). */
export function ActivityFeed({ rows }: { rows: ActivityRow[] }) {
    return (
        <section
            aria-labelledby="activity-heading"
            className="card bg-base-100 border-base-300 border transition-shadow duration-200 hover:shadow-md"
        >
            <div className="card-body gap-3 p-4">
                <h2 id="activity-heading" className="card-title text-base capitalize">
                    <Activity className="text-primary size-4" aria-hidden="true" />
                    Recent activity
                </h2>
                {rows.length === 0 ? (
                    <p className="text-base-content/60 text-sm">No recent status changes.</p>
                ) : (
                    <ul className="timeline timeline-vertical timeline-compact timeline-snap-icon -ml-1">
                        {rows.map((row, i) => (
                            <li key={row.id}>
                                {i > 0 && <hr />}
                                <div className="timeline-middle">
                                    <CircleDot className="text-base-content/50 size-3.5" aria-hidden="true" />
                                </div>
                                <div className="timeline-end mb-3 min-w-0">
                                    <time className="text-base-content/60 text-xs">{when(row.at)}</time>
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <Link href={route('cases.show', row.case_id)} className="link link-hover font-mono text-[13px] font-medium">
                                            {row.reference_no ?? 'Case'}
                                        </Link>
                                        <StatusPill label={row.to_label} tone={row.to_tone} />
                                    </div>
                                    {row.actor && <p className="text-base-content/60 text-xs">by {row.actor}</p>}
                                </div>
                                {i < rows.length - 1 && <hr />}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
