import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { KeyboardEvent, useRef } from 'react';

export interface CaseTab<T extends string = string> {
    id: T;
    label: string;
}

interface CaseTabsProps<T extends string> {
    tabs: CaseTab<T>[];
    active: T;
    onSelect: (tab: T) => void;
}

/**
 * Tab strip for the staff case view, the same pattern as the vendor request
 * form: wraps on narrow screens, arrow keys move between tabs. Neutral token
 * colours only (no complete/incomplete or lateness meaning).
 */
export function CaseTabs<T extends string>({ tabs, active, onSelect }: CaseTabsProps<T>) {
    const refs = useRef<Record<string, HTMLButtonElement | null>>({});

    const onKeyDown = (e: KeyboardEvent, index: number) => {
        const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (step === 0) {
            return;
        }
        e.preventDefault();
        const next = tabs[(index + step + tabs.length) % tabs.length];
        refs.current[next.id]?.focus();
        onSelect(next.id);
    };

    return (
        <div role="tablist" aria-label="Case sections" className="flex flex-wrap gap-1 border-b">
            {tabs.map((tab, index) => {
                const selected = tab.id === active;

                return (
                    <button
                        key={tab.id}
                        ref={(el) => {
                            refs.current[tab.id] = el;
                        }}
                        type="button"
                        role="tab"
                        id={`tab-${tab.id}`}
                        aria-selected={selected}
                        aria-controls={`panel-${tab.id}`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onSelect(tab.id)}
                        onKeyDown={(e) => onKeyDown(e, index)}
                        className={cn(
                            '-mb-px flex shrink-0 items-center gap-2 rounded-t-md border-b-[3px] px-3 py-2 text-sm whitespace-nowrap transition-colors',
                            selected
                                ? 'border-primary bg-muted text-foreground font-semibold'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground border-transparent font-medium',
                        )}
                    >
                        <span className="tabular-nums opacity-70">{index + 1}.</span>
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}

interface CaseTabNavProps<T extends string> {
    tabs: CaseTab<T>[];
    active: T;
    onSelect: (tab: T) => void;
    finishHref: string;
}

/**
 * Previous / Next, pinned to the bottom of the screen on every tab. Moves
 * between tabs only (it never saves). On the last tab Next becomes Finish.
 */
export function CaseTabNav<T extends string>({ tabs, active, onSelect, finishHref }: CaseTabNavProps<T>) {
    const index = tabs.findIndex((t) => t.id === active);
    const prev = tabs[index - 1];
    const next = tabs[index + 1];

    return (
        <div className="bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky bottom-0 z-10 -mx-4 border-t px-4 py-3 backdrop-blur">
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" onClick={() => prev && onSelect(prev.id)} disabled={!prev}>
                    {prev ? `Back: ${prev.label}` : 'Back'}
                </Button>
                <span className="text-muted-foreground ml-auto text-xs">
                    {index + 1} of {tabs.length}
                </span>
                {next ? (
                    <Button type="button" onClick={() => onSelect(next.id)}>
                        Next: {next.label}
                    </Button>
                ) : (
                    // The last tab (Actions) ends the walk-through: back to the case list.
                    <Button asChild>
                        <Link href={finishHref}>Finish: back to cases</Link>
                    </Button>
                )}
            </div>
        </div>
    );
}
