import { Link } from '@inertiajs/react';

/** OVERNIGHT-2 (C1): one stage of the Pipeline, counted and worded by the server. */
export interface PipelineStage {
    status: string;
    label: string;
    count: number;
    href: string;
}

/** A plain count per stage, each linking to the case list for that stage. No time colouring. */
export function PipelinePanel({ stages }: { stages: PipelineStage[] }) {
    return (
        <section aria-labelledby="pipeline-heading" className="bg-card shadow-card space-y-3 rounded-xl border p-4">
            <h2 id="pipeline-heading" className="text-[15px] font-semibold">
                Pipeline
            </h2>
            <ol className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                {stages.map((stage) => (
                    <li key={stage.status}>
                        <Link
                            href={stage.href}
                            className="hover:border-input focus-visible:ring-ring flex h-full flex-col rounded-lg border px-3 py-2 outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                        >
                            <span className="text-muted-foreground text-[13px]">{stage.label}</span>
                            <span className="text-lg font-semibold tabular-nums">{stage.count}</span>
                        </Link>
                    </li>
                ))}
            </ol>
        </section>
    );
}
