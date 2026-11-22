import { type ProgressMilestone } from '@/types/requests';
import { CircleCheck } from 'lucide-react';

/**
 * OVERNIGHT-2 (B1): the vendor's Progress timeline. Dated milestones in the
 * server's words and order; this only renders them (Blueprint timeline:
 * vertical, compact, the icon snapped to the start, a neutral rail).
 */
export function ProgressTimeline({ milestones }: { milestones: ProgressMilestone[] }) {
    if (milestones.length === 0) {
        return null;
    }

    const last = milestones.length - 1;

    return (
        <section aria-labelledby="progress-heading" className="card bg-base-100 border-base-300 min-w-0 border">
            <div className="card-body min-w-0 gap-3 p-4">
                <h2 id="progress-heading" className="card-title text-lg capitalize">
                    Progress
                </h2>
                <ol className="timeline timeline-vertical timeline-compact timeline-snap-icon">
                    {milestones.map((m, i) => (
                        <li key={m.key}>
                            {i > 0 && <hr className="bg-base-300" />}
                            <div className="timeline-middle">
                                <CircleCheck aria-hidden="true" className="text-primary size-5" />
                            </div>
                            <div className={i < last ? 'timeline-end mb-4 text-sm' : 'timeline-end text-sm'}>
                                <span className="font-medium">{m.label}</span>{' '}
                                <time dateTime={m.date} className="text-base-content/70 tabular-nums">
                                    {m.date_label}
                                </time>
                            </div>
                            {i < last && <hr className="bg-base-300" />}
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
