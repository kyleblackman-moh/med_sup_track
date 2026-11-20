import { useFill } from '@/components/dashboard/motion';
import { type StatusTone } from '@/types/cases';
import { type DashboardStage, type DashboardStages } from '@/types/dashboard';
import { Link } from '@inertiajs/react';
import { Route } from 'lucide-react';

// Tone → progress colour. Full class names only (never built from strings).
const BAR: Record<StatusTone, string> = {
    neutral: 'progress h-1.5',
    info: 'progress h-1.5',
    success: 'progress progress-success h-1.5',
    warning: 'progress progress-warning h-1.5',
    danger: 'progress progress-error h-1.5',
};

const SUBTITLE: Record<DashboardStages['scope'], string> = {
    all: 'All open cases',
    assigned: 'Cases you hold or were assigned',
    own: "Your organisation's requests",
};

function Row({ stage, max, href }: { stage: DashboardStage; max: number; href: string | null }) {
    const fill = useFill(stage.count);
    const inner = (
        <>
            <span className="flex min-w-0 items-center gap-2">
                <span className={stage.mine ? 'status status-primary shrink-0' : 'status shrink-0'} aria-hidden="true" />
                <span className={stage.mine ? 'truncate font-semibold capitalize' : 'text-base-content/80 truncate capitalize'}>{stage.label}</span>
                {stage.mine && <span className="sr-only">(your desk)</span>}
            </span>
            <progress className={BAR[stage.tone]} value={fill} max={max} aria-label={`${stage.label}: ${stage.count}`} />
            <span className="text-right font-medium tabular-nums">{stage.count}</span>
        </>
    );

    return (
        <li>
            {href ? (
                <Link
                    href={href}
                    className="hover:bg-base-200 rounded-field -mx-2 grid grid-cols-[minmax(0,1fr)_3.5rem_2rem] items-center gap-3 px-2 py-1.5 text-sm transition-colors"
                >
                    {inner}
                </Link>
            ) : (
                <div className="rounded-field -mx-2 grid grid-cols-[minmax(0,1fr)_3.5rem_2rem] items-center gap-3 px-2 py-1.5 text-sm">{inner}</div>
            )}
        </li>
    );
}

/**
 * Cases by stage, in workflow order, with the user's own stops marked (dot
 * and bold). Staff rows open File tracking filtered to that status; vendor
 * rows are plain. Assigned-only users see the stages that hold their cases
 * or sit on their desk.
 */
export function StageRail({ stages, title }: { stages: DashboardStages; title: string }) {
    const items = stages.scope === 'assigned' ? stages.items.filter((s) => s.count > 0 || s.mine) : stages.items;
    const max = Math.max(1, ...items.map((s) => s.count));
    const staff = stages.scope !== 'own';

    return (
        <section aria-labelledby="stage-heading" className="card bg-base-100 border-base-300 border transition-shadow duration-200 hover:shadow-md">
            <div className="card-body gap-3 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                    <h2 id="stage-heading" className="card-title text-base whitespace-nowrap capitalize">
                        <Route className="text-primary size-4" aria-hidden="true" />
                        {title}
                    </h2>
                    <span className="text-base-content/60 text-xs capitalize">{SUBTITLE[stages.scope]}</span>
                </div>
                {staff && (
                    <p className="text-base-content/60 flex items-center gap-1.5 text-xs">
                        <span className="status status-primary" aria-hidden="true" />
                        Bold stages sit on your desk
                    </p>
                )}
                <ol className="flex flex-col">
                    {items.map((stage) => (
                        <Row key={stage.key} stage={stage} max={max} href={staff ? `${route('cases.index')}?status=${stage.key}` : null} />
                    ))}
                </ol>
            </div>
        </section>
    );
}
