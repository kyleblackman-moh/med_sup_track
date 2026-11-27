import { cn } from '@/lib/utils';
import { type CaseTracking, type TrackingPath, type TrackingPathStep, type TrackingStage } from '@/types/tracking';
import { Building2, CircleCheck, Clock, MapPin, Users } from 'lucide-react';
import { useState } from 'react';

/** Each unit's fill on the journey bar and the lanes (the theme's status palette). The unit is always named too, never colour alone. */
const UNIT_FILL: Record<string, string> = {
    vendor: 'bg-status-lime',
    cmo: 'bg-primary',
    technocrats: 'bg-status-teal',
    cab: 'bg-status-cyan',
    legal: 'bg-status-violet',
    ps: 'bg-status-fuchsia',
};
/** Each lane's edge beside its name. */
const LANE_EDGE: Record<string, string> = {
    vendor: 'border-s-status-lime',
    cmo: 'border-s-primary',
    technocrats: 'border-s-status-teal',
    cab: 'border-s-status-cyan',
    legal: 'border-s-status-violet',
    ps: 'border-s-status-fuchsia',
};
const NO_UNIT_FILL = 'bg-base-content/30';
const LINE = 'bg-base-content/25 absolute';

export function holdersText(stage: TrackingStage): string {
    if (stage.holders.length === 0) {
        return 'No one';
    }

    return stage.holders.map((h) => (h.name ? `${h.name} (${h.label})` : h.label)).join(', ');
}

type Segments = { left?: boolean; right?: boolean; up?: boolean; down?: boolean };

/**
 * The connector pieces of every lane × column cell: the path runs right
 * along the lane it left, then up or down the next step's column to it. CAB
 * hangs off its review step in the same column.
 */
function connectors(path: TrackingPath): Map<string, Segments> {
    const laneIndex = new Map(path.lanes.map((lane, i) => [lane.key, i]));
    const cells = new Map<string, Segments>();
    const mark = (lane: number, column: number, part: keyof Segments) => {
        const key = `${lane}:${column}`;
        cells.set(key, { ...cells.get(key), [part]: true });
    };
    const vertical = (from: number, to: number, column: number) => {
        const [top, bottom] = from < to ? [from, to] : [to, from];
        for (let lane = top; lane <= bottom; lane++) {
            if (lane > top) mark(lane, column, 'up');
            if (lane < bottom) mark(lane, column, 'down');
        }
    };

    const main = path.steps.filter((s) => s.kind !== 'beside');
    main.forEach((step, k) => {
        if (k === 0) return;
        const from = laneIndex.get(main[k - 1].lane) ?? 0;
        const to = laneIndex.get(step.lane) ?? 0;
        mark(from, step.column - 1, 'right');
        mark(from, step.column, 'left');
        vertical(from, to, step.column);
    });
    path.steps
        .filter((s) => s.kind === 'beside')
        .forEach((step) => {
            const owner = main.find((s) => s.column === step.column);
            vertical(laneIndex.get(owner?.lane ?? step.lane) ?? 0, laneIndex.get(step.lane) ?? 0, step.column);
        });

    return cells;
}

/**
 * Kyle, 2026-11-26: "Where Is The File" (was File Tracking). Three readings
 * of the same server data (FileTracking::units, ::timeline and ::path):
 *
 * 1. Where the file is now: the unit, since when, and who holds it.
 * 2. The journey bar (kept, the users like it): one segment per stage visit,
 *    as long as the time it took (returns included).
 * 3. The lanes: one coloured lane per office (the vendor, CMO's Office,
 *    Technocrats, CAB, Legal Unit, PS Office) and the file's numbered path
 *    across them, left to right. Returns show as repeat visits; CAB appears
 *    once, beside the first review; queries to the vendor branch out to its
 *    lane. The lanes scroll sideways; the lane names stay in view.
 *
 * Choosing a bar segment or a step shows its details below; the open stage
 * is chosen at first.
 *
 * Kyle, 2026-11-26: the steps are coloured circles, no numbers; the one where
 * the file is now pulses. Hovering a step names it and, for the viewer's own
 * part only, how long the file was there. A Technocrat, CAB or Legal officer
 * gets no times for the other offices: the server leaves them out
 * (FileTracking::forViewer, `mine`), and the bar is then evenly divided.
 */
export function FileJourney({ tracking }: { tracking: CaseTracking }) {
    const { current, units, stages, path, restricted = false } = tracking;
    const firstChosen = () => {
        const open = path.steps.findIndex((s) => s.open && s.kind === 'stage');
        return open >= 0 ? open : Math.max(0, path.steps.length - 1);
    };
    const [chosen, setChosen] = useState(firstChosen);
    const total = stages.reduce((sum, s) => sum + s.duration_seconds, 0);
    const step: TrackingPathStep | undefined = path.steps[chosen];
    const stage = step ? stages[step.stage] : undefined;
    const laneLabel = (key: string) => path.lanes.find((lane) => lane.key === key)?.label ?? key;
    const cells = connectors(path);
    const columns = Array.from({ length: path.columns }, (_, i) => i);

    if (stages.length === 0) {
        return <p className="text-base-content/70 text-sm">This file has not moved yet.</p>;
    }

    const chooseStage = (index: number) => {
        const found = path.steps.findIndex((s) => s.kind === 'stage' && s.stage === index);
        if (found >= 0) setChosen(found);
    };

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
                {current && current.received_at && (
                    <dl className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <div>
                            <dt className="text-base-content/60 text-xs capitalize">Received</dt>
                            <dd className="font-medium tabular-nums">
                                <time dateTime={current.received_at_iso ?? undefined}>{current.received_at}</time>
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

            {/* 2. The journey bar: every stage visit by its share of the time. */}
            <div className="grid gap-2">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold capitalize">Journey</h3>
                    <p className="text-base-content/60 text-xs">
                        {restricted ? 'Choose a part of the bar to see that stage' : 'Each part is as long as the time taken. Choose one to see it.'}
                    </p>
                </div>
                <div className="bg-base-200 rounded-box flex h-8 w-full gap-0.5 overflow-hidden p-0.5" role="group" aria-label="Stages by time taken">
                    {stages.map((s, i) => (
                        <button
                            key={`${s.status}-${i}`}
                            type="button"
                            onClick={() => chooseStage(i)}
                            aria-pressed={step?.kind === 'stage' && step.stage === i}
                            aria-label={`${s.label}${s.unit_label ? `, ${s.unit_label}` : ''}${s.duration ? `: ${s.duration}` : ''}${s.open ? ', still open' : ''}`}
                            title={s.duration ? `${s.label}: ${s.duration}` : s.label}
                            className={cn(
                                'rounded-field min-w-2 basis-0 cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-offset-1 motion-safe:transition-opacity',
                                s.unit ? UNIT_FILL[s.unit] : NO_UNIT_FILL,
                                step?.stage === i ? 'ring-base-content ring-2 ring-offset-1' : 'opacity-70 hover:opacity-100',
                                // The open stage breathes (and its label says "still open"); still for reduced motion.
                                s.open && 'motion-safe:animate-pulse',
                            )}
                            // Evenly divided when the viewer may not see every office's times.
                            style={{ flexGrow: !restricted && total > 0 ? Math.max(s.duration_seconds, total * 0.01) : 1 }}
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

            {/* 3. The lanes: the file's numbered path across the offices. */}
            <div className="grid gap-2">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold capitalize">Route Between Offices</h3>
                    <p className="text-base-content/60 text-xs">In order, left to right. Hover or choose a step to see it.</p>
                </div>
                <div className="border-base-300 rounded-box max-w-full overflow-x-auto border">
                    <div role="group" aria-label="The file's route between offices" className="w-max min-w-full">
                        {path.lanes.map((lane, laneIdx) => (
                            <div key={lane.key} className="border-base-300 even:bg-base-200/40 flex border-b last:border-b-0">
                                <div
                                    className={cn(
                                        'bg-base-100 border-base-300 sticky left-0 z-20 flex w-32 shrink-0 items-center border-s-4 border-e px-3 text-sm font-medium',
                                        LANE_EDGE[lane.key],
                                    )}
                                >
                                    <span className="line-clamp-2">{lane.label}</span>
                                </div>
                                {columns.map((column) => {
                                    const seg = cells.get(`${laneIdx}:${column}`) ?? {};
                                    const index = path.steps.findIndex((s) => s.lane === lane.key && s.column === column);
                                    const here = index >= 0 ? path.steps[index] : undefined;

                                    return (
                                        <div key={column} className="relative h-24 w-28 shrink-0">
                                            {seg.left && (
                                                <span className={cn(LINE, 'top-1/2 left-0 h-0.5 w-1/2 -translate-y-1/2')} aria-hidden="true" />
                                            )}
                                            {seg.right && (
                                                <span className={cn(LINE, 'top-1/2 left-1/2 h-0.5 w-1/2 -translate-y-1/2')} aria-hidden="true" />
                                            )}
                                            {seg.up && (
                                                <span className={cn(LINE, 'top-0 left-1/2 h-1/2 w-0.5 -translate-x-1/2')} aria-hidden="true" />
                                            )}
                                            {seg.down && (
                                                <span className={cn(LINE, 'top-1/2 left-1/2 h-1/2 w-0.5 -translate-x-1/2')} aria-hidden="true" />
                                            )}
                                            {here && (
                                                <div className="contents">
                                                    <div
                                                        className={cn(
                                                            'tooltip absolute top-1/2 left-1/2 z-10 -translate-x-1/2 -translate-y-1/2',
                                                            laneIdx === 0 ? 'tooltip-bottom' : 'tooltip-top',
                                                        )}
                                                        data-tip={stepTip(here)}
                                                    >
                                                        {here.open && (
                                                            <span
                                                                className={cn(
                                                                    'absolute inset-0 rounded-full opacity-60 motion-safe:animate-ping',
                                                                    UNIT_FILL[here.lane] ?? NO_UNIT_FILL,
                                                                )}
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() => setChosen(index)}
                                                            aria-pressed={index === chosen}
                                                            aria-label={`${stepTip(here)}, ${lane.label}`}
                                                            className={cn(
                                                                'relative block size-7 cursor-pointer rounded-full outline-none focus-visible:ring-2 focus-visible:ring-offset-2 motion-safe:transition-transform motion-safe:hover:scale-110',
                                                                UNIT_FILL[here.lane] ?? NO_UNIT_FILL,
                                                                index === chosen && 'ring-base-content ring-2 ring-offset-2',
                                                                here.kind === 'query' && 'rounded-field',
                                                                here.open && 'motion-safe:animate-pulse',
                                                            )}
                                                        />
                                                    </div>
                                                    <span
                                                        className="text-base-content/70 absolute inset-x-1 bottom-0.5 truncate text-center text-xs"
                                                        aria-hidden="true"
                                                    >
                                                        {here.open ? 'Now' : here.label}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* The chosen step. */}
            {step && (
                <div className="border-base-300 rounded-box grid gap-3 border p-4" aria-live="polite">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className={cn('rounded-field size-2.5 shrink-0', UNIT_FILL[step.lane] ?? NO_UNIT_FILL)} aria-hidden="true" />
                        <h3 className="text-base font-semibold">{step.label}</h3>
                        <span className="badge badge-ghost badge-sm">{laneLabel(step.lane)}</span>
                        {step.open && <span className="badge badge-primary badge-soft badge-sm capitalize">Current</span>}
                    </div>
                    {step.mine === false ? (
                        <p className="text-base-content/70 text-sm">The times of this step are for the {laneLabel(step.lane)} to see.</p>
                    ) : (
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                    <Building2 className="size-3.5" aria-hidden="true" />
                                    {step.kind === 'query' ? 'Raised' : 'Arrived'}
                                </dt>
                                <dd className="font-medium tabular-nums">{step.entered_at ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                    <Building2 className="size-3.5" aria-hidden="true" />
                                    {step.kind === 'query' ? 'Answered' : 'Left'}
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {step.kind === 'start' ? '—' : (step.left_at ?? (step.kind === 'query' ? 'Not yet' : 'Still there'))}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                    <Clock className="size-3.5" aria-hidden="true" />
                                    Time Taken
                                </dt>
                                <dd className="font-medium tabular-nums">{step.duration ?? '—'}</dd>
                            </div>
                            <div className="min-w-0">
                                <dt className="text-base-content/60 flex items-center gap-1 text-xs capitalize">
                                    <Users className="size-3.5" aria-hidden="true" />
                                    Held By
                                </dt>
                                <dd className="font-medium">
                                    {step.kind === 'start' || step.kind === 'query' ? laneLabel(step.lane) : stage ? holdersText(stage) : '—'}
                                </dd>
                            </div>
                        </dl>
                    )}
                </div>
            )}
        </div>
    );
}

/** What hovering a step says: its name, and how long the file was there when the viewer may see that ("With Technocrats: 2 days, still there"). */
function stepTip(step: TrackingPathStep): string {
    const where = step.open ? (step.kind === 'query' ? ', not answered yet' : ', the file is here now') : '';
    if (step.mine === false || !step.duration || step.kind === 'start') {
        return `${step.label}${where}`;
    }

    return `${step.label}: ${step.duration}${where}`;
}
