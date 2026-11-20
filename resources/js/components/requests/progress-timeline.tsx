import { type ProgressMilestone } from '@/types/requests';
import { CircleCheck } from 'lucide-react';

/**
 * OVERNIGHT-2 (B1): the vendor's Progress timeline. Dated milestones in the
 * server's words and order; this only renders them.
 */
export function ProgressTimeline({ milestones }: { milestones: ProgressMilestone[] }) {
    if (milestones.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="progress-heading" className="bg-card space-y-3 rounded-lg border p-4">
            <h2 id="progress-heading" className="text-lg font-semibold">
                Progress
            </h2>
            <ol className="space-y-2">
                {milestones.map((m) => (
                    <li key={m.key} className="flex items-center gap-3 text-sm">
                        <CircleCheck aria-hidden="true" className="text-primary size-5 shrink-0" />
                        <span className="font-medium">{m.label}</span>
                        <time dateTime={m.date} className="text-muted-foreground tabular-nums">
                            {m.date_label}
                        </time>
                    </li>
                ))}
            </ol>
        </section>
    );
}
