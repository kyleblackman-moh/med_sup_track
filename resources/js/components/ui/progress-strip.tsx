import { cn } from '@/lib/utils';
import { CircleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface ProgressItem {
    key: string;
    label: string;
    /** The section's anchor, for example "#section-stock". */
    href: string;
    /** The marker text; defaults to the position in the list. */
    number?: number;
    /** v0.4: the section has something to fix (from the server's issues); a small error dot on its marker. */
    problem?: boolean;
}

/** The application header's height (design.md section 5). */
const HEADER_HEIGHT = 72;

/** How far below the strip's bottom edge (or the header) a section's top must pass to become current. */
const LINE_OFFSET = 48;

/**
 * V2-4b: which section the user is at, as local UI state (no storage). A
 * section is current once its top has passed the line just under the header
 * and the sticky strip; at the bottom of the page the last section is current;
 * with no scroll the first one is. A small rAF-throttled scroll handler, so an
 * anchor jump (a marker click) updates the strip when the page lands.
 */
function useCurrentSection(hrefs: string[], strip: React.RefObject<HTMLElement | null>): number {
    const [current, setCurrent] = useState(0);
    const key = hrefs.join('|');

    useEffect(() => {
        const ids = key === '' ? [] : key.split('|').map((href) => href.replace(/^#/, ''));
        let frame = 0;

        const measure = () => {
            frame = 0;
            const root = document.documentElement;
            if (ids.length === 0) {
                return;
            }
            if (window.scrollY > 0 && window.innerHeight + window.scrollY >= root.scrollHeight - 2) {
                setCurrent(ids.length - 1);

                return;
            }
            const line = Math.max(strip.current?.getBoundingClientRect().bottom ?? 0, HEADER_HEIGHT) + LINE_OFFSET;
            let index = 0;
            ids.forEach((id, i) => {
                const top = document.getElementById(id)?.getBoundingClientRect().top;
                if (top !== undefined && top <= line) {
                    index = i;
                }
            });
            setCurrent(index);
        };

        const schedule = () => {
            if (frame === 0) {
                frame = window.requestAnimationFrame(measure);
            }
        };

        measure();
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        window.addEventListener('hashchange', schedule);

        return () => {
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('resize', schedule);
            window.removeEventListener('hashchange', schedule);
            if (frame !== 0) {
                window.cancelAnimationFrame(frame);
            }
        };
    }, [key, strip]);

    return Math.min(current, Math.max(hrefs.length - 1, 0));
}

/**
 * The form progress strip (design.md section 7, 0.7.3): it shows WHERE THE
 * USER IS on the form, not what is complete (each section's state pill shows
 * that). One column per VISIBLE section with a 36px circular marker, a 3px
 * connector to the next marker and a 14px/500 centred label below. Current:
 * solid --primary at 40px with the number, the label bold in --primary,
 * aria-current="step". Passed: solid --primary with the number. Ahead: a
 * tinted circle with the number in --muted-foreground. The connector is
 * --primary up to the current marker, --border after it. Each marker and
 * label is one plain link to its section. Below md only the markers show,
 * plus the current label. The items, their order and labels come from the
 * server; nothing here knows about completion or readiness.
 */
export function ProgressStrip({ items, label = 'Form progress' }: { items: ProgressItem[]; label?: string }) {
    const ref = useRef<HTMLElement>(null);
    const current = useCurrentSection(
        items.map((item) => item.href),
        ref,
    );

    return (
        <nav ref={ref} aria-label={label}>
            <ol className="flex">
                {items.map((item, i) => {
                    const isCurrent = i === current;
                    const passed = i < current;
                    const last = i === items.length - 1;

                    return (
                        <li key={item.key} className="relative min-w-0 flex-1">
                            {/* The connector to the next marker, centred on the 40px marker row; decorative. */}
                            {!last && (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute top-[18.5px] left-1/2 h-[3px] w-full rounded-full motion-safe:transition-colors motion-safe:duration-200',
                                        i < current ? 'bg-primary' : 'bg-border',
                                    )}
                                />
                            )}
                            <a
                                href={item.href}
                                aria-current={isCurrent ? 'step' : undefined}
                                className="focus-visible:ring-ring group relative flex flex-col items-center gap-2 rounded-lg px-1 py-0.5 text-center outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                            >
                                <span className="relative flex h-10 items-center justify-center">
                                    {item.problem && (
                                        // A shape, not only a colour: the alert icon on the marker's corner.
                                        <CircleAlert
                                            aria-hidden="true"
                                            className="text-error bg-base-100 absolute top-0 left-1/2 z-10 ms-3 size-4 rounded-full"
                                        />
                                    )}
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums motion-safe:transition-colors motion-safe:duration-200',
                                            passed && 'bg-primary text-primary-foreground',
                                            !passed && !isCurrent && 'bg-muted text-muted-foreground border',
                                            isCurrent && 'bg-primary text-primary-foreground size-10 text-base shadow-xs',
                                        )}
                                    >
                                        {item.number ?? i + 1}
                                    </span>
                                </span>
                                <span className={cn('text-sm leading-snug font-medium', !isCurrent && 'sr-only md:not-sr-only')}>
                                    <span className={cn('break-words group-hover:underline', isCurrent ? 'text-primary font-bold' : 'text-muted-foreground')}>
                                        {item.label}
                                    </span>
                                    {isCurrent && <span className="sr-only">, current section</span>}
                                    {item.problem && <span className="sr-only">, has items to fix</span>}
                                </span>
                            </a>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
