import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';

// V2-2: 'optional' (nothing required) and 'blocked' (waiting for something else, see note).
export type ProgressState = 'complete' | 'incomplete' | 'current' | 'error' | 'optional' | 'blocked';

export interface ProgressItem {
    key: string;
    label: string;
    state: ProgressState;
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
 * UI-4: the form progress strip (design.md section 7): one segment per VISIBLE
 * section, a 4px bar, a 16px marker and a 12px label; each segment links to its
 * section. Presentational only: the items and their states come from the server.
 */
export function ProgressStrip({ items, label = 'Form progress' }: { items: ProgressItem[]; label?: string }) {
    return (
        <nav aria-label={label}>
            <ol className="flex gap-2 overflow-x-auto">
                {items.map((item, i) => (
                    <li key={item.key} className="min-w-28 flex-1">
                        <a
                            href={item.href}
                            aria-current={item.state === 'current' ? 'step' : undefined}
                            className="focus-visible:ring-ring group flex flex-col gap-1.5 rounded-md py-1 outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                        >
                            <span
                                aria-hidden="true"
                                className={cn('h-1 w-full rounded-full', item.state === 'complete' || item.state === 'current' ? 'bg-primary' : 'bg-muted')}
                            />
                            <span className="flex items-center gap-1.5 text-[13px]">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border text-[13px] leading-none font-semibold tabular-nums',
                                        item.state === 'complete' && 'border-primary bg-primary text-primary-foreground',
                                        item.state === 'current' && 'border-primary text-primary',
                                        (item.state === 'incomplete' || item.state === 'optional') && 'border-input text-muted-foreground',
                                        item.state === 'blocked' && 'border-input text-muted-foreground border-dashed',
                                        item.state === 'error' && 'border-destructive-text text-destructive-text',
                                    )}
                                >
                                    {item.state === 'complete' ? <Check className="size-3" strokeWidth={3} /> : (item.number ?? i + 1)}
                                </span>
                                <span
                                    className={cn(
                                        'truncate group-hover:underline',
                                        item.state === 'current' ? 'text-primary font-semibold' : 'text-muted-foreground',
                                        item.state === 'error' && 'text-destructive-text',
                                    )}
                                >
                                    {item.label}
                                </span>
                                <span className="sr-only">, {item.note ?? STATE_TEXT[item.state]}</span>
                            </span>
                        </a>
                    </li>
                ))}
            </ol>
        </nav>
    );
}
