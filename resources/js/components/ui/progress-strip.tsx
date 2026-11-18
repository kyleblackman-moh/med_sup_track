import { cn } from '@/lib/utils';
import { Check, CircleAlert } from 'lucide-react';

// V2-2: 'optional' (nothing required) and 'blocked' (waiting for something else, see note).
export type ProgressState = 'complete' | 'incomplete' | 'current' | 'error' | 'optional' | 'blocked';

export interface ProgressItem {
    key: string;
    label: string;
    state: ProgressState;
    /** V2-4: the server's current section (the first visible incomplete one; at most one). */
    current?: boolean;
    /** The section's anchor, for example "#section-stock". */
    href: string;
    /** The marker text; defaults to the position in the list. */
    number?: number;
    /** Screen-reader state text instead of the default (for example why a section is blocked). */
    note?: string | null;
}

/** Visible-to-screen-reader state text, so the marker's shape is never the only signal. */
const STATE_TEXT: Record<ProgressState, string> = {
    complete: 'Complete',
    incomplete: 'Not complete',
    current: 'Current section',
    error: 'Needs attention',
    optional: 'Optional',
    blocked: 'Not available yet',
};

/**
 * The form progress strip (design.md section 7), restyled in place in V2-4:
 * one column per VISIBLE section with a 36px circular marker, a 3px connector
 * to the next marker and a 14px/500 centred label below. Complete: solid
 * --primary with a white check. Current (the server's flag): solid --primary
 * at 40px with the number, the label bold in --primary. Not complete: a tinted
 * circle with the number in --muted-foreground. Blocked: a warning-tone ring
 * with circle-alert. The connector is --primary after a complete section.
 * Each marker and label is one plain link to its section. Below md only the
 * markers show, plus the current label. Presentational only: the items, their
 * order, states and labels come from the server.
 */
export function ProgressStrip({ items, label = 'Form progress' }: { items: ProgressItem[]; label?: string }) {
    return (
        <nav aria-label={label}>
            <ol className="flex">
                {items.map((item, i) => {
                    const current = item.current === true || item.state === 'current';
                    const last = i === items.length - 1;

                    return (
                        <li key={item.key} className="relative min-w-0 flex-1">
                            {/* The connector to the next marker, centred on the 40px marker row; decorative. */}
                            {!last && (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute top-[18.5px] left-1/2 h-[3px] w-full rounded-full',
                                        item.state === 'complete' ? 'bg-primary' : 'bg-border',
                                    )}
                                />
                            )}
                            <a
                                href={item.href}
                                aria-current={current ? 'step' : undefined}
                                className="focus-visible:ring-ring group relative flex flex-col items-center gap-2 rounded-lg px-1 py-0.5 text-center outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                            >
                                <span className="flex h-10 items-center justify-center">
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold tabular-nums',
                                            item.state === 'complete' && 'bg-primary text-primary-foreground',
                                            (item.state === 'incomplete' || item.state === 'optional') && 'bg-muted text-muted-foreground border',
                                            item.state === 'blocked' && 'bg-status-warning text-status-warning-foreground border-status-warning-foreground border-2',
                                            item.state === 'error' && 'bg-card text-destructive-text border-destructive-text border-2',
                                            current && 'bg-primary text-primary-foreground border-primary size-10 border-0 text-base shadow-xs',
                                        )}
                                    >
                                        {item.state === 'complete' ? (
                                            <Check className="size-4" strokeWidth={3} />
                                        ) : item.state === 'blocked' && !current ? (
                                            <CircleAlert className="size-4" />
                                        ) : (
                                            (item.number ?? i + 1)
                                        )}
                                    </span>
                                </span>
                                <span className={cn('text-sm leading-snug font-medium', !current && 'sr-only md:not-sr-only')}>
                                    <span
                                        className={cn(
                                            'break-words group-hover:underline',
                                            current ? 'text-primary font-bold' : 'text-muted-foreground',
                                            item.state === 'complete' && !current && 'text-foreground',
                                            item.state === 'error' && 'text-destructive-text',
                                        )}
                                    >
                                        {item.label}
                                    </span>
                                    <span className="sr-only">, {item.note ?? (current ? STATE_TEXT.current : STATE_TEXT[item.state])}</span>
                                </span>
                            </a>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
