import { useEffect, useState } from 'react';

/** Complete class strings for each dashboard slot: layout plus the staggered entrance (starting-style transitions, motion-safe only). */
export const RISE = [
    // 0: page header row
    'flex flex-wrap items-end justify-between gap-3 motion-safe:transition motion-safe:duration-500 motion-safe:ease-out motion-safe:starting:translate-y-2 motion-safe:starting:opacity-0',
    // 1: KPI band
    'min-w-0 motion-safe:transition motion-safe:delay-75 motion-safe:duration-500 motion-safe:ease-out motion-safe:starting:translate-y-2 motion-safe:starting:opacity-0',
    // 2: main column
    'min-w-0 motion-safe:transition motion-safe:delay-150 motion-safe:duration-500 motion-safe:ease-out motion-safe:starting:translate-y-2 motion-safe:starting:opacity-0',
    // 3: side column
    'flex min-w-0 flex-col gap-4 motion-safe:transition motion-safe:delay-200 motion-safe:duration-500 motion-safe:ease-out motion-safe:starting:translate-y-2 motion-safe:starting:opacity-0',
] as const;

function prefersReducedMotion(): boolean {
    try {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch {
        return false;
    }
}

/**
 * Counts from 0 up to `target` once on mount (ease-out, about 650ms). Shows
 * the final value straight away when the user prefers reduced motion.
 */
export function useCountUp(target: number | null, duration = 650): number | null {
    const [value, setValue] = useState<number | null>(() => (target === null || prefersReducedMotion() ? target : 0));

    useEffect(() => {
        if (target === null || prefersReducedMotion()) {
            setValue(target);
            return;
        }
        let frame = 0;
        const start = performance.now();
        const step = (now: number) => {
            const p = Math.min(1, (now - start) / duration);
            setValue(Math.round(target * (1 - Math.pow(1 - p, 3))));
            if (p < 1) frame = requestAnimationFrame(step);
        };
        frame = requestAnimationFrame(step);
        return () => cancelAnimationFrame(frame);
    }, [target, duration]);

    return value;
}

/** Starts at 0 and switches to `target` just after mount, so a <progress> bar fills. */
export function useFill(target: number): number {
    const [value, setValue] = useState(() => (prefersReducedMotion() ? target : 0));

    useEffect(() => {
        const id = window.setTimeout(() => setValue(target), 150);
        return () => window.clearTimeout(id);
    }, [target]);

    return value;
}
