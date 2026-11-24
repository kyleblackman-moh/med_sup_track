import { cn } from '@/lib/utils';
import { type CaseTracking, type TrackingStage } from '@/types/tracking';
import { Building2, CircleCheck, Clock, MapPin, Users } from 'lucide-react';
import { useState } from 'react';

/** Each unit's fill on the journey bar (the theme's status palette). The unit is always named too, never colour alone. */
const UNIT_FILL: Record<string, string> = {
    cmo: 'bg-primary',
    technocrats: 'bg-status-teal',
    legal: 'bg-status-violet',
    ps: 'bg-status-fuchsia',
};
const NO_UNIT_FILL = 'bg-base-content/30';

function holdersText(stage: TrackingStage): string {
    if (stage.holders.length === 0) {
        return 'No one';
    }

    return stage.holders.map((h) => (h.name ? `${h.name} (${h.label})` : h.label)).join(', ');
}

/**
 * Kyle, 2026-11-24: the case page's File Tracking tab. Three readings of the
 * same server data (FileTracking::units and ::timeline):
 *
 * 1. Where the file is now: the unit, since when, and who holds it.
 * 2. The route (daisyUI steps): CMO's Office, Technocrats, Legal Unit, PS
 *    Office, each with its total days; reached units are filled, the current
 *    one carries a pin and "Now".
 * 3. The journey bar: one segment per stage visit, as long as the time it
 *    took (returns included). Choosing a segment, or a row of the stage list
 *    under it, shows that visit's details; the open stage is chosen at first.
 */
export function FileJourney({ tracking }: { tracking: CaseTracking }) {
    const { current, units, stages } = tracking;
    const [chosen, setChosen] = useState(() => Math.max(0, stages.length - 1));
    const total = stages.reduce((sum, s) => sum + s.duration_seconds, 0);
    const stage = stages[chosen];

    if (stages.length === 0) {
        return <p className="text-base-content/70 text-sm">This file has not moved yet.</p>;
    }

    return (
        <div className="grid min-w-0 gap-5">
            {/* 1. Where it is now. */}
            <div className="border-base-300 bg-base-200/50 rounded-box flex flex-wrap items-center gap-x-6 gap-y-3 border p-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="bg-primary/10 text-primary rounded-field flex size-10 shrink-0 items-center justify-center" aria-hidden="true">
                        {current ? <MapPin className="size-5" /> : <CircleCheck className="size-5" />}
                    </span>
                    <div className="min-w-0">
                        <p className="text-base-content/60 text-xs capitalize">{current ? 'Where it is now' : 'File closed'}</p>
                        <p className="flex items-center gap-2 text-lg font-semibold">
                            {current && (
                                <span className="inline-grid shrink-0 *:[grid-area:1/1]" aria-hidden="true">
                                    <span className="status status-primary motion-safe:animate-ping" />
                                    <span className="status status-primary" />
                                </span>
                            )}
                            {current?.label ?? 'No unit holds it'}
                        </p>
                    </div>
                </div>
                {current && (
                    <dl className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <div>
                            <dt className="text-base-content/60 text-xs capitalize">Received</dt>
                            <dd className="font-medium tabular-nums">
                                <time dateTime={current.received_at_iso}>{current.received_at}</time>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-base-content/60 text-xs capitalize">Time There</dt>
                            <dd className="font-medium tabular-nums">{current.days}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className="text-base-content/60 text-xs capitalize">Held By</dt>
                            <dd className="font-medium">{holdersText(stages[stages.length - 1])}</dd>
                        </div>
                    </dl>
                )}
            </div>

            {/* 2. The route through the four units. */}
            <div className="max-w-full overflow-x-auto">
                <ul className="steps steps-vertical sm:steps-horizontal w-full min-w-0" aria-label="The file's route through the units">
                    {units.map((unit) => (
                        <li key={unit.key} className={cn('step', unit.visited && 'step-primary')} aria-current={unit.current ? 'step' : undefined}>
                            <span className="step-icon">
                                {unit.current ? (
                                    <MapPin className="size-4" aria-hidden="true" />
                                ) : unit.visited ? (
                                    <CircleCheck className="size-4" aria-hidden="true" />
                                ) : null}
                            </span>
                            <span className="grid text-start sm:text-center">
                                <span className={cn('text-sm', unit.current ? 'font-semibold' : 'font-medium')}>
                                    {unit.label}
                                    {unit.current && <span className="badge badge-primary badge-soft badge-xs ms-1.5 capitalize">Now</span>}
                                </span>
                                <span className="text-base-content/60 text-xs tabular-nums">{unit.visited ? unit.days : 'Not reached'}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            {/* 3. The journey bar: every stage visit by its share of the time. */}
            <div className="grid gap-2">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold capitalize">Journey</h3>
                    <p className="text-base-content/60 text-xs">Choose a part of the bar to see that stage</p>
                </div>
                <div className="bg-base-200 rounded-box flex h-8 w-full gap-0.5 overflow-hidden p-0.5" role="group" aria-label="Stages by time taken">
                    {stages.map((s, i) => (
                        <button
                            key={`${s.status}-${s.entered_at_iso}`}
                            type="button"
                            onClick={() => setChosen(i)}
                            aria-pressed={i === chosen}
                            aria-label={`${s.label}${s.unit_label ? `, ${s.unit_label}` : ''}: ${s.duration}${s.open ? ', still open' : ''}`}
                            title={`${s.label}: ${s.duration}`}
                            className={cn(
                                'rounded-field min-w-2 basis-0 cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-offset-1 motion-safe:transition-opacity',
                                s.unit ? UNIT_FILL[s.unit] : NO_UNIT_FILL,
                                i === chosen ? 'ring-base-content ring-2 ring-offset-1' : 'opacity-70 hover:opacity-100',
                                // The open stage breathes (and its label says "still open"); still for reduced motion.
                                s.open && 'motion-safe:animate-pulse',
                            )}
                            style={{ flexGrow: total > 0 ? Math.max(s.duration_seconds, total * 0.01) : 1 }}
                        />
                    ))}
                </div>
                <ul className="text-base-content/70 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Key">
                    {units.map((unit) => (
                        <li key={unit.key} className="flex items-center gap-1.5">
                            <span className={cn('rounded-field size-2.5', UNIT_FILL[unit.key] ?? NO_UNIT_FILL)} aria-hidden="true" />
                            {unit.label}
                        </li>
                    ))}
                </ul>
            </div>

            {/* The chosen stage, then every stage as a list (a row chooses it too). */}
            {stage && (
                <div className="border-base-300 rounded-box grid gap-3 border p-4" aria-live="polite">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-base font-semibold">{stage.label}</h3>
                        {stage.unit_label && <span className="badge badge-ghost badge-sm">{stage.unit_label}</span>}
                        {stage.open && <span className="badge badge-primary badge-soft badge-sm capitalize">Current</span>}
                    </div>
                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                <Building2 className="size-3.5" aria-hidden="true" />
                                Arrived
                            </dt>
                            <dd className="font-medium tabular-nums">{stage.entered_at}</dd>
                        </div>
                        <div>
                            <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                <Building2 className="size-3.5" aria-hidden="true" />
                                Left
                            </dt>
                            <dd className="font-medium tabular-nums">{stage.left_at ?? 'Still there'}</dd>
                        </div>
                        <div>
                            <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                <Clock className="size-3.5" aria-hidden="true" />
                                Time Taken
                            </dt>
                            <dd className="font-medium tabular-nums">{stage.duration}</dd>
                        </div>
                        <div className="min-w-0">
                            <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                <Users className="size-3.5" aria-hidden="true" />
                                Held By
                            </dt>
                            <dd className="font-medium">{holdersText(stage)}</dd>
                        </div>
                    </dl>
                </div>
            )}

            <ul className="timeline timeline-vertical timeline-compact timeline-snap-icon">
                {stages.map((s, i) => (
                    <li key={`${s.status}-${s.entered_at_iso}`}>
                        {i > 0 && <hr className="bg-base-300" />}
                        <div className="timeline-middle">
                            <span className={cn('block size-3 rounded-full', s.unit ? UNIT_FILL[s.unit] : NO_UNIT_FILL)} aria-hidden="true" />
                        </div>
                        <div className="timeline-end mb-2 w-full min-w-0">
                            <button
                                type="button"
                                onClick={() => setChosen(i)}
                                aria-pressed={i === chosen}
                                className={cn(
                                    'rounded-field flex w-full min-w-0 cursor-pointer flex-wrap items-baseline justify-between gap-x-3 px-2 py-1 text-start outline-none focus-visible:ring-2 motion-safe:transition-colors',
                                    i === chosen ? 'bg-base-200' : 'hover:bg-base-200',
                                )}
                            >
                                <span className="min-w-0">
                                    <span className="text-sm font-medium">{s.label}</span>
                                    <span className="text-base-content/60 ms-2 text-xs">{s.unit_label ?? '—'}</span>
                                </span>
                                <span className="text-base-content/60 text-xs tabular-nums">
                                    {s.entered_at} · {s.duration}
                                    {s.open && ' so far'}
                                </span>
                            </button>
                        </div>
                        {i < stages.length - 1 && <hr className="bg-base-300" />}
                    </li>
                ))}
            </ul>
        </div>
    );
}
